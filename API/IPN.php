<?php

namespace XenditPaymentForPaymattic\API;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use WPPayForm\Framework\Support\Arr;
use WPPayForm\App\Models\Submission;
use WPPayForm\App\Models\Subscription;
use WPPayForm\App\Models\SubscriptionTransaction;
use WPPayForm\App\Models\SubmissionActivity;
use XenditPaymentForPaymattic\Settings\XenditSettings;
use WPPayForm\App\Models\Transaction;

class IPN
{
    public function init()
    {
        $this->verifyIPN();
    }

    public function verifyIPN()
    {
        if (!isset($_REQUEST['wpf_xendit_listener'])) {
            return;
        }

        // Check the request method is POST
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] != 'POST') {
            return;
        }

        // Set initial post data to empty string
        $post_data = '';

        // Fallback just in case post_max_size is lower than needed
        if (ini_get('allow_url_fopen')) {
            $post_data = file_get_contents('php://input');
        } else {
            // If allow_url_fopen is not enabled, then make sure that post_max_size is large enough
            ini_set('post_max_size', '12M');
        }

        $data =  json_decode($post_data);

        if (!is_object($data)) {
            error_log("Invalid or empty JSON received.");
            return;
        }

        if (!property_exists($data, 'event')) {
            $this->handleInvoicePaid($data);
        } else {
            $this->handleIpn($data);
        }

        exit(200);
    }

    protected function handleIpn($data)
    {
        $eventType = $data->event ? str_replace( '.', '_', $data->event) : 'unknown';
        try {
            switch ($eventType) {
                case 'recurring_plan_activated':
                    $this->handleRecurringPlanActivated($data->data);
                    break;
                case 'recurring_plan_inactivated':
                    $this->handleRecurringPlanDeActivated($data->data);
                    break;
                case 'recurring_cycle_succeeded':
                    $this->handleRecurringSucceeded($data->data);
                    break;
                case 'recurring_cycle_failed':
                    $this->handleRecurringFailed($data->data);
                    break;
                default:
                    error_log('Unhandled webhook event: ' . $eventType);
            }
            
        } catch (\Exception $e) {
            // exit with message
            return $e->getMessage();
        }
    }

    private function handleRecurringPlanActivated($plan)
    {
        // plan id is the vendor subscription id
        $vendorSubscriptionId = $plan->id ?? null;

        if (!$vendorSubscriptionId) {
            return;
        }

        $subscriptionModel = Subscription::where('vendor_subscriptipn_id', $vendorSubscriptionId)->first();

        if (!$subscriptionModel) {
            return;
        }

        $submissionModel = Submission::where('id', $subscriptionModel->submission_id)->first();
        if (!$submissionModel) {
            error_log('Submission not found for subscription ID: ' . $subscriptionModel->id);
            return;
        }
        if ($subscriptionModel->trial_days) {
            $transaction = Transaction::where('subscription_id', $subscriptionModel->id)
                ->where('payment_method', 'xendit')
                ->first();
            
            do_action('wppayform/payment_sucess', $submissionModel, $transaction, $submissionModel->form_id);
            do_action('wppayform/payment_sucess_xendit', $submissionModel, $transaction, $submissionModel->form_id);
            do_action('wppayform/subscription_payment_received', $submissionModel, $transaction, $submissionModel->form_id, $subscriptionModel);
            do_action('wppayform/subscription_payment_received_xendit', $submissionModel, $transaction, $submissionModel->form_id, $subscriptionModel);
        } else if ($subscriptionModel->initial_amount) {
            // update the subscription in xendit to the eactual recurring amount
            // make api call to xendit to update the plan
            $updatedXenditPlan = $this->makeApiCall('recurring/plans/' . $vendorSubscriptionId, [
                'amount' => (int)($subscriptionModel->recurring_amount / 100),
                'currency' => strtoupper($submissionModel->currency)
            ], $subscriptionModel->form_id, 'PATCH');

        }


        $status = strtolower($plan->status ?? 'active');

        $updateData = [
            'vendor_subscriptipn_id' => $vendorSubscriptionId,
            'status' => $status,
            'vendor_response' => maybe_serialize($plan)
        ];

        $subscriptionModel->update($updateData);

        exit(200);

    }

    private function handleRecurringPlanDeActivated($plan)
    {
         // plan id is the vendor subscription id
        $vendorSubscriptionId = $plan->id ?? null;

        if (!$vendorSubscriptionId) {
            return;
        }

        $subscriptionModel = Subscription::where('vendor_subscriptipn_id', $vendorSubscriptionId)->first();

        if (!$subscriptionModel || $subscriptionModel->status == 'cancelled') {
            return;
        }

        $status = strtolower($plan->status ?? 'cancelled');
        if ($status == 'inactive') {
            $status = 'cancelled';
        }

        $submissionModel = new Submission();
        $submission = $submissionModel->getSubmission($subscriptionModel->submission_id);

        SubmissionActivity::createActivity(array(
            'form_id' => $subscriptionModel->form_id,
            'submission_id' => $subscriptionModel->submission_id,
            'type' => 'info',
            'created_by' => 'PayForm Bot',
            'content' => sprintf(__('Subscription Cancelled with Subscription ID: %s', 'xendit-payment-for-paymattic'), json_encode($plan) .'', $subscriptionModel->submission_id)
        ));

        $subscriptionModel->update(['status' => $status]);

        do_action('wppayform/subscription_payment_canceled', $submission, $subscriptionModel, $submission->form_id, $plan);
        do_action('wppayform/subscription_payment_canceled_authorizedotnet', $submission, $subscriptionModel, $submission->form_id, $plan);

        exit(200);
    }



    protected function handleInvoicePaid($data)
    {
        $invoiceId = $data->id;
        $externalId = $data->external_id;
        $status = strtolower($data->status);

        //get transaction from database
        $transaction = Transaction::where('charge_id', $invoiceId)
            ->where('payment_method', 'xendit')
            ->first();

        if (!$transaction || $transaction->payment_method != 'xendit' || $transaction->payment_status == $status) {
            return;
        }

        $submissionModel = new Submission();
        $submission = $submissionModel->getSubmission($transaction->submission_id);

        if ($submission->submission_hash != $externalId) {
            // not our invoice
            return;
        }

        $invoice = $this->makeApiCall('invoices/' . $invoiceId, [], $transaction->form_id, '');

        if (!$invoice || is_wp_error($invoice)) {
            return;
        }

        do_action('wppayform/form_submission_activity_start', $transaction->form_id);

        $updateData = [
            'payment_note'     => maybe_serialize($data),
            'charge_id'        => sanitize_text_field($invoiceId),
            'status' => $status
        ];

        $xenditProcessor = new XenditProcessor();
        if ('paid' == $status) {
            $xenditProcessor->markAsPaid($status, $updateData, $transaction);
        } else {
            $xenditProcessor->handleOtherStatus($status, $updateData, $transaction);
        }
        
    }

    private function handleRecurringSucceeded($recurringCharge)
    {
        $vendorSubscriptionId = $recurringCharge->plan_id ?? null;
        $vendorChargeId = $recurringCharge->id ?? null;

        if (!$vendorSubscriptionId || !$vendorChargeId) {
            return;
        }

        $subscriptionModel = Subscription::where('vendor_subscriptipn_id', $vendorSubscriptionId)->first();

        if (!$subscriptionModel) {
            return;
        }

        $submissionModel = Submission::where('id', $subscriptionModel->submission_id)->first();

        if (!$submissionModel) {
            error_log('Submission not found for subscription ID: ' . $subscriptionModel->id);
            return;
        }

        $amount = $recurringCharge->amount ?? 0;
        
        $status = strtolower($recurringCharge->status ?? 'SUCCEEDED');

        if ($status == 'succeeded') {
            $status = 'paid';
        }
        $cycleNumber = $recurringCharge->cycle_number ?? null;
        if ($cycleNumber == 1) {
            $transactionModel = new Transaction();
            $transaction = $transactionModel->where('submission_id', $submissionModel->id)
                ->where('payment_method', 'xendit')
                ->where('subscription_id', $subscriptionModel->id)
                ->first();
         
            if ($transaction) {
                // update the transaction status to paid
                $transaction->update([
                    'status' => 'paid',
                    'charge_id' => $vendorChargeId,
                    'payment_total' => $amount,
                    'updated_at' => current_time('mysql')
                ]);
                // update the bill count
                $subscriptionModel->updateSubscription($subscriptionModel->id, [
                    'status' => 'active',
                    'bill_count' => 1,
                    'updated_at' => current_time('mysql')
                ]);

                // update submission to paid if not already
                $submissionModel->update([
                    'payment_total' => $amount,
                    'payment_status' => 'paid',
                    'updated_at' => current_time('mysql')
                ]);

                // New Payment Made so we have to fire some events here
                // also trigger submission paid event
                do_action('wppayform/payment_sucess', $submissionModel, $transaction, $submissionModel->form_id);
                do_action('wppayform/payment_sucess_xendit', $submissionModel, $transaction, $submissionModel->form_id);
                do_action('wppayform/subscription_payment_received', $submissionModel, $transaction, $submissionModel->form_id, $subscriptionModel);
                do_action('wppayform/subscription_payment_received_xendit', $submissionModel, $transaction, $submissionModel->form_id, $subscriptionModel);
            } else {
                return;
            }

        } else {
            $subscriptionTransaction = new SubscriptionTransaction();
            $paymentMode = $submissionModel->payment_mode ?: 'test';
            $transactionId = $subscriptionTransaction->maybeInsertCharge([
                'form_id' => $submissionModel->form_id,
                'user_id' => $submissionModel->user_id,
                'submission_id' => $submissionModel->id,
                'subscription_id' => $submissionModel->id,
                'transaction_type' => 'subscription',
                'payment_method' => 'authorizedotnet',
                'charge_id' => $vendorChargeId,
                'payment_total' => $amount * 100,
                'status' => 'paid',
                'currency' => $submissionModel->currency,
                'payment_mode' => $paymentMode,
                'payment_note' => sanitize_text_field('subscription payment synced from upstream'),
                'created_at' => current_time('mysql'), // current_time('mysql'),
                'updated_at' => current_time('mysql')
            ]);

            $transaction = $subscriptionTransaction->getTransaction($transactionId);
            $subscriptionModel = new Subscription();
        
            // Check For Payment EOT
            if ($subscriptionModel->bill_times && $cycleNumber >= $subscriptionModel->bill_times) {
                // we will update the subscription status to completed
                $subscriptionModel->updateSubscription($subscriptionModel->id, [
                    'status' => 'completed',
                    'bill_count' => $cycleNumber
                ]);

                SubmissionActivity::createActivity(array(
                    'form_id' => $submissionModel->form_id,
                    'submission_id' => $submissionModel->id,
                    'type' => 'activity',
                    'created_by' => 'Paymattic BOT',
                    'content' => __('The Subscription Term Period has been completed', 'wp-payment-form-pro')
                ));

                $updatedSubscription = $subscriptionModel->getSubscription($subscriptionModel->id);
                do_action('wppayform/subscription_payment_eot_completed', $submissionModel, $updatedSubscription, $submissionModel->form_id, []);
                do_action('wppayform/subscription_payment_eot_completed_authorizedotnet', $submissionModel, $updatedSubscription, $submissionModel->form_id, []);
            } 

        }

        return;
    }

    private function handleRecurringFailed($data)
    {

        // Handle recurring failed event
        error_log('Recurring failed: ' . json_encode($data));
        error_log(print_r($data, true));
    }

    private function handlePaymentFailed($data)
    {
        // Handle payment failed event
        error_log('Payment failed: ' . json_encode($data));
    }

    public function makeApiCall($path, $args, $formId, $method = 'GET')
    {
        $apiKey = (new XenditSettings())->getApiKey($formId);
        // we are using basic authentication , we will use the api key as username and password empty so add : at the end
        $basicAuthCred = $apiKey . ':';
        $basicAuthCred = base64_encode($basicAuthCred);

        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Basic ' . $basicAuthCred
        ];
    

        if ($method == 'POST') {
            $response = wp_remote_post('https://api.xendit.co/' . $path, [
                'headers' => $headers,
                'body' => json_encode($args)
            ]);

        } elseif ($method == 'PATCH') {
            $response = wp_remote_request('https://api.xendit.co/' . $path, [
                'method' => 'PATCH',
                'headers' => $headers,
                'body' => json_encode($args)
            ]);

        } else {
            // For GET requests, add query parameters to the URL
            $url = 'https://api.xendit.co/' . $path;
            if (!empty($args)) {
                $url .= '?' . http_build_query($args);
            }
            
            $response = wp_remote_get($url, [
                'headers' => $headers
            ]);
        }

        if (is_wp_error($response)) {
            return $response;
        }



        $body = wp_remote_retrieve_body($response);
        $responseData = json_decode($body, true);


        if (empty($responseData['id'])) {
            $message = Arr::get($responseData, 'detail');
            if (!$message) {
                $message = Arr::get($responseData, 'error.message');
            }
            if (!$message) {
                $message = 'Unknown Xendit API request error';
            }

            return new \WP_Error(423, $message, $responseData);
        }

        return $responseData;
    }

}
