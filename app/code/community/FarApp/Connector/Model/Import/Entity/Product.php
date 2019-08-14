<?php
/**
 */
class FarApp_Connector_Model_Import_Entity_Product extends Mage_ImportExport_Model_Import_Entity_Product
{
    public function __construct()
    {
        parent::__construct();
        $this->_errorsLimit = 1000000000;
    }

    protected function _createAttributeOption($attrType, $attrCode, array $rowData, $rowNum)
    {
        /** @var $attribute Mage_Eav_Model_Entity_Attribute */
        $attribute = Mage::getSingleton('catalog/product')->getResource()->getAttribute($attrCode);
        if (!is_object($attribute)) {
            $this->addRowError(Mage::helper('importexport')->__('Attribute ' . $attrCode . ' not found.'), $rowNum);
            return false;
        }
        if ($attrType == 'select' && $attribute->getSourceModel() != 'eav/entity_attribute_source_table') {
            $this->addRowError(Mage::helper('importexport')->__('Attribute ' . $attrCode . ' is no dropdown attribute.'), $rowNum);
            return false;
        }
        elseif ($attrType == 'multiselect' && $attribute->getBackendModel() != 'eav/entity_attribute_backend_array') {
            $this->addRowError(Mage::helper('importexport')->__('Attribute ' . $attrCode . ' is no multiselect attribute.'), $rowNum);
            return false;
        }

        $option = array(
            'value' => array(
                array('0' => $rowData[$attrCode])
            ),
            'order' => array(0),
            'delete' => array('')
        );

        $attribute->setOption($option);
        $attribute->save();

        $this->_initTypeModels();

        return true;
    }

    public function isAttributeValid($attrCode, array $attrParams, array $rowData, $rowNum)
    {
        switch ($attrParams['type']) {
            case 'select':
            case 'multiselect':
                $valid = isset($attrParams['options'][strtolower($rowData[$attrCode])]);
                if (!$valid) {
                    $valid = $this->_createAttributeOption($attrParams['type'], $attrCode, $rowData, $rowNum);
                    if ($valid) {
                        $attrParams['options'][] = strtolower($rowData[$attrCode]);
                    }
                } elseif (!empty($attrParams['is_unique'])) {
                    if (isset($this->_uniqueAttributes[$attrCode][$rowData[$attrCode]])) {
                        $this->addRowError(Mage::helper('importexport')->__("Duplicate Unique Attribute for '%s'"), $rowNum, $attrCode);
                        return false;
                    }
                    $this->_uniqueAttributes[$attrCode][$rowData[$attrCode]] = true;
                }
                break;
            default:
                $valid = parent::isAttributeValid($attrCode, $attrParams, $rowData, $rowNum);
                break;
        }

        return (bool) $valid;
    }

