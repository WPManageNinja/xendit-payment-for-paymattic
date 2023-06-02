<?php

namespace  XenditPaymentForPaymattic\Settings;

use \WPPayForm\Framework\Support\Arr;
use \WPPayForm\App\Services\AccessControl;
use \WPPayFormPro\GateWays\BasePaymentMethod;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class XenditSettings extends BasePaymentMethod
{
    /**
     * Automatically create global payment settings page
     * @param  String: key, title, routes_query, 'logo')
     */
    public function __construct()
    {
        parent::__construct(
            'xendit',
            'Xendit',
            [],
            XENDIT_PAYMENT_FOR_PAYMATTIC_URL . 'assets/xendit.svg' // follow naming convention of logo with lowercase exactly as payment key to avoid logo rendering hassle
        );
    }

    /**
     * @function mapperSettings, To map key => value before store
     * @function validateSettings, To validate before save settings
     */

    public function init()
    {
        add_filter('wppayform_payment_method_settings_mapper_' . $this->key, array($this, 'mapperSettings'));
        add_filter('wppayform_payment_method_settings_validation_' . $this->key, array($this, 'validateSettings'), 10, 2);
    }

    public function mapperSettings($settings)
    {
        return $this->mapper(
            static::settingsKeys(),
            $settings,
            false
        );
    }

    /**
     * @return Array of default fields
     */
    public static function settingsKeys()
    {
        $slug = 'xendit-payment-for-paymattic';

        $updateAvailable = static::checkForUpdate($slug);
        return array(
            'payment_mode' => 'test',
            'test_api_key' => '',
            'live_api_key' => '',
            'update_available' => $updateAvailable
        );
    }

    public static function checkForUpdate($slug)
    {
        $githubApi = "https://api.github.com/repos/WPManageNinja/{$slug}/releases";
        $result = array(
            'available' => 'no',
            'url' => '',
            'slug' => 'xendit-payment-for-paymattic'
        );

        // $response = wp_remote_get($githubApi, 
        // [
        //     'headers' => array('Accept' => 'application/json',
        //     'authorization' => 'bearer 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')
        // ]);

        $response = wp_remote_get($githubApi);
        $releases = json_decode($response['body']);
        if (isset($releases->documentation_url)) {
            return $result;
        }

        $latestRelease = $releases[0];
        $latestVersion = $latestRelease->tag_name;
        $zipUrl = $latestRelease->zipball_url;
    
        $plugins = get_plugins();
        $currentVersion = '';

        // Check if the plugin is present
        foreach ($plugins as $plugin_file => $plugin_data) {
            // Check if the plugin slug or name matches
            if ($slug === $plugin_data['TextDomain'] || $slug === $plugin_data['Name']) {
                $currentVersion = $plugin_data['Version'];
            }
        }

        if (version_compare( $latestVersion, $currentVersion, '>')) {
            $result['available'] = 'yes';
            $result['url'] = $zipUrl;
        }

        return $result;
    }

    public static function getSettings()
    {
        $setting = get_option('wppayform_payment_settings_xendit', []);

        // Check especially only for addons
        $tempSettings = static::settingsKeys();
        if (isset($tempSettings['update_available'])) {
            $setting['update_available'] = $tempSettings['update_available'];
        }
        return wp_parse_args($setting, static::settingsKeys());
    }

    public function getPaymentSettings()
    {
        $settings = $this->mapper(
            $this->globalFields(),
            static::getSettings()
        );
        return array(
            'settings' => $settings
        );
    }

    /**
     * @return Array of global fields
     */
    public function globalFields()
    {
        return array(
            'payment_mode' => array(
                'value' => 'test',
                'label' => __('Payment Mode', 'xendit-payment-for-paymattic'),
                'options' => array(
                    'test' => __('Test Mode', 'xendit-payment-for-paymattic'),
                    'live' => __('Live Mode', 'xendit-payment-for-paymattic')
                ),
                'type' => 'payment_mode'
            ),
            'test_api_key' => array(
                'value' => '',
                'label' => __('Test Secret Key', 'xendit-payment-for-paymattic'),
                'type' => 'test_secret_key',
                'placeholder' => __('Test Secret Key', 'xendit-payment-for-paymattic')
            ),
            'live_api_key' => array(
                'value' => '',
                'label' => __('Live Secret Key', 'xendit-payment-for-paymattic'),
                'type' => 'live_secret_key',
                'placeholder' => __('Live Secret Key', 'xendit-payment-for-paymattic')
            ),
            'desc' => array(
                'value' => '<p>See our <a href="https://paymattic.com/docs/how-to-integrate-xendit-in-wordpress" target="_blank" rel="noopener">documentation</a> to get more information about xendit setup.</p>',
                'type' => 'html_attr',
                'placeholder' => __('Description', 'xendit-payment-for-paymattic')
            ),
            'webhook_desc' => array(
                'value' => "<h3>Xendit Webhook </h3> <p>In order for Xendit to function completely for payments, you must configure your Xendit webhooks. Visit your <a href='https://dashboard.xendit.co/settings/developers#callbacks' target='_blank' rel='noopener'>account dashboard</a> to configure them. Please add a webhook endpoint for the URL below. </p> <p><b>Webhook URL: </b><code> " . site_url('?wpf_xendit_listener=1') . "</code></p> <p>See <a href='https://paymattic.com/docs/how-to-integrate-xendit-in-wordpress#webhook' target='_blank' rel='noopener'>our documentation</a> for more information.</p> <div> <p><b>Please subscribe to these following Webhook events for this URL:</b></p> <ul> <li><code>invoice paid</code></li></ul> </div>",
                'label' => __('Webhook URL', 'xendit-payment-for-paymattic'),
                'type' => 'html_attr'
            ),
            'is_pro_item' => array(
                'value' => 'yes',
                'label' => __('Xendit', 'xendit-payment-for-paymattic'),
            ),
            'update_available' => array(
                'value' => array(
                    'available' => 'no',
                    'url' => '',
                    'slug' => 'xendit-payment-for-paymattic'
                ),
                'type' => 'update_check',
                'label' => __('Update to new version avaiable', 'xendit-payment-for-paymattic'),
            )
        );
    }

    public function validateSettings($errors, $settings)
    {
        AccessControl::checkAndPresponseError('set_payment_settings', 'global');
        $mode = Arr::get($settings, 'payment_mode');

        if ($mode == 'test') {
            if (empty(Arr::get($settings, 'test_api_key'))) {
                $errors['test_api_key'] = __('Please provide Test Secret Key', 'xendit-payment-for-paymattic');
            }
        }

        if ($mode == 'live') {
            if (empty(Arr::get($settings, 'live_api_key'))) {
                $errors['live_api_key'] = __('Please provide Live Secret Key', 'xendit-payment-for-paymattic');
            }
        }
        return $errors;
    }

    public function isLive($formId = false)
    {
        $settings = $this->getSettings();
        return $settings['payment_mode'] == 'live';
    }

    public function getApiKey($formId = false)
    {
        $isLive = $this->isLive($formId);
        $settings = $this->getSettings();

        if ($isLive) {
            return $settings['live_api_key'];
        }

        return $settings['test_api_key'];
    }
}
