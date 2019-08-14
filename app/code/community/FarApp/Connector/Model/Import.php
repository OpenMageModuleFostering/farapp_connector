<?php
/**
 */
class FarApp_Connector_Model_Import extends Mage_ImportExport_Model_Import
{
    public function getEntityAdapter()
    {
        return $this->_getEntityAdapter();
    }
}

