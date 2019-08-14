<?php
class FarApp_Connector_Model_Order_Creditmemo_Api extends Mage_Sales_Model_Order_Creditmemo_Api
{
    public function create($orderIncrementId, $creditmemoData = null, $comment = null, $notifyCustomer = false,
        $includeComment = false, $refundToStoreCreditAmount = null, $online = true)
    {
        /** @var $order Mage_Sales_Model_Order */
        $order = Mage::getModel('sales/order')->load($orderIncrementId, 'increment_id');
        if (!$order->getId()) {
            $this->_fault('order_not_exists');
        }
        if (!$order->canCreditmemo()) {
            $this->_fault('cannot_create_creditmemo');
        }
        $creditmemoData = $this->_prepareCreateData($creditmemoData);

        /** @var $service Mage_Sales_Model_Service_Order */
        $service = Mage::getModel('sales/service_order', $order);
        /** @var $creditmemo Mage_Sales_Model_Order_Creditmemo */
        if ($online) {
            $invoices = $order->getInvoiceCollection();
            if ($invoices) {
                $creditmemo = $service->prepareInvoiceCreditmemo($invoices->getFirstItem(), $creditmemoData);
            } else {
                $creditmemo = $service->prepareCreditmemo($creditmemoData);
            }
        }
        else {
            $creditmemo = $service->prepareCreditmemo($creditmemoData);
        }

        // refund to Store Credit
        if ($refundToStoreCreditAmount) {
            // check if refund to Store Credit is available
            if ($order->getCustomerIsGuest()) {
                $this->_fault('cannot_refund_to_storecredit');
            }
            $refundToStoreCreditAmount = max(
                0,
                min($creditmemo->getBaseCustomerBalanceReturnMax(), $refundToStoreCreditAmount)
            );
            if ($refundToStoreCreditAmount) {
                $refundToStoreCreditAmount = $creditmemo->getStore()->roundPrice($refundToStoreCreditAmount);
                $creditmemo->setBaseCustomerBalanceTotalRefunded($refundToStoreCreditAmount);
                $refundToStoreCreditAmount = $creditmemo->getStore()->roundPrice(
                    $refundToStoreCreditAmount*$order->getStoreToOrderRate()
                );
                // this field can be used by customer balance observer
                $creditmemo->setBsCustomerBalTotalRefunded($refundToStoreCreditAmount);
                // setting flag to make actual refund to customer balance after credit memo save
                $creditmemo->setCustomerBalanceRefundFlag(true);
            }
        }
        if ($online) {
            $creditmemo->register();
        }
        else {
            $creditmemo->setPaymentRefundDisallowed(true)->register();
        }
        // add comment to creditmemo
        if (!empty($comment)) {
            $creditmemo->addComment($comment, $notifyCustomer);
        }
        try {
            if ($online) {
                $transactionSave = Mage::getModel('core/resource_transaction')
                    ->addObject($creditmemo)
                    ->addObject($order);
                if ($creditmemo->getInvoice()) {
                    $transactionSave->addObject($creditmemo->getInvoice());
                }
                $transactionSave->save();
            }
            else {
                Mage::getModel('core/resource_transaction')
                    ->addObject($creditmemo)
                    ->addObject($order)
                    ->save();
            }
            // send email notification
            $creditmemo->sendEmail($notifyCustomer, ($includeComment ? $comment : ''));
        } catch (Mage_Core_Exception $e) {
            $this->_fault('data_invalid', $e->getMessage());
        }
        return $creditmemo->getIncrementId();
    }
}
