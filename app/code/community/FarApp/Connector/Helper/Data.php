<?php
class FarApp_Connector_Helper_Data extends Mage_Core_Helper_Abstract
{
    public function strtolower($str)
    {
        if (function_exists('mb_strtolower') && function_exists('mb_detect_encoding')) {
            return mb_strtolower($str, mb_detect_encoding($str));
        }
        return strtolower($str);
    }
}
