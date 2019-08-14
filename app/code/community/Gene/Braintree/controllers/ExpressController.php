<?php

/**
 * Class Gene_Braintree_ExpressController
 *
 * @author Aidan Threadgold <aidan@gene.co.uk> & Dave Macaulay <dave@gene.co.uk>
 */
class Gene_Braintree_ExpressController extends Mage_Core_Controller_Front_Action
{

    /**
     * @var Mage_Sales_Model_Quote
     */
    protected $_quote;

    /**
     * Prevent access if disabled
     */
    public function preDispatch()
    {
        if (!Mage::getStoreConfig('payment/gene_braintree_paypal/express_active')) {
            $this->setFlag('', 'no-dispatch', true);

            return;
        }

        parent::preDispatch();
    }

    /**
     * Load the quote based on the session data or create a new one
     *
     * @return Mage_Sales_Model_Quote
     */
    protected function _getQuote()
    {
        if ($this->_quote) {
            return $this->_quote;
        }

        // Use the cart quote
        if (Mage::getSingleton('core/session')->getBraintreeExpressSource() == 'cart') {
            $this->_quote = Mage::getSingleton('checkout/session')->getQuote();
        } // Create the quote a'new
        else {
            $store = Mage::app()->getStore();
            $this->_quote = Mage::getModel('sales/quote')->setStoreId($store->getId());
            $quoteId = Mage::getSingleton('core/session')->getBraintreeExpressQuote();

            if ($quoteId) {
                $this->_quote = $this->_quote->load($quoteId);
            } else {
                $this->_quote->reserveOrderId();
            }
        }

        return $this->_quote;
    }

    /**
     * Set up the quote based on Paypal's response.
     *
     * @return Mage_Core_Controller_Varien_Action|void
     * @throws Exception
     */
    public function authorizationAction()
    {
        parse_str($this->getRequest()->getParam('form_data'), $formData);

        // Validate form key
        if (Mage::getSingleton('core/session')->getFormKey() != $formData['form_key']) {
            Mage::getSingleton('core/session')->addError(Mage::helper('gene_braintree')->__('We were unable to start the express checkout.'));

            return $this->_redirect("braintree/express/error");
        }

        // Clean up
        Mage::getSingleton('core/session')->setBraintreeExpressQuote(null);
        Mage::getSingleton('core/session')->setBraintreeNonce(null);

        // Where the user came from - product or cart page
        if (!isset($formData['source'])) {
            $formData['source'] = "product";
        }
        Mage::getSingleton('core/session')->setBraintreeExpressSource($formData['source']);

        $paypal = json_decode($this->getRequest()->getParam('paypal'), true);
        // Check for a valid nonce
        if (!isset($paypal['nonce']) || empty($paypal['nonce'])) {
            Mage::getSingleton('core/session')->addError(Mage::helper('gene_braintree')->__('We were unable to process the response from PayPal. Please try again.'));

            return $this->_redirect("braintree/express/error");
        }

        // Check paypal sent an address
        if (!isset($paypal['details']['shippingAddress']) || !isset($paypal['details']['email'])) {
            Mage::getSingleton('core/session')->addError(Mage::helper('gene_braintree')->__('Please provide a shipping address.'));

            return $this->_redirect("braintree/express/error");
        }

        Mage::getModel('core/session')->setBraintreeNonce($paypal['nonce']);
        $paypalData = $paypal['details'];
        $quote = $this->_getQuote();

        // Pass the customer into the quote
        if (Mage::getSingleton('customer/session')->isLoggedIn()) {
            $quote->setCustomer(Mage::getSingleton('customer/session')->getCustomer());
        } else {
            // Save the email address
            $quote->setCustomerEmail($paypalData['email']);
        }

        // Is this express checkout request coming from the product page?
        if (isset($formData['product']) && isset($formData['qty'])) {
            $product = Mage::getModel('catalog/product')->load($formData['product']);
            if (!$product->getId()) {
                Mage::getSingleton('core/session')->addError(Mage::helper('gene_braintree')->__('We\'re unable to load that product.'));

                return $this->_redirect("braintree/express/error");
            }

            // Build up the add request
            $request = new Varien_Object($formData);

            // Attempt to add the product into the quote
            try {
                $quote->addProduct($product, $request);
            } catch (Exception $e) {
                Mage::getSingleton('core/session')->addError(Mage::helper('gene_braintree')->__('Sorry, we were unable to process your request. Please try again.'));

                return $this->_redirect("braintree/express/error");
            }
        }

        // Build the address
        list($firstName, $lastName) = explode(" ", $paypalData['shippingAddress']['recipientName'], 2);

        $address = Mage::getModel('sales/quote_address');
        $address->setFirstname($firstName)
            ->setLastname($lastName)
            ->setStreet($paypalData['shippingAddress']['extendedAddress'] . ' ' . $paypalData['shippingAddress']['streetAddress'])
            ->setCity($paypalData['shippingAddress']['locality'])
            ->setCountryId($paypalData['shippingAddress']['countryCodeAlpha2'])
            ->setPostcode($paypalData['shippingAddress']['postalCode'])
            ->setTelephone('0000000000');

        // Check if the region is needed
        if (Mage::helper('directory')->isRegionRequired($address->getCountryId())) {
            $region = Mage::getModel('directory/region')->loadbyCode($paypalData['shippingAddress']['region'], $address->getCountryId());
            $regionId = $region->getRegionId();

            if (empty($regionId)) {
                Mage::getSingleton('core/session')->addError(Mage::helper('gene_braintree')->__('We were unable to process the country.'));

                return $this->_redirect("braintree/express/error");
            }

            $address->setRegionId($region->getRegionId());
        }

        // Save the addresses
        $quote->setShippingAddress($address);
        $quote->setBillingAddress($address);

        // Store quote id in session
        $quote->save();
        Mage::getSingleton('core/session')->setBraintreeExpressQuote($quote->getId());

        // redirect to choose shipping method
        return $this->_redirect("braintree/express/shipping");
    }

