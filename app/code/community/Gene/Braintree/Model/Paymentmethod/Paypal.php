<?php

/**
 * Class Gene_Braintree_Model_Paymentmethod_Paypal
 *
 * @author Dave Macaulay <dave@gene.co.uk>
 */
class Gene_Braintree_Model_Paymentmethod_Paypal extends Gene_Braintree_Model_Paymentmethod_Abstract
{
    /**
     * Setup block types
     *
     * @var string
     */
    protected $_formBlockType = 'gene_braintree/paypal';
    protected $_infoBlockType = 'gene_braintree/paypal_info';

    /**
     * Set the code
     *
     * @var string
     */
    protected $_code = 'gene_braintree_paypal';

    /**
     * Payment Method features
     *
     * @var bool
     */
    protected $_isGateway = false;
    protected $_canOrder = false;
    protected $_canAuthorize = false;
    protected $_canCapture = true;
    protected $_canCapturePartial = false;
    protected $_canRefund = true;
    protected $_canRefundInvoicePartial = true;
    protected $_canVoid = false;
    protected $_canUseInternal = false;
    protected $_canUseCheckout = true;
    protected $_canUseForMultishipping = false;
    protected $_isInitializeNeeded = false;
    protected $_canFetchTransactionInfo = false;
    protected $_canReviewPayment = false;
    protected $_canCreateBillingAgreement = false;
    protected $_canManageRecurringProfiles = false;

    /**
     * Place Braintree specific data into the additional information of the payment instance object
     *
     * @param   mixed $data
     * @return  Mage_Payment_Model_Info
     */
    public function assignData($data)
    {
        if (!($data instanceof Varien_Object)) {
            $data = new Varien_Object($data);
        }
        $info = $this->getInfoInstance();
        $info->setAdditionalInformation('paypal_payment_method_token', $data->getData('paypal_payment_method_token'))
            ->setAdditionalInformation('payment_method_nonce', $data->getData('payment_method_nonce'))
            ->setAdditionalInformation('save_paypal', $data->getData('save_paypal'))
            ->setAdditionalInformation('device_data', $data->getData('device_data'));

        return $this;
    }

    /**
     * Return the PayPal payment type
     *
     * @return mixed
     */
    public function getPaymentType()
    {
        $object = new Varien_Object();
        $object->setType($this->_getConfig('payment_type'));

        // Specific event for this method
        Mage::dispatchEvent('gene_paypal_get_payment_type', array('object' => $object));

        return $object->getType();
    }

    /**
     * Is the vault enabled?
     *
     * @return bool
     */
    public function isVaultEnabled()
    {
        $object = new Varien_Object();
        $object->setResponse(($this->getPaymentType() == Gene_Braintree_Model_Source_Paypal_Paymenttype::GENE_BRAINTREE_PAYPAL_FUTURE_PAYMENTS && $this->_getConfig('use_vault')));

        // Specific event for this method
        Mage::dispatchEvent('gene_braintree_paypal_is_vault_enabled', array('object' => $object));

        // General event if we want to enforce saving of all payment methods
        Mage::dispatchEvent('gene_braintree_is_vault_enabled', array('object' => $object));

        return $object->getResponse();
    }

    /**
     * Should we save this method in the database?
     *
     * @param \Varien_Object $payment
     *
     * @return mixed
     */
    public function shouldSaveMethod($payment)
    {
        // Retrieve whether or not we should save the card from the info instance
        $savePaypal = $this->getInfoInstance()->getAdditionalInformation('save_paypal');

        $object = new Varien_Object();
        $object->setResponse(($this->isVaultEnabled() && $savePaypal == 1));

        // Specific event for this method
        Mage::dispatchEvent('gene_braintree_paypal_should_save_method', array('object' => $object, 'payment' => $payment));

        // General event if we want to enforce saving of all payment methods
        Mage::dispatchEvent('gene_braintree_save_method', array('object' => $object, 'payment' => $payment));

        return $object->getResponse();
    }

    /**
     * Return the payment method token from the info instance
     *
     * @return null|string
     */
    public function getPaymentMethodToken()
    {
        return $this->getInfoInstance()->getAdditionalInformation('paypal_payment_method_token');
    }

    /**
     * Return the payment method nonce from the info instance
     *
     * @return null|string
     */
    public function getPaymentMethodNonce()
    {
        return $this->getInfoInstance()->getAdditionalInformation('payment_method_nonce');
    }

