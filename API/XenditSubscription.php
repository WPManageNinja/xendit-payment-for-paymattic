<?php

namespace XenditPaymentForPaymattic\API;

use WPPayForm\App\Models\Form;
use WPPayForm\App\Services\ConfirmationHelper;
use WPPayForm\App\Services\PlaceholderParser;
use XenditPaymentForPaymattic\API\XenditPlan;
use WPPayForm\App\Models\Subscription;
use WPPayForm\Framework\Support\Arr;
use WPPayForm\App\Models\Transaction;
use XenditPaymentForPaymattic\Settings\XenditSettings;

class XenditSubscription
{
    public function handleSubscription($transaction, $submission, $form, $paymentItems = array())
    {
        try {
            $subscriptionModel = $this->getValidSubscription($submission, $form, $paymentItems);


            $xenditCustomerId = static::getOrCreateXenditCustomer($submission, $form->ID);
            $successUrl = static::getSuccessURL($form, $submission);
            // $failureUrl = $submission->failure_url; // should return to form

            // Create plan - this will throw an exception if customer creation fails
            
            $plan = XenditPlan::createPlan($xenditCustomerId, $subscriptionModel, $submission, $form->ID, $successUrl, '');
      
            $updateData = [
                'vendor_subscriptipn_id' => $plan['id'], // xendit plan id will be treated as vendor subscription id
                'vendor_customer_id' => $plan['customer_id'] ?? null,
                'vendor_plan_id' => $plan['reference_id'] ?? null,
            ];

            $transaction->update([
                'subscription_id' => $subscriptionModel->id,
                'payment_mode' => $submission->payment_mode,
                'payment_total' => $subscriptionModel->recurring_amount,
                'transaction_type' => 'subscription',
            ]);

            Subscription::where('id', $subscriptionModel->id)
                ->update($updateData);
       
        
            if ($status = Arr::get($plan, 'status') == 'REQUIRES_ACTION') {
                $redirectUrl = Arr::get($plan, 'actions.0.url', null);
                wp_send_json_success(array(
                    'message' => __('Please complete payment method setup to activate your subscription.', 'xendit-payment-for-paymattic'),
                    'call_next_method' => 'normalRedirect',
                    'redirect_url' => $redirectUrl,
                    'subscription_status' => 'requires_action',
                    'plan_id' => $plan['id'] ?? null
                ), 200);
                
            }
    
        } catch (\Exception $e) {
            error_log('Xendit Subscription Error: ' . $e->getMessage());

            wp_send_json_error(array(
                'message' => $e->getMessage(),
                'payment_error' => true,
                'type' => 'subscription_error'
            ), 423);
        }
    }

    public static function getOrCreateXenditCustomer($submission, $formId)
    {
        try {
            $phone = Arr::get($submission->form_data_formatted, 'phone', null);
    

            $customerReferenceId = '';
            if ((new XenditSettings())->isLive($formId)) {
                $customerReferenceId =  'wppayform_xendit_live_cust_' . $submission->customer_email;
            } else {
                $customerReferenceId = 'wppayform_xendit_test_cust_' . $submission->customer_email;
            }


            // check if the customer already exists
            $savedCustomerId = get_option($customerReferenceId, '');

            if ($savedCustomerId) {
                $existingXenditCustomer = (new IPN())->makeApiCall('customers/' . $savedCustomerId, [], $formId, 'GET');
                 if (!is_wp_error($existingXenditCustomer)) {
                    return $existingXenditCustomer['id'];
                }
            }
    
            $phone = '';
            $givenNames = $submission->customer_name ?: 'Guest';
    
            $customerData = [
                'reference_id' => $customerReferenceId,
                'type' => 'INDIVIDUAL',
                'date_of_registration' => date('Y-m-d H:i:s'),
                'individual_detail' => [
                    'given_names' => $submission->customer_name ?: 'Guest',
                ],
                'email' => $submission->customer_email,
                'mobile_number' => $phone,
                'address' => '',
            ];


            // unset if any field is empty
            foreach ($customerData as $key => $value) {
                if (empty($value)) {
                    unset($customerData[$key]);
                }
            }

            // Handle customer name
            // if (!empty($submission->customer_name)) {
            //     $nameParts = explode(' ', trim($submission->customer_name), 2);
            //     $customerData['given_names'] = $nameParts[0];
            //     if (isset($nameParts[1]) && !empty($nameParts[1])) {
            //         $customerData['surname'] = $nameParts[1];
            //     }
            // } else {
            //     $customerData['given_names'] = 'Guest Customer';
            // }

            // Log request for debugging
            error_log('Creating Xendit customer with data: ' . json_encode($customerData));
        

            $response = (new IPN())->makeApiCall('customers', $customerData, $formId, 'POST');

            update_option($customerReferenceId, $response['id']);

            
            if (is_wp_error($response)) {
                error_log('Customer creation failed: ' . $response->get_error_message());
                return $response; // Return WP_Error to be handled by caller
            }

            // Validate successful response
            if (empty($response) || !isset($response['id'])) {
                error_log('Invalid customer response: ' . json_encode($response));
                return new \WP_Error('invalid_response', 'Invalid customer response from Xendit');
            }
        
            return $response['id'];
            
        } catch (\Exception $e) {
            error_log('Exception in createCustomer: ' . $e->getMessage());
            return new \WP_Error('customer_creation_failed', $e->getMessage());
        }
    }

