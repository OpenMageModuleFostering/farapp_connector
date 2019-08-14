<?php
/**
 */
//include_once(__DIR__."/../../../../core/Mage/ImportExport/controllers/Adminhtml/ImportController.php");
class FarApp_Connector_ImportController extends Mage_Core_Controller_Front_Action // Mage_Adminhtml_Controller_Action //Mage_ImportExport_Adminhtml_ImportController
{
    /**
     * Check access (in the ACL) for current user.
     *
     * @return bool
     */
    protected function _isAllowed()
    {
        return Mage::getSingleton('admin/session')->isAllowed('system/convert/import');
    }

    /**
     * Index action.
     *
     * @return void
     */
    public function indexAction()
    {
        error_reporting(E_ALL);
        ini_set('display_errors', '1');
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
        if (!$apiModel->isAllowed('system/convert/import')) {
            echo $this->__('You don\'t have permission to import data.');
            return;
        }
        if ($data) {
            /** @var $importModel Mage_ImportExport_Model_Import */
            $importModel = Mage::getModel('farapp_connector/import');

            // validate file
            try {
                $tmpDir = Mage::getConfig()->getOptions()->getMediaDir() . '/import';
                if (!is_writable($tmpDir)) {
                    @mkdir($tmpDir, 0777, true);
                }
                $validationResult = $importModel->validateSource($importModel->setData($data)->uploadSource());
                if (!$importModel->getProcessedRowsCount()) {
                    echo $this->__('File does not contain data. Please import another one.');
                    return;
                } else {
                    if (!$validationResult) {
                        $stop = true;
                        if ($importModel->getProcessedRowCount() == $importModel->getInvalidRowsCount()) {
                            echo $this->__('File is totally invalid. Please fix errors and re-upload file.').'<br />';
                        } elseif ($importModel->getErrorsCount() >= $importModel->getErrorsLimit()) {
                            echo $this->__(
                                'Errors limit (%d) reached. Please fix errors and re-upload file',
                                $importModel->getErrorsLimit()
                            ).'<br />';
                        } else {
                            if ($importModel->isImportAllowed()) {
                                echo $this->__(
                                    'Skipping %d rows with errors.',
                                    $importModel->getErrorsCount()
                                ).'<br />';
                                $stop = false;
                            } else {
                                echo $this->__('File is partially valid, but import is not possible').'<br />';
                            }
                        }
                        // errors info
                        foreach ($importModel->getErrors() as $errorCode => $rows) {
                            $error = $errorCode . ' ' . $this->__('in rows:') . ' ' . implode(', ', $rows).'<br />';
                            echo $error;
                        }
                        if ($stop) {
                            return;
                        }
                    } else {
                        if (!$importModel->isImportAllowed()) {
                            echo $this->__('File is valid, but import is not possible').'<br />';
                        }
                    }
                    $notices = $importModel->getNotices();
                    if (!empty($notices)) {
                        echo $notices;
                    }
                    echo $this->__(
                        'Checked rows: %d, checked entities: %d, invalid rows: %d, total errors: %d',
                        $importModel->getProcessedRowsCount(), $importModel->getProcessedEntitiesCount(),
                        $importModel->getInvalidRowsCount(), $importModel->getErrorsCount()
                    ).'<br />';
                }
            } catch (Exception $e) {
                echo $e->getMessage();
                return;
            }

            try {
                $importModel->importSource();
                $importModel->invalidateIndex();
            } catch (Exception $e) {
                echo $e->getMessage();
                return;
            }
            echo $this->__('Import successfully done.');
        } else {
            echo $this->__('Didn\'t receive file for import.');
        }
    }
}
