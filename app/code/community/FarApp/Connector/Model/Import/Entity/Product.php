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
                $nextRowBackupValues = array_values($nextRowBackup);
                if ($this->getBehavior() != Mage_ImportExport_Model_Import::BEHAVIOR_DELETE && $startNewBunch && !$nextRowBackupValues[0]['sku']) {
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
            if ((!is_file($fullTempPath) || !getimagesize($fullTempPath)) && strpos($fileName, 'http') === 0 && strpos($fileName, '://') !== false) {
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
                    var_dump($e);
                    return '';
                }
            }
            if (is_file($fullTempPath)) {
                return parent::_uploadMediaFiles($correctedBaseName);
            }
            else {
                return '';
            }
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

    /**
     * Prepare attributes data
     *
     * @param array       $rowData    Row data
     * @param int         $rowScope   Row scope
     * @param array       $attributes Attributes
     * @param string|null $rowSku     Row sku
     * @param int         $rowStore   Row store
     * @return array
     */
    protected function _prepareAttributes($rowData, $rowScope, $attributes, $rowSku, $rowStore)
    {
        if (method_exists($this, '_prepareUrlKey')) {
            $rowData = $this->_prepareUrlKey($rowData, $rowScope, $rowSku);
        }
        $product = Mage::getModel('importexport/import_proxy_product', $rowData);
        foreach ($rowData as $attrCode => $attrValue) {
            $attribute = $this->_getAttribute($attrCode);
            if ('multiselect' != $attribute->getFrontendInput()
                && self::SCOPE_NULL == $rowScope
            ) {
                continue; // skip attribute processing for SCOPE_NULL rows
            }
            $attrId = $attribute->getId();
            $backModel = $attribute->getBackendModel();
            $attrTable = $attribute->getBackend()->getTable();
            $storeIds = array($rowStore);
            if (!is_null($attrValue)) {
                if ('datetime' == $attribute->getBackendType() && strtotime($attrValue)) {
                    $attrValue = gmstrftime($this->_getStrftimeFormat(), strtotime($attrValue));
                } elseif ($backModel) {
                    $attribute->getBackend()->beforeSave($product);
                    $attrValue = $product->getData($attribute->getAttributeCode());
                }
            }
            if (self::SCOPE_STORE == $rowScope) {
                if (self::SCOPE_WEBSITE == $attribute->getIsGlobal()) {
                    // check website defaults already set
                    if (!isset($attributes[$attrTable][$rowSku][$attrId][$rowStore])) {
                        $storeIds = $this->_storeIdToWebsiteStoreIds[$rowStore];
                    }
                } elseif (self::SCOPE_STORE == $attribute->getIsGlobal()) {
                    $storeIds = array($rowStore);
                }
            }
            foreach ($storeIds as $storeId) {
                if ('multiselect' == $attribute->getFrontendInput()) {
                    if (!isset($attributes[$attrTable][$rowSku][$attrId][$storeId])) {
                        $attributes[$attrTable][$rowSku][$attrId][$storeId] = '';
                    } else {
                        $attributes[$attrTable][$rowSku][$attrId][$storeId] .= ',';
                    }
                    $attributes[$attrTable][$rowSku][$attrId][$storeId] .= $attrValue;
                } else {
                    $attributes[$attrTable][$rowSku][$attrId][$storeId] = $attrValue;
                }
            }
            $attribute->setBackendModel($backModel); // restore 'backend_model' to avoid 'default' setting
        }
        return $attributes;
    }

    /**
     * Save product attributes.
     *
     * @param array $attributesData Attribute data
     * @return Mage_ImportExport_Model_Import_Entity_Product
     */
    protected function _saveProductAttributes(array $attributesData)
    {
        foreach ($attributesData as $tableName => $skuData) {
            $tableData = array();
            foreach ($skuData as $sku => $attributes) {
                $productId = $this->_newSku[$sku]['entity_id'];
                foreach ($attributes as $attributeId => $storeValues) {
                    foreach ($storeValues as $storeId => $storeValue) {
                        // For storeId 0 we *must* save the NULL value into DB otherwise product collections can not load the store specific values
                        if ($storeId == 0 || ! is_null($storeValue)) {
                            $tableData[] = array(
                                'entity_id'      => $productId,
                                'entity_type_id' => $this->_entityTypeId,
                                'attribute_id'   => $attributeId,
                                'store_id'       => $storeId,
                                'value'          => $storeValue
                            );
                        } else {
                            /** @var Magento_Db_Adapter_Pdo_Mysql $connection */
                            $connection = $this->_connection;
                            $connection->delete($tableName, array(
                                'entity_id=?'      => (int) $productId,
                                'entity_type_id=?' => (int) $this->_entityTypeId,
                                'attribute_id=?'   => (int) $attributeId,
                                'store_id=?'       => (int) $storeId,
                            ));
                        }
                    }
                    if (Mage_ImportExport_Model_Import::BEHAVIOR_APPEND != $this->getBehavior()) {
                        /**
                         * If the store based values are not provided for a particular store,
                         * we default to the default scope values.
                         * In this case, remove all the existing store based values stored in the table.
                         **/
                        $where = $this->_connection->quoteInto('store_id NOT IN (?)', array_keys($storeValues)) .
                            $this->_connection->quoteInto(' AND attribute_id = ?', $attributeId) .
                            $this->_connection->quoteInto(' AND entity_id = ?', $productId) .
                            $this->_connection->quoteInto(' AND entity_type_id = ?', $this->_entityTypeId);
                        $this->_connection->delete(
                            $tableName, $where
                        );
                    }
                }
            }
            if (count($tableData)) {
                $this->_connection->insertOnDuplicate($tableName, $tableData, array('value'));
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

    public function filterRowData(&$rowData) {
        $this->_filterRowData($rowData);
    }

    protected function _filterRowData(&$rowData)
    {
        $rowData = array_filter($rowData, 'strlen');

        foreach($rowData as $key => $fieldValue) {
            if (trim($fieldValue) == 'FARAPPOMIT') {
                $rowData[$key] = NULL;
            } else if (trim($fieldValue) == 'FARAPPIGNORE') {
                unset($rowData[$key]);
            }
        }

        // Exceptions - for sku - put them back in
        if (!isset($rowData[self::COL_SKU])) {
            $rowData[self::COL_SKU] = null;
        }
    }

    public function validateRow(array $rowData, $rowNum)
    {
        static $sku = null; // SKU is remembered through all product rows
        if (isset($this->_validatedRows[$rowNum])) { // check that row is already validated
            return !isset($this->_invalidRows[$rowNum]);
        }
        $this->_validatedRows[$rowNum] = true;
        if (isset($this->_newSku[$rowData[self::COL_SKU]])) {
            $this->addRowError(self::ERROR_DUPLICATE_SKU, $rowNum);
            return false;
        }
        $rowScope = $this->getRowScope($rowData);
        // BEHAVIOR_DELETE use specific validation logic
        if (Mage_ImportExport_Model_Import::BEHAVIOR_DELETE == $this->getBehavior()) {
            if (self::SCOPE_DEFAULT == $rowScope && !isset($this->_oldSku[$rowData[self::COL_SKU]])) {
                $this->addRowError(self::ERROR_SKU_NOT_FOUND_FOR_DELETE, $rowNum);
                return false;
            }
            return true;
        }
        $this->_validate($rowData, $rowNum, $sku);
        if (self::SCOPE_DEFAULT == $rowScope) { // SKU is specified, row is SCOPE_DEFAULT, new product block begins
            $this->_processedEntitiesCount ++;
            $sku = $rowData[self::COL_SKU];
            if (isset($this->_oldSku[$sku])) { // can we get all necessary data from existant DB product?
                // check for supported type of existing product
                if (isset($this->_productTypeModels[$this->_oldSku[$sku]['type_id']])) {
                    $this->_newSku[$sku] = array(
                        'entity_id'     => $this->_oldSku[$sku]['entity_id'],
                        'type_id'       => $this->_oldSku[$sku]['type_id'],
                        'attr_set_id'   => $this->_oldSku[$sku]['attr_set_id'],
                        'attr_set_code' => $this->_attrSetIdToName[$this->_oldSku[$sku]['attr_set_id']]
                    );
                } else {
                    $this->addRowError(self::ERROR_TYPE_UNSUPPORTED, $rowNum);
                    $sku = false; // child rows of legacy products with unsupported types are orphans
                }
            } else { // validate new product type and attribute set
                if (!isset($rowData[self::COL_TYPE])
                    || !isset($this->_productTypeModels[$rowData[self::COL_TYPE]])
                ) {
                    $this->addRowError(self::ERROR_INVALID_TYPE, $rowNum);
                } elseif (!isset($rowData[self::COL_ATTR_SET])
                          || !isset($this->_attrSetNameToId[$rowData[self::COL_ATTR_SET]])
                ) {
                    $this->addRowError(self::ERROR_INVALID_ATTR_SET, $rowNum);
                } elseif (!isset($this->_newSku[$sku])) {
                    $this->_newSku[$sku] = array(
                        'entity_id'     => null,
                        'type_id'       => $rowData[self::COL_TYPE],
                        'attr_set_id'   => $this->_attrSetNameToId[$rowData[self::COL_ATTR_SET]],
                        'attr_set_code' => $rowData[self::COL_ATTR_SET]
                    );
                }
            }
            if (isset($this->_invalidRows[$rowNum])) {
                // mark SCOPE_DEFAULT row as invalid for future child rows
                $sku = false;
            }
        } else {
            if (null === $sku) {
                $this->addRowError(self::ERROR_SKU_IS_EMPTY, $rowNum);
            } elseif (false === $sku) {
                $this->addRowError(self::ERROR_ROW_IS_ORPHAN, $rowNum);
            } elseif (self::SCOPE_STORE == $rowScope && !isset($this->_storeCodeToId[$rowData[self::COL_STORE]])) {
                $this->addRowError(self::ERROR_INVALID_STORE, $rowNum);
            }
        }
        if (!isset($this->_invalidRows[$rowNum])) {
            // set attribute set code into row data for followed attribute validation in type model
            $rowData[self::COL_ATTR_SET] = $this->_newSku[$sku]['attr_set_code'];
            $rowAttributesValid = $this->_productTypeModels[$this->_newSku[$sku]['type_id']]->isRowValid(
                $rowData, $rowNum, !isset($this->_oldSku[$sku])
            );
            if (!$rowAttributesValid && self::SCOPE_DEFAULT == $rowScope) {
                $sku = false; // mark SCOPE_DEFAULT row as invalid for future child rows
            }
        }
        return !isset($this->_invalidRows[$rowNum]);
    }

    protected function _isProductCategoryValid(array $rowData, $rowNum)
    {
        $emptyCategory = empty($rowData[self::COL_CATEGORY]);
        $emptyRootCategory = empty($rowData[self::COL_ROOT_CATEGORY]);
        $hasCategory = $emptyCategory ? false : isset($this->_categories[$rowData[self::COL_CATEGORY]]);
        $category = $emptyRootCategory ? null : $this->_categoriesWithRoots[$rowData[self::COL_ROOT_CATEGORY]];
        if (!$emptyCategory && !$hasCategory) {
            $this->addRowError("Category '".$rowData[self::COL_CATEGORY]."' not found", $rowNum);
            return false;
        }
        else if (!$emptyRootCategory && !isset($category)) {
            $this->addRowError("Root category '".$rowData[self::COL_ROOT_CATEGORY]."' not found", $rowNum);
            return false;
        }
        else if (!$emptyRootCategory && !$emptyCategory && !isset($category[$rowData[self::COL_CATEGORY]])) {
            $this->addRowError("Category '".$rowData[self::COL_CATEGORY]."' not found under root category '".$rowData[self::COL_ROOT_CATEGORY]."'", $rowNum);
            return false;
        }
        return true;
    }

    protected function _validate($rowData, $rowNum, $sku)
    {
        $this->_isProductWebsiteValid($rowData, $rowNum);
        $this->_isProductCategoryValid($rowData, $rowNum);
        $this->_isTierPriceValid($rowData, $rowNum);
        $this->_isGroupPriceValid($rowData, $rowNum);
        $this->_isSuperProductsSkuValid($rowData, $rowNum);
    }

    public function prepareDeletedProductsReindex()
    {
        if ($this->getBehavior() != Mage_ImportExport_Model_Import::BEHAVIOR_DELETE) {
            return $this;
        }
        $skus = $this->_getProcessedProductSkus();
        $productCollection = Mage::getModel('catalog/product')
            ->getCollection()
            ->addAttributeToFilter('sku', array('in' => $skus));
        foreach ($productCollection as $product) {
            /** @var $product Mage_Catalog_Model_Product */
            $this->_logDeleteEvent($product);
        }
        return $this;
    }

    protected function _getProcessedProductSkus()
    {
        $skus = array();
        $source = $this->getSource();
        $source->rewind();
        while ($source->valid()) {
            $current = $source->current();
            $key = $source->key();
            if (! empty($current[self::COL_SKU]) && $this->_validatedRows[$key]) {
                $skus[] = $current[self::COL_SKU];
            }
            $source->next();
        }
        return $skus;
    }

    public function reindexImportedProducts()
    {
        switch ($this->getBehavior()) {
            case Mage_ImportExport_Model_Import::BEHAVIOR_DELETE:
                $this->_indexDeleteEvents();
                break;
            case Mage_ImportExport_Model_Import::BEHAVIOR_REPLACE:
            case Mage_ImportExport_Model_Import::BEHAVIOR_APPEND:
                $this->_reindexUpdatedProducts();
                break;
        }
    }

    protected function _reindexUpdatedProducts()
    {
        if (Mage::helper('core')->isModuleEnabled('Enterprise_Index')) {
            Mage::getSingleton('enterprise_index/observer')->refreshIndex(Mage::getModel('cron/schedule'));
        } else {
            $entityIds = $this->_getProcessedProductIds();
            /*
             * Generate a fake mass update event that we pass to our indexers.
             */
            $event = Mage::getModel('index/event');
            $event->setNewData(array(
                'reindex_price_product_ids' => &$entityIds, // for product_indexer_price
                'reindex_stock_product_ids' => &$entityIds, // for indexer_stock
                'product_ids'               => &$entityIds, // for category_indexer_product
                'reindex_eav_product_ids'   => &$entityIds  // for product_indexer_eav
            ));
            // Index our product entities.
            Mage::getResourceSingleton('cataloginventory/indexer_stock')->catalogProductMassAction($event);
            Mage::getResourceSingleton('catalog/product_indexer_price')->catalogProductMassAction($event);
            Mage::getResourceSingleton('catalog/category_indexer_product')->catalogProductMassAction($event);
            Mage::getResourceSingleton('catalog/product_indexer_eav')->catalogProductMassAction($event);
            Mage::getResourceSingleton('catalogsearch/fulltext')->rebuildIndex(null, $entityIds);
            if (Mage::getResourceModel('ecomdev_urlrewrite/indexer')) {
                Mage::getResourceSingleton('ecomdev_urlrewrite/indexer')->updateProductRewrites($entityIds);
            } else {
                /* @var $urlModel Mage_Catalog_Model_Url */
                $urlModel = Mage::getSingleton('catalog/url');
                $urlModel->clearStoreInvalidRewrites(); // Maybe some products were moved or removed from website
                foreach ($entityIds as $productId) {
                    $urlModel->refreshProductRewrite($productId);
                }
            }
            if (Mage::helper('catalog/product_flat')->isEnabled()) {
                Mage::getSingleton('catalog/product_flat_indexer')->saveProduct($entityIds);
            }
        }
        return $this;
    }

    protected function _getProcessedProductIds()
    {
        $productIds = array();
        $source = $this->getSource();
        $source->rewind();
        while ($source->valid()) {
            $current = $source->current();
            if (! empty($current['sku']) && isset($this->_oldSku[$current[self::COL_SKU]])) {
                $productIds[] = $this->_oldSku[$current[self::COL_SKU]]['entity_id'];
            } elseif (! empty($current['sku']) && isset($this->_newSku[$current[self::COL_SKU]])) {
                $productIds[] = $this->_newSku[$current[self::COL_SKU]]['entity_id'];
            }
            $source->next();
        }
        return $productIds;
    }

    protected function _logDeleteEvent($product)
    {
        /** @var $stockItem Mage_CatalogInventory_Model_Stock_Item */
        $stockItem = Mage::getModel('cataloginventory/stock_item')->loadByProduct($product->getId());
        $stockItem->setForceReindexRequired(true);
        Mage::getSingleton('index/indexer')->logEvent(
            $stockItem,
            Mage_CatalogInventory_Model_Stock_Item::ENTITY,
            Mage_Index_Model_Event::TYPE_DELETE
        );
        Mage::getSingleton('index/indexer')->logEvent(
            $product,
            Mage_Catalog_Model_Product::ENTITY,
            Mage_Index_Model_Event::TYPE_DELETE
        );
    }

    protected function _indexDeleteEvents()
    {
        Mage::getSingleton('index/indexer')->indexEvents(
            Mage_CatalogInventory_Model_Stock_Item::ENTITY, Mage_Index_Model_Event::TYPE_DELETE
        );
        Mage::getSingleton('index/indexer')->indexEvents(
            Mage_Catalog_Model_Product::ENTITY, Mage_Index_Model_Event::TYPE_DELETE
        );
    }
}
