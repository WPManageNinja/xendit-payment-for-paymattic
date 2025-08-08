<?php

namespace XenditPaymentForPaymattic\API;

use WPPayForm\Framework\Support\Arr;
use XenditPaymentForPaymattic\API\IPN;


class XenditPlan
{
    public static function createPlan($subscription, $submission, $formId)
    {
        // Create customer with proper error handling
        $customer = self::createCustomer($submission, $formId);
        
        if (is_wp_error($customer)) {
            error_log('Customer creation failed: ' . $customer->get_error_message());
            throw new \Exception('Customer creation failed: ' . $customer->get_error_message());
        }

        // Validate customer response
        if (empty($customer) || !isset($customer['id'])) {
            error_log('Invalid customer response: ' . json_encode($customer));
            throw new \Exception('Invalid customer response from Xendit API');
        }

        // Create recurring plan using correct endpoint
        $planData = array(
            'reference_id' => 'plan_' . $subscription->id . '_' . time(),
            'customer_id' => $customer['id'], // ✅ Safe to use now
            'recurring_action' => 'PAYMENT',
            'currency' => strtoupper($submission->currency),
            'amount' => (int)($subscription->recurring_amount * 100),
            'schedule' => array(
                'reference_id' => 'schedule_' . $subscription->id,
                'interval' => self::getInterval($subscription->billing_interval),
                'interval_count' => 1,
                'total_recurrence' => $subscription->bill_times ?: null,
                'anchor_date' => self::getAnchorDate($subscription->billing_interval)
            ),
            'notification_config' => array(
                'recurring_created' => ['EMAIL'],
                'recurring_succeeded' => ['EMAIL'],
                'recurring_failed' => ['EMAIL']
            ),
            'metadata' => array(
                'form_id' => $formId,
                'submission_id' => $submission->id,
                'payment_method' => 'xendit'
            )
        );
        error_log('Creating plan with data: ' . json_encode($planData));
        try {
            $planResponse = (new IPN())->makeApiCall('recurring/plans', $planData, $submission->form_id, 'POST');
            if (is_wp_error($planResponse)) {
                throw new \Exception('Failed to create plan: ' . $planResponse->get_error_message());
            }
            error_log('Plan created successfully: ' . $planResponse['id']);
            return $planResponse;
            
        } catch (\Exception $e) {
            error_log('Failed to create plan: ' . $e->getMessage());
            throw new \Exception('Failed to create plan: ' . $e->getMessage());
        }
    }

    public static function activatePlan($plan, $formId)
    {
        if (is_wp_error($plan)) {
            throw new \Exception('Cannot activate plan: Invalid plan data');
        }
        
        if (empty($plan) || !isset($plan['id'])) {
            throw new \Exception('Cannot activate plan: Missing plan ID');
        }
        
        $response = (new IPN())->makeApiCall('recurring.plan.activated', $plan, $formId, 'POST');
        
        if (is_wp_error($response)) {
            throw new \Exception('Failed to activate plan: ' . $response->get_error_message());
        }
        
        return $response;
    }

    public static function createCustomer($submission, $formId)
    {
        try {
            $phone = Arr::get($submission->form_data_formatted, 'phone', null);
            
            $customerData = array(
                'reference_id' => 'customer_' . $submission->id . '_' . time(),
                'mobile_number' => $phone,
                'email' => $submission->customer_email,
                'type' => 'INDIVIDUAL',
                'individual_detail' => array(
                    'given_names' => $submission->customer_name ?: 'Guest'
                )                
            );

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
            
            if (is_wp_error($response)) {
                error_log('Customer creation failed: ' . $response->get_error_message());
                return $response; // Return WP_Error to be handled by caller
            }

            // Validate successful response
            if (empty($response) || !isset($response['id'])) {
                error_log('Invalid customer response: ' . json_encode($response));
                return new \WP_Error('invalid_response', 'Invalid customer response from Xendit');
            }

            error_log('Customer created successfully: ' . $response['id']);
            return $response;
            
        } catch (\Exception $e) {
            error_log('Exception in createCustomer: ' . $e->getMessage());
            return new \WP_Error('customer_creation_failed', $e->getMessage());
        }
    }
    
    public static function getAnchorDate($interval)
    {
        $nextBilling = strtotime('+1 ' . $interval);
        return date('Y-m-d\TH:i:s.000\Z', $nextBilling);
    }

    public static function getInterval($interval)
    {
        $intervalMap = [
            'day' => 'DAY',
            'week' => 'WEEK',
            'month' => 'MONTH',
            'year' => 'YEAR'
        ];
        return $intervalMap[$interval] ?? 'MONTH';
    }
}
