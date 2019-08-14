<?php

/**
 * Class Gene_Braintree_Block_Creditcard
 *
 * @author Dave Macaulay <dave@gene.co.uk>
 */
class Gene_Braintree_Block_Creditcard extends Mage_Payment_Block_Form_Cc
{
    /**
     * Store our saved details
     */
    private $_savedDetails = false;

    /**
     * We can use the same token twice
     * @var bool
     */
    private $token = false;

    /**
     * Set the template
     */
    protected function _construct()
    {
        parent::_construct();

        // Utilise a differente template if we're using Hosted Fields
        if(Mage::getModel('gene_braintree/paymentmethod_creditcard')->getConfigData('form_integration') == Gene_Braintree_Model_Source_Creditcard_FormIntegration::INTEGRATION_HOSTED) {
            $this->setTemplate('gene/braintree/creditcard/hostedfields.phtml');
        } else {
            $this->setTemplate('gene/braintree/creditcard.phtml');
        }
    }

    /**
     * Can we save the card?
     *
     * @return bool
     */
    protected function canSaveCard()
    {
        // Validate that the vault is enabled and that the user is either logged in or registering
        if ($this->getMethod()->isVaultEnabled()
            && (Mage::getSingleton('customer/session')->isLoggedIn()
                || Mage::getSingleton('checkout/type_onepage')->getCheckoutMethod() == Mage_Checkout_Model_Type_Onepage::METHOD_REGISTER))
        {
            return true;
        }

        return false;
    }

    /**
     * is 3D secure enabled?
     *
     * @return mixed
     */
    protected function is3DEnabled()
    {
        return Mage::getModel('gene_braintree/paymentmethod_creditcard')->is3DEnabled();
    }

    /**
     * Does this customer have saved accounts?
     *
     * @return mixed
     */
    public function hasSavedDetails()
    {
        if(Mage::getSingleton('customer/session')->isLoggedIn()) {
            if ($this->getSavedDetails()) {
                return sizeof($this->getSavedDetails());
            }
        }
        return false;
    }

    /**
     * Return the saved accounts
     *
     * @return array
     */
    public function getSavedDetails()
    {
        if(!$this->_savedDetails) {
            $this->_savedDetails = Mage::getSingleton('gene_braintree/saved')->getSavedMethodsByType(Gene_Braintree_Model_Saved::SAVED_CREDITCARD_ID);
        }
        return $this->_savedDetails;
    }

    /**
     * Return the original CC types
     *
     * @return array
     */
    public function getOriginalCcAvailableTypes()
    {
        return parent::getCcAvailableTypes();
    }

    /**
     * Convert the available types into something
     *
     * @return string
     */
    public function getCcAvailableTypes()
    {
        // Collect the types from the core method
        $types = parent::getCcAvailableTypes();

        // Grab the keys and encode
        return json_encode(array_keys($types));
    }

    /**
     * Return the card icon
     *
     * @param $cardType
     *
     * @return string
     */
    static public function getCardIcon($cardType)
    {
        // Convert the card type to lower case, no spaces
        switch(str_replace(' ', '', strtolower($cardType))) {
            case 'mastercard':
                return 'MC.png';
            break;
            case 'visa':
                return 'VI.png';
            break;
            case 'americanexpress':
            case 'amex':
                return 'AE.png';
            break;
            case 'discover':
                return 'DI.png';
            break;
            case 'jcb':
                return 'JCB.png';
            break;
            case 'maestro':
                return 'ME.png';
            break;
            case 'paypal':
                return 'PP.png';
            break;
        }

        // Otherwise return the standard card image
        return 'card.png';
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

}