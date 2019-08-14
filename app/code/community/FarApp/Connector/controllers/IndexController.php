<?php
class FarApp_Connector_IndexController extends Mage_Core_Controller_Front_Action
{
	public function indexAction()
	{
		echo 'The FarApp Connector '. Mage::getConfig()->getModuleConfig('FarApp_Connector')->version .' module is installed.';
	}
}
?>