    /**
     * Capture the payment on the checkout page
     *
     * @param Varien_Object $payment
     * @param float         $amount
     *
     * @return Mage_Payment_Model_Abstract
     */
    public function capture(Varien_Object $payment, $amount)
    {
        // Confirm that we have a nonce from Braintree
        // We cannot utilise the validate() function as these checks need to happen at the capture point
        if(!$this->getPaymentMethodToken() && !$this->getPaymentMethodNonce()) {
            Mage::throwException(
                $this->_getHelper()->__('There has been an issue processing your PayPal payment, please try again.')
            );
        }

        // Init the environment
        $this->_getWrapper()->init();

        if($this->getPaymentMethodToken() && $this->getPaymentMethodToken() != 'other') {
            $paymentArray = array(
                'paymentMethodToken' => $this->getPaymentMethodToken()
            );
        } else {
            $paymentArray = array(
                'paymentMethodNonce' => $this->getPaymentMethodNonce()
            );
        }

        // Retrieve the amount we should capture
        $amount = $this->_getWrapper()->getCaptureAmount($payment->getOrder(), $amount);

        // Attempt to create the sale
        try {
            // Build the array for the sale
            $saleArray = $this->_getWrapper()->buildSale(
                $amount,
                $paymentArray,
                $payment->getOrder(),
                true,
                $this->getInfoInstance()->getAdditionalInformation('device_data'),
                $this->shouldSaveMethod($payment)
            );

            // Pass the sale array into a varien object
            $request = new Varien_Object();
            $request->setData('sale_array', $saleArray);

            // Dispatch event for modifying the sale array
            Mage::dispatchEvent('gene_braintree_paypal_sale_array', array('payment' => $payment, 'request' => $request));

            // Pull the saleArray back out
            $saleArray = $request->getData('sale_array');

            // Log the initial sale array, no protected data is included
            Gene_Braintree_Model_Debug::log(array('saleArray' => $saleArray));

            // Attempt to create the sale
            $result = $this->_getWrapper()->makeSale(
                $saleArray
            );
        } catch (Exception $e) {

            // Dispatch an event for when a payment fails
            Mage::dispatchEvent('gene_braintree_paypal_failed_exception', array('payment' => $payment, 'exception' => $e));

            // If there's an error
            Gene_Braintree_Model_Debug::log($e);

            Mage::throwException(
                $this->_getHelper()->__('We were unable to complete your purchase through PayPal, please try again or an alternative payment method.')
            );
        }

        // Log the result
        Gene_Braintree_Model_Debug::log(array('result' => $result));

        // If the sale has failed
        if ($result->success != true) {

            // Dispatch an event for when a payment fails
            Mage::dispatchEvent('gene_braintree_paypal_failed', array('payment' => $payment, 'result' => $result));

            Mage::throwException($this->_getHelper()->__('%s. Please try again or attempt refreshing the page.', $result->message));
        }

        // Finish of the order
        $this->_processSuccessResult($payment, $result, $amount);

        return $this;
    }

    /**
     * Process a successful result from the sale request
     *
     * @param Varien_Object               $payment
     * @param Braintree_Result_Successful $result
     * @param                             $amount
     *
     * @return Varien_Object
     */
    protected function _processSuccessResult(Varien_Object $payment, $result, $amount)
    {
        // Pass an event if the payment was a success
        Mage::dispatchEvent('gene_braintree_paypal_success', array('payment' => $payment, 'result' => $result, 'amount' => $amount));

        // Set some basic things
        $payment->setStatus(self::STATUS_APPROVED)
            ->setCcTransId($result->transaction->id)
            ->setLastTransId($result->transaction->id)
            ->setTransactionId($result->transaction->id)
            ->setIsTransactionClosed(0)
            ->setAmount($amount)
            ->setShouldCloseParentTransaction(false);

        // Set the additioanl information about the customers PayPal account
        $payment->setAdditionalInformation(
            array(
                'paypal_email'     => $result->transaction->paypal['payerEmail'],
                'payment_id'       => $result->transaction->paypal['paymentId'],
                'authorization_id' => $result->transaction->paypal['authorizationId'],
            )
        );

        // Handle any fraud response from Braintree
        $this->handleFraud($result, $payment);

        // Store the PayPal token if we have one
        if (isset($result->transaction->paypal['token']) && !empty($result->transaction->paypal['token'])) {
            $payment->setAdditionalInformation('token', $result->transaction->paypal['token']);
        }

        return $payment;
    }

}