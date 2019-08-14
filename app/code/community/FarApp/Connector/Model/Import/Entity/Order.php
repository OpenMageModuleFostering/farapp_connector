<?php
/**
 * Import entity order model
 */
class FarApp_Connector_Model_Import_Entity_Order extends Mage_ImportExport_Model_Import_Entity_Abstract
{
    /**
     * Size of bunch - part of entities to save in one step.
     */
    const BUNCH_SIZE = 20;

    /**
     * Data row scopes.
     */
    const SCOPE_DEFAULT = 1;
    const SCOPE_ADDRESS = -1;

    /**
     * Permanent column names.
     *
     * Names that begins with underscore is not an attribute. This name convention is for
     * to avoid interference with same attribute name.
     */
    const COL_EMAIL   = 'customer_email';
    const COL_STORE   = 'store_id';

    /**
     * Error codes.
     */
    const ERROR_INVALID_WEBSITE      = 'invalidWebsite';
    const ERROR_INVALID_EMAIL        = 'invalidEmail';
    const ERROR_DUPLICATE_EMAIL_SITE = 'duplicateEmailSite';
    const ERROR_EMAIL_IS_EMPTY       = 'emailIsEmpty';
    const ERROR_ROW_IS_ORPHAN        = 'rowIsOrphan';
    const ERROR_VALUE_IS_REQUIRED    = 'valueIsRequired';
    const ERROR_INVALID_STORE        = 'invalidStore';
    const ERROR_EMAIL_SITE_NOT_FOUND = 'emailSiteNotFound';
    const ERROR_PASSWORD_LENGTH      = 'passwordLength';

    /**
     * Order constants
     *
     */
    const DEFAULT_GROUP_ID = 1;
    const MAX_PASSWD_LENGTH = 6;

    /**
     * Order address import entity model.
     *
     * @var Mage_ImportExport_Model_Import_Entity_Order_Address
     */
    protected $_addressEntity;

    /**
     * Order attributes parameters.
     *
     *  [attr_code_1] => array(
     *      'options' => array(),
     *      'type' => 'text', 'price', 'textarea', 'select', etc.
     *      'id' => ..
     *  ),
     *  ...
     *
     * @var array
     */
    protected $_attributes = array();

    /**
     * Order account sharing. TRUE - is global, FALSE - is per website.
     *
     * @var boolean
     */
    protected $_orderGlobal;

    /**
     * Order groups ID-to-name.
     *
     * @var array
     */
    protected $_orderGroups = array();

    /**
     * Order entity DB table name.
     *
     * @var string
     */
    protected $_entityTable;

    /**
     * Array of attribute codes which will be ignored in validation and import procedures.
     * For example, when entity attribute has own validation and import procedures
     * or just to deny this attribute processing.
     *
     * @var array
     */
    protected $_ignoredAttributes = array('website_id', 'store_id', 'default_billing', 'default_shipping');

    /**
     * Attributes with index (not label) value.
     *
     * @var array
     */
    protected $_indexValueAttributes = array('group_id');

    /**
     * Validation failure message template definitions
     *
     * @var array
     */
    protected $_messageTemplates = array(
        self::ERROR_INVALID_WEBSITE      => 'Invalid value in Website column (website does not exists?)',
        self::ERROR_INVALID_EMAIL        => 'E-mail is invalid',
        self::ERROR_DUPLICATE_EMAIL_SITE => 'E-mail is duplicated in import file',
        self::ERROR_EMAIL_IS_EMPTY       => 'E-mail is not specified',
        self::ERROR_ROW_IS_ORPHAN        => 'Orphan rows that will be skipped due default row errors',
        self::ERROR_VALUE_IS_REQUIRED    => "Required attribute '%s' has an empty value",
        self::ERROR_INVALID_STORE        => 'Invalid value in Store column (store does not exists?)',
        self::ERROR_EMAIL_SITE_NOT_FOUND => 'E-mail and website combination is not found',
        self::ERROR_PASSWORD_LENGTH      => 'Invalid password length'
    );

    /**
     * Dry-runned orders information from import file.
     *
     * @var array
     */
    protected $_newOrders = array();

    /**
     * Existing orders information. In form of:
     *
     * [customer e-mail] => array(
     *    [website code 1] => customer_id 1,
     *    [website code 2] => customer_id 2,
     *           ...       =>     ...      ,
     *    [website code n] => customer_id n,
     * )
     *
     * @var array
     */
    protected $_oldOrders = array();

