<?php

class FarApp_Connector_Model_Product_Api extends Mage_Catalog_Model_Product_Api
{
    public function items($filters = null, $store = null, $detailed = false)
    {
        $collection = Mage::getModel('catalog/product')->getCollection()
            ->addStoreFilter($this->_getStoreId($store))
            ->addAttributeToSelect('name');

        /** @var $apiHelper Mage_Api_Helper_Data */
        $apiHelper = Mage::helper('api');
        $filters = $apiHelper->parseFilters($filters, $this->_filtersMap);
        Mage::log('HI0.6');
        try {
            Mage::log('HI0.61');
            foreach ($filters as $field => $value) {
                Mage::log('HI0.62 '.$field.' '.$value);
                $collection->addFieldToFilter($field, $value);
            }
        } catch (Mage_Core_Exception $e) {
            $this->_fault('filters_invalid', $e->getMessage());
        }
        $result = array();
        foreach ($collection as $product) {
            if (!$detailed) {
                $result[] = array(
                    'product_id' => $product->getId(),
                    'sku'        => $product->getSku(),
                    'name'       => $product->getName(),
                    'set'        => $product->getAttributeSetId(),
                    'type'       => $product->getTypeId(),
                    'category_ids' => $product->getCategoryIds(),
                    'website_ids'  => $product->getWebsiteIds()
                );
            }
            else {
                $productDetails = $this->info($product->getId(), null, null, null, true);
                $mediaApi = new Mage_Catalog_Model_Product_Attribute_Media_Api();
                $mediaList = $mediaApi->items($product->getId());
                $productDetails['product_media.list'] = $mediaList;
                $result[] = $productDetails;
            }
        }
        return $result;
    }

    public function info($productId, $store = null, $attributes = null, $identifierType = null, $detailed = false)
    {
        // make sku flag case-insensitive
        if (!empty($identifierType)) {
            $identifierType = strtolower($identifierType);
        }

        $product = $this->_getProduct($productId, $store, $identifierType);

        $result = array( // Basic product data
            'product_id' => $product->getId(),
            'sku'        => $product->getSku(),
            'set'        => $product->getAttributeSetId(),
            'type'       => $product->getTypeId(),
            'categories' => $product->getCategoryIds(),
            'websites'   => $product->getWebsiteIds()
        );

        foreach ($product->getTypeInstance(true)->getEditableAttributes($product) as $attribute) {
            if ($this->_isAllowedAttribute($attribute, $attributes)) {
                $attributeData = $product->getData($attribute->getAttributeCode());
                if ($attributeData && $detailed && $attribute->usesSource()) {
                    $result[$attribute->getAttributeCode()] = $product->getAttributeText(
                                                                    $attribute->getAttributeCode());
                }
                else {
                    $result[$attribute->getAttributeCode()] = $attributeData;
                }
            }
        }

        return $result;
    }
}

?>
