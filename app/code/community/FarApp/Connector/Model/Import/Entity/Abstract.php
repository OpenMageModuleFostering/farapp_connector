<?php
/**
 */
class FarApp_Connector_Model_Import_Entity_Abstract extends Mage_ImportExport_Model_Import_Entity_Abstract
{
    protected function _saveValidatedBunches()
    {
        $source          = $this->_getSource();
        $productDataSize = 0;
        $bunchRows       = array();
        $startNewBunch   = false;
        $nextRowBackup   = array();
        $maxDataSize = Mage::getResourceHelper('importexport')->getMaxDataSize();
        $bunchSize = Mage::helper('importexport')->getBunchSize();

        $source->rewind();
        $this->_dataSourceModel->cleanBunches();

        while ($source->valid() || $bunchRows) {
            if ($startNewBunch || !$source->valid()) {
                if ($this->getEntityTypeCode() == 'catalog_product' && $startNewBunch && !array_values($nextRowBackup)[0]['sku']) {
                    $arrKeys = array_keys($bunchRows);
                    $arrNew  = array();
                    while(($tRow = array_pop($bunchRows))) {
                        $tKey = array_pop($arrKeys);
                        $arrNew[$tKey] = $tRow;
                        if ($tRow['sku']) {
                            break;
                        }
                    }
                    $nextRowBackup = array_reverse($arrNew, TRUE) + $nextRowBackup;
                }
                if ($bunchRows) {
                    $this->_dataSourceModel->saveBunch($this->getEntityTypeCode(), $this->getBehavior(), $bunchRows);
                }

                $bunchRows       = $nextRowBackup;
                $productDataSize = strlen(serialize($bunchRows));
                $startNewBunch   = false;
                $nextRowBackup   = array();
            }
            if ($source->valid()) {
                if ($this->_errorsCount >= $this->_errorsLimit) { // errors limit check
                    return;
                }
                $rowData = $source->current();

                $this->_processedRowsCount++;

                if ($this->validateRow($rowData, $source->key())) { // add row to bunch for save
                    $rowData = $this->_prepareRowForDb($rowData);
                    $rowSize = strlen(Mage::helper('core')->jsonEncode($rowData));

                    $isBunchSizeExceeded = ($bunchSize > 0 && count($bunchRows) >= $bunchSize);

                    if (($productDataSize + $rowSize) >= $maxDataSize || $isBunchSizeExceeded) {
                        $startNewBunch = true;
                        $nextRowBackup = array($source->key() => $rowData);
                    } else {
                        $bunchRows[$source->key()] = $rowData;
                        $productDataSize += $rowSize;
                    }
                }
                $source->next();
            }
        }
        return $this;
    }
}
