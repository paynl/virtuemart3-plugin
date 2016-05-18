<?php
/**
 *
 * Paypal payment plugin
 *
 * @author Jeremy Magne
 * @author Valérie Isaksen
 * @version $Id: paypal.php 7217 2013-09-18 13:42:54Z alatak $
 * @package VirtueMart
 * @subpackage payment
 * Copyright (C) 2004-2014 Virtuemart Team. All rights reserved.
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL, see LICENSE.php
 * VirtueMart is free software. This version may have been modified pursuant
 * to the GNU General Public License, and as distributed it includes or
 * is derivative of works licensed under the GNU General Public License or
 * other free or open source software licenses.
 * See /administrator/components/com_virtuemart/COPYRIGHT.php for copyright notices and details.
 *
 * http://virtuemart.net
 */

defined('_JEXEC') or die('Restricted access');

class PaynlHelperPaynl {

	var $_method;
	var $cart;
	var $order;
	var $vendor;
	var $customerData;
	var $context;
	var $total;
	var $post_variables;
	var $post_string;
	var $requestData;
	var $response;
	var $currency_code_3;
	var $currency_display;
	var $paypalPlugin;
	private $_timeout = 60;
	const TIMEOUT_SETEXPRESSCHECKOUT = 15;
	const TIMEOUT_GETEXPRESSCHECKOUTDETAILS = 15;
	const TIMEOUT_OTHERS = 60;

	const FRAUD_FAILURE_ERROR_CODE = 10486;
	const FMF_PENDED_ERROR_CODE = 11610;
	const FMF_DENIED_ERROR_CODE = 11611;
	const BNCODE = "VirtueMart_Cart_PPA";


	function __construct ($method, $paypalPlugin) {
		$session = JFactory::getSession();
		$this->context = $session->getId();
		$this->_method = $method;
		$this->paypalPlugin = $paypalPlugin;
		//Set the vendor
		$vendorModel = VmModel::getModel('Vendor');
		$vendorModel->setId($this->_method->virtuemart_vendor_id);
		$vendor = $vendorModel->getVendor();
		$vendorModel->addImages($vendor, 1);
		$this->vendor = $vendor;

		$this->getPaypalPaymentCurrency();
	}

	function getPaypalPaymentCurrency ($getCurrency = FALSE) {

		vmPSPlugin::getPaymentCurrency($this->_method);
		$this->currency_code_3 = shopFunctions::getCurrencyByID($this->_method->payment_currency, 'currency_code_3');

	}

	public function getContext () {
		return $this->context;
	}

	public function setCart ($cart) {
		$this->cart = $cart;
		if (!isset($this->cart->pricesUnformatted)) {
			$this->cart->getCartPrices();
		}
	}

	public function setOrder ($order) {
		$this->order = $order;
	}

	public function setCustomerData ($customerData) {
		$this->customerData = $customerData;
	}

	public function loadCustomerData () {
		$this->customerData = new PaynlHelperCustomerData();
		$this->customerData->load();
		$this->customerData->loadPost();
	}

	function getItemName ($name) {
		return substr(strip_tags($name), 0, 127);
	}

	function getProductAmount ($productPricesUnformatted) {
		if ($productPricesUnformatted['salesPriceWithDiscount']) {
			return vmPSPlugin::getAmountValueInCurrency($productPricesUnformatted['salesPriceWithDiscount'], $this->_method->payment_currency);
		} else {
			return vmPSPlugin::getAmountValueInCurrency($productPricesUnformatted['salesPrice'], $this->_method->payment_currency);
		}
	}


	function addRulesBill ($rules) {
		$handling = 0;
		foreach ($rules as $rule) {
			$handling += vmPSPlugin::getAmountValueInCurrency($this->cart->pricesUnformatted[$rule['virtuemart_calc_id'] . 'Diff'], $this->_method->payment_currency);
		}
		return $handling;
	}

	/**
	 * @return value
	 */
	function getHandlingAmount () {
		$handling = 0;
		$handling += $this->addRulesBill($this->cart->cartData['DBTaxRulesBill']);
		$handling += $this->addRulesBill($this->cart->cartData['taxRulesBill']);
		$handling += $this->addRulesBill($this->cart->cartData['DATaxRulesBill']);
		$handling += vmPSPlugin::getAmountValueInCurrency($this->cart->pricesUnformatted['salesPricePayment'], $this->_method->payment_currency);
		return $handling;
	}

