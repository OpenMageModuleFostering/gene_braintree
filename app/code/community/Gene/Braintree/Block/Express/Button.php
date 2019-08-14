<?php

/**
 * Class Gene_Braintree_Block_Express_Button
 *
 * @author Dave Macaulay <dave@gene.co.uk>
 */
class Gene_Braintree_Block_Express_Button extends Mage_Core_Block_Template
{
    /**
     * Generate braintree token
     */
    protected function _construct()
    {
        parent::_construct();
    }

    /**
     * Is the express mode enabled
     *
     * @return bool
     */
    public function isEnabled()
    {
        if (Mage::getStoreConfig('payment/gene_braintree_paypal/active')
            && Mage::getStoreConfig('payment/gene_braintree_paypal/express_active')
        ) {
            return true;
        }

        return false;
    }

    /**
     * Is express enabled on the product page?
     *
     * @return bool
     */
    public function isEnabledPdp()
    {
        if ($this->isEnabled() && Mage::getStoreConfig('payment/gene_braintree_paypal/express_pdp')) {
            return true;
        }

        return false;
    }

    /**
     * Is express enabled in the cart?
     *
     * @return bool
     */
    public function isEnabledCart()
    {
        if ($this->isEnabled() && Mage::getStoreConfig('payment/gene_braintree_paypal/express_cart')) {
            return true;
        }

        return false;
    }

    /**
     * Registry entry to mark this block as instantiated
     *
     * @param string $html
     *
     * @return string
     */
    public function _afterToHtml($html)
    {
        if ($this->isEnabled()) {
            return $html;
        }

        return '';
    }
}