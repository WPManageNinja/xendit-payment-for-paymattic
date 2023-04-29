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
 * Description: Xendit payment gateway for paymattic. Xendit is the leading payment gateway in Indonesia, the Philippines, and all of Southeast Asia.
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

   if (!defined('WPPAYFORMPRO_DIR_PATH') || !defined('WPPAYFORM_VERSION') || !defined('WPPAYFORMPRO_VERSION')) { 
         add_action('admin_notices', function () {
            if (current_user_can('activate_plugins')) {
                echo '<div class="notice notice-error"><p>';
                echo __('Please install & Activate Paymattic Pro to use xendit-payment-for-paymattic plugin.', 'xendit-payment-for-paymattic');
                echo '</p></div>';
            }
        });
    }
    else {
        $currentVersion = WPPAYFORM_VERSION;
        if (version_compare($currentVersion, '4.3.2', '>=')) {
            if (!class_exists('XenditForPaymattic\XenditProcessor')) {
                require_once XENDIT_PAYMENT_FOR_PAYMATTIC_DIR . '/API/XenditProcessor.php';
                (new XenditPaymentForPaymattic\API\XenditProcessor())->init();
                add_action('init', function() {
                    load_plugin_textdomain('wp-payment-form-pro', false, dirname(plugin_basename(__FILE__)) . '/language');
                });
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
});