	public function setTotal ($total) {
		if (!class_exists('CurrencyDisplay')) {
			require(JPATH_VM_ADMINISTRATOR . '/helpers/currencydisplay.php');
		}
		$this->total = vmPSPlugin::getAmountValueInCurrency($total, $this->_method->payment_currency);

//		$cd = CurrencyDisplay::getInstance($this->cart->pricesCurrency);
	}

	public function getTotal () {
		return $this->total;
	}

	public function getResponse () {
		return $this->response;
	}

	public function getRequest () {
		$this->debugLog($this->requestData, 'PayPal ' . $this->requestData['METHOD'] . ' Request variables ', 'debug');
		return $this->requestData;
	}

	protected function sendRequest ($post_data) {
		$retryCodes = array('401', '403', '404',);

		$this->post_data = $post_data;
		$post_url = $this->_getApiUrl();

		$post_string = $this->ToUri($post_data);
		$curl_request = curl_init($post_url);
		curl_setopt($curl_request, CURLOPT_POSTFIELDS, $post_string);
		curl_setopt($curl_request, CURLOPT_HEADER, 0);
		curl_setopt($curl_request, CURLOPT_TIMEOUT, $this->_timeout);
		curl_setopt($curl_request, CURLOPT_RETURNTRANSFER, 1);


		if ($this->_method->authentication == 'certificate') {
			$certPath = "";
			$passPhrase = "";
			$this->getSSLCertificate($certPath, $passPhrase);
			curl_setopt($curl_request, CURLOPT_SSLCERT, $certPath);
			curl_setopt($curl_request, CURLOPT_SSLCERTPASSWD, $passPhrase);
			curl_setopt($curl_request, CURLOPT_SSL_VERIFYPEER, 1);
			curl_setopt($curl_request, CURLOPT_SSL_VERIFYHOST, 2);
		} else {
			curl_setopt($curl_request, CURLOPT_SSL_VERIFYPEER, false);
		}


		curl_setopt($curl_request, CURLOPT_POST, 1);
		if (preg_match('/xml/', $post_url)) {
			curl_setopt($curl_request, CURLOPT_HTTPHEADER, Array("Content-Type: text/xml"));
		}

		$response = curl_exec($curl_request);

		if ($curl_error = curl_error($curl_request)) {
			$this->debugLog($curl_error, '----CURL ERROR----', 'error');
		}

		$responseArray = array();
		parse_str($response, $responseArray);
		curl_close($curl_request);

		$responseArray['custom'] = $this->context;
		$responseArray['method'] = $post_data['METHOD'];
		$this->response = $responseArray;

		if ($this->response['ACK'] == 'SuccessWithWarning') {
			$level = 'warning';
		} else {
			$level = 'debug';
		}

		$this->debugLog($post_data, 'PayPal ' . $post_data['METHOD'] . ' Request variables:', $level);
		$this->debugLog($this->response, 'PayPal response:', $level);

		return $this->response;

	}

	/**
	 * Get ssl parameters for certificate based client authentication
	 *
	 * @param string $certPath - path to client certificate file (PEM formatted file)
	 */
	public function getSSLCertificate (&$certifPath, &$passPhrase) {
		$safePath = VmConfig::get('forSale_path', '');
		if ($safePath) {
			$sslCertifFolder = $safePath . "paypal";

		}
		$certifPath = $sslCertifFolder . DS . $this->api_certificate;
	}

	protected function setTimeOut ($value = 45) {
		$this->_timeout = $value;
	}

	protected function getDurationValue ($duration) {
		$parts = explode('-', $duration);
		return $parts[0];
	}

	protected function getDurationUnit ($duration) {
		$parts = explode('-', $duration);
		return $parts[1];
	}

	protected function truncate ($string, $length) {
		return substr($string, 0, $length);
	}

	protected function _getFormattedDate ($month, $year) {

		return sprintf('%02d%04d', $month, $year);
	}

	public function validate ($enqueueMessage = true) {
		return true;
	}

	public function validatecheckout ($enqueueMessage = true) {
		return true;
	}

	function ToUri ($post_variables) {
		$poststring = '';
		foreach ($post_variables AS $key => $val) {
			$poststring .= urlencode($key) . "=" . urlencode($val) . "&";
		}
		$poststring = rtrim($poststring, "& ");
		return $poststring;
	}

	public function displayExtraPluginInfo () {
		$extraInfo = '';
		return $extraInfo;
	}

	public function getExtraPluginInfo () {
		$extraInfo = '';
		return $extraInfo;
	}

	public function getLogoImage () {
		if ($this->_method->logoimg) {
			return JURI::base() . '/images/stories/virtuemart/payment/' . $this->_method->logoimg;
		} else {
			return JURI::base() . $this->vendor->images[0]->file_url;
		}

	}

