<?php

/**
 * @package xendit-payment-for-paymattic
 * 
 * 
 * 
 */

/** 
 * Plugin Name: Xendit Payment for paymattic
 * Plugin URI: https://paymattic.com/
 * Description: Xendit payment gateway for paymattic.
 * Version: 1.0.0
 * Author: WPManageNinja LLC
 * Author URI: https://paymattic.com/
 * License: GPLv2 or later
 * Text Domain: xendit-payment-for-paymattic
 * Domain Path: /language
*/

if (!defined('ABSPATH')) {
    exit;
}

defined('ABSPATH') or die;
define('XENDIT_PAYMENT_FOR_PAYMATTIC', true);
define('XENDIT_PAYMENT_FOR_PAYMATTIC_DIR', __DIR__);
define('XENDIT_PAYMENT_FOR_PAYMATTIC_URL', plugin_dir_url(__FILE__));
define('XENDIT_PAYMENT_FOR_PAYMATTIC_VERSION', '1.0.0');


add_action('wppayform_loaded', function () {

   if (defined('WPPAYFORMHASPRO') && defined('WPPAYFORM_VERSION')) { 
        $paymattic_pro__path = WPPAYFORMPRO_DIR_PATH . 'wp-payment-form-pro.php';
        $plugin = get_plugin_data($paymattic_pro__path);
        $currentVersion = $plugin['Version'];

        if (version_compare($currentVersion, '4.3.2', '>=')) {
            if (!class_exists('XenditForPaymattic\XenditProcessor')) {
                require_once XENDIT_PAYMENT_FOR_PAYMATTIC_DIR . '/API/XenditProcessor.php';
                (new XenditPaymentForPaymattic\API\XenditProcessor())->init();
            };
        } else {
            add_action('admin_notices', function () {
                if (current_user_can('activate_plugins')) {
                    echo '<div class="notice notice-error"><p>';
                    echo __('Please update Paymattic and Paymattic Pro to use xendit-payment-for-paymattic plugin!', 'xendit-payment-for-paymattic');
                    echo '</p></div>';
                }
            });
        }
    }
    else {
        add_action('admin_notices', function () {
            if (current_user_can('activate_plugins')) {
                echo '<div class="notice notice-error"><p>';
                echo __('Please install & Activate Paymattic Pro to use xendit-payment-for-paymattic plugin.', 'xendit-payment-for-paymattic');
                echo '</p></div>';
            }
        });
    }
});