<?php

/**
 * Class Gene_Braintree_Block_Info
 *
 * @author Dave Macaulay <dave@gene.co.uk>
 */
class Gene_Braintree_Block_Paypal_Info extends Gene_Braintree_Block_Info
{

    /**
     * Use a custom template
     */
    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('gene/braintree/paypal/info.phtml');
    }

    /**
     * Prepare information specific to current payment method
     *
     * @param null | array $transport
     *
     * @return Varien_Object
     */
    protected function _prepareSpecificInformation($transport = null)
    {
        // Get the original transport data
        $transport = parent::_prepareSpecificInformation($transport);

        // Build up the data we wish to pass through
        $data = array(
            $this->__('PayPal Email') => $this->getInfo()->getAdditionalInformation('paypal_email')
        );

        // Check we're in the admin area
        if(Mage::app()->getStore()->isAdmin()) {

            // Include live details for this transaction
            $this->includeLiveDetails($data);

            // Show these details to the admin only
            $data = array_merge(
                $data, array(
                    $this->__('Payment ID')               => $this->getInfo()->getAdditionalInformation('payment_id'),
                    $this->__('Authorization ID')         => $this->getInfo()->getAdditionalInformation('authorization_id')
                )
            );

        }

        // Add the data to the class variable
        $transport->setData(array_merge($data, $transport->getData()));
        $this->_paymentSpecificInformation = $transport->getData();

        // And return it
        return $transport;
    }

}