<?php

namespace XenditPaymentForPaymattic\API;

use WPPayForm\Framework\Support\Arr;
use XenditPaymentForPaymattic\API\IPN;


class XenditPlan
{
    /**
     * Retrieve an existing Xendit plan by plan ID
     *
     * @param string $planId The Xendit plan ID or reference_id
     * @param int $formId The form ID
     * @return array|false|WP_Error
     */
    public static function retrieve($planId, $formId = false)
    {
        try {
            // Try to get the plan by ID first
            $response = (new IPN())->makeApiCall('recurring/plans/' . $planId, [], $formId, 'GET');

            if (is_wp_error($response)) {
                // Plan might not exist or other error
                return false;
            }

            if (!empty($response) && isset($response['id'])) {
                return $response;
            }

            return false;

        } catch (\Exception $e) {
            error_log('Failed to retrieve Xendit plan: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get or create a Xendit plan for the subscription
     * Similar to Stripe's getOrCreatePlan pattern - checks for existing plan
     * with matching parameters before creating a new one
     *
     * @param string $xenditCustomerId The Xendit customer ID
     * @param object $subscription The subscription object
     * @param object $submission The submission object
     * @param int $formId The form ID
     * @param string $successUrl The success redirect URL
     * @param string $failureUrl The failure redirect URL
     * @return array|WP_Error The plan data or error
     */
    public static function getOrCreatePlan($xenditCustomerId, $subscription, $submission, $formId, $successUrl, $failureUrl)
    {
        if (!$xenditCustomerId) {
            wp_send_json_error('Xendit customer ID is required to create a plan.', 400);
        }

        // Generate a unique plan ID based on subscription parameters
        $planId = self::getGeneratedPlanId($subscription, $submission->currency, $xenditCustomerId);
        $planId = apply_filters('wppayform/xendit_plan_id_form_' . $submission->form_id, $planId, $subscription, $submission);

        // Try to retrieve existing plan first
        $xenditPlan = self::retrieve($planId, $formId);

        if ($xenditPlan && !is_wp_error($xenditPlan)) {
            // Plan exists, verify it matches our requirements
            if (self::planMatchesRequirements($xenditPlan, $subscription, $submission, $xenditCustomerId)) {
                // Return existing plan
                return $xenditPlan;
            }
        }

        return self::createPlan($xenditCustomerId, $subscription, $submission, $formId, $successUrl, $failureUrl, $planId);
    }

    /**
     * Create a new Xendit plan
     *
     * @param string $xenditCustomerId The Xendit customer ID
     * @param object $subscription The subscription object
     * @param object $submission The submission object
     * @param int $formId The form ID
     * @param string $successUrl The success redirect URL
     * @param string $failureUrl The failure redirect URL
     * @param string $planId Optional plan ID to use (if not provided, will be generated)
     * @return array|WP_Error The created plan data or error
     */
    public static function createPlan($xenditCustomerId, $subscription, $submission, $formId, $successUrl, $failureUrl, $planId = null)
    {
        if (!$xenditCustomerId) {
            wp_send_json_error('Xendit customer ID is required to create a plan.', 400);
        }

        // Generate plan ID if not provided
        if (!$planId) {
            $planId = self::getGeneratedPlanId($subscription, $submission->currency, $xenditCustomerId);
        }

        if ($subscription->trial_days) {
            $anchorTimestamp = strtotime('+' . (int) $subscription->trial_days . ' days');
        } else {
            $anchorTimestamp = time();
        }
        $anchorDate = self::getAnchorDateForXendit($anchorTimestamp);

        $amount = (int)($subscription->recurring_amount / 100);

        if ($subscription->initial_amount) {
            $amount += (int)($subscription->initial_amount / 100);
        }

        if ($amount <= 0) {
            throw new \Exception(__('Invalid subscription amount. Amount must be greater than zero.', 'xendit-payment-for-paymattic'));
        }

        // Create recurring plan using correct endpoint
        $planData = array(
            'reference_id' => $planId,
            'customer_id' => $xenditCustomerId,
            'recurring_action' => 'PAYMENT',
            'currency' => strtoupper($submission->currency),
            'amount' => $amount,
            'schedule' => array(
                'reference_id' => 'schedule_' . $subscription->id,
                'interval' => self::getInterval($subscription->billing_interval),
                'interval_count' => self::getIntervalCount($subscription),
                'total_recurrence' => $subscription->bill_times ? (int) $subscription->bill_times : null,
                'anchor_date' => $anchorDate,
                'retry_interval' => 'DAY',
                'retry_interval_count' => 1,
                'total_retry' => 3,
                'failed_attempt_notifications' => [1, 2]
            ),
            'notification_config' => array(
                'recurring_created' => ['EMAIL'],
                'recurring_succeeded' => ['EMAIL'],
                'recurring_failed' => ['EMAIL']
            ),
            'metadata' => array(
                'form_id' => $formId,
                'submission_id' => $submission->id,
                'payment_method' => 'xendit',
                'trial_days' => (int) ($subscription->trial_days ?? 0),
                'billing_interval' => $subscription->billing_interval ?? 'month',
                'recurring_amount' => (int)($subscription->recurring_amount / 100),
                'bill_times' => $subscription->bill_times ? (int) $subscription->bill_times : null,
            ),
            'success_return_url' => self::ensureValidXenditUrl($successUrl),
            'failure_return_url' => self::ensureValidXenditUrl($failureUrl),
        );

        // Immediate charge on link can trigger API_VALIDATION_ERROR (e.g. card/currency rules). Allow disabling.
        $useImmediateCharge = apply_filters('wppayform/xendit_plan_immediate_charge', true, $subscription, $submission);
        if (!$subscription->trial_days && $useImmediateCharge) {
            $planData['immediate_action_type'] = 'FULL_AMOUNT';
        }
        $planData = apply_filters('wppayform/xendit_plan_data', $planData, $subscription, $submission);
        $planResponse = (new IPN())->makeApiCall('recurring/plans', $planData, $submission->form_id, 'POST');
        // error_log('Plan Response: ' . print_r($planResponse, true));

        if (is_wp_error($planResponse)) {
            $errorMessage = $planResponse->get_error_message();
            $errorData = $planResponse->get_error_data();
            $errorData = is_array($errorData) ? $errorData : array();

            if (isset($errorData['error_code'])) {
                $errorCode = $errorData['error_code'];
                $apiMessage = isset($errorData['message']) ? $errorData['message'] : $errorMessage;
                if ($errorCode === 'UNSUPPORTED_CURRENCY') {
                    throw new \Exception($apiMessage);
                }
                if ($errorCode === 'API_VALIDATION_ERROR') {
                    throw new \Exception($apiMessage);
                }
            }

            throw new \Exception($errorMessage);
        }

        return $planResponse;
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
    

    /**
     * Xendit does not accept anchor_timestamp with day-of-month 29, 30, or 31.
     * Returns ISO 8601 date with day clamped to 28 when necessary.
     */
    public static function getAnchorDateForXendit($timestamp)
    {
        $day = (int) date('j', $timestamp);
        if ($day >= 29) {
            $timestamp = strtotime(date('Y-m-28', $timestamp) . ' ' . date('H:i:s', $timestamp));
        }
        return date('c', $timestamp);
    }


    /**
     * Xendit schedule interval: must be one of DAY, WEEK, MONTH, YEAR (uppercase).
     */
    public static function getInterval($billingInterval)
    {
        $interval = strtolower($billingInterval ?? 'month');
        $map = [
            'day' => 'DAY',
            'week' => 'WEEK',
            'fortnight' => 'WEEK',
            'month' => 'MONTH',
            'quarter' => 'MONTH',
            'half_year' => 'MONTH',
            'year' => 'YEAR',
        ];
        return $map[$interval] ?? 'MONTH';
    }

    /**
     * Get interval_count for schedule (matches getInterval).
     */
    public static function getIntervalCount($subscription)
    {
        $interval = strtolower($subscription->billing_interval ?? '');
        $data = [
            'day' => ['interval_count' => 1],
            'week' => ['interval_count' => 1],
            'fortnight' => ['interval_count' => 2],
            'month' => ['interval_count' => 1],
            'quarter' => ['interval_count' => 3],
            'half_year' => ['interval_count' => 6],
            'year' => ['interval_count' => 1],
        ];
        return Arr::get($data[$interval], 'interval_count', 1);
    }

    /**
     * Ensure URL matches Xendit's required pattern for success_return_url and failure_return_url.
     * Xendit accepts: http(s) URLs, empty string, or custom scheme URLs.
     * Localhost URLs may fail—use a tunnel (e.g. ngrok) for local testing if needed.
     *
     * @param string $url The URL to validate
     * @return string Valid absolute URL
     */
    private static function ensureValidXenditUrl($url)
    {
        $url = trim($url);
        if (empty($url)) {
            return home_url('/');
        }
        if (!preg_match('#^https?://#i', $url)) {
            $url = home_url($url);
        }
        return esc_url_raw($url);
    }

    /**
     * Generate a unique plan ID based on subscription parameters
     * This ensures plans with identical configurations use the same ID
     * Similar to Stripe's getGeneratedSubscriptionId pattern
     *
     * @param object $subscription The subscription object
     * @param string $currency The currency code
     * @param string $customerId The Xendit customer ID
     * @return string The generated plan ID
     */
    public static function getGeneratedPlanId($subscription, $currency = 'USD', $customerId = '')
    {
        $planId = 'wpf_xendit_' . $subscription->form_id . '_' . 
                     $subscription->element_id . '_' . 
                     $subscription->recurring_amount . '_' . 
                     $subscription->billing_interval . '_' . 
                     $subscription->trial_days . '_' . 
                     $currency . '_' . 
                     $subscription->bill_times;
        
        return apply_filters('wppayform/xendit_plan_name_generated', $planId, $subscription, $currency, $customerId);
    }

    /**
     * Check if an existing plan matches the subscription requirements
     *
     * @param array $xenditPlan The existing plan from Xendit
     * @param object $subscription The subscription object
     * @param object $submission The submission object
     * @param string $customerId The Xendit customer ID
     * @return bool True if plan matches requirements
     */
    private static function planMatchesRequirements($xenditPlan, $subscription, $submission, $customerId)
    {
        // Check if plan belongs to the same customer
        if (isset($xenditPlan['customer_id']) && $xenditPlan['customer_id'] != $customerId) {
            return false;
        }

        // Check currency
        if (isset($xenditPlan['currency']) && 
            strtoupper($xenditPlan['currency']) != strtoupper($submission->currency)) {
            return false;
        }

        // Check amount (recurring amount only, not including initial)
        $expectedAmount = (int)($subscription->recurring_amount / 100);
        if (isset($xenditPlan['amount']) && $xenditPlan['amount'] != $expectedAmount) {
            return false;
        }

        // Check billing interval (Xendit returns DAY, WEEK, MONTH, YEAR)
        $expectedInterval = self::getInterval($subscription->billing_interval);
        if (isset($xenditPlan['schedule']['interval']) && 
            strtoupper($xenditPlan['schedule']['interval']) != $expectedInterval) {
            return false;
        }

        // Check interval count (1 for day/week/month, 12 for year)
        $expectedIntervalCount = self::getIntervalCount($subscription);
        if (isset($xenditPlan['schedule']['interval_count']) && 
            (int) $xenditPlan['schedule']['interval_count'] != $expectedIntervalCount) {
            return false;
        }

        // Check trial days
        if (isset($xenditPlan['metadata']['trial_days']) && 
            $xenditPlan['metadata']['trial_days'] != $subscription->trial_days) {
            return false;
        }

        // Check bill times
        $expectedBillTimes = $subscription->bill_times ? (int) $subscription->bill_times : null;
        $actualBillTimes = isset($xenditPlan['schedule']['total_recurrence']) ? 
                         $xenditPlan['schedule']['total_recurrence'] : null;
        if ($expectedBillTimes !== $actualBillTimes) {
            return false;
        }

        return true;
    }
}
