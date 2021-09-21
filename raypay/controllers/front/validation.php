<?php
/**
 * RayPay - A Payment Module for PrestaShop 1.7
 *
 * Order Validation Controller
 *
 * @author Saminray <info@saminray.com>
 * @license https://opensource.org/licenses/afl-3.0.php
 */
class RayPayValidationModuleFrontController extends ModuleFrontController
{

    /** @var array Controller errors */
    public $errors = [];

    /** @var array Controller warning notifications */
    public $warning = [];

    /** @var array Controller success notifications */
    public $success = [];

    /** @var array Controller info notifications */
    public $info = [];


    /**
     * set notifications on SESSION
     */
    public function notification()
    {

        $notifications = json_encode([
            'error' => $this->errors,
            'warning' => $this->warning,
            'success' => $this->success,
            'info' => $this->info,
        ]);

        if (session_status() == PHP_SESSION_ACTIVE) {
            $_SESSION['notifications'] = $notifications;
        } elseif (session_status() == PHP_SESSION_NONE) {
            session_start();
            $_SESSION['notifications'] = $notifications;
        } else {
            setcookie('notifications', $notifications);
        }


    }


    /**
     * register order and request to api
     */
    public function postProcess()
    {
        /**
         * Get current cart object from session
         */
        $cart = $this->context->cart;
        $authorized = false;

        /** @var CustomerCore $customer */
        $customer = new Customer($cart->id_customer);


        /**
         * Verify if this module is enabled and if the cart has
         * a valid customer, delivery address and invoice address
         */
        if (!$this->module->active || $cart->id_customer == 0 || $cart->id_address_delivery == 0
            || $cart->id_address_invoice == 0) {
            Tools::redirect('index.php?controller=order&step=1');
        }


        /**
         * Verify if this payment module is authorized
         */
        foreach (Module::getPaymentModules() as $module) {
            if ($module['name'] == 'raypay') {
                $authorized = true;
                break;
            }
        }

        if (!$authorized) {
            $this->errors[] = 'This payment method is not available.';
            $this->notification();
            /**
             * Redirect the customer to the order confirmation page
             */
            Tools::redirect('index.php?controller=order-confirmation&id_cart=' . (int)$cart->id . '&id_module=' . (int)$this->module->id . '&id_order=' . $this->module->currentOrder . '&key=' . $customer->secure_key);
        }

        /**
         * Check if this is a vlaid customer account
         */
        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect('index.php?controller=order&step=1');
        }


        //call callBack function
        if (isset($_GET['do'])) {
            $this->callBack($customer);
        }


        $this->module->validateOrder(
            (int)$this->context->cart->id,
            13,
            (float)$this->context->cart->getOrderTotal(true, Cart::BOTH),
            "RayPay",
            null,
            null,
            (int)$this->context->currency->id,
            false,
            $customer->secure_key
        );


        //get order id
        $sql = ' SELECT  `id_order`  FROM `' . _DB_PREFIX_ . 'orders` WHERE `id_cart` = "' . $cart->id . '"';
        $order_id = Db::getInstance()->executeS($sql);
        $order_id = $order_id[0]['id_order'];
        $invoice_id             = round(microtime(true) * 1000);


        $user_id = Configuration::get('raypay_user_id');
        $marketing_id = Configuration::get('raypay_marketing_id');
        $sandbox = !(Configuration::get('raypay_sandbox') == 'no');
        $amount = $cart->getOrderTotal();
        if (Configuration::get('raypay_currency') == "toman") {
            $amount *= 10;
        }

        // Customer information
        $details = $cart->getSummaryDetails();
        $delivery = $details['delivery'];
        $name = $delivery->firstname . ' ' . $delivery->lastname;
        $phone = $delivery->phone_mobile;

        if (empty($phone_mobile)) {
            $phone = $delivery->phone;
        }

        // There is not any email field in the cart details.
        // So we gather the customer email from this line of code:
        $mail = Context::getContext()->customer->email;


        $desc = 'پرداخت فروشگاه پرستاشاپ، سفارش شماره: ' . $order_id;
        $url = $this->context->link->getModuleLink('raypay', 'validation', array(), true);
        $callback =  $url. '?do=callback&hash=' .md5($amount . $order_id . Configuration::get('raypay_HASH_KEY')) . '&order_id=' . $order_id;

        if (empty($amount)) {
            $this->errors[] = $this->otherStatusMessages(404);
            $this->notification();
            Tools::redirect('index.php?controller=order-confirmation');
        }

        $data = array(
            'amount'       => strval($amount),
            'invoiceID'    => strval($invoice_id),
            'userID'       => $user_id,
            'redirectUrl'  => $callback,
            'factorNumber' => strval($order_id),
            'marketingID' => $marketing_id,
            'email'        => $mail,
            'mobile'       => $phone,
            'fullName'     => $name,
            'comment'      => $desc,
            'enableSandBox'      => $sandbox
        );


