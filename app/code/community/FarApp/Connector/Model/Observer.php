<?php

/**
 * Observer to handle event
 * Sends JSON data to URL specified in extensions admin settings
 *
 */
class FarApp_Connector_Model_Observer {

    /**
     * Used to ensure the event is not fired multiple times
     * http://magento.stackexchange.com/questions/7730/sales-order-save-commit-after-event-triggered-twice
     *
     * @var bool
     */
    private $_processFlag = false;

    /**
     * Posts order
     *
     * @param Varien_Event_Observer $observer
     * @return GoMedia_Webhook_Model_Observer
     */
    public function postOrder($observer) {

        // make sure this has not already run
        if (!$this->_processFlag) {

            /** @var $order Mage_Sales_Model_Order */
            $order = $observer->getEvent()->getOrder();
            $orderStatus = $order->getStatus();
            //$url = Mage::getStoreConfig('connector/order/url', $order['store_id']);
            $url = Mage::getStoreConfig('connector/order/url');
            if (!is_null($orderStatus) && $url) {
                $data = $this->transformOrder($order);
                $response = $this->proxy($data, $url);

                // save comment
                //$order->addStatusHistoryComment(
                //    'GoMedia Web Hook: Sent, Status: ' . $response->status . " Response: " . $response->body,
                //    false
                //);
                $this->_processFlag = true;
                //$order->save();
            }
        }
        return $this;
    }


    /**
     * Curl data and return body
     *
     * @param $data
     * @param $url
     * @return stdClass $output
     */
    private function proxy($data, $url) {

        $output = new stdClass();
        $ch = curl_init();
        $body = json_encode($data);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            //'Accept: application/json',
            'Content-Type: application/json',
            'Content-Length: ' . strlen($body),
            // http://stackoverflow.com/questions/11359276/php-curl-exec-returns-both-http-1-1-100-continue-and-http-1-1-200-ok-separated-b
            'Expect:' // Remove "HTTP/1.1 100 Continue" from response
        ));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 60 * 2); // 2 minutes to connect
        curl_setopt($ch, CURLOPT_TIMEOUT, 60 * 4); // 8 minutes to fetch the response
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        // ignore cert issues
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        // execute
        $response = curl_exec($ch);
        $output->status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // handle response
        $arr = explode("\r\n\r\n", $response, 2);
        if (count($arr) == 2) {
            $output->header = $arr[0];
            $output->body = $arr[1];
        } else {
            $output->body = "Unexpected response";
        }
        return $output;
    }

    /**
     * Transform order into one data object for posting
     */
    /**
     * @param $orderIn Mage_Sales_Model_Order
     * @return mixed
     */
    private function transformOrder($orderIn) {
        $orderOut = $orderIn->getData();
        $orderOut['order_id'] = $orderOut['entity_id'];
        $orderOut['items'] = array();
        foreach ($orderIn->getAllItems() as $item) {
            $orderOut['items'][] = $item->getData();
        }

        /** @var $customer Mage_Customer_Model_Customer */
        $customer = Mage::getModel('customer/customer')->load($orderIn->getCustomerId());
        $orderOut['customer'] = $customer->getData();
        $orderOut['customer']['customer_id'] = $orderIn->getCustomerId();

        /** @var $shipping_address Mage_Sales_Model_Order_Address*/
        $shipping_address = $orderIn->getShippingAddress();
        if ($shipping_address) {
            $orderOut['shipping_address'] = $shipping_address->getData();
        }

        /** @var $shipping_address Mage_Sales_Model_Order_Address*/
        $billing_address = $orderIn->getBillingAddress();
        if ($billing_address) {
            $orderOut['billing_address'] = $billing_address->getData();
        }

        /** @var $shipping_address Mage_Sales_Model_Order_Payment*/
        $payment = $orderIn->getPayment()->getData();
        $orderOut['payment'] = $payment;

        // remove cc fields
        //foreach ($payment as $key => $value) {
        //    if (strpos($key, 'cc_') !== 0) {
        //        $orderOut['payment'][$key] = $value;
        //    }
        //}

        /** @var $orderOut Mage_Core_Model_Session */
        $session = Mage::getModel('core/session');
        $orderOut['visitor'] = $session->getValidatorData();

        $history = $orderIn->getStatusHistoryCollection();
        $orderOut['status_history'] = $history->getData();

        return $orderOut;
    }
}
