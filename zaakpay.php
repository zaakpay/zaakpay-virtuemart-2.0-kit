<?php
defined('_JEXEC') or die('Restricted access');

 
if (!class_exists('vmPSPlugin'))
    require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');

class plgVmPaymentZaakpay extends vmPSPlugin {

    // instance of class
    public static $_this = false;

    function __construct(& $subject, $config) {
		//if (self::$_this)
		//   return self::$_this;
		parent::__construct($subject, $config);
	
		$this->_loggable = true;
		$this->tableFields = array_keys($this->getTableSQLFields());
		$this->_tablepkey = 'id'; //virtuemart_id';
		$this->_tableId = 'id'; //'virtuemart_id';
		$varsToPush = array(
			'merchant_id' => array('','char'),
			'secret_key' => array('','char'),
			'mode' => array('','int'),
			'log' => array('','char'),
			'description' => array('','text'),
		    'payment_logos' => array('', 'char'),
			'payment_currency' => array('', 'int'),
		    'status_pending' => array('', 'char'),
		    'status_success' => array('', 'char'),
		    'status_canceled' => array('', 'char'),
		    'countries' => array('', 'char'),
		    'min_amount' => array('', 'int'),
		    'max_amount' => array('', 'int'),
		    'secure_post' => array('', 'int'),
		    'ipn_test' => array('', 'int'),
		    'no_shipping' => array('', 'int'),
		    'address_override' => array('', 'int'),
		    'cost_per_transaction' => array('', 'int'),
		    'cost_percent_total' => array('', 'int'),
		    'tax_id' => array(0, 'int')
		);
	
		$this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
	
		//self::$_this = $this;
    }
    
 	public function getVmPluginCreateTableSQL() {
		return $this->createTableSQL('Payment Zaakpay Table');
    }
    
	function getTableSQLFields() {
		$SQLfields = array(
		    'id' => 'int(11) UNSIGNED NOT NULL AUTO_INCREMENT',
		    'virtuemart_order_id' => 'int(10) UNSIGNED',
		    'order_number' => ' char(64)',
		    'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED',
		    'payment_name' => 'varchar(5000)',
			'zaakpay_custom' => ' varchar(255)',
		    'amount' => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\'',
		    'billing_currency' => 'char(3) ',
			'response_code' => 'int(11)',
			'response_description' => 'varchar(225)',
			'mode'=> 'int(2)',
			'payment_id' => 'char(100)',
			'description' => 'text',
			
		);
	return $SQLfields;
	}
	
	function plgVmConfirmedOrder($cart, $order) {		
		if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
		    return null; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement($method->payment_element)) {
		    return false;
		}
		$session = JFactory::getSession();
		$return_context = $session->getId();
		$this->logInfo('plgVmConfirmedOrder order number: ' . $order['details']['BT']->order_number, 'message');