	public function getRecurringProfileDesc () {
		$durationValue = $this->getDurationValue($this->_method->subscription_duration);
		$durationUnit = $this->getDurationUnit($this->_method->subscription_duration);
		$recurringDesc = vmText::sprintf('VMPAYMENT_PAYPAL_SUBSCRIPTION_DESCRIPTION', $durationValue, $durationUnit, $this->_method->subscription_term);
		return $recurringDesc;
	}

	public function getPaymentPlanDesc () {
		$durationValue = $this->getDurationValue($this->_method->payment_plan_duration);
		$durationUnit = $this->getDurationUnit($this->_method->payment_plan_duration);
		$recurringDesc = vmText::sprintf('VMPAYMENT_PAYPAL_PAYMENT_PLAN_DESCRIPTION', $this->_method->payment_plan_term, $durationValue, $durationUnit);
		if ($this->_method->payment_plan_defer && $this->_method->paypalproduct == 'std') {
			$defer_duration = $this->getDurationValue($this->_method->payment_plan_defer_duration);
			$defer_unit = $this->getDurationUnit($this->_method->payment_plan_defer_duration);
			$startDate = JFactory::getDate('+' . $defer_duration . ' ' . $defer_unit);
			$recurringDesc .= '<br/>' . vmText::sprintf('VMPAYMENT_PAYPAL_PAYMENT_PLAN_INITIAL_PAYMENT', JHTML::_('date', $startDate->toFormat(), vmText::_('DATE_FORMAT_LC4')));
		} else {
			if ($this->_method->payment_plan_defer_strtotime) {
				$startDate = JFactory::getDate($this->_method->payment_plan_defer_strtotime);
				$recurringDesc .= '<br/>' . vmText::sprintf('VMPAYMENT_PAYPAL_PAYMENT_PLAN_INITIAL_PAYMENT', JHTML::_('date', $startDate->toFormat(), vmText::_('DATE_FORMAT_LC4')));
				//$recurringDesc .= '<br/>'.vmText::sprintf('VMPAYMENT_PAYPAL_PAYMENT_PLAN_INITIAL_PAYMENT',date(vmText::_('DATE_FORMAT_LC4'),strtotime('first day of next month')));
			}
		}
		return $recurringDesc;
	}

	/********************************/
	/* Instant Payment Notification */
	/********************************/
	public function processIPN ($paynl_data, $payments) {
//prevedere línvio di piu dati per aiutare il debug. creare struttura ad hoc per errori
		$result=array('success'=>'','message'=>'','order_history'=>'');
		// Validate the IPN content upon PayPal
		if (!$this->validateIpnContent($paynl_data)) {
                 	return false;
		}
		//Check the PayPal response
		/*
		 * https://cms.paypal.com/us/cgi-bin/?cmd=_render-content&content_ID=developer/e_howto_html_IPNandPDTVariables
		 * The status of the payment:
		 * Canceled_Reversal: A reversal has been canceled. For example, you won a dispute with the customer, and the funds for the transaction that was reversed have been returned to you.
		 * Completed: The payment has been completed, and the funds have been added successfully to your account balance.
		 * Created: A German ELV payment is made using Express Checkout.
		 * Denied: You denied the payment. This happens only if the payment was previously pending because of possible reasons described for the pending_reason variable or the Fraud_Management_Filters_x variable.
		 * Expired: This authorization has expired and cannot be captured.
		 * Failed: The payment has failed. This happens only if the payment was made from your customer’s bank account.
		 * Pending: The payment is pending. See pending_reason for more information.
		 * Refunded: You refunded the payment.
		 * Reversed: A payment was reversed due to a chargeback or other type of reversal. The funds have been removed from your account balance and returned to the buyer. The reason for the reversal is specified in the ReasonCode element.
		 * Processed: A payment has been accepted.
		 * Voided: This authorization has been voided.
		 */
		$order_history = array();
		$order_history['customer_notified'] = 1;
		
                
                if ($paynl_data['action'] == 'cancel') {
			$order_history['order_status'] = $this->_method->status_canceled;
		
		}  else {
			if (strcmp($paynl_data['action'], 'paid') == 0) {
				$this->debugLog('PAID', 'action', 'debug');

				// 1. check the payment_status is Completed
				// 2. check that txn_id has not been previously processed
				if ($this->_check_txn_id_already_processed($payments, $paynl_data['order_id'])) {
					$this->debugLog($paynl_data['order_id'], '_order_id_already_processed', 'debug');
					return FALSE;
				}
				$order_history['order_status'] = $this->_method->status_success;
				// now we can process the payment
				$order_history['comments'] = vmText::sprintf('VMPAYMENT_PAYPAL_PAYMENT_STATUS_CONFIRMED', $this->order['details']['BT']->order_number);

			} elseif (strcmp($paynl_data['action'], 'Pending') == 0) {
				$order_history['order_status'] = $this->_method->status_pending;

			} elseif (isset ($paynl_data['action'])) {
				// voided
				$order_history['order_status'] = $this->_method->status_canceled;
			} else {
				/*
				* a notification was received that concerns one of the payment (since $paypal_data['invoice'] is found in our table),
				* but the IPN notification has no $paypal_data['payment_status']
				* We just log the info in the order, and do not change the status, do not notify the customer
				*/
				$order_history['comments'] = vmText::_('VMPAYMENT_PAYPAL_IPN_NOTIFICATION_RECEIVED');
				$order_history['customer_notified'] = 0;
			}
		}
		return $order_history;
	}


