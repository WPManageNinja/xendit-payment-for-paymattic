<?php

/**
 * @package xendit-payment-for-paymattic
 * 
 */

/** 
 * Plugin Name: Xendit Payment for paymattic
 * Plugin URI: https://paymattic.com/
 * Description: Xendit payment gateway for paymattic. Xendit is the leading payment gateway in Indonesia, Philippines and all of Southeast Asia.
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


if (!class_exists('XenditForPaymattic')) {
    class XenditForPaymattic
    {
        public function boot()
        {
            if (!class_exists('XenditPaymentForPaymattic\API\XenditProcessor')) {
                $this->init();
            };
        }

        public function init()
        {
            require_once XENDIT_PAYMENT_FOR_PAYMATTIC_DIR . '/API/XenditProcessor.php';
            (new XenditPaymentForPaymattic\API\XenditProcessor())->init();

            $this->loadTextDomain();
        }

        public function loadTextDomain()
        {
            load_plugin_textdomain('xendit-payment-for-paymattic', false, dirname(plugin_basename(__FILE__)) . '/language');
        }

        public function hasPro()
        {
            return defined('WPPAYFORMPRO_DIR_PATH') || defined('WPPAYFORMPRO_VERSION');
        }

        public function hasFree()
        {

            return defined('WPPAYFORM_VERSION');
        }

        public function versionCheck()
        {
            $currentFreeVersion = WPPAYFORM_VERSION;
            $currentProVersion = WPPAYFORMPRO_VERSION;

            return version_compare($currentFreeVersion, '4.3.2', '>=') && version_compare($currentProVersion, '4.3.2', '>=');
        }

        public function renderNotice()
        {
            add_action('admin_notices', function () {
                if (current_user_can('activate_plugins')) {
                    echo '<div class="notice notice-error"><p>';
                    echo __('Please install & Activate Paymattic and Paymattic Pro to use xendit-payment-for-paymattic plugin.', 'xendit-payment-for-paymattic');
                    echo '</p></div>';
                }
            });
        }

        public function updateVersionNotice()
        {
            add_action('admin_notices', function () {
                if (current_user_can('activate_plugins')) {
                    echo '<div class="notice notice-error"><p>';
                    echo __('Please update Paymattic and Paymattic Pro to use xendit-payment-for-paymattic plugin!', 'xendit-payment-for-paymattic');
                    echo '</p></div>';
                }
            });
        }
    }

    add_action('init', function () {

        $xendit = (new XenditForPaymattic);

        if (!$xendit->hasFree() || !$xendit->hasPro()) {
            $xendit->renderNotice();
        } else if (!$xendit->versionCheck()) {
            $xendit->updateVersionNotice();
        } else {
            $xendit->boot();
        }
    });
}
