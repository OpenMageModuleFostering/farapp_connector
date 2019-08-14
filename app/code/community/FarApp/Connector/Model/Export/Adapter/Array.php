<?php


class FarApp_Connector_Model_Export_Adapter_Array extends FarApp_Connector_Model_Export_Adapter_Abstract
{
    protected  $_content = array();
    public function writeRow(array $rowData)
    {
        $this->_content[] = $rowData;
    }

    public function getContents()
    {
        return $this->_content;
    }
}
