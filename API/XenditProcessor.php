<?php

namespace XenditPaymentForPaymattic\API;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use WPPayForm\Framework\Support\Arr;
use WPPayForm\App\Models\Transaction;
use WPPayForm\App\Models\Form;
use WPPayForm\App\Models\Submission;
use WPPayForm\App\Services\PlaceholderParser;
use WPPayForm\App\Services\ConfirmationHelper;
use WPPayForm\App\Models\SubmissionActivity;

// can't use namespace as these files are not accessible yet
require_once XENDIT_PAYMENT_FOR_PAYMATTIC_DIR . '/Settings/XenditElement.php';
require_once XENDIT_PAYMENT_FOR_PAYMATTIC_DIR . '/Settings/XenditSettings.php';
require_once XENDIT_PAYMENT_FOR_PAYMATTIC_DIR . '/API/IPN.php';


class XenditProcessor
{
    public $method = 'xendit';

    protected $form;

    public function init()
    {
        new  \XenditPaymentForPaymattic\Settings\XenditElement();
        (new  \XenditPaymentForPaymattic\Settings\XenditSettings())->init();
        (new \XenditPaymentForPaymattic\API\IPN())->init();

        add_filter('wppayform/choose_payment_method_for_submission', array($this, 'choosePaymentMethod'), 10, 4);
        add_action('wppayform/form_submission_make_payment_xendit', array($this, 'makeFormPayment'), 10, 6);
        // add_action('wppayform_payment_frameless_' . $this->method, array($this, 'handleSessionRedirectBack'));
        add_filter('wppayform/entry_transactions_' . $this->method, array($this, 'addTransactionUrl'), 10, 2);
        // add_action('wppayform_ipn_xendit_action_refunded', array($this, 'handleRefund'), 10, 3);
        add_filter('wppayform/submitted_payment_items_' . $this->method, array($this, 'validateSubscription'), 10, 4);
    }



    protected function getPaymentMode($formId = false)
    {
        $isLive = (new \XenditPaymentForPaymattic\Settings\XenditSettings())->isLive($formId);

        if ($isLive) {
            return 'live';
        }
        return 'test';
    }

    public function addTransactionUrl($transactions, $submissionId)
    {
        foreach ($transactions as $transaction) {
            if ($transaction->payment_method == 'xendit' && $transaction->charge_id) {
                $transactionUrl = Arr::get(unserialize($transaction->payment_note), '_links.dashboard.href');
                $transaction->transaction_url =  $transactionUrl;
            }
        }
        return $transactions;
    }

    public function choosePaymentMethod($paymentMethod, $elements, $formId, $form_data)
    {
        if ($paymentMethod) {
            // Already someone choose that it's their payment method
            return $paymentMethod;
        }
        // Now We have to analyze the elements and return our payment method
        foreach ($elements as $element) {
            if ((isset($element['type']) && $element['type'] == 'xendit_gateway_element')) {
                return 'xendit';
            }
        }
        return $paymentMethod;
    }

    public function makeFormPayment($transactionId, $submissionId, $form_data, $form, $hasSubscriptions)
    {
        $paymentMode = $this->getPaymentMode();

        $transactionModel = new Transaction();
        if ($transactionId) {
            $transactionModel->updateTransaction($transactionId, array(
                'payment_mode' => $paymentMode
            ));
        }
        $transaction = $transactionModel->getTransaction($transactionId);

        $submission = (new Submission())->getSubmission($submissionId);
        $this->handleRedirect($transaction, $submission, $form, $paymentMode);
    }

