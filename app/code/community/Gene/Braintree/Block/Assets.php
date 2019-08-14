<?php

/**
 * Class Gene_Braintree_Block_Assets
 *
 * @author Dave Macaulay <dave@gene.co.uk>
 */
class Gene_Braintree_Block_Assets extends Mage_Core_Block_Template
{
    /**
     * Record the current version
     *
     * @var null|string|float
     */
    protected $version = null;

    /**
     * Return the Braintree module version
     *
     * @return mixed
     */
    public function getModuleVersion()
    {
        if ($this->version === null) {
            if ($version = Mage::getConfig()->getModuleConfig('Gene_Braintree')->version) {
                $this->version = $version;
            } else {
                $this->version = false;
            }
        }

        return $this->version;
    }

    /**
     * Replace {VERSION} with the current module verison
     *
     * @param string $fileName
     *
     * @return string
     */
    public function getJsUrl($fileName = '')
    {
        $fileName = str_replace('{VERSION}', $this->getModuleVersion(), $fileName);
        return parent::getJsUrl($fileName);
    }

    /**
     * Determine whether or not assets are required for the current page
     *
     * @throws \Exception
     */
    protected function handleRequiresAssets()
    {
        // Build up the request string
        $request = $this->getRequest();
        $requestString =
            $request->getModuleName() . '_' . $request->getControllerName() . '_' . $request->getActionName();

        // Determine if we're viewing a product or cart and handle different logic
        if ($requestString == 'catalog_product_view') {
            return $this->checkAssetsForProduct();
        } elseif ($requestString == 'checkout_cart_index') {
            return $this->checkAssetsForCart();
        }

        // Otherwise assume the block has been included on the checkout
        return true;
    }

    /**
     * Do we need to include assets on the product view page?
     *
     * @return bool
     */
    protected function checkAssetsForProduct()
    {
        return Mage::helper('gene_braintree')->isExpressEnabled('catalog_product_view');
    }

    /**
     * Do we need to include assets on the cart page?
     *
     * @return bool
     */
    protected function checkAssetsForCart()
    {
        return Mage::helper('gene_braintree')->isExpressEnabled('checkout_cart_index');
    }

    /**
     * Does the module require setup and thus these assets?
     *
     * @return bool|string
     */
    protected function _toHtml()
    {
        if (Mage::helper('gene_braintree')->isSetupRequired() && $this->handleRequiresAssets()) {
            return parent::_toHtml();
        }

        return false;
    }
}
