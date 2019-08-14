<?php

/**
 * Class Gene_Braintree_Model_Paymentmethod_Creditcard
 *
 * @author Dave Macaulay <dave@gene.co.uk>
 */
class Gene_Braintree_Model_Paymentmethod_Creditcard extends Gene_Braintree_Model_Paymentmethod_Abstract
{
    /**
     * Setup block types
     *
     * @var string
     */
    protected $_formBlockType = 'gene_braintree/creditcard';
    protected $_infoBlockType = 'gene_braintree/creditcard_info';

    /**
     * Set the code
     *
     * @var string
     */
    protected $_code = 'gene_braintree_creditcard';

    /**
     * Payment Method features
     *
     * @var bool
     */
    protected $_isGateway = false;
    protected $_canOrder = false;
    protected $_canAuthorize = true;
    protected $_canCapture = true;
    protected $_canCapturePartial = true;
    protected $_canRefund = false;
    protected $_canRefundInvoicePartial = false;
    protected $_canVoid = false;
    protected $_canUseInternal = true;
    protected $_canUseCheckout = true;
    protected $_canUseForMultishipping = false;
    protected $_isInitializeNeeded = false;
    protected $_canFetchTransactionInfo = false;
    protected $_canReviewPayment = false;
    protected $_canCreateBillingAgreement = false;
    protected $_canManageRecurringProfiles = false;

    /**
     * If we're trying to charge a 3D secure card in the vault we need to build a special nonce
     *
     * @param $paymentMethodToken
     *
     * @return mixed
     */
    public function getThreeDSecureVaultNonce($paymentMethodToken)
    {
        return $this->_getWrapper()->getThreeDSecureVaultNonce($paymentMethodToken);
    }

    /**
     * Is 3D secure enabled?
     *
     * @return bool
     */
    public function is3DEnabled()
    {
        // 3D secure can never be enabled for the admin
        if(Mage::app()->getStore()->isAdmin()) {
            return false;
        }

        if($this->_getConfig('threedsecure')) {
            return true;
        }
        return false;
    }

    /**
     * Do we need to send the CCV, which Braintree calls a CVV?
     *
     * @return mixed
     */
    public function requireCcv()
    {
        if($this->_getConfig('useccv')) {
            return true;
        }
        return false;
    }