        /**
         * Check if the action is the same received from api
         * @param type $paynl_data
         * @return boolean
         */
	protected function validateIpnContent ($paynl_data) {
          if (!class_exists('Pay_Api_Info')) {
              require(JPATH_PLUGINS . '/vmpayment/paynl/paynl/Api.php');
              require(JPATH_PLUGINS . '/vmpayment/paynl/paynl/api/Info.php');
              require(JPATH_PLUGINS . '/vmpayment/paynl/paynl/Helper.php');
              //require(JPATH_SITE . '/plugins/vmpayment/paynl/pay/api/Start.php');
          }
          $payApiInfo = new Pay_Api_Info();
          $payApiInfo->setApiToken($this->_method->token_api);
          $payApiInfo->setServiceId($this->_method->service_id);
          $payApiInfo->setTransactionId($paynl_data['order_id']);
          try{
            $result = $payApiInfo->doRequest();
          }catch(Exception $ex){
              vmError($ex->getMessage());
          }
          $state = Pay_Helper::getStateText($result['paymentDetails']['state']);
          return (strcmp(strtolower($state), strtolower($paynl_data['action']))== 0);
          
	}

	protected function _check_txn_id_already_processed ($payments, $order_id) {

		if ($this->order['details']['BT']->order_status == $this->_method->status_success) {
			foreach ($payments as $payment) {
				$paynl_data = json_decode($payment->paynl_fullresponse);
				if ($paynl_data->order_id == $order_id) {
					return true;
				}
			}
		}
		return false;
	}

	protected function _check_email_amount_currency ($payments, $paypal_data) {

		/*
		 * TODO Not checking yet because config do not have primary email address
		* Primary email address of the payment recipient (that is, the merchant).
		* If the payment is sent to a non-primary email address on your PayPal account,
		* the receiver_email is still your primary email.
		*/

		if ($this->_method->paypalproduct == "std") {
			if (strcasecmp($paypal_data['business'], $this->merchant_email) != 0) {
				$errorInfo = array("paypal_data" => $paypal_data, 'merchant_email' => $this->merchant_email);
				$this->debugLog($errorInfo, 'IPN notification: wrong merchant_email', 'error', false);
				return false;
			}
		}
		$result = false;
		if ($this->_method->paypalproduct == "std" and $paypal_data['txn_type'] == 'cart') {
			if (abs($payments[0]->payment_order_total - $paypal_data['mc_gross'] < abs($paypal_data['mc_gross'] * 0.001)) and ($this->currency_code_3 == $paypal_data['mc_currency'])) {
				$result = TRUE;
			}
		} else {
			if (($payments[0]->payment_order_total == $paypal_data['mc_gross']) and ($this->currency_code_3 == $paypal_data['mc_currency'])) {
				$result = TRUE;
			}
		}
		if (!$result) {
			$errorInfo = array(
				"paypal_data" => $paypal_data,
				'payment_order_total' => $payments[0]->payment_order_total,
				'currency_code_3' => $this->currency_code_3
			);
			$this->debugLog($errorInfo, 'IPN notification with invalid amount or currency or email', 'error', false);
		}
		return $result;
	}

	static function getPaypalCreditCards () {
		return array(
			'Visa',
			'Mastercard',
			'Amex',
			'Discover',
			'Maestro',
		);

	}

	function  _is_full_refund ($payment, $paypal_data) {
		if (($payment->payment_order_total == (-1 * $paypal_data['mc_gross']))) {
			return TRUE;
		} else {
			return FALSE;
		}
	}

