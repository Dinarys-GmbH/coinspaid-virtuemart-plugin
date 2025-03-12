<?php
/**
 *
 * @author Coinspaid
 * @version 1.0
 * @package VirtueMart
 * @subpackage Coinspaid
 * @copyright Copyright (c) 2015 Web Active Corporation Pty Ltd
 *
 * @license MIT License GNU/GPL, see LICENSE.php
 * Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"),
 * to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense,
 * and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.
 *
 * https://www.coinspaid.com
 */
use Coinspaid\Helper\Signature;

defined('_JEXEC') or die('Restricted access');
if (!class_exists('vmPSPlugin')) {
    require(VMPATH_PLUGINLIBS.DS.'vmpsplugin.php');
}

require_once __DIR__.DS.'autoload.php';

class plgVmpaymentCoinspaid extends vmPSPlugin
{
    public function __construct(&$subject, $config)
    {
        parent::__construct($subject, $config);

        $jlang = JFactory::getLanguage();
        $jlang->load('plg_vmpayment_coinspaid', JPATH_ADMINISTRATOR, null, true);
        $this->_loggable = true;
        $this->_debug = true;
        $this->tableFields = array_keys($this->getTableSQLFields());
        $this->_tablepkey = 'id';
        $this->_tableId = 'id';

        $varsToPush = [
            'sandbox' => ['0', 'int'],
            'enable_debug' => ['0', 'int'],
            'time_restriction' => ['0', 'int'],
            'sandbox_secret_key' => ['', 'char'],
            'secret_key' => ['', 'char'],
            'sandbox_public_key' => ['', 'char'],
            'public_key' => ['', 'char'],
            'payment_logos' => ['', 'char'],
            'status_pending' => ['', 'char'],
            'status_success' => ['', 'char'],
            'status_canceled' => ['', 'char'],
            'tax_id' => [0, 'int'],
        ];

        $this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
    }

    /**
     * Fields to create the payment table
     */
    public function getTableSQLFields(): array
    {

        $SQLfields = [
            'id' => 'int(1) UNSIGNED NOT NULL AUTO_INCREMENT',
            'virtuemart_order_id' => 'int(1) UNSIGNED',
            'order_number' => 'char(64)',
            'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED',
            'payment_name' => 'varchar(5000)',
            'payment_order_total' => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\'',
            'payment_currency' => 'char(3)',
            'email_currency' => 'char(3)',
            'cost_per_transaction' => 'decimal(10,2)',
            'cost_min_transaction' => 'decimal(10,2)',
            'cost_percent_total' => 'decimal(10,2)',
            'tax_id' => 'smallint(1)',
            'user_session' => 'varchar(255)',
        ];

        return $SQLfields;
    }