    /**
     * Psuedo _authorize function so we can pass in extra data
     * @param Varien_Object $payment
     * @param               $amount
     * @param bool          $shouldCapture
     *
     * @throws Mage_Core_Exception
     */
    protected function _authorize(Varien_Object $payment, $amount, $shouldCapture = false)
    {
        // Retrieve the post data from the request
        $paymentPost = Mage::app()->getRequest()->getPost('payment');

        // Confirm that we have a nonce from Braintree
        if(!isset($paymentPost['card_payment_method_token']) || (isset($paymentPost['card_payment_method_token']) && $paymentPost['card_payment_method_token'] == 'threedsecure')) {
            if ((!isset($paymentPost['payment_method_nonce']) || empty($paymentPost['payment_method_nonce']))) {
                Mage::throwException(
                    $this->_getHelper()->__('Your card payment has failed, please try again.')
                );
            }
        } else if(isset($paymentPost['card_payment_method_token']) && empty($paymentPost['card_payment_method_token'])) {
            Mage::throwException(
                $this->_getHelper()->__('Your card payment has failed, please try again.')
            );
        }

        // Get the device data for fraud screening
        $deviceData = Mage::app()->getRequest()->getPost('device_data');

        // Init the environment
        $this->_getWrapper()->init();

        // Attempt to create the sale
        try {

            // Pass over the CVV/CCV
            if($this->requireCcv() && isset($paymentPost['cc_cid'])) {

                $paymentArray['cvv'] = $paymentPost['cc_cid'];

            } else if($this->requireCcv() && !isset($paymentPost['cc_cid']) && empty($paymentPost['card_payment_method_token'])) {

                // Log it
                Gene_Braintree_Model_Debug::log('CVV required but not present in request');

                // Politely inform the user
                Mage::throwException(
                    $this->_getHelper()->__('We require a CVV when creating card transactions.')
                );

            }

            // Check to see whether we're using a payment method token?
            if(isset($paymentPost['card_payment_method_token']) && !empty($paymentPost['card_payment_method_token']) && !in_array($paymentPost['card_payment_method_token'], array('other', 'threedsecure'))) {

                // Build our payment array
                $paymentArray = array(
                    'paymentMethodToken' => $paymentPost['card_payment_method_token'],
                );

                unset($paymentArray['cvv']);

            } else {

                // Build our payment array with a nonce
                $paymentArray = array(
                    'paymentMethodNonce' => $paymentPost['payment_method_nonce']
                );

            }

            // The 3D secure variable
            $threeDSecure = $this->is3DEnabled();

            // If the user is using a stored card with 3D secure, enable it in the request and remove CVV
            if(isset($paymentPost['card_payment_method_token']) && $paymentPost['card_payment_method_token'] == 'threedsecure') {

                // If we're using 3D secure token card don't send CVV
                unset($paymentArray['cvv']);

                // Force 3D secure on
                $threeDSecure = true;

            } elseif(isset($paymentPost['card_payment_method_token']) && !empty($paymentPost['card_payment_method_token']) && $paymentPost['card_payment_method_token'] != 'other') {

                // Force 3D secure off
                $threeDSecure = false;
            }

            // Retrieve the amount we should capture
            $amount = $this->_getWrapper()->getCaptureAmount($payment->getOrder(), $amount);

            // Build up the sale array
            $saleArray = $this->_getWrapper()->buildSale(
                $amount,
                $paymentArray,
                $payment->getOrder(),
                $shouldCapture,
                $deviceData,
                ($this->isVaultEnabled() && isset($paymentPost['save_card']) && $paymentPost['save_card'] == 1),
                $threeDSecure
            );

            // Pass the sale array into a varien object
            $request = new Varien_Object();
            $request->setData('sale_array', $saleArray);

            // Dispatch event for modifying the sale array
            Mage::dispatchEvent('gene_braintree_creditcard_sale_array', array('payment' => $payment, 'request' => $request));

            // Pull the saleArray back out
            $saleArray = $request->getData('sale_array');

            // Log the initial sale array, no protected data is included
            Gene_Braintree_Model_Debug::log(array('_authorize:saleArray' => $saleArray));

            // Attempt to create the sale
            $result = $this->_getWrapper()->makeSale(
                $saleArray
            );

        } catch (Exception $e) {

            // Dispatch an event for when a payment fails
            Mage::dispatchEvent('gene_braintree_creditcard_failed_exception', array('payment' => $payment, 'exception' => $e));

            // If there's an error
            Gene_Braintree_Model_Debug::log($e);

            Mage::throwException(
                $this->_getHelper()->__('There was an issue whilst trying to process your card payment, please try again or another method.')
            );
        }

        // Log the initial sale array, no protected data is included
        Gene_Braintree_Model_Debug::log(array('_authorize:result' => $result));

        // If the sale has failed
        if ($result->success != true) {

            // Dispatch an event for when a payment fails
            Mage::dispatchEvent('gene_braintree_creditcard_failed', array('payment' => $payment, 'result' => $result));

            // Return a different message for declined cards
            if(isset($result->transaction->status) && $result->transaction->status == Braintree_Transaction::PROCESSOR_DECLINED) {
                Mage::throwException($this->_getHelper()->__('Your transaction has been declined, please try another payment method or contacting your issuing bank.'));
            }

            Mage::throwException($this->_getHelper()->__('%s. Please try again or attempt refreshing the page.', $result->message));
        }

        // If 3D is enabled and the transaction gets a rejection reason
        if($this->is3DEnabled()) {

            // Check the rejection reason
            if (isset($result->transaction) && $result->transaction->gatewayRejectionReason == Braintree_Transaction::THREE_D_SECURE) {

                // An event for when 3D secure fails
                Mage::dispatchEvent('gene_braintree_creditcard_failed_threed', array('payment' => $payment, 'result' => $result));

                // Log it
                Gene_Braintree_Model_Debug::log('Transaction failed with 3D secure');

                // Politely inform the user
                Mage::throwException(
                    $this->_getHelper()->__('Your 3D secure verification has failed, please try using another card, or payment method.')
                );
            }
        }

        $this->_processSuccessResult($payment, $result, $amount);

        return $this;
    }

