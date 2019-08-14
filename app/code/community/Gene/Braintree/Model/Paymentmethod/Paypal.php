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
    protected $_canRefund = false;
    protected $_canRefundInvoicePartial = false;
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
     * Is the vault enabled?
     *
     * @return bool
     */
    public function isVaultEnabled()
    {
        if ($this->_getConfig('payment_type') == Gene_Braintree_Model_Source_Paypal_Paymenttype::GENE_BRAINTREE_PAYPAL_FUTURE_PAYMENTS
            && $this->_getConfig('use_vault'))
        {
            return true;
        }
        return false;
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
        // Retrieve the payment data from the request
        $paymentPost = Mage::app()->getRequest()->getPost('payment');

        // Confirm that we have a nonce from Braintree
        if(!isset($paymentPost['paypal_payment_method_token'])) {
            if ((!isset($paymentPost['payment_method_nonce']) || empty($paymentPost['payment_method_nonce']))) {
                Mage::throwException(
                    $this->_getHelper()->__('There has been an issue processing your PayPal payment, please try again.')
                );
            }
        } else if(isset($paymentPost['paypal_payment_method_token']) && empty($paymentPost['paypal_payment_method_token'])) {
            Mage::throwException(
                $this->_getHelper()->__('There has been an issue processing your PayPal payment, please try again.')
            );
        }

        // Get the device data for fraud screening
        $deviceData = Mage::app()->getRequest()->getPost('device_data');

        // Init the environment
        $this->_getWrapper()->init();

        if(isset($paymentPost['paypal_payment_method_token']) && !empty($paymentPost['paypal_payment_method_token']) && $paymentPost['paypal_payment_method_token'] != 'other') {
            $paymentArray = array(
                'paymentMethodToken' => $paymentPost['paypal_payment_method_token']
            );
        } else {
            $paymentArray = array(
                'paymentMethodNonce' => $paymentPost['payment_method_nonce']
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
                $deviceData,
                ($this->isVaultEnabled() && isset($paymentPost['save_paypal']) && $paymentPost['save_paypal'] == 1)
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

        // Save the payment data
        $payment->save();

        return $payment;
    }

}