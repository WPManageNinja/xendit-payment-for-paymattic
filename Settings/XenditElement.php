<?php

namespace XenditPaymentForPaymattic\Settings;

use WPPayForm\App\Modules\FormComponents\BaseComponent;
use WPPayForm\Framework\Support\Arr;

if (!defined('ABSPATH')) {
    exit;
}

class XenditElement extends BaseComponent
{
    public $gateWayName = 'xendit';

    public function __construct()
    {
        parent::__construct('xendit_gateway_element', 8);

        add_action('wppayform/validate_gateway_api_' . $this->gateWayName, array($this, 'validateApi'));
        add_filter('wppayform/validate_gateway_api_' . $this->gateWayName, function($data, $form) {
            return $this->validateApi();
        }, 2, 10);
        add_action('wppayform/payment_method_choose_element_render_xendit', array($this, 'renderForMultiple'), 10, 3);
        add_filter('wppayform/available_payment_methods', array($this, 'pushPaymentMethod'), 2, 1);
    }

    public function pushPaymentMethod($methods)
    {
        $methods['xendit'] = array(
            'label' => 'xendit',
            'isActive' => true,
            'logo' => XENDIT_PAYMENT_FOR_PAYMATTIC_URL . 'assets/xendit.svg',
            'editor_elements' => array(
                'label' => array(
                    'label' => 'Payment Option Label',
                    'type' => 'text',
                    'default' => 'Pay with xendit'
                )
            )
        );
        return $methods;
    }


    public function component()
    {
        return array(
            'type' => 'xendit_gateway_element',
            'editor_title' => 'Xendit Payment',
            'editor_icon' => '',
            'conditional_hide' => true,
            'group' => 'payment_method_element',
            'method_handler' => $this->gateWayName,
            'postion_group' => 'payment_method',
            'single_only' => true,
            'editor_elements' => array(
                'label' => array(
                    'label' => 'Field Label',
                    'type' => 'text'
                )
            ),
            'field_options' => array(
                'label' => __('Xendit Payment Gateway', 'xendit-payment-for-paymattic')
            )
        );
    }

    public function validateApi()
    {
        $xendit = new XenditSettings();
        return $xendit->getApiKey();
    }

    public function render($element, $form, $elements)
    {
        if (!$this->validateApi()) { ?>
            <p style="color: red">You did not configure Xendit payment gateway. Please configure xendit payment
                gateway from <b>Paymattic->Payment Gateway->Xendit Settings</b> to start accepting payments</p>
            <?php return;
        }

        echo '<input data-wpf_payment_method="xendit" type="hidden" name="__xendit_payment_gateway" value="xendit" />';
    }

    public function renderForMultiple($paymentSettings, $form, $elements)
    {
        $component = $this->component();
        $component['id'] = 'xendit_gateway_element';
        $component['field_options'] = $paymentSettings;
        $this->render($component, $form, $elements);
    }
}