    protected function _uploadMediaFiles($fileName)
    {
        $correctedBaseName = Mage_Core_Model_File_Uploader::getCorrectFileName(basename($fileName));
        $fullTempPath = $this->_getUploader()->getTmpDir() . DS . $correctedBaseName;
        $destPath = $this->_getUploader()->correctFileNameCase(Mage_Core_Model_File_Uploader::getDispretionPath(basename($fileName)) . DS . $correctedBaseName);
        $fullDestPath = $this->_getUploader()->getDestDir() . DS . $destPath;
        if (!is_file($fullDestPath)) {
            if (!is_file($fullTempPath) && strpos($fileName, 'http') === 0 && strpos($fileName, '://') !== false) {
                try {
                    $dir = $this->_getUploader()->getTmpDir();
                    if (!is_dir($dir)) {
                        mkdir($dir);
                    }
                    $fileHandle = fopen($fullTempPath, 'w+');
                    $ch = curl_init($fileName);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 50);
                    curl_setopt($ch, CURLOPT_FILE, $fileHandle);
                    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                    curl_exec($ch);
                    curl_close($ch);
                    fclose($fileHandle);
                } catch (Exception $e) {
                    return '';
                }
            }
            return parent::_uploadMediaFiles($correctedBaseName);
        }
        else {
            return $destPath;
        }
    }

    protected function _saveLinks()
    {
        $resource       = Mage::getResourceModel('catalog/product_link');
        $mainTable      = $resource->getMainTable();
        $positionAttrId = array();
        /** @var Varien_Db_Adapter_Interface $adapter */
        $adapter = $this->_connection;

        // pre-load 'position' attributes ID for each link type once
        foreach ($this->_linkNameToId as $linkName => $linkId) {
            $select = $adapter->select()
                ->from(
                    $resource->getTable('catalog/product_link_attribute'),
                    array('id' => 'product_link_attribute_id')
                )
                ->where('link_type_id = :link_id AND product_link_attribute_code = :position');
            $bind = array(
                ':link_id' => $linkId,
                ':position' => 'position'
            );
            $positionAttrId[$linkId] = $adapter->fetchOne($select, $bind);
        }
        $nextLinkId = Mage::getResourceHelper('importexport')->getNextAutoincrement($mainTable);
        while ($bunch = $this->_dataSourceModel->getNextBunch()) {
            $productIds   = array();
            $linkRows     = array();
            $positionRows = array();

            foreach ($bunch as $rowNum => $rowData) {
                $this->_filterRowData($rowData);
                if (!$this->isRowAllowedToImport($rowData, $rowNum)) {
                    continue;
                }
                if (self::SCOPE_DEFAULT == $this->getRowScope($rowData)) {
                    $sku = $rowData[self::COL_SKU];
                }
                foreach ($this->_linkNameToId as $linkName => $linkId) {
                    $productId    = $this->_newSku[$sku]['entity_id'];
                    $productIds[] = $productId;
                    if (isset($rowData[$linkName . 'sku'])) {
                        $linkedSku = $rowData[$linkName . 'sku'];

                        if ((isset($this->_newSku[$linkedSku]) || isset($this->_oldSku[$linkedSku]))
                                && $linkedSku != $sku) {
                            if (isset($this->_newSku[$linkedSku])) {
                                $linkedId = $this->_newSku[$linkedSku]['entity_id'];
                            } else {
                                $linkedId = $this->_oldSku[$linkedSku]['entity_id'];
                            }
                            $linkKey = "{$productId}-{$linkedId}-{$linkId}";

                            if (!isset($linkRows[$linkKey])) {
                                $linkRows[$linkKey] = array(
                                    'link_id'           => $nextLinkId,
                                    'product_id'        => $productId,
                                    'linked_product_id' => $linkedId,
                                    'link_type_id'      => $linkId
                                );
                                if (!empty($rowData[$linkName . 'position'])) {
                                    $positionRows[] = array(
                                        'link_id'                   => $nextLinkId,
                                        'product_link_attribute_id' => $positionAttrId[$linkId],
                                        'value'                     => $rowData[$linkName . 'position']
                                    );
                                }
                                $nextLinkId++;
                            }
                        }
                    }
                }
            }
            if ($linkRows) {
                if (Mage_ImportExport_Model_Import::BEHAVIOR_APPEND != $this->getBehavior() && $productIds) {
                    $adapter->delete(
                        $mainTable,
                        $adapter->quoteInto('product_id IN (?)', array_unique($productIds))
                    );
                }
                $adapter->insertOnDuplicate(
                    $mainTable,
                    $linkRows,
                    array('link_id')
                );
                $adapter->changeTableAutoIncrement($mainTable, $nextLinkId);
            }
            if ($positionRows) { // process linked product positions
                $adapter->insertOnDuplicate(
                    $resource->getAttributeTypeTable('int'),
                    $positionRows,
                    array('value')
                );
            }
        }
        return $this;
    }

    protected function _saveStockItem()
    {
        $defaultStockData = array(
            'manage_stock'                  => 1,
            'use_config_manage_stock'       => 1,
            'qty'                           => 0,
            'min_qty'                       => 0,
            'use_config_min_qty'            => 1,
            'min_sale_qty'                  => 1,
            'use_config_min_sale_qty'       => 1,
            'max_sale_qty'                  => 10000,
            'use_config_max_sale_qty'       => 1,
            'is_qty_decimal'                => 0,
            'backorders'                    => 0,
            'use_config_backorders'         => 1,
            'notify_stock_qty'              => 1,
            'use_config_notify_stock_qty'   => 1,
            'enable_qty_increments'         => 0,
            'use_config_enable_qty_inc'     => 1,
            'qty_increments'                => 0,
            'use_config_qty_increments'     => 1,
            'is_in_stock'                   => 0,
            'low_stock_date'                => null,
            'stock_status_changed_auto'     => 0,
            'is_decimal_divided'            => 0
        );

        $entityTable = Mage::getResourceModel('cataloginventory/stock_item')->getMainTable();
        $helper      = Mage::helper('catalogInventory');

        while ($bunch = $this->_dataSourceModel->getNextBunch()) {
            $stockData = array();

            // Format bunch to stock data rows
            foreach ($bunch as $rowNum => $rowData) {
                if (!$this->isRowAllowedToImport($rowData, $rowNum)) {
                    continue;
                }
                // only SCOPE_DEFAULT can contain stock data
                if (self::SCOPE_DEFAULT != $this->getRowScope($rowData)) {
                    continue;
                }

                $row = array();

                $row['product_id'] = $this->_newSku[$rowData[self::COL_SKU]]['entity_id'];
                $row['stock_id'] = 1;

                /** @var $stockItem Mage_CatalogInventory_Model_Stock_Item */
                $stockItem = Mage::getModel('cataloginventory/stock_item');
                $stockItem->loadByProduct($row['product_id']);
                $existStockData = $stockItem->getData();

                $row = array_merge(
                    $defaultStockData,
                    array_intersect_key($existStockData, $defaultStockData),
                    array_intersect_key($rowData, $defaultStockData),
                    $row
                );

                $stockItem->setData($row);

                if ($helper->isQty($this->_newSku[$rowData[self::COL_SKU]]['type_id'])) {
                    if ($stockItem->verifyNotification()) {
                        $stockItem->setLowStockDate(Mage::app()->getLocale()
                            ->date(null, null, null, false)
                            ->toString(Varien_Date::DATETIME_INTERNAL_FORMAT)
                        );
                    }
                    $stockItem->setStockStatusChangedAutomatically((int) !$stockItem->verifyStock());
                } else {
                    $stockItem->setQty(0);
                }
                $stockData[] = $stockItem->unsetOldData()->getData();
            }

            // Insert rows
            if ($stockData) {
                $this->_connection->insertOnDuplicate($entityTable, $stockData);
            }
        }
        return $this;
    }
}
