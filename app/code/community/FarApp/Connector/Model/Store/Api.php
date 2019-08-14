<?php

class FarApp_Connector_Model_Store_Api extends Mage_Core_Model_Store_Api
{
    public function items()
    {
        // Retrieve stores
        $stores = Mage::app()->getStores();

        // Make result array
        $result = array();
        foreach ($stores as $store) {
            $result[] = array(
                'store_id'     => $store->getId(),
                'code'         => $store->getCode(),
                'website_id'   => $store->getWebsiteId(),
                'website_code' => $store->getWebsite()->getCode(),
                'group_id'     => $store->getGroupId(),
                'name'         => $store->getName(),
                'sort_order'   => $store->getSortOrder(),
                'is_active'    => $store->getIsActive()
            );
        }

        return $result;
    }

    public function info($storeId)
    {
        // Retrieve store info
        try {
            $store = Mage::app()->getStore($storeId);
        } catch (Mage_Core_Model_Store_Exception $e) {
            $this->_fault('store_not_exists');
        }

        if (!$store->getId()) {
            $this->_fault('store_not_exists');
        }

        // Basic store data
        $result = array();
        $result['store_id'] = $store->getId();
        $result['code'] = $store->getCode();
        $result['website_id'] = $store->getWebsiteId();
        $result['website_code'] = $store->getWebsite()->getCode();
        $result['group_id'] = $store->getGroupId();
        $result['name'] = $store->getName();
        $result['sort_order'] = $store->getSortOrder();
        $result['is_active'] = $store->getIsActive();

        return $result;
    }

}