    /**
     * Display shipping methods for the user to select.
     *
     * @return Mage_Core_Controller_Varien_Action
     * @throws Exception
     */
    public function shippingAction()
    {
        $quote = $this->_getQuote();
        $quote->setTotalsCollectedFlag(false)->collectTotals()->save();

        // collect shipping rates
        $quote->getShippingAddress()->removeAllShippingRates();
        $quote->getShippingAddress()->setCollectShippingRates(true)->collectShippingRates();

        // Get the shipping rates
        $shippingRates = $quote->getShippingAddress()->getShippingRatesCollection();

        // Save the shipping method
        $submitShipping = $this->getRequest()->getParam('submit_shipping');
        if (!empty($submitShipping)) {

            // If the quote is virtual process the order without a shipping method
            if ($quote->isVirtual()) {
                return $this->_redirect("braintree/express/process");
            }

            // Check the shipping rate we want to use is available
            $method = $this->getRequest()->getParam('shipping_method');
            if (!empty($method) && $quote->getShippingAddress()->getShippingRateByCode($method)) {
                $quote->getShippingAddress()->setShippingMethod($method);
                $quote->setTotalsCollectedFlag(false)->collectTotals()->save();

                // Redirect to confirm payment
                return $this->_redirect("braintree/express/process");
            }

            // Missing a valid shipping method
            Mage::getSingleton('core/session')->addWarning(Mage::helper('gene_braintree')->__('Please select a shipping method.'));
        }

        // Recollect the totals
        $quote->setTotalsCollectedFlag(false)->collectTotals();

        // Build up the totals block
        /* @var $totals Mage_Checkout_Block_Cart_Totals */
        $totals = $this->getLayout()->createBlock('checkout/cart_totals')
            ->setTemplate('checkout/cart/totals.phtml')
            ->setCustomQuote($this->_getQuote());

        // View to select shipping method
        $block = $this->getLayout()->createBlock('gene_braintree/express_checkout')
            ->setChild('totals', $totals)
            ->setTemplate('gene/braintree/express/shipping_details.phtml')
            ->setShippingRates($shippingRates)
            ->setQuote($quote);

        $this->getResponse()->setBody($block->toHtml());
    }

