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
    public function handleSubscription($submission, $form, $paymentItems = array())
    {
        try {
            $subscriptionModel = $this->getValidSubscription($submission, $form, $paymentItems);


            $xenditCustomerId = static::getOrCreateXenditCustomer($submission, $form->ID);
            $successUrl = static::getSuccessURL($form, $submission);
            // $failureUrl = $submission->failure_url; // should return to form

            // Create plan - this will throw an exception if customer creation fails
            $plan = XenditPlan::createPlan($xenditCustomerId, $subscriptionModel, $submission, $form->ID, $successUrl, '');

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
        
            // Handle the plan response based on status
            $this->handlePlanResponse($plan, $submission, $form);
        } catch (\Exception $e) {
            error_log('Xendit Subscription Error: ' . $e->getMessage());

            wp_send_json_error(array(
                'message' => 'Subscription creation failed. Please try again.',
                'payment_error' => true,
                'type' => 'subscription_error'
            ), 423);
        }
    }

    public static function getOrCreateXenditCustomer($submission, $formId)
    {
        try {
            $phone = Arr::get($submission->form_data_formatted, 'phone', null);
            
            // $customerData = array(
            //     'reference_id' => 'customer_' . $submission->id . '_' . time(),
            //     'mobile_number' => $phone,
            //     'email' => $submission->customer_email,
            //     'type' => 'INDIVIDUAL',
            //     'individual_detail' => array(
            //         'given_names' => $submission->customer_name ?: 'Guest'
            //     )                
            // );


            $customerReferenceId = '';
            if ((new XenditSettings())->isLive($formId)) {
                $customerReferenceId =  'wppayform_xendit_live_cust_' . $submission->customer_email;
            } else {
                $customerReferenceId = 'wppayform_xendit_test_cust_' . $submission->customer_email;
            }


            // check if the customer already exists
            $savedCustomerId = get_option($customerReferenceId, false);
    
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

    private function handlePlanResponse($plan, $submission, $form)
    {
        if (is_wp_error($plan)) {
            throw new \Exception('Plan creation failed: ' . $plan->get_error_message());
        }

        // $status = $plan['status'] ?? 'UNKNOWN';
        $status = Arr::get($plan, 'status', 'UNKNOWN');
        $planId = Arr::get($plan, 'id', null);

        error_log('Xendit Plan Status: ' . $status);
        error_log('Xendit Plan ID: ' . $planId);

        switch ($status) {
            case 'ACTIVE':
                return $this->handleActivePlan($plan, $submission, $form);

            case 'REQUIRES_ACTION':
                return $this->handleRequiresActionPlan($plan, $submission, $form);

            case 'PENDING':
                return $this->handlePendingPlan($plan, $submission, $form);

            default:
                error_log('Unknown plan status: ' . $status);
                throw new \Exception('Unexpected plan status: ' . $status);
        }
    }

    private function handleActivePlan($plan, $submission, $form)
    {
        // Plan is active - subscription is ready and billing will start automatically
        $this->updateSubscriptionRecord($plan, $submission, 'active');
        $this->updateTransactionRecord($plan, $submission, 'subscribed');

        // Log successful activation
        do_action('wppayform_log_data', [
            'form_id' => $form->ID,
            'submission_id' => $submission->id,
            'type' => 'activity',
            'created_by' => 'Xendit Bot',
            'title' => 'Subscription Activated',
            'content' => 'Subscription plan activated successfully. Plan ID: ' . ($plan['id'] ?? 'unknown')
        ]);

        wp_send_json_success([
            'message' => __('Subscription created successfully! Billing will start automatically.', 'xendit-payment-for-paymattic'),
            'call_next_method' => 'normalRedirect',
            'redirect_url' => self::getSuccessURL($form, $submission),
            'subscription_status' => 'active',
            'plan_id' => $plan['id'] ?? null
        ], 200);
    }

    private function handleRequiresActionPlan($plan, $submission, $form)
    {
        // Extract action URL from plan response
        $actionUrl = null;
        $plan['success_return_url'] = self::getSuccessURL($form, $submission);

        if (isset($plan['actions']) && is_array($plan['actions'])) {
            foreach ($plan['actions'] as $action) {
                if (isset($action['url']) && isset($action['method'])) {
                    $actionUrl = $action['url'];
                    break;
                }
            }
        }

        if (!$actionUrl) {
            error_log('Plan response: ' . json_encode($plan));
            throw new \Exception('Plan requires action but no redirect URL found in response');
        }

        // Sanitize the redirect URL
        $redirectUrl = wp_sanitize_redirect($actionUrl);
        // Update records
        $this->updateSubscriptionRecord($plan, $submission, 'pending_payment_method');
        $this->updateTransactionRecord($plan, $submission, 'pending');

        // Log the redirect
        do_action('wppayform_log_data', [
            'form_id' => $form->ID,
            'submission_id' => $submission->id,
            'type' => 'activity',
            'created_by' => 'Xendit Bot',
            'title' => 'Payment Method Setup Required',
            'content' => 'User redirected to Xendit for payment method setup. Plan ID: ' . ($plan['id'] ?? 'unknown')
        ]);

        wp_send_json_success([
            'message' => __('Please complete payment method setup to activate your subscription.', 'xendit-payment-for-paymattic'),
            'call_next_method' => 'normalRedirect',
            'redirect_url' => $redirectUrl,
            'subscription_status' => 'requires_action',
            'plan_id' => $plan['id'] ?? null
        ], 200);
    }

    private function handlePendingPlan($plan, $submission, $form)
    {
        // Plan is pending - usually waiting for approval or processing
        $this->updateSubscriptionRecord($plan, $submission, 'pending');
        $this->updateTransactionRecord($plan, $submission, 'pending');

        wp_send_json_success([
            'message' => __('Subscription is being processed. You will be notified once it\'s activated.', 'xendit-payment-for-paymattic'),
            'call_next_method' => 'normalRedirect',
            'redirect_url' => self::getSuccessURL($form, $submission),
            'subscription_status' => 'pending',
            'plan_id' => $plan['id'] ?? null
        ], 200);
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
