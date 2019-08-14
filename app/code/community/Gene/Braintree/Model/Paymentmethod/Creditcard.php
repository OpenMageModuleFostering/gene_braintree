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
    protected $_canRefund = true;
    protected $_canRefundInvoicePartial = true;
    protected $_canVoid = true;
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

        // Is 3Ds enabled within the configuration?
        if($this->_getConfig('threedsecure')) {

            // Do we have a requirement on the threshold
            if($this->_getConfig('threedsecure_threshold') > 0) {

                // Check to see if the base grand total is bigger then the threshold
                if(Mage::getSingleton('checkout/cart')->getQuote()->collectTotals()->getBaseGrandTotal() > $this->_getConfig('threedsecure_threshold')) {
                    return true;
                }

                return false;
            }

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
     * Validate payment method information object
     *
     * @return $this
     */
    public function validate()
    {
        // Run the built in Magento validation
        parent::validate();

        // Retrieve the post data from the request
        $paymentPost = Mage::app()->getRequest()->getPost('payment');

        // Confirm that we have a nonce from Braintree
        if (!isset($paymentPost['card_payment_method_token']) || (isset($paymentPost['card_payment_method_token']) && $paymentPost['card_payment_method_token'] == 'threedsecure')) {
            if ((!isset($paymentPost['payment_method_nonce']) || empty($paymentPost['payment_method_nonce']))) {

                Gene_Braintree_Model_Debug::log('Card payment has failed, missing token/nonce');

                Mage::throwException(
                    $this->_getHelper()->__('Your card payment has failed, please try again.')
                );
            }
        } else if (isset($paymentPost['card_payment_method_token']) && empty($paymentPost['card_payment_method_token'])) {

            Gene_Braintree_Model_Debug::log('No saved card token present');

            Mage::throwException(
                $this->_getHelper()->__('Your card payment has failed, please try again.')
            );
        }

        // If the CVV is required and it's not been sent in the request throw an error
        if ($this->requireCcv() && (!isset($paymentPost['cc_cid']) || empty($paymentPost['cc_cid'])) && empty($paymentPost['card_payment_method_token'])) {

            // Log it
            Gene_Braintree_Model_Debug::log('CVV required but not present in request');

            // Politely inform the user
            Mage::throwException(
                $this->_getHelper()->__('We require a CVV when creating card transactions.')
            );

        }


        return $this;
    }

    /**
     * Psuedo _authorize function so we can pass in extra data
     * @param Varien_Object $payment
     * @param               $amount
     * @param bool          $shouldCapture
     *
     * @throws Mage_Core_Exception
     */
    protected function _authorize(Varien_Object $payment, $amount, $shouldCapture = false, $token = false)
    {
        // Retrieve the post data from the request
        $paymentPost = Mage::app()->getRequest()->getPost('payment');

        // Get the device data for fraud screening
        $deviceData = Mage::app()->getRequest()->getPost('device_data');

        // Init the environment
        $this->_getWrapper()->init();

        // Attempt to create the sale
        try {

            // Pass over the CVV/CCV
            if ($this->requireCcv() && isset($paymentPost['cc_cid'])) {
                $paymentArray['cvv'] = $paymentPost['cc_cid'];
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

            // If a token is present in the request use that
            if($token) {

                // Remove this unneeded data
                unset($paymentArray['paymentMethodNonce'], $paymentArray['cvv']);

                // Send the token as the payment array
                $paymentArray['paymentMethodToken'] = $token;
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

        // If the transaction was 3Ds but doesn't contain a 3Ds response
        if(($this->is3DEnabled() && isset($saleArray['options']['three_d_secure']['required']) && $saleArray['options']['three_d_secure']['required'] == true) && (!isset($result->transaction->threeDSecureInfo) || (isset($result->transaction->threeDSecureInfo) && is_null($result->transaction->threeDSecureInfo)))) {

            // Inform the user that their payment didn't go through 3Ds and thus failed
            Mage::throwException($this->_getHelper()->__('This transaction must be passed through 3D secure, please try again or consider using an alternate payment method.'));
        }

        // If the sale has failed
        if ($result->success != true) {

            // Dispatch an event for when a payment fails
            Mage::dispatchEvent('gene_braintree_creditcard_failed', array('payment' => $payment, 'result' => $result));

            // Return a different message for declined cards
            if(isset($result->transaction->status)) {

                // Return a custom response for processor declined messages
                if($result->transaction->status == Braintree_Transaction::PROCESSOR_DECLINED) {

                    Mage::throwException($this->_getHelper()->__('Your transaction has been declined, please try another payment method or contacting your issuing bank.'));

                } else if($result->transaction->status == Braintree_Transaction::GATEWAY_REJECTED
                    && isset($result->transaction->gatewayRejectionReason)
                    && $result->transaction->gatewayRejectionReason == Braintree_Transaction::THREE_D_SECURE)
                {

                    // An event for when 3D secure fails
                    Mage::dispatchEvent('gene_braintree_creditcard_failed_threed', array('payment' => $payment, 'result' => $result));

                    // Log it
                    Gene_Braintree_Model_Debug::log('Transaction failed with 3D secure');

                    // Politely inform the user
                    Mage::throwException($this->_getHelper()->__('Your card has failed 3D secure validation, please try again or consider using an alternate payment method.'));
                }
            }

            Mage::throwException($this->_getHelper()->__('%s. Please try again or attempt refreshing the page.', $result->message));
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

            // Convert the capture amount to the correct currency
            $captureAmount = $this->_getWrapper()->getCaptureAmount($payment->getOrder(), $amount);

            // Has the authorization already been settled? Partial invoicing
            if($this->authorizationUsed($payment)) {

                // Set the token as false
                $token = false;

                // Was the original payment created with a token?
                if($additionalInfoToken = $payment->getAdditionalInformation('token')) {

                    try {
                        // Init the environment
                        $this->_getWrapper()->init($payment->getOrder()->getStoreId());

                        // Attempt to find the token
                        Braintree_PaymentMethod::find($additionalInfoToken);

                        // Set the token if a success
                        $token = $additionalInfoToken;

                    } catch (Exception $e) {
                        $token = false;
                    }

                }

                // If we managed to find a token use that for the capture
                if($token) {

                    // Stop processing the rest of the method
                    // We pass $amount instead of $captureAmount as the authorize function contains the conversion
                    $this->_authorize($payment, $amount, true, $token);
                    return $this;

                } else {

                    // Attempt to clone the transaction
                    $result = $this->_getWrapper()->init($payment->getOrder()->getStoreId())->cloneTransaction($payment->getLastTransId(), $captureAmount);
                }

            } else {

                // Init the environment
                $result = $this->_getWrapper()->init($payment->getOrder()->getStoreId())->submitForSettlement($payment->getCcTransId(), $captureAmount);

                // Log the result
                Gene_Braintree_Model_Debug::log(array('capture:submitForSettlement' => $result));
            }

            if($result->success) {
                $this->_processSuccessResult($payment, $result, $amount);
            } else if($result->errors->deepSize() > 0) {
                Mage::throwException($this->_getWrapper()->parseErrors($result->errors->deepAll()));
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
     * If we're doing authorize, has the payment already got more than one transaction?
     *
     * @param \Varien_Object $payment
     *
     * @return int
     */
    public function authorizationUsed(Varien_Object $payment)
    {
        $collection = Mage::getModel('sales/order_payment_transaction')
            ->getCollection()
            ->addFieldToFilter('payment_id', $payment->getId())
            ->addFieldToFilter('txn_type', Mage_Sales_Model_Order_Payment_Transaction::TYPE_CAPTURE);

        return $collection->getSize();
    }

    /**
     * Void payment abstract method
     *
     * @param Varien_Object $payment
     *
     * @return Mage_Payment_Model_Abstract
     */
    public function void(Varien_Object $payment)
    {
        try {
            // Init the environment
            $this->_getWrapper()->init($payment->getOrder()->getStoreId());

            // Retrieve the transaction ID
            $transactionId = $this->_getWrapper()->getCleanTransactionId($payment->getLastTransId());

            // Load the transaction from Braintree
            $transaction = Braintree_Transaction::find($transactionId);

            // If the transaction hasn't yet settled we can't do partial refunds
            if ($transaction->status !== Braintree_Transaction::AUTHORIZED || $transaction->status !== Braintree_Transaction::SUBMITTED_FOR_SETTLEMENT) {
                Mage::throwException($this->_getHelper()->__('You can only void authorized/submitted for settlement payments, please setup a credit memo if you wish to refund this order.'));
            }

            // Swap between refund and void
            $result = Braintree_Transaction::void($transactionId);

            // If it's a success close the transaction
            if ($result->success) {
                $payment->setIsTransactionClosed(1);
            } else {
                if($result->errors->deepSize() > 0) {
                    Mage::throwException($this->_getWrapper()->parseErrors($result->errors->deepAll()));
                } else {
                    Mage::throwException('Unknown');
                }
            }

        } catch (Exception $e) {
            Mage::throwException($this->_getHelper()->__('An error occurred whilst trying to void the transaction: ') . $e->getMessage());
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

        // Handle any fraud response from Braintree
        $this->handleFraud($result, $payment);

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