    private function getValidSubscription($submission, $form, $paymentItems = array())
    {
        $subscriptionModel = new Subscription();
        $subscriptions = $subscriptionModel->getSubscriptions($submission->id);

        $validSubscriptions = [];
        foreach ($subscriptions as $subscriptionItem) {
            if ($subscriptionItem->recurring_amount) {
                $validSubscriptions[] = $subscriptionItem;
            }
        }

        if (count($validSubscriptions) > 1) {
            wp_send_json_error(array(
                'message' => 'Xendit payment method does not support more than 1 subscription. Please remove the extra subscription and try again.',
                'payment_error' => true,
                'type' => 'error',
                'form_events' => [
                    'payment_failed'
                ]
            ), 423);
        }

        return $validSubscriptions[0];
    }
    private function updateSubscriptionRecord($plan, $submission, $status)
    {
        $subscriptionModel = new Subscription();
        $subscription = $subscriptionModel->getSubscriptions($submission->id)[0];

        $updateData = [
            'status' => $status,
            'vendor_subscriptipn_id' => $plan['id'],
            'vendor_customer_id' => $plan['customer_id'] ?? null,
            'vendor_response' => json_encode($plan),
            'updated_at' => current_time('mysql')
        ];

        $subscriptionModel->where('id', $subscription->id)->update($updateData);

        error_log('Updated subscription record: ' . json_encode($updateData));
    }

    private function updateTransactionRecord($plan, $submission, $status)
    {
        $transactionModel = new Transaction();

        $updateData = [
            'status' => $status,
            'charge_id' => $plan['id'],
            'payment_mode' => $this->getPaymentMode($submission->form_id),
            'updated_at' => current_time('mysql')
        ];

        $transactionModel->where('submission_id', $submission->id)
                        ->where('payment_method', 'xendit')
                        ->update($updateData);

        error_log('Updated transaction record: ' . json_encode($updateData));
    }

    private function getPaymentMode($formId)
    {
        $settings = (new \XenditPaymentForPaymattic\Settings\XenditSettings())->getSettings();
        return $settings['payment_mode'] ?? 'test';
    }

    public static function getSuccessURL($form, $submission)
    {
        // Check If the form settings have success URL
        $confirmation = Form::getConfirmationSettings($form->ID);
        $confirmation = ConfirmationHelper::parseConfirmation($confirmation, $submission);
        if (
            ($confirmation['redirectTo'] == 'customUrl' && $confirmation['customUrl']) ||
            ($confirmation['redirectTo'] == 'customPage' && $confirmation['customPage']) ||
            ($confirmation['redirectTo'] == 'customPost' && $confirmation['customPage'])
        ) {
            if ($confirmation['redirectTo'] == 'customUrl') {
                $url = $confirmation['customUrl'];
            } else {
                $url = get_permalink(intval($confirmation['customPage']));
            }
            $url = add_query_arg(array(
                'payment_method' => 'xendit'
            ), $url);
            $url = PlaceholderParser::parse($url, $submission);
            return wp_sanitize_redirect($url);
        }
        // now we have to check for global Success Page
        $globalSettings = get_option('wppayform_confirmation_pages');
        if (isset($globalSettings['confirmation']) && $globalSettings['confirmation']) {
            $url = add_query_arg(array(
                'wpf_submission' => $submission->submission_hash,
                'payment_method' => 'xendit'
            ), get_permalink(intval($globalSettings['confirmation'])));
            return wp_sanitize_redirect($url);
        }
        // In case we don't have global settings
        $url = add_query_arg(array(
            'wpf_submission' => $submission->submission_hash,
            'payment_method' => 'xendit'
        ), home_url());
        return wp_sanitize_redirect($url);
    }
}
