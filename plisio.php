<?php
defined('_JEXEC') or die('Restricted access');
?><?php
class plgHikashoppaymentPlisio extends hikashopPaymentPlugin
{
	var $multiple = true;
	var $name = 'plisio';
	var $doc_form = 'plisio';
	var $apiUrl = 'https://api.plisio.net/api/v1/invoices/new';

	var $pluginConfig = array(
		'api_key' => array('Secret key','input'),
		'invalid_status' => array('INVALID_STATUS', 'orderstatus'),
		'verified_status' => array('VERIFIED_STATUS', 'orderstatus')
	);


	function __construct(&$subject, $config)
	{
		return parent::__construct($subject, $config);
	}


	function onAfterOrderConfirm(&$order,&$methods,$method_id) //On the checkout
	{
		parent::onAfterOrderConfirm($order,$methods,$method_id);

		if (empty($this->payment_params->api_key))
		{
			$this->app->enqueueMessage(JText::sprintf('CONFIGURE_X_PAYMENT_PLUGIN_ERROR','a secret key','Plisio'),'error');
			return false;
		}

		$order_name = $order->order_id.'-'.$order->order_number;
		$source_currency = $this->currency->currency_code;
		$source_amount =$order->cart->full_total->prices[0]->price_value_with_tax;
		$callback_url = HIKASHOP_LIVE.'index.php?option=com_hikashop&ctrl=checkout&task=notify&notif_payment='.$this->name.'&tmpl=component&lang='.$this->locale . $this->url_itemid;
		$cancel_url = HIKASHOP_LIVE.'index.php?option=com_hikashop&ctrl=order&task=cancel_order&order_id='.$order->order_id . $this->url_itemid;

        $api_key = $this->payment_params->api_key;


		$invoiceData = array(
			'order_number' => $order->order_id,
			'order_name' => $order_name,
			'source_amount' => $source_amount,
			'source_currency' => $source_currency,
            'cancel_url' => $cancel_url,
            'callback_url' => $callback_url,
			'email' => $this->user->user_email,
			'plugin' => 'Hikashop',
            'version' => '1.0.0',
            'api_key' => $api_key,
		);

		$session = curl_init();
		$postData = http_build_query($invoiceData, '', '&');
        $queryString = $this->apiUrl . '?' . $postData;

        $curlOptions = array(
            CURLOPT_URL => $queryString,
            CURLOPT_HTTPGET => true,
            CURLOPT_FAILONERROR => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json'
            ],
        );
        curl_setopt_array($session, $curlOptions);
		$data = curl_exec($session);
		$error = curl_errno($session);
        $header_size = curl_getinfo($session, CURLINFO_HEADER_SIZE);
        $body = substr($data, $header_size);
        $dec = json_decode($body, TRUE, 512, JSON_BIGINT_AS_STRING);
		curl_close($session);

        $this->url = $dec['data']['invoice_url'];

		if( $error ) {
			$this->app->enqueueMessage('An error occured: '.$error);
			return false;
		}

        $app = JFactory::getApplication();
        $app->redirect($this->url);
        return true;

	}

	function onPaymentConfiguration(&$element)
	{
		parent::onPaymentConfiguration($element);
	}

	function getPaymentDefaultValues(&$element) //To set the back end default values
	{
		$element->payment_name='Plisio';
		$element->payment_description='You can pay with cryptocurrencies using this payment method';
		$element->payment_params->invalid_status='cancelled';
		$element->payment_params->verified_status='confirmed';
	}


	function onPaymentNotification(&$statuses)
	{
		$vars = array();
		$filter = JFilterInput::getInstance();
		foreach($_REQUEST as $key => $value)
		{
			$key = $filter->clean($key);
			$value = hikaInput::get()->getString($key);
			$vars[$key]=$value;
		}

		$order_id = $vars['order_number'];
		$dbOrder = $this->getOrder($order_id);
		$this->loadPaymentParams($dbOrder);
		if(empty($this->payment_params))
			return false;
		$this->loadOrderData($dbOrder);

        if ($vars['status'] == 'canceled') {
            $this->modifyOrder($order_id, $this->payment_params->invalid_status, true, true);
        } elseif (($vars['status'] == 'completed') || ($vars['status'] == 'mismatch')) {
            $this->modifyOrder($order_id, $this->payment_params->verified_status, true, true);
        } else {
            echo 'Wrong status';
        }

		echo 'OK';
		exit;
	}

}
