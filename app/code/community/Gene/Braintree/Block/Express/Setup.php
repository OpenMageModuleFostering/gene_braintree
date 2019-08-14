<?php

/**
 * Class Gene_Braintree_Block_Express_Button
 *
 * @author Aidan Threadgold <aidan@gene.co.uk>
 */
class Gene_Braintree_Block_Express_Setup extends Mage_Core_Block_Template
{
    /**
     * Braintree token
     *
     * @var string
     */
    protected $_token = null;

    /**
     * Generate braintree token
     */
    protected function _construct()
    {
        parent::_construct();
        $this->_token = Mage::getModel('gene_braintree/wrapper_braintree')->init()->generateToken();
    }

    /**
     * Get braintree token
     *
     * @return string
     */
    public function getToken()
    {
        return $this->_token;
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
     * Get store currency code.
     *
     * @return string
     */
    public function getStoreCurrency()
    {
        return Mage::app()->getStore()->getCurrentCurrencyCode();
    }

    /**
     * Get the store locale.
     *
     * @return string
     */
    public function getStoreLocale()
    {
        return Mage::app()->getLocale()->getLocaleCode();;
    }

    /**
     * Get the current product
     *
     * @return mixed
     */
    public function getProduct()
    {
        return Mage::registry('current_product');
    }

    /**
     * Registry entry to determine if block has been instantiated yet
     *
     * @return bool
     */
    public function hasBeenSetup()
    {
        if (Mage::registry('gene_braintree_btn_loaded')) {
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
        if (!$this->hasBeenSetup()) {
            Mage::register('gene_braintree_btn_loaded', true);
        }

        return $html;
    }
}