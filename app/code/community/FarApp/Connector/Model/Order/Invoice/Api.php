<?php

class FarApp_Connector_Model_Order_Invoice_Api extends Mage_Sales_Model_Order_Invoice_Api
{
    public function capture($invoiceIncrementId, $offline = false)
    {
        $invoice = Mage::getModel('sales/order_invoice')->loadByIncrementId($invoiceIncrementId);

        /* @var $invoice Mage_Sales_Model_Order_Invoice */

        if (!$invoice->getId()) {
            $this->_fault('not_exists');
        }

        if (!$invoice->canCapture()) {
            $this->_fault('status_not_changed', Mage::helper('sales')->__('Invoice cannot be captured.'));
        }

        try {
            if (!$offline) {
                $invoice->capture();
                $invoice->getOrder()->setIsInProcess(true);
            }
            else {
                $invoice->setCanVoidFlag(false);
                $invoice->pay();
            }
            $transactionSave = Mage::getModel('core/resource_transaction')
                ->addObject($invoice)
                ->addObject($invoice->getOrder())
                ->save();
        } catch (Mage_Core_Exception $e) {
            $this->_fault('status_not_changed', $e->getMessage());
        } catch (Exception $e) {
            $this->_fault('status_not_changed', Mage::helper('sales')->__('Invoice capturing problem.'));
        }

        return true; 
    }
}

?>