	function handleResponse () {
		if ($this->response) {
			if ($this->response['ACK'] == 'Failure' || $this->response['ACK'] == 'FailureWithWarning') {

				$error = '';
				$public_error = '';

				for ($i = 0; isset($this->response["L_ERRORCODE" . $i]); $i++) {
					$error .= $this->response["L_ERRORCODE" . $i];
					$message = isset($this->response["L_LONGMESSAGE" . $i]) ? $this->response["L_LONGMESSAGE" . $i] : $this->response["L_SHORTMESSAGE" . $i];
					$error .= ": " . $message . "<br />";
				}
				if ($this->_method->debug) {
					$public_error = $error;
				}
				$this->debugLog($this->response, 'handleResponse:', 'debug');
				VmError($error, $public_error);

				return false;
			} elseif ($this->response['ACK'] == 'Success' || $this->response['ACK'] == 'SuccessWithWarning' || $this->response['TRANSACTIONID'] != NULL || $this->response['PAYMENTINFO_0_TRANSACTIONID'] != NULL) {
				return true;
			} else {
				// Unexpected ACK type. Log response and inform the buyer that the
				// transaction must be manually investigated.
				$error = '';
				$public_error = '';
				$error = "Unexpected ACK type:" . $this->response['ACK'];
				$this->debugLog($this->response, 'Unexpected ACK type:', 'debug');
				if ($this->_method->debug) {
					$public_error = $error;
				}
				VmError($error, $public_error);
				return false;
			}

		}
	}

	function onShowOrderBEPayment ($data) {

		$showOrderBEFields = $this->getOrderBEFields();
		$prefix = 'PAYPAL_RESPONSE_';

		$html = '';
		if ($data->ACK == 'SuccessWithWarning' && $data->L_ERRORCODE0 == self::FMF_PENDED_ERROR_CODE && $data->PAYMENTSTATUS == "Pending"
		) {
			$showOrderField = 'L_SHORTMESSAGE0';
			$html .= $this->paypalPlugin->getHtmlRowBE($prefix . $showOrderField, $this->highlight($data->$showOrderField));
		}
		if (($data->ACK == 'Failure' OR $data->ACK == 'FailureWithWarning')) {
			$showOrderField = 'L_SHORTMESSAGE0';
			$html .= $this->paypalPlugin->getHtmlRowBE($prefix . 'ERRORMSG', $this->highlight($data->$showOrderField));
			$showOrderField = 'L_LONGMESSAGE0';
			$html .= $this->paypalPlugin->getHtmlRowBE($prefix . 'ERRORMSG', $this->highlight($data->$showOrderField));
		}


		foreach ($showOrderBEFields as $key => $showOrderBEField) {
			if (($showOrderBEField == 'PAYMENTINFO_0_REASONCODE' and $data->$showOrderBEField != 'None') OR
				($showOrderBEField == 'PAYMENTINFO_0_ERRORCODE' and $data->$showOrderBEField != 0)  OR
				($showOrderBEField != 'PAYMENTINFO_0_REASONCODE'  and $showOrderBEField != 'PAYMENTINFO_0_ERRORCODE')
			) {
				if (isset($data->$showOrderBEField)) {
					$key = $prefix . $key;
					$html .= $this->paypalPlugin->getHtmlRowBE($key, $data->$showOrderBEField);
				}
			}

		}

		return $html;
	}

	function onShowOrderBEPaymentByFields ($payment) {
		return NULL;
	}

	/*********************/
	/* Log and Reporting */
	/*********************/
	public function debug ($subject, $title = '', $echo = true) {

		$debug = '<div style="display:block; margin-bottom:5px; border:1px solid red; padding:5px; text-align:left; font-size:10px;white-space:nowrap; overflow:scroll;">';
		$debug .= ($title) ? '<br /><strong>' . $title . ':</strong><br />' : '';
		//$debug .= '<pre>';
		$debug .= str_replace("=>", "&#8658;", str_replace("Array", "<font color=\"red\"><b>Array</b></font>", nl2br(str_replace(" ", " &nbsp; ", print_r($subject, true)))));
		//$debug .= '</pre>';
		$debug .= '</div>';
		if ($echo) {
			echo $debug;
		} else {
			return $debug;
		}
	}

	function highlight ($string) {
		return '<span style="color:red;font-weight:bold">' . $string . '</span>';
	}

	public function debugLog ($message, $title = '', $type = 'message', $echo = false, $doVmDebug = false) {

	

		if ($this->_method->debug) {
			$this->debug($message, $title, true);
		}

		if ($echo) {
			echo $message . '<br/>';
		}


		$this->paypalPlugin->debugLog($message, $title, $type, $doVmDebug);
	}


}
