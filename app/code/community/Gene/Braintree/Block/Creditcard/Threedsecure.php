<?php

/**
 * Class Gene_Braintree_Block_Creditcard_Threedsecure
 *
 * @author Dave Macaulay <dave@gene.co.uk>
 */
class Gene_Braintree_Block_Creditcard_Threedsecure extends Mage_Core_Block_Template
{
    /**
     * Only render if the payment method is active and 3D secure is enabled
     *
     * @return string
     */
    protected function _toHtml()
    {
        // Check the payment method is active
        if (Mage::getModel('gene_braintree/paymentmethod_creditcard')->isAvailable()
            && Mage::getModel('gene_braintree/paymentmethod_creditcard')->is3DEnabled()
        ) {
            return parent::_toHtml();
        }

        return '';
    }
}