<?php

/**
 * Class Gene_Braintree_Model_Observer
 *
 * @author Dave Macaulay <dave@gene.co.uk>
 */
class Gene_Braintree_Model_Observer
{

    /**
     * Detect which checkout is in use and add a new layout handle
     *
     * @param Varien_Event_Observer $observer
     *
     * @return $this
     */
    public function addLayoutHandle(Varien_Event_Observer $observer)
    {
        /* @var $action Mage_Core_Controller_Varien_Action */
        $action = $observer->getAction();

        /* @var $layout Mage_Core_Model_Layout */
        $layout = $observer->getLayout();

        // We only want to run this action on the checkout
        if($action->getFullActionName() == 'checkout_onepage_index') {

            // Attempt to detect Amasty_Scheckout
            if (Mage::helper('core')->isModuleEnabled('Amasty_Scheckout')) {
                $layout->getUpdate()->addHandle('amasty_onestep_checkout');
            }

        }

        // As some 3rd party checkouts use the same handles, and URL we have to dynamically add new handles
        if($action->getFullActionName() == 'onestepcheckout_index_index') {

            // Attempt to detect Magestore_Onestepcheckout
            if (Mage::helper('core')->isModuleEnabled('Magestore_Onestepcheckout')) {
                if(Mage::helper('onestepcheckout')->enabledOnestepcheckout()) {
                    $layout->getUpdate()->addHandle('magestore_onestepcheckout_index');
                }
            }

            // Attempt to detect Idev_OneStepCheckout
            // @todo add new handle for idev
            if (Mage::helper('core')->isModuleEnabled('Idev_OneStepCheckout')) {
                $layout->getUpdate()->addHandle('idev_onestepcheckout_index');
            }

        }

        return $this;
    }

    /**
     * Store the customer ID if set in session
     *
     * @param Varien_Event_Observer $observer
     */
    public function completeCheckout(Varien_Event_Observer $observer)
    {
        // Do we have a customer ID within the session?
        if(Mage::getSingleton('checkout/session')->getBraintreeCustomerId() &&
            Mage::getSingleton('checkout/type_onepage')->getCheckoutMethod() == Mage_Checkout_Model_Type_Onepage::METHOD_CUSTOMER) {

            // Get the customer
            $customer = Mage::getSingleton('customer/session')->getCustomer();

            // Save the braintree customer ID
            $customer->setBraintreeCustomerId(Mage::getSingleton('checkout/session')->getBraintreeCustomerId())->save();
        }

        // Unset the ID from the session
        Mage::getSingleton('checkout/session')->unsetData('braintree_customer_id');

        return $this;
    }

    /**
     * Capture payment on shipment if set
     *
     * @param Varien_Event_Observer $observer
     *
     * @return $this
     */
    public function captureBraintreePayment(Varien_Event_Observer $observer)
    {
        /* @var $shipment Mage_Sales_Model_Order_Shipment */
        $shipment = $observer->getEvent()->getShipment();

        /* @var $order Mage_Sales_Model_Order */
        $order = $shipment->getOrder();

        // Should we capture the payment in shipment?
        if($this->shouldCaptureShipment($order)) {

            // Check the order can be invoiced
            if($order->canInvoice()) {

                /* @var @invoice Mage_Sales_Model_Order_Invoice */
                $invoice = Mage::getModel('sales/service_order', $order)->prepareInvoice();

                // Check the invoice has items to invoice
                if ($invoice->getTotalQty()) {

                    // Set the requested capture case
                    $invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::CAPTURE_ONLINE);

                    // Register the invoice
                    $invoice->register();

                    // Save the transaction
                    $transactionSave = Mage::getModel('core/resource_transaction')
                        ->addObject($invoice)
                        ->addObject($invoice->getOrder());

                    // Save the transaction
                    $transactionSave->save();

                }

            }

        }

        return $this;
    }

    /**
     * Store the currency mapping as a JSON string
     *
     * @param \Varien_Event_Observer $observer
     *
     * @return $this
     */
    public function modifyCurrencyMapping(Varien_Event_Observer $observer)
    {

        return $this;
    }

    /**
     * Should we capture the payment?
     *
     * @param $order Mage_Sales_Model_Order
     *
     * @return bool
     */
    private function shouldCaptureShipment($order)
    {
        // Check the store configuration settings are set to capture shipment
        if(Mage::getStoreConfig(Gene_Braintree_Model_Source_Creditcard_PaymentAction::PAYMENT_ACTION_XML_PATH, $order->getStoreId()) == Mage_Payment_Model_Method_Abstract::ACTION_AUTHORIZE
            && Mage::getStoreConfig(Gene_Braintree_Model_Source_Creditcard_CaptureAction::CAPTURE_ACTION_XML_PATH, $order->getStoreId()) == Gene_Braintree_Model_Source_Creditcard_CaptureAction::CAPTURE_SHIPMENT)
        {
            return true;
        }
        return false;
    }
}