<?php

/**
 * Class Gene_Braintree_Model_Paymentmethod_Abstract
 *
 * @author Dave Macaulay <dave@gene.co.uk>
 */
abstract class Gene_Braintree_Model_Paymentmethod_Abstract extends Mage_Payment_Model_Method_Abstract
{
    /**
     * The decision responses from braintree
     */
    const ADVANCED_FRAUD_REVIEW = 'Review';
    const ADVANCED_FRAUD_DECLINE = 'Decline';

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

    /**
     * Handle any risk decision returned from Braintree
     *
     * @param                $result
     * @param \Varien_Object $payment
     *
     * @return $this
     */
    protected function handleFraud($result, Varien_Object $payment)
    {
        // Verify we have risk data
        if(isset($result->transaction) && isset($result->transaction->riskData) && isset($result->transaction->riskData->decision)) {

            // If the decision is to review the payment mark the payment as such
            if($result->transaction->riskData->decision == self::ADVANCED_FRAUD_REVIEW || $result->transaction->riskData->decision == self::ADVANCED_FRAUD_DECLINE) {

                // Mark the payment as pending
                $payment->setIsTransactionPending(true);

                // If the payment got marked as fraud/decline, we mark it as fraud
                if($result->transaction->riskData->decision == self::ADVANCED_FRAUD_DECLINE) {
                    $payment->setIsFraudDetected(true);
                }
            }
        }

        return $this;
    }

}