    private function getSuccessURL($form, $submission)
    {
        // Check If the form settings have success URL
        $confirmation = Form::getConfirmationSettings($form->ID);
        $confirmation = ConfirmationHelper::parseConfirmation($confirmation, $submission);
        if (
            ($confirmation['redirectTo'] == 'customUrl' && $confirmation['customUrl']) ||
            ($confirmation['redirectTo'] == 'customPage' && $confirmation['customPage'])
        ) {
            if ($confirmation['redirectTo'] == 'customUrl') {
                $url = $confirmation['customUrl'];
            } else {
                $url = get_permalink(intval($confirmation['customPage']));
            }
            $url = add_query_arg(array(
                'payment_method' => 'xendit'
            ), $url);
            return PlaceholderParser::parse($url, $submission);
        }
        // now we have to check for global Success Page
        $globalSettings = get_option('wppayform_confirmation_pages');
        if (isset($globalSettings['confirmation']) && $globalSettings['confirmation']) {
            return add_query_arg(array(
                'wpf_submission' => $submission->submission_hash,
                'payment_method' => 'xendit'
            ), get_permalink(intval($globalSettings['confirmation'])));
        }
        // In case we don't have global settings
        return add_query_arg(array(
            'wpf_submission' => $submission->submission_hash,
            'payment_method' => 'xendit'
        ), home_url());
    }

    public function handleRedirect($transaction, $submission, $form, $methodSettings)
    {
        $settings = (new \XenditPaymentForPaymattic\Settings\XenditSettings())->getSettings();

        $invoice_duration = Arr::get($settings, 'invoice_duration', '');
        $customer_notification_preference = Arr::get($settings, 'customer_notification_preference', []);

        $successUrl = $this->getSuccessURL($form, $submission);
        // we need to change according to the payment gateway documentation
        $formDataFormatted = $submission->form_data_formatted;
        $formDataRaw = $submission->form_data_raw;
        $phone = Arr::get($formDataFormatted, 'phone', '');
        $address = $formDataRaw['address_input'];

        if (!empty($address)) {
           $address = array(
                'city' => Arr::get($address, 'city', ''),
                'country' => Arr::get($address, 'country', ''),
                'postal_code' => Arr::get($address, 'zip_code', ''),
                'state' => Arr::get($address, 'state', ''),
                'street_line1' => Arr::get($address, 'address_line_1', ''),
                'street_line2' => Arr::get($address, 'address_line_2', ''),
           );
        }
  

        // we need to change according to the payment gateway documentation
        $paymentArgs = array(
            'external_id' => $submission->submission_hash,
            'amount' => number_format((float) $transaction->payment_total / 100, 2, '.', ''),
            'description' => $form->post_title,
            'payer_email' => $submission->customer_email,
            'should_send_email' => true,
            'customer' => array(
                'given_names' => $submission->customer_name ? $submission->customer_name : 'Guest',
                'email' => $submission->customer_email,
                'mobile_number' => $phone ? $phone : '0000000000',
                'addresses' => $address ? [$address] : [],
            ),
            'success_redirect_url' => $successUrl,
            'currency' => $submission->currency,
            'locale' => 'en',
        );
        

        if ($invoice_duration && $invoice_duration != 'none') {
            $invoice_duration = intval($invoice_duration) * 3600;
            $paymentArgs['invoice_duration'] = $invoice_duration;
        }

        if ($customer_notification_preference && count($customer_notification_preference) > 0) {
            $paymentArgs['customer_notification_preference'] = array(
                'invoice_created' => $customer_notification_preference,
                'invoice_reminder' => $customer_notification_preference,
                'invoice_paid' => $customer_notification_preference,
            );
        }


        $paymentArgs = apply_filters('wppayform_xendit_payment_args', $paymentArgs, $submission, $transaction, $form);
        $invoice = (new IPN())->makeApiCall('invoices', $paymentArgs, $form->ID, 'POST');

     

        if (is_wp_error($invoice)) {
            $message = $invoice->error_data[423]['message'];
            wp_send_json_error(array('message' => $message), 423);
        }

        $invoiceId = Arr::get($invoice, 'id');
        $status = Arr::get($invoice, 'status');

        $transactionModel = new Transaction();
        $transactionModel->updateTransaction($transaction->id, array(
            'status' => strtolower($status),
            'charge_id' => $invoiceId,
            'payment_mode' => $this->getPaymentMode($submission->form_id)
        ));

        if (is_wp_error($invoice)) {
            do_action('wppayform_log_data', [
                'form_id' => $submission->form_id,
                'submission_id'        => $submission->id,
                'type' => 'activity',
                'created_by' => 'Paymattic BOT',
                'title' => 'xendit Payment Redirect Error',
                'content' => $invoice->get_error_message()
            ]);

            wp_send_json_success([
                'message'      => $invoice->get_error_message()
            ], 423);
        }


        do_action('wppayform_log_data', [
            'form_id' => $form->ID,
            'submission_id' => $submission->id,
            'type' => 'activity',
            'created_by' => 'Paymattic BOT',
            'title' => 'xendit Payment Redirect',
            'content' => 'User redirect to xendit for completing the payment'
        ]);

        wp_send_json_success([
            // 'nextAction' => 'payment',
            'call_next_method' => 'normalRedirect',
            'redirect_url' => $invoice['invoice_url'],
            'message'      => __('You are redirecting to xendit.com to complete the purchase. Please wait while you are redirecting....', 'xendit-payment-for-paymattic'),
        ], 200);
    }

