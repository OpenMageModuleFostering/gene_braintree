<?php

/**
 * Class Gene_Braintree_SavedController
 *
 * @author Dave Macaulay <dave@gene.co.uk>
 */
class Gene_Braintree_SavedController extends Mage_Core_Controller_Front_Action
{

    /**
     * Retrieve customer session object
     *
     * @return Mage_Customer_Model_Session
     */
    protected function _getSession()
    {
        return Mage::getSingleton('customer/session');
    }

    /**
     * Validate that the user is logged in
     */
    public function preDispatch()
    {
        parent::preDispatch();

        if (!Mage::getSingleton('customer/session')->authenticate($this)) {
            $this->setFlag('', 'no-dispatch', true);
        }
    }

    /**
     * Show the listing page of saved payment information
     */
    public function indexAction()
    {
        $this->loadLayout();

        $this->_initLayoutMessages('customer/session');
        $this->_initLayoutMessages('catalog/session');

        $this->getLayout()->getBlock('head')->setTitle($this->__('Saved Payment Information'));

        $this->renderLayout();
    }

    /**
     * Action to allow users to delete payment methods
     *
     * @return Mage_Core_Controller_Varien_Action
     *
     * @throws Exception
     */
    public function removeAction()
    {
        // Check we've recieved a payment ID
        $token = $this->getRequest()->getParam('id');
        if(!$token) {
            $this->_getSession()->addError('Please select a saved payment entry to remove.');
            return $this->_redirectReferer();
        }

        // Grab a new instance of the wrapper
        $wrapper = Mage::getModel('gene_braintree/wrapper_braintree');

        // Init the braintree wrapper
        $wrapper->init();

        // Load the payment method from Braintree
        try {
            $paymentMethod = Braintree_PaymentMethod::find($token);
        } catch (Exception $e) {
            $this->_getSession()->addError('The requested payment method cannot be found.');
            return $this->_redirectReferer();
        }

        // Check that this is the users payment method, we have to use a custom method as Braintree don't return the PayPal customer ID
        if(!$wrapper->customerOwnsMethod($paymentMethod)) {
            $this->_getSession()->addError('You do not have permission to modify this payment method.');
            return $this->_redirectReferer();
        }

        // Remove the payment method
        Braintree_PaymentMethod::delete($token);

        // Inform the user of the great news
        $this->_getSession()->addSuccess('Saved payment has been successfully deleted.');
        return $this->_redirectReferer();
    }

}