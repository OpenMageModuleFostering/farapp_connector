<?php
/**
 */
class FarApp_Connector_Model_Import_Entity_Customer extends Mage_ImportExport_Model_Import_Entity_Customer
{
    /**
     * Constructor.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->_errorsLimit = 1000000000;
    }

    public function setPoints($customer_id = false, $points = false, $comment = 'Adjustment', $action = 0) {
        $customer = Mage::getModel('customer/customer')->load($customer_id);

        $reward = Mage::getModel('enterprise_reward/reward');

        if (!$customer || !$reward) {
            return;
        }

        $reward->setCustomer($customer)
            ->setWebsiteId(Mage::app()->getWebsite()->getId())
            ->loadByCustomer();

        $reward->setPointsBalance($points)
            ->setAction($action) // Enterprise_Reward_Model_Reward::REWARD_ACTION_ADMIN
            ->setComment($comment)
            ->updateRewardPoints();

        $history = Mage::getModel('enterprise_reward/reward_history')
            ->setReward($reward)->prepareFromReward()->save();
    }

    protected function _saveCustomers() {
        $resource       = Mage::getModel('customer/customer');
        $strftimeFormat = Varien_Date::convertZendToStrftime(Varien_Date::DATETIME_INTERNAL_FORMAT, true, true);
        $table = $resource->getResource()->getEntityTable();
        $nextEntityId   = Mage::getResourceHelper('importexport')->getNextAutoincrement($table);
        $passId         = $resource->getAttribute('password_hash')->getId();
        $passTable      = $resource->getAttribute('password_hash')->getBackend()->getTable();

        while ($bunch = $this->_dataSourceModel->getNextBunch()) {
            $entityRowsIn = array();
            $entityRowsUp = array();
            $attributes   = array();
            $rewardPoints = array();

            $oldCustomersToLower = array_change_key_case($this->_oldCustomers, CASE_LOWER);

            foreach ($bunch as $rowNum => $rowData) {
                if (!$this->validateRow($rowData, $rowNum)) {
                    continue;
                }
                if (self::SCOPE_DEFAULT == $this->getRowScope($rowData)) {
                    // entity table data
                    $entityRow = array(
                        'group_id'   => empty($rowData['group_id']) ? self::DEFAULT_GROUP_ID : $rowData['group_id'],
                        'store_id'   => empty($rowData[self::COL_STORE])
                                        ? 0 : $this->_storeCodeToId[$rowData[self::COL_STORE]],
                        'created_at' => empty($rowData['created_at'])
                                        ? now() : gmstrftime($strftimeFormat, strtotime($rowData['created_at'])),
                        'updated_at' => now()
                    );

                    $emailToLower = strtolower($rowData[self::COL_EMAIL]);
                    if (isset($oldCustomersToLower[$emailToLower][$rowData[self::COL_WEBSITE]])) { // edit
                        $entityId = $oldCustomersToLower[$emailToLower][$rowData[self::COL_WEBSITE]];
                        $entityRow['entity_id'] = $entityId;
                        $entityRowsUp[] = $entityRow;
                    } else { // create
                        $entityId                      = $nextEntityId++;
                        $entityRow['entity_id']        = $entityId;
                        $entityRow['entity_type_id']   = $this->_entityTypeId;
                        $entityRow['attribute_set_id'] = 0;
                        $entityRow['website_id']       = $this->_websiteCodeToId[$rowData[self::COL_WEBSITE]];
                        $entityRow['email']            = $rowData[self::COL_EMAIL];
                        $entityRow['is_active']        = 1;
                        $entityRowsIn[]                = $entityRow;

                        $this->_newCustomers[$rowData[self::COL_EMAIL]][$rowData[self::COL_WEBSITE]] = $entityId;
                    }
                    // attribute values
                    foreach (array_intersect_key($rowData, $this->_attributes) as $attrCode => $value) {
                        if (!$this->_attributes[$attrCode]['is_static'] && strlen($value)) {
                            /** @var $attribute Mage_Customer_Model_Attribute */
                            $attribute  = $resource->getAttribute($attrCode);
                            $backModel  = $attribute->getBackendModel();
                            $attrParams = $this->_attributes[$attrCode];

                            if ('select' == $attrParams['type']) {
                                $value = $attrParams['options'][strtolower($value)];
                            } elseif ('datetime' == $attrParams['type']) {
                                $value = gmstrftime($strftimeFormat, strtotime($value));
                            } elseif ($backModel) {
                                $attribute->getBackend()->beforeSave($resource->setData($attrCode, $value));
                                $value = $resource->getData($attrCode);
                            }
                            $attributes[$attribute->getBackend()->getTable()][$entityId][$attrParams['id']] = $value;

                            // restore 'backend_model' to avoid default setting
                            $attribute->setBackendModel($backModel);
                        }
                    }
                    // password change/set
                    if (isset($rowData['password']) && strlen($rowData['password'])) {
                        $attributes[$passTable][$entityId][$passId] = $resource->hashPassword($rowData['password']);
                    }
                    // reward balance change/set
                    if (isset($rowData['reward_point_balance']) && strlen($rowData['reward_point_balance'])) {
                        $rewardPoints[$entityId] = $rowData['reward_point_balance'];
                    }
                }
            }
            $this->_saveCustomerEntity($entityRowsIn, $entityRowsUp)->_saveCustomerAttributes($attributes);
            foreach ($rewardPoints as $entityId => $rewardPointBalance) {
                $this->setPoints($entityId, $rewardPointBalance);
            }
        }
        return $this;
    }
}