        $url  = 'https://api.raypay.ir/raypay/api/v1/Payment/pay';
        $options = array('Content-Type: application/json');
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HTTPHEADER,$options );
        $result = curl_exec($ch);
        $result = json_decode($result );
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $msg = [
            'raypay_id' => $invoice_id,
            'msg' => "در انتظار پرداخت...",
        ];
        $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
        $sql = ' UPDATE `' . _DB_PREFIX_ . 'orders` SET `current_state` = "' . 13 . '", `payment` = ' . "'" . $msg . "'" . ' WHERE `id_order` = "' . $order_id . '"';
        Db::getInstance()->Execute($sql);


        if ($http_status != 200 || empty($result) || empty($result->Data)) {
            $msg         = sprintf('خطا هنگام ایجاد تراکنش. کد خطا: %s - پیام خطا: %s', $http_status, $result->Message);
            $this->errors[] =$msg;
            $this->notification();
            $this->saveOrder($msg, 8, $order_id);
            Tools::redirect('index.php?controller=order-confirmation');

        } else {
            $token = $result->Data;
            $link='https://my.raypay.ir/ipg?token=' . $token;
            Tools::redirect($link);
            exit;
    }

    }


    /**
     * @param $customer
     */
    public function callBack($customer)
    {
            $order_id = $_GET['order_id'];
            $order = new Order((int)$order_id);
            $amount = (float)$order->total_paid_tax_incl;



        if (!empty( $order_id )) {

            if (Configuration::get('raypay_currency') == "toman") {
                $amount *= 10;
            }

            if ( md5($amount . $order->id . Configuration::get('raypay_HASH_KEY')) == $_GET['hash']) {
                $data = array('order_id' => $order_id);
                $url = 'https://api.raypay.ir/raypay/api/v1/Payment/verify';
                $options = array('Content-Type: application/json');
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($_POST));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $options);
                $result = curl_exec($ch);
                $result = json_decode($result);
                $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                    if ($http_status != 200) {
                        $msg = sprintf('خطا هنگام بررسی تراکنش. کد خطا: %s - پیام خطا: %s', $http_status, $result->Message);
                        $this->errors[] = $msg;
                        $this->notification();
                        $this->saveOrder($msg, 8, $order_id);
                        Tools::redirect('index.php?controller=order-confirmation');

                    } else {
                        $state           = $result->Data->Status;
                        $verify_order_id = $result->Data->FactorNumber;
                        $verify_invoice_id = $result->Data->InvoiceID;
                        $verify_amount   = $result->Data->Amount;

                        if (empty($verify_order_id) || empty($verify_amount) || $state !== 1) {

                            //generate msg and save to database as order
                            $msgForSaveDataTDataBase = 'پرداخت ناموفق بوده است. شناسه ارجاع بانکی رای پی : ' . $verify_invoice_id;
                            $this->saveOrder($msgForSaveDataTDataBase, 8, $order_id);
                            $msg = $this->raypay_get_failed_message($verify_invoice_id, $verify_order_id);
                            $this->errors[] = $msg;
                            $this->notification();
                            Tools::redirect('index.php?controller=order-confirmation');

                        } else {


                            if (Configuration::get('raypay_currency') == "toman") {
                                $amount /= 10;
                            }

                            $msgForSaveDataTDataBase = 'پرداخت شما با موفقیت انجام شد.';
                            $this->saveOrder($msgForSaveDataTDataBase,Configuration::get('PS_OS_PAYMENT'),$order_id);

                            $this->success[] = $this->raypay_get_success_message($verify_invoice_id, $verify_order_id);
                            $this->notification();
                            /**
                             * Redirect the customer to the order confirmation page
                             */
                            Tools::redirect('index.php?controller=order-confirmation&id_cart=' . (int)$order->id_cart . '&id_module=' . (int)$this->module->id . '&id_order=' . $this->module->currentOrder . '&key=' . $customer->secure_key);


                        }
                    }
            } else {
                $invoice_id = $_POST['invoiceid'];
                $this->errors[] = $this->raypay_get_failed_message($invoice_id ,$order_id);
                $this->notification();
                $msgForSaveDataTDataBase = 'سفارش پیدا نشد.';
                $this->saveOrder($msgForSaveDataTDataBase, 8, $order_id);
                Tools::redirect('index.php?controller=order-confirmation');
            }


        } else {

            $this->errors[] = 'خطا هنگام بازگشت از درگاه پرداخت';
            $this->notification();
            Tools::redirect('index.php?controller=order-confirmation');

        }


    }

    /**
     * @param $msgForSaveDataTDataBase
     * @param $paymentStatus
     * @param $order_id
     * 13 for waiting ,8 for payment error and Configuration::get('PS_OS_PAYMENT') for payment is OK
     */
    public function saveOrder($msgForSaveDataTDataBase, $paymentStatus, $order_id)
    {

        $sql = 'SELECT payment FROM `' . _DB_PREFIX_ . 'orders` WHERE id_order  = "' . $order_id . '"';
        $payment = Db::getInstance()->executes($sql);

        $payment = json_decode($payment[0]['payment'], true);
        $payment['msg'] = $msgForSaveDataTDataBase;
        $data = json_encode($payment, JSON_UNESCAPED_UNICODE);
        $sql = ' UPDATE `' . _DB_PREFIX_ . 'orders` SET `current_state` = "' . $paymentStatus .
            '", `payment` = ' . "'" . $data . "'" .
            ' WHERE `id_order` = "' . $order_id . '"';

        Db::getInstance()->Execute($sql);
    }

    /**
     * @param $invoice_id
     * @param $order_id
     * @return string
     */
    function raypay_get_success_message($invoice_id, $order_id)
    {
        return str_replace(["{invoice_id}", "{order_id}"], [$invoice_id, $order_id], Configuration::get('raypay_success_massage')) ;
    }

    /**
     * @param $invoice_id
     * @param $order_id
     * @return mixed
     */
    public function raypay_get_failed_message($invoice_id, $order_id)
    {

        return str_replace(["{invoice_id}", "{order_id}"], [$invoice_id, $order_id], Configuration::get('raypay_failed_massage'));

    }
}