    /**
     * Saving a shipping action will update the quote and then provide new totals
     *
     * @return \Mage_Core_Controller_Varien_Action|string
     */
    public function saveShippingAction()
    {
        $quote = $this->_getQuote();
        $quote->setTotalsCollectedFlag(false)->collectTotals()->save();

        // collect shipping rates
        $quote->getShippingAddress()->removeAllShippingRates();
        $quote->getShippingAddress()->setCollectShippingRates(true)->collectShippingRates();

        // Save the shipping method
        $submitShipping = $this->getRequest()->getParam('submit_shipping');
        if (!empty($submitShipping)) {

            // Check the shipping rate we want to use is available
            $method = $this->getRequest()->getParam('shipping_method');
            if (!empty($method) && $quote->getShippingAddress()->getShippingRateByCode($method)) {
                $quote->getShippingAddress()->setShippingMethod($method);
                $quote->setTotalsCollectedFlag(false)->collectTotals()->save();
            }
        }

        // Build up the totals block
        /* @var $totals Mage_Checkout_Block_Cart_Totals */
        $totals = $this->getLayout()->createBlock('checkout/cart_totals')
            ->setTemplate('checkout/cart/totals.phtml')
            ->setCustomQuote($this->_getQuote());

        // Set the body in the response
        $this->getResponse()->setBody($totals->toHtml());
    }

    /**
     * Take the payment.
     */
    public function processAction()
    {
        $quote = $this->_getQuote();
        $quote->setTotalsCollectedFlag(false)->collectTotals()->save();

        // Set payment method
        $paymentMethod = $quote->getPayment();
        $paymentMethod->setMethod('gene_braintree_paypal');
        $paymentMethod->setAdditionalInformation('payment_method_nonce', Mage::getModel('core/session')->getBraintreeNonce());
        $quote->setPayment($paymentMethod);

        // Convert quote to order
        $convert = Mage::getSingleton('sales/convert_quote');

        /* @var $order Mage_Sales_Model_Order */
        $order = $convert->toOrder($quote);
        $order->setShippingAddress($convert->addressToOrderAddress($quote->getShippingAddress()));
        $order->setBillingAddress($convert->addressToOrderAddress($quote->getBillingAddress()));
        $order->setPayment($convert->paymentToOrderPayment($quote->getPayment()));

        // Add the items
        foreach ($quote->getAllItems() as $item) {
            $order->addItem($convert->itemToOrderItem($item));
        }

        // Set the order as complete
        $service = Mage::getModel('sales/service_quote', $order->getQuote());
        $service->submitAll();
        $order = $service->getOrder();

        // Send the new order email
        $order->sendNewOrderEmail();

        // Cleanup
        Mage::getSingleton('core/session')->setBraintreeExpressQuote(null);
        Mage::getSingleton('core/session')->setBraintreeNonce(null);
        Mage::getSingleton('core/session')->setBraintreeExpressSource(null);

        // Redirect to thank you page
        Mage::getSingleton('checkout/session')->setLastSuccessQuoteId($quote->getId());
        Mage::getSingleton('checkout/session')->setLastQuoteId($quote->getId());
        Mage::getSingleton('checkout/session')->setLastOrderId($order->getId());
        $this->getResponse()->setBody('complete');
    }

    /**
     * Display order summary.
     */
    public function errorAction()
    {
        // View to select shipping method
        $block = $this->getLayout()->createBlock('gene_braintree/express_checkout')
            ->setTemplate('gene/braintree/express/error.phtml');

        $this->getResponse()->setBody($block->toHtml());
    }

}