    /**
     * User click on the Confirm Purchase
     */
    function plgVmConfirmedOrder(VirtueMartCart $cart, array $order): ?bool
    {
        $virtuemartPaymentmethodId = $order['details']['BT']->virtuemart_paymentmethod_id;
        if (!($method = $this->getVmPluginMethod($virtuemartPaymentmethodId))) {
            return null; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }

        $config = new Coinspaid\Model\Config($method);

        if (!$config->valid()) {
            if ($config->enableDebug()) {
                $html = vmText::_('VMPAYMENT_COINSPAID_NOT_CONFIGURED');
            }

            $this->processConfirmedOrderPaymentResponse(0, $cart, $order, $html, $this->_name);

            return false;
        }

        $this->getPaymentCurrency($method);
        $currencyCode3 = shopFunctions::getCurrencyByID($method->payment_currency, 'currency_code_3');
        $emailCurrency = $this->getEmailCurrency($method);

        $totalInPaymentCurrency = vmPSPlugin::getAmountInCurrency(
            $order['details']['BT']->order_total,
            $method->payment_currency
        );

        // Prepare data that should be stored in the database
        $dbValues['payment_name'] = $this->renderPluginName($method);
        if (!empty($method->payment_info)) {
            $dbValues['payment_name'] .= '<br />'.$method->payment_info;
        }

        $orderNumber = $order['details']['BT']->order_number;

        $dbValues['order_number'] = $orderNumber;
        $dbValues['virtuemart_paymentmethod_id'] = (int)$virtuemartPaymentmethodId;
        $dbValues['cost_per_transaction'] = $method->cost_per_transaction;
        $dbValues['cost_min_transaction'] = $method->cost_min_transaction;
        $dbValues['cost_percent_total'] = $method->cost_percent_total;
        $dbValues['payment_currency'] = $currencyCode3;
        $dbValues['email_currency'] = $emailCurrency;
        $dbValues['payment_order_total'] = $totalInPaymentCurrency['value'];
        $dbValues['tax_id'] = $method->tax_id;
        $dbValues['user_session'] = JFactory::getApplication()->getSession()->getId();
        $this->storePSPluginInternalData($dbValues);

        $items = [];
        foreach ($order["items"] as $item) {
            $items[] = $item->order_item_name;
        }

        $invoice = new Coinspaid\Invoice($config);

        $params = [
            'timer' => $config->timeRestriction(),
            'title' => (string)'#'.$orderNumber,
            'foreign_id' => (string)$order['details']['BT']->virtuemart_order_id,
            'currency' => $currencyCode3,
            'amount' => $totalInPaymentCurrency['value'],
            'url_success' => $this->getSuccessUrl($orderNumber),
            'url_failed' => $this->getCancelUrl($orderNumber),
            'email_user' => $order['details']['BT']->email,
            'description' => implode('; ', $items),
        ];

        // get payment URL
        $invoice->send($params);

        // invoice failed, redirect to cart
        if ($invoice->hasErrors()) {
            $this->debugLog(json_encode($invoice->getErrors()), 'Unable to retrieve payment URL from provider', 'error');

            $html = vmText::_('VMPAYMENT_COINSPAID_TECHNICAL_ISSUE').'<br />'.vmText::_(
                    'VMPAYMENT_COINSPAID_CONTACT_SHOPOWNER'
                );

            // show details on checkout
            if (isset($invoice->getErrors()['response']['errors'])) {
                $html .= '<hr />'. implode('<br />', $invoice->getErrors()['response']['errors']);
            }

            // returnValue = 0; error while processing the payment
            $this->processConfirmedOrderPaymentResponse(0, $cart, $order, $html, $this->_name);

            return false;
        }

        if ($config->enableDebug()) {
            $this->debugLog(json_encode($invoice->getData()), 'Invoice data', 'error');
        }

        $html = $this->renderByLayout('redirect', ['url' => $invoice->getUrl()]);
        $this->processConfirmedOrderPaymentResponse(2, $cart, $order, $html, $this->_name);

        return true;
    }

    private static function getSuccessUrl(string $orderNumber): string
    {
        return JURI::root()
            ."index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&on=$orderNumber";
    }

    private static function getCancelUrl(string $orderNumber): string
    {
        return JURI::root()
            ."index.php?option=com_virtuemart&view=pluginresponse&task=pluginUserPaymentCancel&on=$orderNumber";
    }

    /**
     * Display stored payment data for an order
     */
    function plgVmOnShowOrderBEPayment($virtuemart_order_id, $virtuemart_payment_id)
    {

        if (!$this->selectedThisByMethodId($virtuemart_payment_id)) {
            return null; // Another method was selected, do nothing
        }

        if (!($paymentTable = $this->getDataByOrderId($virtuemart_order_id))) {
            return null;
        }
        vmLanguage::loadJLang('com_virtuemart');

        $html = '<table class="adminlist table">'."\n";
        $html .= $this->getHtmlHeaderBE();
        $html .= $this->getHtmlRowBE('COM_VIRTUEMART_PAYMENT_NAME', $paymentTable->payment_name);
        $html .= $this->getHtmlRowBE(
            'STANDARD_PAYMENT_TOTAL_CURRENCY',
            $paymentTable->payment_order_total.' '.$paymentTable->payment_currency
        );
        $html .= '</table>'."\n";

        return $html;
    }

    /**
     * Create the table for this plugin if it does not yet exist.
     * This functions checks if the called plugin is active one.
     * When yes it is calling the standard method to create the tables
     *
     */
    function plgVmOnStoreInstallPaymentPluginTable($jplugin_id)
    {
        return $this->onStoreInstallPluginTable($jplugin_id);
    }

    /**
     * This event is fired after the payment method has been selected. It can be used to store
     * additional payment info in the cart.
     *
     * @param VirtueMartCart $cart : the actual cart
     * @return null if the payment was not selected, true if the data is valid, error message if the data is not valid
     */
    public function plgVmOnSelectCheckPayment(VirtueMartCart $cart, &$msg)
    {
        return $this->OnSelectCheck($cart);
    }

