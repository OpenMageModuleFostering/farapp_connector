<?php
/**
 */
//include_once(__DIR__."/../../../../core/Mage/ImportExport/controllers/Adminhtml/ExportController.php");
class FarApp_Connector_ExportController extends Mage_Core_Controller_Front_Action // Mage_Adminhtml_Controller_Action //Mage_ImportExport_Adminhtml_ExportController
{
    /**
     * Check access (in the ACL) for current user.
     *
     * @return bool
     */
    protected function _isAllowed()
    {
        return Mage::getSingleton('admin/session')->isAllowed('system/convert/export');
    }

    /**
     * Index action.
     *
     * @return void
     */
    public function indexAction()
    {
        $data = $this->getRequest()->getPost();
        if (!array_key_exists('username', $data)) {
            echo 'Username required in posted data.';
            return;
        }
        if (!array_key_exists('apikey', $data)) {
            echo 'API key required in posted data.';
            return;
        }
        $apiModel = Mage::getModel('api/session');
        try {
            $apiModel->login($data['username'], $data['apikey']);
        } catch (exception $e) {
            echo $this->__('Login failed.');
            return;
        }
        if (!$apiModel->isAllowed('system/convert/export')) {
            echo $this->__('You don\'t have permission to export data.');
            return;
        }
        if ($data['entity'] == 'order') {
            //error_reporting(E_ALL);
            //ini_set('display_errors', '1');
            $model = Mage::getModel('farapp_connector/export');
            $result = $model
                ->setIncludePayment(true)
                ->setIncludeShipment(true)
                ->setIncludeAddresses(true)
                ->setIncludeItems(true)
                ->processOrderExport();
            //var_dump($result);
            $resultStr = '';
            $csv = array();
            $baseFields = array();
            $shippingAddrFields = array();
            $billingAddrFields = array();
            $itemFields = array();
            $paymentFields = array();
            $shipmentFields = array();
            foreach ($result as $row) {
                if (!array_key_exists('shipping_address', $row) ||
                    !array_key_exists('billing_address', $row) ||
                    !array_key_exists('items', $row) ||
                    !array_key_exists('payment', $row) ||
                    !array_key_exists('shipments', $row)) {
                    continue;
                }
                $csvrow = array();
                foreach ($row as $key => $val) {
                    if ($key == 'shipping_address' || $key == 'billing_address' || $key == 'items' || $key == 'payment' || $key == 'shipments') {
                        continue;
                    }
                    $csvrow[] = '"' . $key . '"';
                    $baseFields[] = $key;
                }
                foreach ($row['shipping_address'] as $key => $val) {
                    $csvrow[] = '"_shipping_address_' . $key . '"';
                    $shippingAddrFields[] = $key;
                }
                foreach ($row['billing_address'] as $key => $val) {
                    $csvrow[] = '"_billing_address_' . $key . '"';
                    $billingAddrFields[] = $key;
                }
                if (isset($row['items'])) {
                    $item = $row['items'][0];
                    foreach ($item as $key => $val) {
                        $csvrow[] = '"_item_' . $key . '"';
                        $itemFields[] = $key;
                    }
                }
                foreach ($row['payment'] as $key => $val) {
                    $csvrow[] = '"_payment_' . $key . '"';
                    $paymentFields[] = $key;
                }
                if (isset($row['shipments'])) {
                    $shipment = $row['shipments'][0];
                    foreach ($shipment as $key => $val) {
                        $csvrow[] = '"_shipment_' . $key . '"';
                        $shipmentFields[] = $key;
                    }
                }
                $csv[] = $csvrow;
                break;
            }
            foreach ($result as $row) {
                $csvrow = array();
                foreach ($baseFields as $key) {
                    $csvrow[] = '"' . $row[$key] . '"';
                }
                foreach ($shippingAddrFields as $key) {
                    $csvrow[] = '"' . $row['shipping_address'][$key] . '"';
                }
                foreach ($billingAddrFields as $key) {
                    $csvrow[] = '"' . $row['billing_address'][$key] . '"';
                }
                if (isset($row['items'])) {
                    $item = $row['items'][0];
                    foreach ($itemFields as $key) {
                        $csvrow[] = '"' . str_replace('"', '""', $item[$key]) . '"';
                    }
                }
                foreach ($paymentFields as $key) {
                    $csvrow[] = '"' . $paymentFields[$key] . '"';
                }
                if (isset($row['shipments'])) {
                    $shipment = $row['shipments'][0];
                    foreach ($shipmentFields as $key) {
                        $csvrow[] = '"' . $shipment[$key] . '"';
                    }
                }
                $csv[] = $csvrow;
                for ($i = 1; $i < max(count($row['items']), count($row['shipments'])); $i++) {
                    $csvrow = array();
                    for ($j = 0; $j < count($baseFields)+count($shippingAddrFields)+count($billingAddrFields); $j++) {
                        $csvrow[] = '""';
                    }
                    if ($i < count($row['items'])) {
                        $item = $row['items'][$i];
                        foreach ($itemFields as $key) {
                            $csvrow[] = '"' . str_replace('"', '""', $item[$key]) . '"';
                        }
                    }
                    else {
                        for ($j = 0; $j < count($itemFields); $j++) {
                            $csvrow[] = '""';
                        }
                    }
                    for ($j = 0; $j < count($paymentFields); $j++) {
                        $csvrow[] = '""';
                    }
                    if ($i < count($row['shipments'])) {
                        $shipment = $row['shipments'][$i];
                        foreach ($shipmentFields as $key) {
                            $csvrow[] = '"' . $shipment[$key] . '"';
                        }
                    }
                    else {
                        for ($j = 0; $j < count($shipmentFields); $j++) {
                            $csvrow[] = '""';
                        }
                    }
                    $csv[] = $csvrow;
                }
            }
            foreach ($csv as $idx => $csvrow) {
                echo implode(',', $csvrow) . "\n";
            }
            return;
        }

        try {
            /** @var $model Mage_ImportExport_Model_Export */
            $model = Mage::getModel('importexport/export');
            $model->setData($this->getRequest()->getParams());

            return $this->_prepareDownloadResponse(
                $model->getFileName(),
                $model->export(),
                $model->getContentType()
            );
        } catch (Mage_Core_Exception $e) {
            echo $e->getMessage();
            $this->_getSession()->addError($e->getMessage());
        } catch (Exception $e) {
            echo $this->__('No valid data sent');
            Mage::logException($e);
            $this->_getSession()->addError($this->__('No valid data sent'));
        }
        return;

        if ($data) {
            /** @var $exportModel Mage_ImportExport_Model_Export */
            $exportModel = Mage::getModel('farapp_connector/export');

            // validate file
            try {
                $tmpDir = Mage::getConfig()->getOptions()->getMediaDir() . '/export';
                if (!is_writable($tmpDir)) {
                    @mkdir($tmpDir, 0777, true);
                }
                $validationResult = $exporModel->validateSource($exportModel->setData($data)->uploadSource());
                if (!$exporModel->getProcessedRowsCount()) {
                    echo $this->__('File does not contain data. Please export another one.');
                    return;
                } else {
                    if (!$validationResult) {
                        $stop = true;
                        if ($exportModel->getProcessedRowCount() == $exportModel->getInvalidRowsCount()) {
                            echo $this->__('File is totally invalid. Please fix errors and re-upload file.').'<br />';
                        } elseif ($exportModel->getErrorsCount() >= $exportModel->getErrorsLimit()) {
                            echo $this->__(
                                'Errors limit (%d) reached. Please fix errors and re-upload file',
                                $exportModel->getErrorsLimit()
                            ).'<br />';
                        } else {
                            if ($exportModel->isExportAllowed()) {
                                echo $this->__(
                                    'Skipping %d rows with errors.',
                                    $exportModel->getErrorsCount()
                                ).'<br />';
                                $stop = false;
                            } else {
                                echo $this->__('File is partially valid, but export is not possible').'<br />';
                            }
                        }
                        // errors info
                        foreach ($exportModel->getErrors() as $errorCode => $rows) {
                            $error = $errorCode . ' ' . $this->__('in rows:') . ' ' . implode(', ', $rows).'<br />';
                            echo $error;
                        }
                        if ($stop) {
                            return;
                        }
                    } else {
                        if (!$exportModel->isExportAllowed()) {
                            echo $this->__('File is valid, but export is not possible').'<br />';
                        }
                    }
                    $notices = $exportModel->getNotices();
                    if (!empty($notices)) {
                        echo $notices;
                    }
                    echo $this->__(
                        'Checked rows: %d, checked entities: %d, invalid rows: %d, total errors: %d',
                        $exportModel->getProcessedRowsCount(), $exportModel->getProcessedEntitiesCount(),
                        $exportModel->getInvalidRowsCount(), $exportModel->getErrorsCount()
                    ).'<br />';
                }
            } catch (Exception $e) {
                echo $e->getMessage();
                return;
            }

            try {
                $exportModel->exportSource();
                $exportModel->invalidateIndex();
            } catch (Exception $e) {
                echo $e->getMessage();
                return;
            }
            echo $this->__('Export successfully done.');
        } else {
            echo $this->__('Didn\'t receive file for export.');
        }
    }
}