		if (!class_exists('VirtueMartModelOrders'))
		    require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
		if (!class_exists('VirtueMartModelCurrency'))
		    require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'currency.php');		
		    
		
		//$usr = JFactory::getUser();
		$new_status = '';	
		$usrBT = $order['details']['BT'];
		$address = ((isset($order['details']['ST'])) ? $order['details']['ST'] : $order['details']['BT']);

		if (!class_exists('TableVendors'))
		    require(JPATH_VM_ADMINISTRATOR . DS . 'table' . DS . 'vendors.php');
		/*$vendorModel = VmModel::getModel('Vendor');
		$vendorModel->setId(1);
		$vendor = $vendorModel->getVendor();
		$vendorModel->addImages($vendor, 1);*/
		$this->getPaymentCurrency($method);
		$q = 'SELECT `currency_code_3` FROM `#__virtuemart_currencies` WHERE `virtuemart_currency_id`="' . $method->payment_currency . '" ';
		$db = JFactory::getDBO();
		$db->setQuery($q);
		$currency_code_3 = $db->loadResult();
	
		$paymentCurrency = CurrencyDisplay::getInstance($method->payment_currency);
		$totalInPaymentCurrency = $paymentCurrency->convertCurrencyTo($method->payment_currency, $order['details']['BT']->order_total, false);
		$cd = CurrencyDisplay::getInstance($cart->pricesCurrency);
		if ($totalInPaymentCurrency <= 0) {
		     vmInfo(JText::_('VMPAYMENT_ZAAKPAY_PAYMENT_AMOUNT_INCORRECT'));
			    return false;
		}
		$merchant_id = $method->merchant_id;
		if (empty($merchant_id)) {
		    vmInfo(JText::_('VMPAYMENT_ZAAKPAY_MERCHANT_ID_NOT_SET'));
		    return false;
		}
		$secret_key = $method->secret_key;
		if (empty($secret_key)) {
		    vmInfo(JText::_('VMPAYMENT_ZAAKPAY_SECRET_KEY_NOT_SET'));
		    return false;
		}
		$mode = $method->mode;
		$log = $method->log;
		$return_url = JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&on=' . $order['details']['BT']->order_number . '&pm=' . $order['details']['BT']->virtuemart_paymentmethod_id. '&orderId=' .JRequest::getVar('orderId'). '&responseCode=' .JRequest::getVar('responseCode'). '&responseDescription=' .JRequest::getVar('responseDescription'). '&checksum=' .JRequest::getVar('checksum'));
		$product = $cart->products;
		//echo "<pre>";print_r($method);echo "</pre>";
		$description = $method->description;
		$order_id = $order['details']['BT']->order_number;
		$email = $order['details']['BT']->email;
		$firstname = $order['details']['BT']->first_name;
		$lastname = $order['details']['BT']->last_name;
		$address =  $order['details']['BT']->address_1." ".$order['details']['BT']->address_2;
		$city = $order['details']['BT']->city;
		
		$state = isset($order['details']['BT']->virtuemart_state_id) ? ShopFunctions::getStateByID($order['details']['BT']->virtuemart_state_id) : '';
		$country = ShopFunctions::getCountryByID($order['details']['BT']->virtuemart_country_id, 'country_2_code');
		$zip = $order['details']['BT']->zip;
		$phone = $order['details']['BT']->phone_1;
		$amount = intval($totalInPaymentCurrency * 100);		//should be in paisa
		$ship_address = $address->address_1;
		if(isset($address->address_2)){
	    	$ship_address .=  " ".$address->address_2;
		}
		
		$post_variables = Array(
		    "merchantIdentifier" => $merchant_id, 
		    "orderId" => $order_id,
			"returnUrl" => $return_url,
			"buyerEmail" => $email,
			"buyerFirstName" => $firstname,
			"buyerLastName" => $lastname,
			"buyerAddress" => $address,
			"buyerCity" => $city,
			"buyerState" => $state,
			"buyerCountry" => $country,
			"buyerPincode" =>  $zip,
			"buyerPhoneNumber" => $phone,
			"txnType" => 1,
			"zpPayOption" => 1,
			"mode" => $mode,
			"currency" => $currency_code_3,
			"amount" => $amount,	
			"merchantIpAddress" => "127.0.0.1",  	//Merchant Ip Address
			"purpose" => 1,
			"productDescription" => "Order Id ".$order_id,		//$product->virtuemart_product_name,//$description,
			"shipToAddress" => $ship_address,	
			"shipToCity" => $address->city,			
			"shipToState" => isset($address->virtuemart_state_id) ? ShopFunctions::getStateByID($address->virtuemart_state_id) : '',
			"shipToCountry" => ShopFunctions::getCountryByID($address->virtuemart_country_id, 'country_2_code'),
		    "shipToPincode" => $address->zip,
		    "shipToPhoneNumber" => $address->phone_1,
			"shipToFirstName" => $address->first_name,
			"shipToLastName" => $address->last_name,
			"txnDate" => date('Y-m-d'),
						
		);
		function sanitizedURL($param) {
		$pattern[0] = "%,%";
	        $pattern[1] = "%\(%";
       		$pattern[2] = "%\)%";
	        $pattern[3] = "%\{%";
	        $pattern[4] = "%\}%";
	        $pattern[5] = "%<%";
	        $pattern[6] = "%>%";
	        $pattern[7] = "%`%";
	        $pattern[8] = "%!%";
	        $pattern[9] = "%\\$%";
	        $pattern[10] = "%\%%";
	        $pattern[11] = "%\^%";
	        $pattern[12] = "%\+%";
	        $pattern[13] = "%\|%";
	        $pattern[14] = "%\\\%";
	        $pattern[15] = "%'%";
	        $pattern[16] = "%\"%";
	        $pattern[17] = "%;%";
	        $pattern[18] = "%~%";
	        $pattern[19] = "%\[%";
	        $pattern[20] = "%\]%";
	        $pattern[21] = "%\*%";
        	$sanitizedParam = preg_replace($pattern, "", $param);
		return $sanitizedParam;
	}
		function sanitizedParam($param) {
		$pattern[0] = "%,%";
	        $pattern[1] = "%#%";
	        $pattern[2] = "%\(%";
       		$pattern[3] = "%\)%";
	        $pattern[4] = "%\{%";
	        $pattern[5] = "%\}%";
	        $pattern[6] = "%<%";
	        $pattern[7] = "%>%";
	        $pattern[8] = "%`%";
	        $pattern[9] = "%!%";
	        $pattern[10] = "%\\$%";
	        $pattern[11] = "%\%%";
	        $pattern[12] = "%\^%";
	        $pattern[13] = "%=%";
	        $pattern[14] = "%\+%";
	        $pattern[15] = "%\|%";
	        $pattern[16] = "%\\\%";
	        $pattern[17] = "%:%";
	        $pattern[18] = "%'%";
	        $pattern[19] = "%\"%";
	        $pattern[20] = "%;%";
	        $pattern[21] = "%~%";
	        $pattern[22] = "%\[%";
	        $pattern[23] = "%\]%";
	        $pattern[24] = "%\*%";
	        $pattern[25] = "%&%";
        	$sanitizedParam = preg_replace($pattern, "", $param);
		return $sanitizedParam;
	}

	
	
		$all = '';
		foreach($post_variables as $name => $value)	{
			if($name != 'checksum') {
				$all .= "'";
				if ($name == 'returnUrl') {
					$all .= sanitizedURL($value);
				} else {				
					
					$all .= sanitizedParam($value);
				}
				$all .= "'";
			}
		}
		
		function calculateChecksum($secret_key, $all) {
			
		
		$hash = hash_hmac('sha256', $all , $secret_key);
		$checksum = $hash;
		
		return $checksum;
	}
	
	if($log == "on")
		{
			error_log("All Params : ".$all);
			error_log("Zaakpay Secret Key : ".$secret_key);
		}

		 $checksum = calculateChecksum($secret_key,$all);
			
	
	$post_variables = Array(
		    "merchantIdentifier" => $merchant_id, 
		    "orderId" => $order_id,
			"returnUrl" => $return_url,
			"buyerEmail" => sanitizedParam($email),
			"buyerFirstName" => sanitizedParam($firstname),
			"buyerLastName" => sanitizedParam($lastname),
			"buyerAddress" => sanitizedParam($address),
			"buyerCity" => $city,
			"buyerState" => $state, 
			"buyerCountry" => $country,
			"buyerPincode" =>  $zip,
			"buyerPhoneNumber" => $phone,
			"txnType" => 1,
			'zpPayOption' => 1,
			"mode" => $mode,
			"currency" => $currency_code_3,
			"amount" => $amount,
			"merchantIpAddress" => "127.0.0.1", 
			"purpose" => 1,
			"productDescription" => "Order Id ".$order_id, //$product->virtuemart_product_name,	//$description,
		    "shipToAddress" => sanitizedParam($ship_address),	
			"shipToCity" => $address->city,			
			"shipToState" => isset($address->virtuemart_state_id) ? ShopFunctions::getStateByID($address->virtuemart_state_id) : '',
			"shipToCountry" => ShopFunctions::getCountryByID($address->virtuemart_country_id, 'country_2_code'),
			"shipToPincode" => $address->zip,
		    "shipToPhoneNumber" => $address->phone_1,
			"shipToFirstName" => $address->first_name,
			"shipToLastName" => $address->last_name,
			"txnDate" => date('Y-m-d'),
			"checksum" => $checksum,			
		);
		
		$dbValues['order_number'] = $order['details']['BT']->order_number;
		$dbValues['payment_name'] = $this->renderPluginName($method, $order);
		$dbValues['virtuemart_paymentmethod_id'] = $cart->virtuemart_paymentmethod_id;
		$dbValues['description'] = $description;
		$dbValues['zaakpay_custom'] = $return_context;
		$dbValues['billing_currency'] = $method->payment_currency;
		$dbValues['amount'] = $amount;
		$this->storePSPluginInternalData($dbValues);
	
		$url = "api.zaakpay.com/transact";
		
		// add spin image
		$html = '<html><head><title>Redirection</title></head><body><div style="margin: auto; text-align: center;">';
		$html .= '<form action="' . "https://" . $url . '" method="post" name="vm_zaakpay_form" >';
		$html.= '<input type="submit"  value="' . JText::_('VMPAYMENT_ZAAKPAY_REDIRECT_MESSAGE') . '" />';
		foreach ($post_variables as $name => $value) {
		    $html.= '<input type="hidden" style="" name="' . $name . '" value="' . $value . '" />';
		}
		$html.= '</form></div>';
		$html.= ' <script type="text/javascript">';
		$html.= ' document.vm_zaakpay_form.submit();';
		$html.= ' </script></body></html>';
	
		// 	2 = don't delete the cart, don't send email and don't redirect
		$cart->_confirmDone = false;
		$cart->_dataValidated = false;
		$cart->setCartIntoSession();
		JRequest::setVar('html', $html);
    }
    
	function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId) {
		if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
		    return null; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement($method->payment_element)) {
		    return false;
		}
		$this->getPaymentCurrency($method);
		$paymentCurrencyId = $method->payment_currency;
    }

    function plgVmOnPaymentResponseReceived(&$html) {
		if (!class_exists('VirtueMartCart'))
	    require(JPATH_VM_SITE . DS . 'helpers' . DS . 'cart.php');
		if (!class_exists('shopFunctionsF'))
		    require(JPATH_VM_SITE . DS . 'helpers' . DS . 'shopfunctionsf.php');
		if (!class_exists('VirtueMartModelOrders'))
		    require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
			$zaakpay_data = JRequest::get('post');
		// the payment itself should send the parameter needed.
		$virtuemart_paymentmethod_id = JRequest::getInt('pm', 0);
		$order_number = JRequest::getString('on', 0);
		
		
		$vendorId = 0;
		if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
		    return null; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement($method->payment_element)) {
		    return null;
		}	
		if (!($virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number))) {
		    return null;
		}
		if (!($paymentTable = $this->getDataByOrderId($virtuemart_order_id) )) {
		    return '';
		}
		
		$payment_name = $this->renderPluginName($method);
		
		function sanitizedParam($param) {
		$pattern[0] = "%,%";
	        $pattern[1] = "%#%";
	        $pattern[2] = "%\(%";
       		$pattern[3] = "%\)%";
	        $pattern[4] = "%\{%";
	        $pattern[5] = "%\}%";
	        $pattern[6] = "%<%";
	        $pattern[7] = "%>%";
	        $pattern[8] = "%`%";
	        $pattern[9] = "%!%";
	        $pattern[10] = "%\\$%";
	        $pattern[11] = "%\%%";
	        $pattern[12] = "%\^%";
	        $pattern[13] = "%=%";
	        $pattern[14] = "%\+%";
	        $pattern[15] = "%\|%";
	        $pattern[16] = "%\\\%";
	        $pattern[17] = "%:%";
	        $pattern[18] = "%'%";
	        $pattern[19] = "%\"%";
	        $pattern[20] = "%;%";
	        $pattern[21] = "%~%";
	        $pattern[22] = "%\[%";
	        $pattern[23] = "%\]%";
	        $pattern[24] = "%\*%";
	        $pattern[25] = "%&%";
        	$sanitizedParam = preg_replace($pattern, "", $param);
		return $sanitizedParam;
	}
	
	function verifyChecksum($checksum, $all, $secret) {
		$hash = hash_hmac('sha256', $all , $secret);
		$cal_checksum = $hash;
		$bool = 0;
		if($checksum == $cal_checksum)	{
			$bool = 1;
		}
		return $bool;
	}
		
		$order_id = JRequest::getString('orderId', 0);
		$res_code = JRequest::getString('responseCode',0);
		$res_desc = JRequest::getString('responseDescription',0);
		$checksum_recv = JRequest::getString('checksum',0);
		
			
		$all = ("'". $order_id ."''". $res_code ."''". $res_desc." " ."'");
		
		$bool = 0;
		$bool = verifyChecksum($checksum_recv, $all, $method->secret_key);

	
	if($bool == 1){
			
	if($res_code=="100")
		{
			
			echo '<br><tr><td width="50%" align="center" valign="middle">Thank you for shopping with us. Your account has been charged and your transaction is successful. We will be shipping your order to you soon.</td></tr><br>';
			$new_status = $method->status_success;
						
		}
		else
		{
			
			echo '<tr><td width="50%" align="center" valign="middle">Thank you for shopping with us. The response is compromised</td></tr><br>'; 
				$new_status = $method->status_pending;		
		}
			}
		
		else
		{
			
			echo '<tr><td width="50%" align="center" valign="middle">Security Error. Response compromised.</td> </tr>';
			$new_status = $method->status_canceled;
						
		}
		function vmModel($model=null)
		{
			if(!class_exists('VmModel'))
			require(JPATH_VM_ADMINISTRATOR.DS.'helpers'.DS.'vmmodel.php');
			return vmModel::getModel($model);
		}
		
		$modelOrder = vmModel('orders');
		$order['order_status'] = $new_status;
		$order['customer_notified'] = 0;
		$order['comments'] = '';
		$modelOrder->updateStatusForOneOrder($virtuemart_order_id, $order, true);
		
		$this->_storeZaakpayInternalData($method, $order_id, $res_code, $res_desc, $virtuemart_order_id, $paymentTable->zaakpay_custom);
		if($res_code==100){		
			$html = $this->_getPaymentResponseHtml($paymentTable, $payment_name, $res_code, $res_desc);
		}
		else{
			$cancel_return = JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginUserPaymentCancel&on=' .$order_number.'&pm='.$virtuemart_paymentmethod_id);
			$html= ' <script type="text/javascript">';
			$html.= 'window.location = "'.$cancel_return.'"';
			$html.= ' </script>';
			JRequest::setVar('html', $html);
		}
	
		//We delete the old stuff
		// get the correct cart / session
		$cart = VirtueMartCart::getCart();
		$cart->emptyCart();
		return true;
    }
    
	function _getPaymentResponseHtml($paymentTable, $payment_name, $res_code, $res_desc) {
		$html = '<table>' . "\n";
		$html .= $this->getHtmlRow('ZAAKPAY_PAYMENT_NAME', $payment_name);		
		if (!empty($paymentTable)) {
		    $html .= $this->getHtmlRow('ZAAKPAY_ORDER_NUMBER', $paymentTable->order_number);
		}
		
		$q = 'SELECT `currency_code_3` FROM `#__virtuemart_currencies` WHERE `virtuemart_currency_id`="' . $paymentTable->billing_currency . '" ';
		$db = JFactory::getDBO();
		$db->setQuery($q);
		$currency_code_3 = $db->loadResult();
		
		
		$html .= $this->getHtmlRow('ZAAKPAY_RESPONSE_CODE', $res_code);
		$html .= $this->getHtmlRow('ZAAKPAY_RESPONSE_CODE', $res_desc);
		
		$html .= '</table>' . "\n";
	
		return $html;
    }
    
	function _storeZaakpayInternalData($method, $order_id, $res_code, $res_desc, $virtuemart_order_id, $custom) {
		$virtuemart_paymentmethod_id = JRequest::getInt('pm', 0);
		$response_fields['payment_name'] = $this->renderPluginName($method);	
		$response_fields['virtuemart_order_id'] = $virtuemart_order_id;
		$response_fields['virtuemart_paymentmethod_id'] = $virtuemart_paymentmethod_id;
		$response_fields['order_number'] = $order_id;
		$response_fields['zaakpay_custom'] = $custom;
		$response_fields['billing_currency'] = $method->payment_currency;
		$response_fields['response_code'] = $res_code;
		$response_fields['response_description'] = $res_desc;
		
		return $response_fields;		
		$this->storePSPluginInternalData($response_fields, 'virtuemart_order_id', true);
    }
    
 	function plgVmOnUserPaymentCancel() {
		if (!class_exists('VirtueMartModelOrders'))
		    require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
	
		$order_number = JRequest::getString('orderId', '');
		$virtuemart_paymentmethod_id = JRequest::getInt('pm', '');
		if (empty($order_number) or empty($virtuemart_paymentmethod_id) or !$this->selectedThisByMethodId($virtuemart_paymentmethod_id)) {
		    return null;
		}
		if (!($virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number))) {
			return null;
		}
		if (!($paymentTable = $this->getDataByOrderId($virtuemart_order_id))) {
		    return null;
		}
	
		VmInfo(Jtext::_('VMPAYMENT_ZAAKPAY_PAYMENT_CANCELLED'));
		$session = JFactory::getSession();
		$return_context = $session->getId();
		if (strcmp($paymentTable->zaakpay_custom, $return_context) === 0) {
		    $this->handlePaymentUserCancel($virtuemart_order_id);
		}
		return true;
    }
    
	
	
    
	function plgVmOnShowOrderBEPayment($virtuemart_order_id, $payment_method_id) {
		if (!$this->selectedThisByMethodId($payment_method_id)) {
		    return null; // Another method was selected, do nothing
		}
		if (!($paymentTable = $this->_getZaakpayInternalData($virtuemart_order_id) )) {
		    // JError::raiseWarning(500, $db->getErrorMsg());
		    return '';
		}
		$q = 'SELECT `currency_code_3` FROM `#__virtuemart_currencies` WHERE `virtuemart_currency_id`="' . $paymentTable->billing_currency . '" ';
		$db = JFactory::getDBO();
		$db->setQuery($q);
		$currency_code_3 = $db->loadResult();
		$html = '<table class="adminlist">' . "\n";
		$html .=$this->getHtmlHeaderBE();
		$html .= $this->getHtmlRowBE('ZAAKPAY_PAYMENT_NAME', $paymentTable->payment_name);		
		//echo "<pre>";print_r($paymentTable);echo "</pre>";
		$html .= $this->getHtmlRowBE('ZAAKPAY_VIRTUEMART_ORDER_ID', $paymentTable->virtuemart_order_id);
		$html .= $this->getHtmlRowBE('ZAAKPAY_RESPONSE_CODE', $paymentTable->response_code);
		$html .= $this->getHtmlRowBE('ZAAKPAY_RESPONSE_DESCRIPTION', $paymentTable->response_description);
		$html .= $this->getHtmlRowBE('ZAAKPAY_PAYMENT_ID', $paymentTable->payment_id);
		$html .= $this->getHtmlRowBE('ZAAKPAY_AMOUNT', $paymentTable->amount.' INR');
		$html .= $this->getHtmlRowBE('ZAAKPAY_MODE', $paymentTable->mode);
		$html .= $this->getHtmlRowBE('ZAAKPAY_PAYMENT_DATE', $paymentTable->modified_on);
		$html .= '</table>' . "\n";
		return $html;
    }

    function _getZaakpayInternalData($virtuemart_order_id, $order_number = '') {
		$db = JFactory::getDBO();
		$q = 'SELECT * FROM `' . $this->_tablename . '` WHERE ';
		if ($order_number) {
		    $q .= " `order_number` = '" . $order_number . "'";
		} else {
		    $q .= ' `virtuemart_order_id` = ' . $virtuemart_order_id;
		}
		$db->setQuery($q);
		if (!($paymentTable = $db->loadObject())) {
		    return '';
		}
		return $paymentTable;
    } 
	
	
  
    
	function getCosts(VirtueMartCart $cart, $method, $cart_prices) {
		if (preg_match('/%$/', $method->cost_percent_total)) {
		    $cost_percent_total = substr($method->cost_percent_total, 0, -1);
		} else {
		    $cost_percent_total = $method->cost_percent_total;
		}
		return ($method->cost_per_transaction + ($cart_prices['salesPrice'] * $cost_percent_total * 0.01));
    }
    
	protected function checkConditions($cart, $method, $cart_prices) {
		$this->convert($method);
		$address = (($cart->ST == 0) ? $cart->BT : $cart->ST);
		$amount = $cart_prices['salesPrice'];
		$amount_cond = ($amount >= $method->min_amount AND $amount <= $method->max_amount
			OR
			($method->min_amount <= $amount AND ($method->max_amount == 0) ));
		$countries = array();
		if (!empty($method->countries)) {
		    if (!is_array($method->countries)) {
			$countries[0] = $method->countries;
		    } else {
			$countries = $method->countries;
		    }
		}
		// probably did not gave his BT:ST address
		if (!is_array($address)) {
		    $address = array();
		    $address['virtuemart_country_id'] = 0;
		}
		if (!isset($address['virtuemart_country_id']))
		    $address['virtuemart_country_id'] = 0;
		if (in_array($address['virtuemart_country_id'], $countries) || count($countries) == 0) {
		    if ($amount_cond) {
			return true;
		    }
		}
		return false;
    }
    
 	function convert($method) {
		$method->min_amount = (float) $method->min_amount;
		$method->max_amount = (float) $method->max_amount;
    }
    
	function plgVmOnStoreInstallPaymentPluginTable($jplugin_id) {
		return $this->onStoreInstallPluginTable($jplugin_id);
    }
    
    public function plgVmOnSelectCheckPayment(VirtueMartCart $cart) {
		return $this->OnSelectCheck($cart);
    }
    
	public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn) {
		return $this->displayListFE($cart, $selected, $htmlIn);
    }
    
	public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name) {
		return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }
    
    function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array(),   &$paymentCounter) {
		return $this->onCheckAutomaticSelected($cart, $cart_prices,  $paymentCounter);
    }
    
 	public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name) {
		$this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
    }
    
 	function plgVmonShowOrderPrintPayment($order_number, $method_id) {
		return $this->onShowOrderPrint($order_number, $method_id);
    }
    
    function plgVmDeclarePluginParamsPayment($name, $id, &$data) {
		return $this->declarePluginParams('payment', $name, $id, $data);
    }

    function plgVmSetOnTablePluginParamsPayment($name, $id, &$table) {
		return $this->setOnTablePluginParams($name, $id, $table);
    }

}

// No closing tag