    /**
     * This event is fired to display the plugin methods in the cart (edit shipment/payment)
     *
     * @param VirtueMartCart $cart
     * @param $selected
     * @param $htmlIn
     * @return bool
     */
    public function plgVmDisplayListFEPayment(VirtueMartCart $cart, int $selected = 0, &$htmlIn)
    {
	    if ( ! empty( $this->methods ) && is_array( $this->methods ) ) {
		    $method = reset( $this->methods );
		    $config = new Coinspaid\Model\Config( $method );

		    $application = JFactory::getApplication();
		    if ( $config->useSandbox() ) {
			    $application->enqueueMessage( JText::_( 'VMPAYMENT_COINSPAID_PAYMENT_SENDBOX' ), 'warning' );
		    }

		    if ( $config->valid() ) {
			    $this->_isInList = true;

			    return $this->displayListFE( $cart, $selected, $htmlIn );
		    }
	    }
    }

    /**
     * Virtuemart V4 word case changed
     * @see https://virtuemart.net/news/506-virtuemart-4
     *
     * Calculate the price (value, tax_id) of the selected method
     * It is called by the calculator
     * This function does NOT to be reimplemented. If not reimplemented, then the default values from this function are taken.
     * @cart: VirtueMartCart the current cart
     * @cart_prices: array the new cart prices
     * @return null if the method was not selected, false if the shipping rate is not valid anymore, true otherwise
     *
     */
    public function plgVmOnSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name)
    {
        return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }

    /**
     * plgVmOnCheckAutomaticSelectedPayment
     * Checks how many plugins are available. If only one, the user will not have the choice. Enter edit_xxx page
     * The plugin must check first if it is the correct type
     *
     * @param VirtueMartCart cart: the cart object
     * @return null if no plugin was found, 0 if more then one plugin was found,  virtuemart_xxx_id if only one plugin is found
     */
    function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = [], &$paymentCounter)
    {
        return $this->onCheckAutomaticSelected($cart, $cart_prices, $paymentCounter);
    }

    /**
     * This method is fired when showing the order details in the frontend.
     * It displays the method-specific data.
     *
     * @param $virtuemart_order_id
     * @param $virtuemart_paymentmethod_id
     * @param $payment_name
     * @return mixed Null for methods that aren't active, text (HTML) otherwise
     */
    public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name)
    {
        $this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
    }

    /**
     * This method is fired when showing when printing an Order
     * It displays the payment method-specific data.
     *
     * @param $order_number
     * @param integer $method_id method used for this order
     * @return mixed Null when for payment methods that were not selected, text (HTML) otherwise
     */
    function plgVmonShowOrderPrintPayment($order_number, $method_id)
    {
        return $this->onShowOrderPrint($order_number, $method_id);
    }

    function plgVmDeclarePluginParamsPaymentVM3(&$data)
    {
        return $this->declarePluginParams('payment', $data);
    }

    function plgVmSetOnTablePluginParamsPayment($name, $id, &$table)
    {
        return $this->setOnTablePluginParams($name, $id, $table);
    }

    /**
     * This event is fired when the  method returns to the shop after the transaction
     */
    public function plgVmOnPaymentResponseReceived(&$html): ?bool
    {
        $orderNumber = vRequest::getUword('on');

        if (!$orderNumber) {
            return false;
        }

        if (!$orderId = VirtueMartModelOrders::getOrderIdByOrderNumber($orderNumber)) {
            return null;
        }

        if (!($paymentTable = $this->getDataByOrderId($orderId))) {
            return null;
        }

        if (strcmp($paymentTable->user_session, JFactory::getApplication()->getSession()->getId()) === 0) {
            JFactory::getApplication()->redirect(self::getOrderDetailPage($orderNumber));
        }

        return false;
    }

    private static function getOrderDetailPage(string $orderNumber): string
    {
        return JURI::root()
            ."index.php?option=com_virtuemart&view=orders&layout=details&order_number=$orderNumber";
    }

    /**
     * Cancel action
     * From the payment page, the user has cancelled the order. The order previousy created is deleted.
     * The cart is not emptied, so the user can reorder if necessary.
     * then delete the order
     *
     */
    public function plgVmOnUserPaymentCancel(): ?bool
    {
        $orderNumber = vRequest::getUword('on');

        if (!$orderNumber) {
            return false;
        }

        if (!$orderId = VirtueMartModelOrders::getOrderIdByOrderNumber($orderNumber)) {
            return null;
        }

        if (!($paymentTable = $this->getDataByOrderId($orderId))) {
            return null;
        }

        if (strcmp($paymentTable->user_session, JFactory::getApplication()->getSession()->getId()) === 0) {
	        $paymenId  = $paymentTable->virtuemart_paymentmethod_id;
	        if ($paymenId) {
		        $payment = $this->getVmPluginMethod($paymenId);
		        $this->plgHandlePaymentUserCancel($orderId, $payment->status_canceled);
	        }

            JFactory::getApplication()->redirect(self::getOrderDetailPage($orderNumber));
        }

        return false;
    }

	/**
	 * @param integer $virtuemart_order_id the id of the order
	 * @param string $status the id of the order
	 */
	function plgHandlePaymentUserCancel ($virtuemart_order_id, $status) {

		if ($virtuemart_order_id) {
			// set the order to cancel , to handle the stock correctly
			$modelOrder = VmModel::getModel ('orders');
			$order['order_status'] = $status;   //There is no reason to X the order. P should be better.
			$order['virtuemart_order_id'] = $virtuemart_order_id;
			$order['customer_notified'] = 0;
			$order['comments'] = vmText::_ ('COM_VIRTUEMART_PAYMENT_CANCELLED_BY_SHOPPER').' '.$this->_name;
			$modelOrder->updateStatusForOneOrder ($virtuemart_order_id, $order, TRUE);
		}
	}

	/**
     * Callback
     * Handler of the payment notification sent to the ServerResultURL
     * and the module information request
     */
    function plgVmOnPaymentNotification(): ?bool
    {
        $paymenId = vRequest::getInt('pm', 0);
        if (!$paymenId) {
            return null;
        }

        $payment = $this->getVmPluginMethod($paymenId);
        if (!$payment) {
            return null;
        }

        $paymentConfig = new Coinspaid\Model\Config($payment);
        $postData = json_decode(JFactory::getApplication()->input->json->getRaw(), true);
        $headers = getallheaders();

        if (!Signature::validateSignatureHeaders($paymentConfig, $postData, $headers)) {
            if ($paymentConfig->enableDebug()) {
                $this->debugLog(json_encode($headers), 'Invalid signature', 'error');
            }

            return false;
        }

        if ($paymentConfig->enableDebug()) {
            $this->debugLog(json_encode($postData), 'Callback data', 'error');
        }

        $orderHistory = [];
        if (!empty($postData['error'])) {
            $orderHistory['customer_notified'] = 0;
            $orderHistory['order_status'] = $payment->status_canceled;
            $orderHistory['comments'] = $postData['error'];
        } else {
            $orderHistory['customer_notified'] = 1;
            switch ($postData['status']) {
                case 'confirmed':
                    $orderHistory['order_status'] = $payment->status_success;
                    $orderHistory['comments'] = 'confirmed';
                    break;
                case 'pending':
                    $orderHistory['order_status'] = $payment->status_pending;
                    $orderHistory['comments'] = 'pending';
                    break;
                default:
                    $orderHistory['order_status'] = $payment->status_pending;
                    $orderHistory['comments'] = 'Unknown payment response type';
                    break;
            }
        }

        $modelOrder = VmModel::getModel('orders');

        $modelOrder->updateStatusForOneOrder($postData['foreign_id'], $orderHistory, false);

	    exit( 'OK' );
        return true;
    }

    /**
     * Displays the logos of a VirtueMart plugin
     */
    protected function displayLogos($logo_list): string
    {
        $img = "";

        if (!(empty($logo_list))) {
            $url = JURI::root().'plugins/vmpayment/coinspaid/';
            if (!is_array($logo_list)) {
                $logo_list = (array)$logo_list;
            }
            foreach ($logo_list as $logo) {
                $alt_text = substr($logo, 0, strpos($logo, '.'));
                $img .= '<img align="middle" src="'.$url.$logo.'"  alt="'.$alt_text.'" /> ';
            }
        }

        return $img;
    }

    protected function getVmPluginCreateTableSQL(): string
    {
        return $this->createTableSQL('Payment Coinspaid Table');
    }
}
