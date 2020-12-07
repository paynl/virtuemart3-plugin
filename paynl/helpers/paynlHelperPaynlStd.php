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

//https://cms.paypal.com/mx/cgi-bin/?cmd=_render-content&content_ID=developer/e_howto_html_Appx_websitestandard_htmlvariables

class PaynlHelperPaynlStd extends PaynlHelperPaynl
{

    var $merchant_email = '';

    function __construct($method, $paypalPlugin)
    {
        parent::__construct($method, $paypalPlugin);
    }

    public function ManageCheckout()
    {
        return $this->preparePost();
    }

    public function preparePost()
    {
        if (!class_exists('Pay_Api_Start')) {
            require(JPATH_SITE . '/plugins/vmpayment/paynl/paynl/Api.php');
            require(JPATH_SITE . '/plugins/vmpayment/paynl/paynl/api/Start.php');
        }

        $paynlService = new Pay_Api_Start();
        $paynlService->setServiceId($this->_method->service_id);
        $paynlService->setApiToken($this->_method->token_api);
        $paynlService->setPaymentOptionId($this->_method->payNL_optionList);
        $paynlService->setAmount(round($this->total * 100));
        $paynlService->setDescription(vmText::_('COM_VIRTUEMART_ORDER_NUMBER') . ': ' . $this->order['details']['BT']->order_number);

        $objCurrency = CurrencyDisplay::getInstance($this->_method->payment_currency);
        $strCurrency = $objCurrency->_vendorCurrency_code_3;

        $paynlService->setCurrency($strCurrency);

        $exchangeUrl = JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&format=raw&task=pluginnotification&tmpl=component' . '&lang=' . vRequest::getCmd('lang', '');

        $altExchange = $this->_method->exchange_url;
        if ($altExchange === "1") {
          $exchangeUrl .= '&action=#action#&order_id=#order_id#&extra1=#extra1#';
        }

        $paynlService->setExchangeUrl($exchangeUrl);
        $paynlService->setFinishUrl(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&on=' . $this->order['details']['BT']->order_number . '&pm=' . $this->order['details']['BT']->virtuemart_paymentmethod_id . '&Itemid=' . vRequest::getInt('Itemid') . '&lang =' . vRequest::getCmd('lang', ''));

        //add items
        foreach ($this->order['items'] as $item) {
            $paynlService->addProduct($item->virtuemart_product_id, $item->order_item_name, round($item->product_final_price * 100), $item->product_quantity);
        }

        //coupon
        if ($this->order['details']['BT']->coupon_discount != 0) {
            $paynlService->addProduct('coupon', $this->order['details']['BT']->coupon_code, round($this->order['details']['BT']->coupon_discount * 100), 1);
        }

        //ship
        if ($this->cart->pricesUnformatted['salesPriceShipment'] != 0) {
            $paynlService->addProduct('ship', 'shipment', round($this->cart->pricesUnformatted['salesPriceShipment'] * 100), 1);
        }

        if ($this->cart->pricesUnformatted['salesPricePayment'] > 0) {
            $paynlService->addProduct('payment', $this->_method->payment_name, round($this->cart->pricesUnformatted['salesPricePayment'] * 100), 1);
        }

        //enduser

        $addressBT = $this->splitAddress($this->order['details']['BT']->address_1 . ' ' . $this->order['details']['BT']->address_2);
        $addressST = $this->splitAddress($this->order['details']['ST']->address_1 . ' ' . $this->order['details']['ST']->address_2);

        $jLang = VmConfig::loadJLang('com_virtuemart_orders', TRUE);
        /**
         * @var $jLang JLanguage
         */
        $language = substr($jLang->getTag(), 0, 2);


        $enduser = array(
            'initials' => substr($this->order['details']['BT']->first_name, 0, 1),
            'lastName' => $this->order['details']['BT']->last_name,
            'emailAddress' => $this->order['details']['BT']->email,
            'language' => $language,
            'invoiceAddress' => array(
                'streetName' => $addressBT[0],
                'streetNumber' => $addressBT[1],
                'zipCode' => $this->order['details']['BT']->zip,
                'city' => $this->order['details']['BT']->city,
                'countryCode' => ShopFunctions::getCountryByID($this->order['details']['BT']->virtuemart_country_id, 'country_2_code')
            ),
            'address' => array(
                'initials' => substr($this->order['details']['ST']->first_name, 0, 1),
                'lastName' => $this->order['details']['ST']->last_name,
                'streetName' => $addressST[0],
                'streetNumber' => $addressST[1],
                'city' => $this->order['details']['ST']->city,
                'zipCode' => $this->order['details']['ST']->zip,
                'countryCode' => ShopFunctions::getCountryByID($this->order['details']['ST']->virtuemart_country_id, 'country_2_code')
            )
        );
        $paynlService->setEnduser($enduser);
        $paynlService->setExtra1($this->order['details']['BT']->order_number);
        $paynlService->setExtra2($this->context);
        $paynlService->setObject('virtuemart 3.5');

        try {
            $result = $paynlService->doRequest();
        } catch (Exception $ex) {
            die($ex);
        }
        return $result;
    }

    function initPostVariables($payment_type)
    {

        $address = ((isset($this->order['details']['ST'])) ? $this->order['details']['ST'] : $this->order['details']['BT']);

        $post_variables = Array();
        $post_variables['cmd'] = '_ext-enter';
        $post_variables['redirect_cmd'] = $payment_type;
        $post_variables['paymentaction'] = strtolower($this->_method->payment_action);
        $post_variables['upload'] = '1';
        $post_variables['business'] = $this->merchant_email; //Email address or account ID of the payment recipient (i.e., the merchant).
        $post_variables['receiver_email'] = $this->merchant_email; //Primary email address of the payment recipient (i.e., the merchant
        $post_variables['order_number'] = $this->order['details']['BT']->order_number;
        $post_variables['invoice'] = $this->order['details']['BT']->order_number;
        $post_variables['custom'] = $this->context;
        $post_variables['currency_code'] = $this->currency_code_3;
        if ($payment_type == '_xclick') {
            $post_variables['address_override'] = $this->_method->address_override; // 0 ??   Paypal does not allow your country of residence to ship to the country you wish to
        }
        $post_variables['first_name'] = $address->first_name;
        $post_variables['last_name'] = $address->last_name;
        $post_variables['address1'] = $address->address_1;
        $post_variables['address2'] = isset($address->address_2) ? $address->address_2 : '';
        $post_variables['zip'] = $address->zip;
        $post_variables['city'] = $address->city;
        $post_variables['state'] = isset($address->virtuemart_state_id) ? ShopFunctions::getStateByID($address->virtuemart_state_id, 'state_2_code') : '';
        $post_variables['country'] = ShopFunctions::getCountryByID($address->virtuemart_country_id, 'country_2_code');
        $post_variables['email'] = $this->order['details']['BT']->email;
        $post_variables['night_phone_b'] = $address->phone_1;


        $post_variables['return'] = JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&on=' . $this->order['details']['BT']->order_number . '&pm=' . $this->order['details']['BT']->virtuemart_paymentmethod_id . '&Itemid=' . vRequest::getInt('Itemid') . '&lang=' . vRequest::getCmd('lang', '');
        //Keep this line, needed when testing
        //$post_variables['return'] 		= JRoute::_(JURI::root().'index.php?option=com_virtuemart&view=pluginresponse&task=pluginnotification&tmpl=component'),
        $post_variables['notify_url'] = JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginnotification&tmpl=component' . '&lang=' . vRequest::getCmd('lang', '');
        $post_variables['cancel_return'] = JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginUserPaymentCancel&on=' . $this->order['details']['BT']->order_number . '&pm=' . $this->order['details']['BT']->virtuemart_paymentmethod_id . '&Itemid=' . vRequest::getInt('Itemid') . '&lang=' . vRequest::getCmd('lang', '');

        //$post_variables['undefined_quantity'] = "0";
        //$post_variables['test_ipn'] = $this->_method->debug;
        $post_variables['rm'] = '2'; // the buyer’s browser is redirected to the return URL by using the POST method, and all payment variables are included
        // todo: check when in subdirectories
        // todo add vendor image
        //$post_variables['image_url'] 			= JURI::root() . $vendor->images[0]->file_url;
        $post_variables['bn'] = self::BNCODE;

        $post_variables['no_shipping'] = $this->_method->no_shipping;
        $post_variables['no_note'] = "1";

        if (empty($this->_method->headerimg) OR $this->_method->headerimg == -1) {
            $post_variables['image_url'] = $this->getLogoImage();
        } else {
            $post_variables['cpp_header_image'] = JURI::base() . 'images/stories/virtuemart/payment/' . $this->_method->headerimg;
        }
        /*
         * The HTML hex code for your principal identifying color.
* Valid only for Buy Now and Add to Cart buttons and the Cart Upload command.
* Not used with Subscribe, Donate, or Buy Gift Certificate buttons.
         */
        if ($this->_method->bordercolor) {
            $post_variables['cpp_cart_border_color'] = str_replace('#', '', strtoupper($this->_method->bordercolor));
        }
// TODO Check that paramterer
        /*
         * cpp_payflow_color The background color for the checkout page below the header.
         * Deprecated for Buy Now and Add to Cart buttons and the Cart Upload command
         *
         */
        //	$post_variables['cpp_payflow_color'] = 'ff0033';

        return $post_variables;
    }

    function addPrices(&$post_variables)
    {

        $paymentCurrency = CurrencyDisplay::getInstance($this->_method->payment_currency);

        $i = 1;
        // Product prices
        if ($this->cart->products) {
            foreach ($this->cart->products as $key => $product) {
                $post_variables["item_name_" . $i] = $this->getItemName($product->product_name);
                if ($product->product_sku) {
                    $post_variables["item_number_" . $i] = $product->product_sku;
                }
                $post_variables["amount_" . $i] = $this->getProductAmount($this->cart->pricesUnformatted[$key]);
                $post_variables["quantity_" . $i] = $product->quantity;
                $i++;
            }
        }

        $post_variables["handling_cart"] = $this->getHandlingAmount();
        $post_variables["handling_cart"] += vmPSPlugin::getAmountValueInCurrency($this->cart->pricesUnformatted['salesPriceShipment'], $this->_method->payment_currency);
        $post_variables['currency_code'] = $this->currency_code_3;
        if (!empty($this->cart->pricesUnformatted['salesPriceCoupon'])) {
            $post_variables['discount_amount_cart'] = abs(vmPSPlugin::getAmountValueInCurrency($this->cart->pricesUnformatted['salesPriceCoupon'], $this->_method->payment_currency));
        }
        $pricesCurrency = CurrencyDisplay::getInstance($this->cart->pricesCurrency);
    }

    function getExtraPluginInfo()
    {
        return;
    }

    function getOrderBEFields()
    {
        $showOrderBEFields = array(
            'TXN_ID' => 'txn_id',
            'PAYER_ID' => 'payer_id',
            'PAYER_STATUS' => 'payer_status',
            'PAYMENT_TYPE' => 'payment_type',
            'MC_GROSS' => 'mc_gross',
            'MC_FEE' => 'mc_fee',
            'TAXAMT' => 'tax',
            'MC_CURRENCY' => 'mc_currency',
            'PAYMENT_STATUS' => 'payment_status',
            'PENDING_REASON' => 'pending_reason',
            'REASON_CODE' => 'reason_code',
            'PROTECTION_ELIGIBILITY' => 'protection_eligibility',
            'ADDRESS_STATUS' => 'address_status'
        );


        return $showOrderBEFields;
    }

    function onShowOrderBEPaymentByFields($payment)
    {
        $prefix = "paypal_response_";
        $html = "";
        $showOrderBEFields = $this->getOrderBEFields();
        foreach ($showOrderBEFields as $key => $showOrderBEField) {
            $field = $prefix . $showOrderBEField;
            // only displays if there is a value or the value is different from 0.00 and the value
            if ($payment->$field) {
                $html .= $this->paypalPlugin->getHtmlRowBE($prefix . $key, $payment->$field);
            }
        }


        return $html;
    }

    function splitAddress($strAddress)
    {
        $strAddress = trim($strAddress);
        $a = preg_split('~^(.*)\s(\d+)\W+(\d+)$~', $strAddress, 2, PREG_SPLIT_DELIM_CAPTURE);
        $strStreetName = trim(array_shift($a));
        $strStreetNumber = trim(implode('', $a));

        if (empty($strStreetName)) { // American address notation
            $a = preg_split('/([a-zA-Z]{2,})/', $strAddress, 2, PREG_SPLIT_DELIM_CAPTURE);

            $strStreetNumber = trim(implode('', $a));
            $strStreetName = trim(array_shift($a));
        }

        return array($strStreetName, $strStreetNumber);
    }
}