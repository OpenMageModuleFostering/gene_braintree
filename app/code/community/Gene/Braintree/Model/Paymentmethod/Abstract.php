<?php

/**
 * Class Gene_Braintree_Model_Paymentmethod_Abstract
 *
 * @author Dave Macaulay <dave@gene.co.uk>
 */
abstract class Gene_Braintree_Model_Paymentmethod_Abstract extends Mage_Payment_Model_Method_Abstract
{

    /**
     * Verify that the module has been setup
     *
     * @param null $quote
     *
     * @return bool
     */
    public function isAvailable($quote = null)
    {
        // Check Magento's internal methods allow us to run
        if(parent::isAvailable($quote)) {

            // Validate the configuration is okay
            return $this->_getWrapper()->validateCredentialsOnce();

        } else {

            // Otherwise it's a no
            return false;
        }
    }

    /**
     * Return the helper
     *
     * @return Mage_Payment_Helper_Data
     */
    protected function _getHelper()
    {
        return Mage::helper('gene_braintree');
    }

    /**
     * Return the wrapper class
     *
     * @return Gene_Braintree_Model_Wrapper_Braintree
     */
    protected function _getWrapper()
    {
        return Mage::getSingleton('gene_braintree/wrapper_braintree');
    }

    /**
     * Return configuration values
     *
     * @param $value
     *
     * @return mixed
     */
    protected function _getConfig($key)
    {
        return Mage::getStoreConfig('payment/'.$this->_code.'/'.$key);
    }

    /**
     * Is the vault enabled?
     *
     * @return bool
     */
    public function isVaultEnabled()
    {
        return $this->_getConfig('use_vault');
    }

}