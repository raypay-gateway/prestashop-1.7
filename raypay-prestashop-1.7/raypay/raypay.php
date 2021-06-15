<?php
/**
 * RayPay - A Payment Module for PrestaShop 1.7
 *
 * @author Saminray <info@saminray.com>
 * @license https://opensource.org/licenses/afl-3.0.php
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class RayPay extends PaymentModule
{


    private $_html = '';
    private $_postErrors = array();

    public $address;

    public function __construct()
    {
        $this->name = 'raypay';
        $this->tab = 'payments_gateways';
        $this->version = '1.0';
        $this->author = 'Saminray';
        $this->controllers = array('payment', 'validation');
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';
        $this->bootstrap = true;
        $this->displayName = 'RayPay';
        $this->description = 'پرداخت از طریق درگاه رای پی';
        $this->confirmUninstall = 'Are you sure you want to uninstall this module?';
        $this->ps_versions_compliancy = array('min' => '1.7.0', 'max' => _PS_VERSION_);
        $this->bootstrap = true;
        parent::__construct();
    }

    /**
     * Install this module and register the following Hooks:
     *
     * @return bool
     */
    public function install()
    {
        return parent::install()
            && $this->registerHook('paymentOptions')
            && $this->registerHook('paymentReturn');
    }

    /**
     * Uninstall this module and remove it from all hooks
     *
     * @return bool
     */
    public function uninstall()
    {
        return parent::uninstall();
    }

    /**
     * Returns a string containing the HTML necessary to
     * generate a configuration screen on the admin
     *
     * @return string
     */
    public function getContent()
    {

        if (Tools::isSubmit('raypay_submit')) {
            Configuration::updateValue('raypay_user_id', $_POST['raypay_user_id']);
            Configuration::updateValue('raypay_acceptor_code', $_POST['raypay_acceptor_code']);
            Configuration::updateValue('raypay_currency', $_POST['raypay_currency']);
            Configuration::updateValue('raypay_success_massage', $_POST['raypay_success_massage']);
            Configuration::updateValue('raypay_failed_massage', $_POST['raypay_failed_massage']);
            $this->_html .= '<div class="conf confirm">' . $this->l('با موفقیت ذخیره شد.') . '</div>';
        }

        $this->_generateForm();
        return $this->_html;

    }


    /**
     * generate setting form for admin
     */
    private function _generateForm()
    {
        $this->_html .= '<div align="center"><form action="' . $_SERVER['REQUEST_URI'] . '" method="post">';
        $this->_html .= $this->l('شناسه کاربری : ') . '<br><br>';
        $this->_html .= '<input type="text" name="raypay_user_id" value="' . Configuration::get('raypay_user_id') . '" ><br><br>';
        $this->_html .= $this->l('کد پذیرنده  : ') . '<br><br>';
        $this->_html .= '<input type="text" name="raypay_acceptor_code" value="' . Configuration::get('raypay_acceptor_code') . '" ><br><br>';
        $this->_html .= $this->l('واحد پول :') . '<br><br>';
        $this->_html .= '<select name="raypay_currency"><option value="rial"' . (Configuration::get('raypay_currency') == "rial" ? 'selected="selected"' : "") . '>' . $this->l('Rial') . '</option><option value="toman"' . (Configuration::get('raypay_currency') == "toman" ? 'selected="selected"' : "") . '>' . $this->l('Toman') . '</option></select><br><br>';
        $this->_html .= $this->l('پیام پرداخت موفق :') . '<br><br>';
        $this->_html .= '<textarea dir="auto" name="raypay_success_massage" style="margin: 0px; width: 351px; height: 57px;">' . (!empty(Configuration::get('raypay_success_massage')) ? Configuration::get('raypay_success_massage') : "پرداخت شما با موفقیت انجام شد.") . '</textarea><br><br>';
        $this->_html .= 'متن پیامی که می خواهید بعد از پرداخت به کاربر نمایش دهید را وارد کنید. همچنین می توانید از شورت کدهای {order_id} برای نمایش شماره سفارش و {invoice_id} برای نمایش شناسه ارجاع بانکی رای پی استفاده نمایید.<br><br>';
        $this->_html .= $this->l('پیام پرداخت ناموفق :') . '<br><br>';
        $this->_html .= '<textarea dir="auto" name="raypay_failed_massage" style="margin: 0px; width: 351px; height: 57px;">' . (!empty(Configuration::get('raypay_failed_massage')) ? Configuration::get('raypay_failed_massage') : "پرداخت شما ناموفق بوده است. لطفا مجددا تلاش نمایید یا در صورت بروز اشکال با مدیر سایت تماس بگیرید.") . '</textarea><br><br>';
        $this->_html .= 'متن پیامی که می خواهید بعد از پرداخت به کاربر نمایش دهید را وارد کنید. همچنین می توانید از شورت کدهای {order_id} برای نمایش شماره سفارش و {invoice_id} برای نمایش شناسه ارجاع بانکی رای پی استفاده نمایید.<br><br>';
        $this->_html .= '<input type="submit" name="raypay_submit" value="' . $this->l(' ذخیره کنید') . '" class="button">';
        $this->_html .= '</form><br></div>';
    }


    /**
     * Display this module as a payment option during the checkout
     *
     * @param array $params
     * @return array|void
     */
    public function hookPaymentOptions($params)
    {
        /*
         * Verify if this module is active
         */
        if (!$this->active) {
            return;
        }


        /**
         * Form action URL. The form data will be sent to the
         * validation controller when the user finishes
         * the order process.
         */
        $formAction = $this->context->link->getModuleLink($this->name, 'validation', array(), true);

        /**
         * Assign the url form action to the template var $action
         */
        $this->smarty->assign(['action' => $formAction]);

        /**
         *  Load form template to be displayed in the checkout step
         */
        $paymentForm = $this->fetch('module:raypay/views/templates/hook/payment_options.tpl');

        /**
         * Create a PaymentOption object containing the necessary data
         * to display this module in the checkout
         */
        $displayName = ' پرداخت از طریق درگاه رای پی';
        $newOption = new PrestaShop\PrestaShop\Core\Payment\PaymentOption;
        $newOption->setModuleName($this->displayName)
            ->setCallToActionText($displayName)
            ->setAction($formAction)
            ->setForm($paymentForm);

        $payment_options = array(
            $newOption
        );

        return $payment_options;
    }


    /**
     * Display a message in the paymentReturn hook
     *
     * @param array $params
     * @return string
     */
    public function hookPaymentReturn($params)
    {
        /**
         * Verify if this module is enabled
         */
        if (!$this->active) {
            return;
        }

        return $this->fetch('module:raypay/views/templates/hook/payment_return.tpl');
    }


    public function hash_key()
    {
        $en = array('a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v', 'w', 'x', 'y', 'z');
        $one = rand(1, 26);
        $two = rand(1, 26);
        $three = rand(1, 26);
        return $hash = $en[$one] . rand(0, 9) . rand(0, 9) . $en[$two] . $en[$three] . rand(0, 9) . rand(10, 99);
    }


}
