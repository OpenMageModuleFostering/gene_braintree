<?php

/**
 * Class Gene_Braintree_Block_Js
 *
 * @author Dave Macaulay <dave@gene.co.uk>
 */
class Gene_Braintree_Block_Js extends Mage_Core_Block_Template
{
    /**
     * We can use the same token twice
     * @var bool
     */
    private $token = false;

    /**
     * Log whether methods are active
     *
     * @var bool
     */
    private $creditCardActive = null;
    private $payPalActive = null;

    /**
     * Return whether PayPal is active
     *
     * @return bool|null
     */
    protected function isCreditCardActive()
    {
        if(is_null($this->creditCardActive)) {
            $this->creditCardActive = Mage::getModel('gene_braintree/paymentmethod_creditcard')->isAvailable();
        }

        return $this->creditCardActive;
    }

    /**
     * Return whether PayPal is active
     *
     * @return bool|null
     */
    protected function isPayPalActive()
    {
        if(is_null($this->payPalActive)) {
            $this->payPalActive = Mage::getModel('gene_braintree/paymentmethod_paypal')->isAvailable();
        }

        return $this->payPalActive;
    }

    /**
     * is 3D secure enabled?
     *
     * @return string
     */
    protected function is3DEnabled()
    {
        return var_export(Mage::getModel('gene_braintree/paymentmethod_creditcard')->is3DEnabled(), true);
    }

    /**
     * Generate and return a token
     *
     * @return mixed
     */
    protected function getClientToken()
    {
        if(!$this->token) {
            $this->token = Mage::getSingleton('gene_braintree/wrapper_braintree')->init()->generateToken();
        }
        return $this->token;
    }

    /**
     * Shall we do a single use payment?
     *
     * @return string
     */
    protected function getSingleUse()
    {
        // We prefer to do future payments, so anything else is future
        $paymentAction = Mage::getStoreConfig('payment/gene_braintree_paypal/payment_type');
        if($paymentAction == Gene_Braintree_Model_Source_Paypal_Paymenttype::GENE_BRAINTREE_PAYPAL_SINGLE_PAYMENT) {
            return 'true';
        }

        return 'false';
    }

    /**
     * If we're using future payments should we retrieve a token or just do a singular payment?
     *
     * @return string
     */
    protected function getSingleFutureUse()
    {
        // We prefer to do future payments, so anything else is future
        $paymentAction = Mage::getStoreConfig('payment/gene_braintree_paypal/payment_type');
        if($paymentAction == Gene_Braintree_Model_Source_Paypal_Paymenttype::GENE_BRAINTREE_PAYPAL_FUTURE_PAYMENTS
            && !Mage::getModel('gene_braintree/paymentmethod_paypal')->isVaultEnabled()) {
            return 'true';
        }

        return 'false';
    }

    /**
     * Return the locale for PayPal
     *
     * @return mixed
     */
    protected function getLocale()
    {
        return Mage::getStoreConfig('payment/gene_braintree_paypal/locale');
    }

    /**
     * Only render if the payment method is active
     *
     * @return string
     */
    protected function _toHtml()
    {
        // Check the payment method is active
        if($this->isCreditCardActive() || $this->isPayPalActive()) {
            return parent::_toHtml();
        }

        return '';
    }

}