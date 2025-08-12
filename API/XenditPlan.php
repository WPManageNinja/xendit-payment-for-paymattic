<?php

namespace XenditPaymentForPaymattic\API;

use WPPayForm\Framework\Support\Arr;
use XenditPaymentForPaymattic\API\IPN;


class XenditPlan
{
    public static function createPlan($xenditCustomerId, $subscription, $submission, $formId, $successUrl, $failureUrl)
    {

        if (!$xenditCustomerId) {
            wp_send_json_error('Xendit customer ID is required to create a plan.', 400);
        }
        // Create recurring plan using correct endpoint
        $planData = array(
            'reference_id' => 'plan_' . $subscription->id . '_' . time(),
            'customer_id' => $xenditCustomerId, // ✅ Safe to use now
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
            ),
            'success_return_url' => $successUrl,
            'failure_return_url' => $successUrl,
        );

        try {
            $planResponse = (new IPN())->makeApiCall('recurring/plans', $planData, $submission->form_id, 'POST');

            if (is_wp_error($planResponse)) {
                throw new \Exception('Failed to create plan: ' . $planResponse->get_error_message());
            }
            error_log('Plan created successfully: ' . json_encode($planResponse));
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