    /**
     * Authorize the requested amount
     * @param Varien_Object $payment
     * @param float         $amount
     *
     * @return Mage_Payment_Model_Abstract|void
     * @throws Mage_Core_Exception
     */
    public function authorize(Varien_Object $payment, $amount)
    {
        $this->_authorize($payment, $amount, false);
    }

    /**
     * Process capturing of a payment
     * @param Varien_Object $payment
     * @param float         $amount
     *
     * @return Mage_Payment_Model_Abstract|void
     */
    public function capture(Varien_Object $payment, $amount)
    {
        // Has the payment already been authorized?
        if ($payment->getCcTransId()) {

            // Init the environment
            $result = $this->_getWrapper()->init()->submitForSettlement($payment->getCcTransId(), $amount);

            // Log the result
            Gene_Braintree_Model_Debug::log(array('capture:submitForSettlement' => $result));

            if($result->success) {
                $this->_processSuccessResult($payment, $result, $amount);
            } else if($result->errors->deepSize() > 0) {
                Mage::throwException($result->errors);
            } else {
                Mage::throwException($result->transaction->processorSettlementResponseCode.': '.$result->transaction->processorSettlementResponseText);
            }

        } else {
            // Otherwise we need to do an auth & capture at once
            $this->_authorize($payment, $amount, true);
        }

        return $this;
    }

    /**
     * Processes successful authorize/clone result
     *
     * @param Varien_Object $payment
     * @param Braintree_Result_Successful $result
     * @param decimal amount
     * @return Varien_Object
     */
    protected function _processSuccessResult(Varien_Object $payment, $result, $amount)
    {
        // Pass an event if the payment was a success
        Mage::dispatchEvent('gene_braintree_creditcard_success', array('payment' => $payment, 'result' => $result, 'amount' => $amount));

        // Set some basic information about the payment
        $payment->setStatus(self::STATUS_APPROVED)
            ->setCcTransId($result->transaction->id)
            ->setLastTransId($result->transaction->id)
            ->setTransactionId($result->transaction->id)
            ->setIsTransactionClosed(0)
            ->setAmount($amount)
            ->setShouldCloseParentTransaction(false);

        // Set information about the card
        $payment->setCcLast4($result->transaction->creditCardDetails->last4)
            ->setCcType($result->transaction->creditCardDetails->cardType)
            ->setCcExpMonth($result->transaction->creditCardDetails->expirationMonth)
            ->setCcExpYear($result->transaction->creditCardDetails->expirationYear);

        // Additional information to store
        $additionalInfo = array();

        // The fields within the transaction to log
        $storeFields = array(
            'avsErrorResponseCode',
            'avsPostalCodeResponseCode',
            'avsStreetAddressResponseCode',
            'cvvResponseCode',
            'gatewayRejectionReason',
            'processorAuthorizationCode',
            'processorResponseCode',
            'processorResponseText',
            'threeDSecure'
        );

        // If 3D secure is enabled, presume it's passed
        if($this->is3DEnabled()) {
            $additionalInfo['threeDSecure'] = Mage::helper('gene_braintree')->__('Passed');
        }

        // Iterate through and pull out any data we want
        foreach($storeFields as $storeField) {
            if(!empty($result->transaction->{$storeField})) {
                $additionalInfo[$storeField] = $result->transaction->{$storeField};
            }
        }

        // Check it's not empty and store it
        if(!empty($additionalInfo)) {
            $payment->setAdditionalInformation($additionalInfo);
        }

        if (isset($result->transaction->creditCard['token']) && $result->transaction->creditCard['token']) {
            $payment->setAdditionalInformation('token', $result->transaction->creditCard['token']);
        }

        return $payment;
    }

}