    /**
     * Column names that holds values with particular meaning.
     *
     * @var array
     */
    protected $_particularAttributes = array(self::COL_STORE);

    public function isAttributeParticular($attrCode)
    {
        if (parent::isAttributeParticular($attrCode)) {
            return true;
        }
        else if (strpos($attrCode, '_shipping_address_') === 0 ||
                 strpos($attrCode, '_billing_address_') === 0 ||
                 strpos($attrCode, '_item_') === 0 ||
                 strpos($attrCode, '_payment_') === 0 ||
                 strpos($attrCode, '_shipment_') === 0) {
            return true;
        }
        else {
            return false;
        }
    }

    /**
     * Permanent entity columns.
     *
     * @var array
     */
    protected $_permanentAttributes = array(self::COL_EMAIL);

    /**
     * All stores code-ID pairs.
     *
     * @var array
     */
    protected $_storeCodeToId = array();

    /**
     * Website code-to-ID
     *
     * @var array
     */
    protected $_websiteCodeToId = array();

    /**
     * Website ID-to-code
     *
     * @var array
     */
    protected $_websiteIdToCode = array();

    /**
     * Constructor.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Save order data to DB.
     *
     * @throws Exception
     * @return bool Result of operation.
     */
    protected function _importData()
    {
        if (Mage_ImportExport_Model_Import::BEHAVIOR_DELETE == $this->getBehavior()) {
            $this->_deleteOrders();
        } else {
            $this->_saveOrders();
        }
        return true;
    }

    /**
     * Initialize stores hash.
     *
     * @return Mage_ImportExport_Model_Import_Entity_Customer
     */
    protected function _initStores()
    {
        foreach (Mage::app()->getStores(true) as $store) {
            $this->_storeCodeToId[$store->getCode()] = $store->getId();
        }
        return $this;
    }

    /**
     * Initialize website values.
     *
     * @return Mage_ImportExport_Model_Import_Entity_Customer
     */
    protected function _initWebsites()
    {
        /** @var $website Mage_Core_Model_Website */
        foreach (Mage::app()->getWebsites(true) as $website) {
            $this->_websiteCodeToId[$website->getCode()] = $website->getId();
            $this->_websiteIdToCode[$website->getId()]   = $website->getCode();
        }
        return $this;
    }

    /**
     * Gather and save information about order entities.
     *
     * @return Mage_ImportExport_Model_Import_Entity_Order
     */
    protected function _saveOrders()
    {
	$orderData            = null;

        while ($bunch = $this->_dataSourceModel->getNextBunch()) {

            foreach ($bunch as $rowNum => $rowData) {
                if (!$this->validateRow($rowData, $rowNum)) {
                    continue;
                }
                if (self::SCOPE_DEFAULT == $this->getRowScope($rowData)) {
                    if ($orderData) {
                        $this->_saveOrder($rowNum - 1, $orderData, $haveShippingAddress, $haveBillingAddress, $havePayment);
                    }

                    $orderData = array(
                        'group_id'   => empty($rowData['group_id']) ? self::DEFAULT_GROUP_ID : $rowData['group_id'],
                        'store_id'   => empty($rowData[self::COL_STORE])
                                        ? 0 : $this->_storeCodeToId[$rowData[self::COL_STORE]],
                        'created_at' => empty($rowData['created_at'])
                                        ? now() : $rowData['created_at'], //gmstrftime($strftimeFormat, strtotime($rowData['created_at'])),
                        'updated_at' => now(),
                        'items'      => array(array()),
                        'shipments'  => array(array())
                    );

                    $haveShippingAddress = false;
                    $haveBillingAddress = false;
                    $havePayment = false;

                    foreach ($rowData as $col => $val) {
                        if (strpos($col, '_item_') === 0) {
                            $orderData['items'][0][$col] = $val;
                        }
                        else if (strpos($col, '_shipment_') === 0) {
                            $orderData['shipments'][0][$col] = $val;
                        }
                        else {
                            if (strpos($col, '_shipping_address_') === 0) {
                                $haveShippingAddress = true;
                            }
                            else if (strpos($col, '_billing_address_') === 0) {
                                $haveBillingAddress = true;
                            }
                            else if (strpos($col, '_payment_') === 0) {
                                $havePayment = true;
                            }
                            $orderData[$col] = $val;
                        }
                    }
                }
                else {
                    $foundItem = false;
                    $foundShipment = false;
                    foreach ($rowData as $col => $val) {
                        if (strpos($col, '_item_') === 0) {
                            if (!$foundItem) {
                                $orderData['items'][] = array();
                                $itemIdx = count($orderData['items']) - 1;
                                $foundItem = true;
                            }
                            $orderData['items'][$itemIdx][$col] = $val;
                        }
                        else if (strpos($col, '_shipment_') === 0) {
                            if (!$foundShipment) {
                                $orderData['shipments'][] = array();
                                $shipmentIdx = count($orderData['shipments']) - 1;
                                $foundShipment = true;
                            }
                            $orderData['shipments'][$shipmentIdx][$col] = $val;
                        }
                    }
                }
            }
        }

        if ($orderData) {
            $this->_saveOrder($rowNum, $orderData, $haveShippingAddress, $haveBillingAddress, $havePayment);
        }
        return $this;
    }