    public function handleRefund($refundAmount, $submission, $vendorTransaction)
    {
        $transaction = $this->getLastTransaction($submission->id);
        $this->updateRefund($vendorTransaction['status'], $refundAmount, $transaction, $submission);
    }

    public function updateRefund($newStatus, $refundAmount, $transaction, $submission)
    {
        $submissionModel = new Submission();
        $submission = $submissionModel->getSubmission($submission->id);
        if ($submission->payment_status == $newStatus) {
            return;
        }

        $submissionModel->updateSubmission($submission->id, array(
            'payment_status' => $newStatus
        ));

        Transaction::where('submission_id', $submission->id)->update(array(
            'status' => $newStatus,
            'updated_at' => current_time('mysql')
        ));

        do_action('wppayform/after_payment_status_change', $submission->id, $newStatus);

        $activityContent = 'Payment status changed from <b>' . $submission->payment_status . '</b> to <b>' . $newStatus . '</b>';
        $note = wp_kses_post('Status updated by xendit.');
        $activityContent .= '<br />Note: ' . $note;
        SubmissionActivity::createActivity(array(
            'form_id' => $submission->form_id,
            'submission_id' => $submission->id,
            'type' => 'info',
            'created_by' => 'xendit',
            'content' => $activityContent
        ));
    }

    public function getLastTransaction($submissionId)
    {
        $transactionModel = new Transaction();
        $transaction = $transactionModel->where('submission_id', $submissionId)
            ->first();
        return $transaction;
    }

    public function markAsPaid($status, $updateData, $transaction)
    {
        $submissionModel = new Submission();
        $submission = $submissionModel->getSubmission($transaction->submission_id);

        $formDataRaw = $submission->form_data_raw;
        $formDataRaw['xendit_ipn_data'] = $updateData;
        $submissionData = array(
            'payment_status' => $status,
            'form_data_raw' => maybe_serialize($formDataRaw),
            'updated_at' => current_time('Y-m-d H:i:s')
        );

        $submissionModel->where('id', $transaction->submission_id)->update($submissionData);

        $transactionModel = new Transaction();
        $data = array(
            'charge_id' => $updateData['charge_id'],
            'payment_note' => $updateData['payment_note'],
            'status' => $status,
            'updated_at' => current_time('Y-m-d H:i:s')
        );
        $transactionModel->where('id', $transaction->id)->update($data);

        $transaction = $transactionModel->getTransaction($transaction->id);
        SubmissionActivity::createActivity(array(
            'form_id' => $transaction->form_id,
            'submission_id' => $transaction->submission_id,
            'type' => 'info',
            'created_by' => 'PayForm Bot',
            'content' => sprintf(__('Transaction Marked as paid and xendit Transaction ID: %s', 'xendit-payment-for-paymattic'), $data['charge_id'])
        ));

        do_action('wppayform/form_payment_success_xendit', $submission, $transaction, $transaction->form_id, $updateData);
        do_action('wppayform/form_payment_success', $submission, $transaction, $transaction->form_id, $updateData);
    }

    public function validateSubscription($paymentItems)
    {
        wp_send_json_error(array(
            'message' => __('Subscription with xendit is not supported yet!', 'xendit-payment-for-paymattic'),
            'payment_error' => true
        ), 423);
    }
}
