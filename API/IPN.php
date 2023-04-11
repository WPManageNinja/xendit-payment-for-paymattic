<?php

namespace XenditPaymentForPaymattic\API;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use WPPayForm\Framework\Support\Arr;
use WPPayForm\App\Models\Submission;
use WPPayForm\App\Models\SubmissionActivity;
use XenditPaymentForPaymattic\Settings\XenditSettings;
use WPPayForm\App\Models\Transaction;

class IPN
{
    public function init() 
    {
        add_action('init', array($this, 'verifyIPN'));
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

        if (!property_exists($data, 'event')) {
           $this->handleInvoicePaid($data);
        } else {
             error_log("specific event");
        }
       
        // // Start the encoded data collection with notification command
        // $encoded_data = 'cmd=_notify-validate';

        // // Get current arg separator
        // $arg_separator = ini_get('arg_separator.output');

        // // Verify there is a post_data
        // if ($post_data || strlen($post_data) > 0) {
        //     // Append the data
        //     $encoded_data .= $arg_separator . $post_data;
        // } else {
        //     // Check if POST is empty
        //     if (empty($_POST)) {
        //         // Nothing to do
        //         return;
        //     } else {
        //         // Loop through each POST
        //         foreach ($_POST as $key => $value) {
        //             // Encode the value and append the data
        //             $encoded_data .= $arg_separator . "$key=" . urlencode($value);
        //         }
        //     }
        // }

        exit(200);
    }

    protected function handleIpn($data)
    {
        $submissionId = intval(Arr::get($data, 'submission_id'));
        if (!$submissionId || empty($data['id'])) {
            return;
        }
        $submission = Submission::where('id', $submissionId)->first();

        if (!$submission) {
            return;
        }
        $vendorTransaction = $this->makeApiCall('payments/'.$data['id'], [], $submission->form_id, 'GET');

        if (is_wp_error($vendorTransaction)) {
            do_action('wppayform_log_data', [
                'form_id' => $submission->form_id,
                'submission_id' => $submission->id,
                'type' => 'activity',
                'component' => 'Payment',
                'created_by' => 'Paymattic BOT',
                'title' => 'Xendit Payment Webhook Error',
                'content' => $vendorTransaction->get_error_message()
            ]);
        }

        $status = $vendorTransaction['status'];
        do_action('wppayform_ipn_xendit_action_'.$status, $submission, $vendorTransaction);

        if ($refundAmount = Arr::get($vendorTransaction, 'amountRefunded.value')) {
            $refundAmount = intval($refundAmount * 100); // in cents
            do_action('wppayform_ipn_xendit_action_refunded', $refundAmount, $submission, $vendorTransaction);
        }
    }

    protected function handleInvoicePaid($data) 
    {
        $invoiceId = $data->id;
        $externalId = $data->external_id;

        //get transaction from database
        $transaction = Transaction::where('charge_id', $invoiceId)
            ->where('payment_method', 'xendit')
            ->first();

        error_log(print_r($transaction, true));
         if (!$transaction || $transaction->payment_method != 'xendit') {
            return;
        }

        $invoice = $this->makeApiCall('invoices/'. $invoiceId, [], $transaction->form_id, '');

        if (!$invoice || is_wp_error($invoice)) {
            return;
        }

        do_action('wppayform/form_submission_activity_start', $transaction->form_id);

        $status = 'paid';

        $updateData = [
            'payment_note'     => maybe_serialize($data),
            'charge_id'        => sanitize_text_field($invoiceId),
        ];

        $xenditProcessor = new XenditProcessor();
        $xenditProcessor->markAsPaid($status, $updateData, $transaction);

    }


    public function makeApiCall($path, $args, $formId, $method = 'GET')
    {
        $apiKey = (new XenditSettings())->getApiKey($formId);
        // we are using basic authentication , we will use the api key as username and password empty so add : at the end
        $basicAuthCred =$apiKey . ':';
        $basicAuthCred = base64_encode($basicAuthCred);

        $headers = [
            'Authorization' => 'Basic ' . $basicAuthCred
        ];

        if ($method == 'POST') {
            $response = wp_remote_post('https://api.xendit.co/v2/'.$path, [
                'headers' => $headers,
                'body' => $args
            ]);
        } else {
            $response = wp_remote_get('https://api.xendit.co/v2/'.$path, [
                'headers' => $headers,
                'body' => $args
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