    protected function _saveOrder($rowNum, $orderData, $haveShippingAddress, $haveBillingAddress, $havePayment)
    {
        $quote          = Mage::getModel('sales/quote');
        $customer       = Mage::getModel('customer/customer');
        $transaction    = Mage::getModel('core/resource_transaction');

        $storeId = $orderData['store_id'];
        $state = empty($orderData['state']) ? 'complete' : $orderData['state'];
        $holdBeforeState = empty($orderData['hold_before_state']) ? '' : $orderData['hold_before_state'];
        $holdBeforeStatus = empty($orderData['hold_before_status']) ? '' : $orderData['hold_before_status'];
        $isVirtual = empty($orderData['is_virtual']) ? '0' : $orderData['is_virtual'];
        $globalCurrencyCode = empty($orderData['global_currency_code']) ? 'USD' : $orderData['global_currency_code'];
        $baseCurrencyCode = empty($orderData['base_currency_code']) ? 'USD' : $orderData['base_currency_code'];
        $storeCurrencyCode = empty($orderData['store_currency_code']) ? 'USD' : $orderData['store_currency_code'];
        $orderCurrencyCode = empty($orderData['order_currency_code']) ? 'USD' : $orderData['order_currency_code'];

        $reservedOrderId = empty($orderData['increment_id']) ? Mage::getSingleton('eav/config')->getEntityType('order')->fetchNewIncrementId('store_id') : $orderData['increment_id'];

        $order = Mage::getModel('sales/order')->loadByIncrementId($reservedOrderId);
        if ($order->getId()) {
            $this->addRowError('Duplicate order increment id found', $rowNum);
            return false;
        }

        $order = Mage::getModel('sales/order')
            ->setIncrementId($reservedOrderId)
            ->setStoreId($storeId)
            ->setHoldBeforeState($holdBeforeState)
            ->setHoldBeforeStatus($holdBeforeStatus)
            ->setIsVirtual($isVirtual)
            ->setGlobal_currency_code($globalCurrencyCode)
            ->setBase_currency_code($baseCurrencyCode)
            ->setStore_currency_code($storeCurrencyCode)
            ->setOrder_currency_code($orderCurrencyCode)
            ->setCreatedAt($orderData['created_at']);

        $email = $orderData['customer_email'];
        $emailToLower = strtolower($email);
        $customer->setWebsiteId(Mage::getModel('core/store')->load($storeId)->getWebsiteId());
        if ($customer->loadByEmail($emailToLower))
            $customerData = $customer->getData();
        else
            $customerData = NULL;
        if ($customerData) {
            $customerFirstname = empty($orderData['customer_firstname']) ? $customer->getFirstname() : $orderData['customer_firstname'];
            $customerLastname = empty($orderData['customer_lastname']) ? $customer->getLastname() : $orderData['customer_lastname'];
            $customerGroupId = empty($orderData['customer_group_id']) ? $customer->getGroupId() : $orderData['customer_group_id'];
            $customerIsGuest = empty($orderData['customer_is_guest']) ? 0 : $orderData['customer_is_guest'];
        }
        else {
            $customerFirstname = $orderData['customer_firstname'];
            $customerLastname = $orderData['customer_lastname'];
            $customerGroupId = $orderData['customer_group_id'];
            $customerIsGuest = 1;
        }

        // set Customer data
        $order->setCustomerEmail($email)
            ->setCustomerFirstname($customerFirstname)
            ->setCustomerLastname($customerLastname)
            ->setCustomerGroupId($customerGroupId)
            ->setCustomerIsGuest($customerIsGuest);
        if ($customerData)
            $order->setCustomerId($customer->getId());

        // set Billing Address
        //var_dump($rowNum);
        //var_dump($orderData);
        //var_dump($haveBillingAddress);
        $billingAddress = NULL;
        if (!$haveBillingAddress) {
            $billing = $customer->getDefaultBillingAddress();
            if ($billing) {
                $billingAddress = Mage::getModel('sales/order_address')
                    ->setStoreId($storeId)
                    ->setAddressType(Mage_Sales_Model_Quote_Address::TYPE_BILLING)
                    ->setCustomerAddressId($billing->getEntityId())
                    ->setPrefix($billing->getPrefix())
                    ->setFirstname($billing->getFirstname())
                    ->setMiddlename($billing->getMiddlename())
                    ->setLastname($billing->getLastname())
                    ->setSuffix($billing->getSuffix())
                    ->setCompany($billing->getCompany())
                    ->setStreet($billing->getStreet())
                    ->setCity($billing->getCity())
                    ->setCountryId($billing->getCountryId())
                    ->setRegion($billing->getRegion())
                    ->setPostcode($billing->getPostcode())
                    ->setTelephone($billing->getTelephone())
                    ->setFax($billing->getFax());
            }
        }
        else {
            $billingAddressPrefix = empty($orderData['_billing_address_prefix']) ? '' : $orderData['_billing_address_prefix'];
            $billingAddressFirstname = empty($orderData['_billing_address_firstname']) ? '' : $orderData['_billing_address_firstname'];
            $billingAddressMiddlename = empty($orderData['_billing_address_middlename']) ? '' : $orderData['_billing_address_middlename'];
            $billingAddressLastname = empty($orderData['_billing_address_lastname']) ? '' : $orderData['_billing_address_lastname'];
            $billingAddressSuffix = empty($orderData['_billing_address_suffix']) ? '' : $orderData['_billing_address_suffix'];
            $billingAddressCompany = empty($orderData['_billing_address_company']) ? '' : $orderData['_billing_address_company'];
            $billingAddressStreet = empty($orderData['_billing_address_street']) ? '' : $orderData['_billing_address_street'];
            $billingAddressCity = empty($orderData['_billing_address_city']) ? '' : $orderData['_billing_address_city'];
            $billingAddressCountryId = empty($orderData['_billing_address_country_id']) ? '' : $orderData['_billing_address_country_id'];
            $billingAddressRegion = empty($orderData['_billing_address_region']) ? '' : $orderData['_billing_address_region'];
            $billingAddressPostcode = empty($orderData['_billing_address_postcode']) ? '' : $orderData['_billing_address_postcode'];
            $billingAddressTelephone = empty($orderData['_billing_address_telephone']) ? '' : $orderData['_billing_address_telephone'];
            $billingAddressFax = empty($orderData['_billing_address_fax']) ? '' : $orderData['_billing_address_fax'];
            $billingAddress = Mage::getModel('sales/order_address')
                ->setStoreId($storeId)
                ->setAddressType(Mage_Sales_Model_Quote_Address::TYPE_BILLING)
                ->setPrefix($billingAddressPrefix)
                ->setFirstname($billingAddressFirstname)
                ->setMiddlename($billingAddressMiddlename)
                ->setLastname($billingAddressLastname)
                ->setSuffix($billingAddressSuffix)
                ->setCompany($billingAddressCompany)
                ->setStreet($billingAddressStreet)
                ->setCity($billingAddressCity)
                ->setCountryId($billingAddressCountryId)
                ->setRegion($billingAddressRegion)
                ->setPostcode($billingAddressPostcode)
                ->setTelephone($billingAddressTelephone)
                ->setFax($billingAddressFax);
        }
        if ($billingAddress)
            $order->setBillingAddress($billingAddress);

        // set Shipping Address
        $shippingAddress = NULL;
        if (!$haveShippingAddress) {
            $shipping = $customer->getDefaultShippingAddress();
            if ($shipping) {
                $shippingAddress = Mage::getModel('sales/order_address')
                    ->setStoreId($storeId)
                    ->setAddressType(Mage_Sales_Model_Quote_Address::TYPE_SHIPPING)
                    ->setCustomerAddressId($shipping->getEntityId())
                    ->setPrefix($shipping->getPrefix())
                    ->setFirstname($shipping->getFirstname())
                    ->setMiddlename($shipping->getMiddlename())
                    ->setLastname($shipping->getLastname())
                    ->setSuffix($shipping->getSuffix())
                    ->setCompany($shipping->getCompany())
                    ->setStreet($shipping->getStreet())
                    ->setCity($shipping->getCity())
                    ->setCountryId($shipping->getCountryId())
                    ->setRegion($shipping->getRegion())
                    ->setPostcode($shipping->getPostcode())
                    ->setTelephone($shipping->getTelephone())
                    ->setFax($shipping->getFax());
            }
        }
        else {
            $shippingAddressPrefix = empty($orderData['_shipping_address_prefix']) ? '' : $orderData['_shipping_address_prefix'];
            $shippingAddressFirstname = empty($orderData['_shipping_address_firstname']) ? '' : $orderData['_shipping_address_firstname'];
            $shippingAddressMiddlename = empty($orderData['_shipping_address_middlename']) ? '' : $orderData['_shipping_address_middlename'];
            $shippingAddressLastname = empty($orderData['_shipping_address_lastname']) ? '' : $orderData['_shipping_address_lastname'];
            $shippingAddressSuffix = empty($orderData['_shipping_address_suffix']) ? '' : $orderData['_shipping_address_suffix'];
            $shippingAddressCompany = empty($orderData['_shipping_address_company']) ? '' : $orderData['_shipping_address_company'];
            $shippingAddressStreet = empty($orderData['_shipping_address_street']) ? '' : $orderData['_shipping_address_street'];
            $shippingAddressCity = empty($orderData['_shipping_address_city']) ? '' : $orderData['_shipping_address_city'];
            $shippingAddressCountryId = empty($orderData['_shipping_address_country_id']) ? '' : $orderData['_shipping_address_country_id'];
            $shippingAddressRegion = empty($orderData['_shipping_address_region']) ? '' : $orderData['_shipping_address_region'];
            $shippingAddressPostcode = empty($orderData['_shipping_address_postcode']) ? '' : $orderData['_shipping_address_postcode'];
            $shippingAddressTelephone = empty($orderData['_shipping_address_telephone']) ? '' : $orderData['_shipping_address_telephone'];
            $shippingAddressFax = empty($orderData['_shipping_address_fax']) ? '' : $orderData['_shipping_address_fax'];
            $shippingAddress = Mage::getModel('sales/order_address')
                ->setStoreId($storeId)
                ->setAddressType(Mage_Sales_Model_Quote_Address::TYPE_SHIPPING)
                ->setPrefix($shippingAddressPrefix)
                ->setFirstname($shippingAddressFirstname)
                ->setMiddlename($shippingAddressMiddlename)
                ->setLastname($shippingAddressLastname)
                ->setSuffix($shippingAddressSuffix)
                ->setCompany($shippingAddressCompany)
                ->setStreet($shippingAddressStreet)
                ->setCity($shippingAddressCity)
                ->setCountryId($shippingAddressCountryId)
                ->setRegion($shippingAddressRegion)
                ->setPostcode($shippingAddressPostcode)
                ->setTelephone($shippingAddressTelephone)
                ->setFax($shippingAddressFax);
        }
        if (!$isVirtual) {
            if ($shippingAddress)
                $order->setShippingAddress($shippingAddress);
            $shippingMethod = empty($orderData['shipping_method']) ? 'flatrate_flatrate' : $orderData['shipping_method'];
            $order->setShipping_method($shippingMethod)
                  ->setShippingDescription($shippingMethod);
        }

        $orderPayment = Mage::getModel('sales/order_payment')
            ->setStoreId($storeId)
            ->setCustomerPaymentId(0)
            ->setMethod('checkmo')
            ->setPo_number(' - ');
        $order->setPayment($orderPayment);

        foreach ($orderData['items'] as $item) {
            $orderItem = Mage::getModel('sales/order_item')
                ->setStoreId($storeId)
                ->setQuoteItemId(0)
                ->setQuoteParentItemId(NULL)
                ->setSku($item['_item_sku']) 
                ->setProductType($item['_item_type'])
                ->setProductOptions(unserialize($item['_item_option']))
                ->setQtyBackordered(NULL)
                ->setTotalQtyOrdered($item['_item_qty_ordered'])
                ->setQtyOrdered($item['_item_qty_ordered'])
                ->setName($item['_item_name'])
                ->setPrice($item['_item_price'])
                ->setBasePrice($item['_item_base_price'])
                ->setOriginalPrice($item['_item_original_price'])
                ->setBaseOriginalPrice($item['_item_base_original_price'])
                ->setRowWeight($item['_item_row_weight'])
                ->setPriceInclTax($item['_item_price_incl_tax'])
                ->setBasePriceInclTax($item['_item_base_price_incl_tax'])
                ->setTaxAmount($item['_item_tax_amount'])
                ->setBaseTaxAmount($item['_item_base_tax_amount'])
                ->setTaxPercent($item['_item_tax_percent'])
                ->setDiscountAmount($item['_item_discount'])
                ->setBaseDiscountAmount($item['_item_base_discount'])
                ->setDiscountPercent($item['_item_discount_percent'])
                ->setRowTotal($item['_item_row_total'])
                ->setBaseRowTotal($item['_item_base_row_total']);

            if ($item['_item_parent_item_id'])
                $orderItem->setParentItemId($item['_item_parent_item_id']);

            $order->addItem($orderItem);
        }

        $order->setShippingAmount($orderData['shipping_amount']);
        $order->setBaseShippingAmount($orderData['base_shipping_amount']);

        //Apply Discount
        $order->setBaseDiscountAmount($orderData['base_discount_amount']);
        $order->setDiscountAmount($orderData['discount_amount']);
		
        //Apply Tax
        $order->setBaseTaxAmount($orderData['base_tax_amount']);
        $order->setTaxAmount($orderData['tax_amount']);

        $order->setSubtotal($orderData['subtotal'])      
            ->setBaseSubtotal($orderData['base_subtotal'])  
            ->setGrandTotal($orderData['grand_total'])      
            ->setBaseGrandTotal($orderData['base_grand_total'])
            ->setShippingTaxAmount($orderData['shipping_tax_amount'])      
            ->setBaseShippingTaxAmount($orderData['base_shipping_tax_amount'])      
            ->setBaseToGlobalRate($orderData['base_to_global_rate'])      
            ->setBaseToOrderRate($orderData['base_to_order_rate'])      
            ->setStoreToBaseRate($orderData['store_to_base_rate'])      
            ->setStoreToOrderRate($orderData['store_to_order_rate'])      
            ->setSubtotalInclTax($orderData['subtotal_incl_tax'])      
            ->setBaseSubtotalInclTax($orderData['base_subtotal_incl_tax'])      
            ->setCouponCode($orderData['coupon_code']) 
            ->setDiscountDescription($orderData['coupon_code']) 
            ->setShippingInclTax($orderData['shipping_incl_tax']) 
            ->setBaseShippingInclTax($orderData['base_shipping_incl_tax']) 
            ->setTotalQtyOrdered($orderData['total_qty_ordered'])
            ->setRemoteIp($orderData['remote_ip']);

        $transaction->addObject($order);
        $transaction->addCommitCallback(array($order, 'place'));
        $transaction->addCommitCallback(array($order, 'save'));

        if ($transaction->save()) {
            $order->setData("state", $state);
            $status = $order->getConfig()->getStateDefaultStatus($state);
            $order->setStatus($status);
            $history = $order->addStatusHistoryComment('', false); 
            $history->setIsCustomerNotified(null);
            $order->save();
            return true;
        }
        else {
            return false;
        }
    }

    /**
     * EAV entity type code getter.
     *
     * @abstract
     * @return string
     */
    public function getEntityTypeCode()
    {
        return 'order';
    }

    /**
     * Obtain scope of the row from row data.
     *
     * @param array $rowData
     * @return int
     */
    public function getRowScope(array $rowData)
    {
        return strlen(trim($rowData[self::COL_EMAIL])) ? self::SCOPE_DEFAULT : self::SCOPE_ADDRESS;
    }

    /**
     * Validate data row.
     *
     * @param array $rowData
     * @param int $rowNum
     * @return boolean
     */
    public function validateRow(array $rowData, $rowNum)
    {
        static $email   = null; // e-mail is remembered through all customer rows
        static $website = null; // website is remembered through all customer rows

        if (isset($this->_validatedRows[$rowNum])) { // check that row is already validated
            return !isset($this->_invalidRows[$rowNum]);
        }
        $this->_validatedRows[$rowNum] = true;

        $rowScope = $this->getRowScope($rowData);

        if (self::SCOPE_DEFAULT == $rowScope) {
            $this->_processedEntitiesCount ++;
        }

        return !isset($this->_invalidRows[$rowNum]);
    }
}
