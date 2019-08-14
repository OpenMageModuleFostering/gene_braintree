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
    protected $_canUseForMultishipping = true;
    protected $_isInitializeNeeded = false;
    protected $_canFetchTransactionInfo = false;
    protected $_canReviewPayment = true;
    protected $_canCreateBillingAgreement = false;
    protected $_canManageRecurringProfiles = false;

    /**
     * Are we submitting the payment after the initial payment validate?
     *
     * @var bool
     */
    protected $_submitAfterPayment = false;

    /**
     * Place Braintree specific data into the additional information of the payment instance object
     *
     * @param   mixed $data
     *
     * @return  Mage_Payment_Model_Info
     */
    public function assignData($data)
    {
        if (!($data instanceof Varien_Object)) {
            $data = new Varien_Object($data);
        }
        $info = $this->getInfoInstance();
        $info->setAdditionalInformation('card_payment_method_token', $data->getData('card_payment_method_token'))
            ->setAdditionalInformation('payment_method_nonce', $data->getData('payment_method_nonce'))
            ->setAdditionalInformation('save_card', $data->getData('save_card'))
            ->setAdditionalInformation('device_data', $data->getData('device_data'));

        if ($submitAfterPayment = $data->getData('submit_after_payment')) {
            $this->_submitAfterPayment = $submitAfterPayment;
        }

        return $this;
    }


    /**
     * Determine whether or not the vault is enabled, can be modified by numerous events
     *
     * @return bool
     */
    public function isVaultEnabled()
    {
        $object = new Varien_Object();
        $object->setResponse($this->_getConfig('use_vault'));

        // Specific event for this method
        Mage::dispatchEvent('gene_braintree_creditcard_is_vault_enabled', array('object' => $object));

        // General event if we want to enforce saving of all payment methods
        Mage::dispatchEvent('gene_braintree_is_vault_enabled', array('object' => $object));

        return $object->getResponse();
    }

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
        if (Mage::app()->getStore()->isAdmin()) {
            return false;
        }

        /* @var $quote Mage_Sales_Model_Quote */
        $quote = Mage::getSingleton('checkout/cart')->getQuote();

        // Is 3D secure currently enabled?
        $result = $this->_getConfig('threedsecure');

        // Do we have a requirement on the threshold
        if ($result && $this->_getConfig('threedsecure_threshold') > 0) {
            // Check to see if the base grand total is bigger then the threshold
            $result = $quote->collectTotals()->getBaseGrandTotal() >
                $this->_getConfig('threedsecure_threshold');
        }

        // Do we only want to enable 3Ds for specific countries?
        if ($result && $this->_getConfig('threedsecure_allowspecific') ==
            Gene_Braintree_Model_System_Config_Source_Payment_Threedsecurecountries::SPECIFIC_COUNTRIES
        ) {
            $countries = $this->_getConfig('threedsecure_specificcountry');
            if ($countries) {
                $countriesArray = explode(',', $countries);
                if (count($countriesArray) >= 1) {
                    $result = in_array($quote->getBillingAddress()->getCountryId(), $countriesArray);
                }
            }
        }

        return ($result == 1 ? true : false);
    }

    /**
     * Should we save this method in the database?
     *
     * @param \Varien_Object $payment
     * @param                $skipMultishipping
     *
     * @return mixed
     */
    public function shouldSaveMethod($payment, $skipMultishipping = false)
    {
        if ($skipMultishipping === false) {
            // We must always save the method for multi shipping requests
            if ($payment->getMultiShipping() && !$this->_getOriginalToken()) {
                return true;
            } elseif ($this->_getOriginalToken()) {
                // If we have an original token, there is no need to save the same payment method again
                return false;
            }
        }

        // Retrieve whether or not we should save the card from the info instance
        $saveCard = $this->getInfoInstance()->getAdditionalInformation('save_card');

        $object = new Varien_Object();
        $object->setResponse(($this->isVaultEnabled() && $saveCard == 1));

        // Specific event for this method
        Mage::dispatchEvent('gene_braintree_creditcard_should_save_method', array(
            'object' => $object,
            'payment' => $payment
        ));

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
        return $this->getInfoInstance()->getAdditionalInformation('card_payment_method_token');
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
     * Validate payment method information object
     *
     * @return $this
     */
    public function validate()
    {
        // Run the built in Magento validation
        parent::validate();

        // Validation doesn't need to occur now, as the payment has not yet been tokenized
        if ($this->_submitAfterPayment) {
            return $this;
        }

        // Confirm that we have a nonce from Braintree
        if (!$this->getPaymentMethodToken() ||
            ($this->getPaymentMethodToken() && $this->getPaymentMethodToken() == 'threedsecure')
        ) {
            if (!$this->getPaymentMethodNonce()) {
                Gene_Braintree_Model_Debug::log('Card payment has failed, missing token/nonce');
                Gene_Braintree_Model_Debug::log($_SERVER['HTTP_USER_AGENT']);

                Mage::throwException(
                    $this->_getHelper()->__('Your card payment has failed, please try again.')
                );
            }
        } elseif (!$this->getPaymentMethodToken()) {
            Gene_Braintree_Model_Debug::log('No saved card token present');
            Gene_Braintree_Model_Debug::log($_SERVER['HTTP_USER_AGENT']);

            Mage::throwException(
                $this->_getHelper()->__('Your card payment has failed, please try again.')
            );
        }

        return $this;
    }

    /**
     * Psuedo _authorize function so we can pass in extra data
     *
     * @param \Varien_Object $payment
     * @param                $amount
     * @param bool|false     $shouldCapture
     * @param bool|false     $token
     *
     * @return $this
     * @throws \Mage_Core_Exception
     */
    protected function _authorize(Varien_Object $payment, $amount, $shouldCapture = false, $token = false)
    {
        // Init the environment
        $this->_getWrapper()->init();

        // Retrieve the amount we should capture
        $amount = $this->_getWrapper()->getCaptureAmount($payment->getOrder(), $amount);

        // Attempt to create the sale
        try {
            // Build up the sale array
            $saleArray = $this->_getWrapper()->buildSale(
                $amount,
                $this->_buildPaymentRequest($token),
                $payment->getOrder(),
                $shouldCapture,
                $this->getInfoInstance()->getAdditionalInformation('device_data'),
                $this->shouldSaveMethod($payment),
                $this->_is3DEnabled()
            );

            // Attempt to create the sale
            $result = $this->_getWrapper()->makeSale(
                $this->_dispatchSaleArrayEvent('gene_braintree_creditcard_sale_array', $saleArray, $payment)
            );

        } catch (Exception $e) {
            // If we're in developer mode return the message error
            if (Mage::getIsDeveloperMode()) {
                return $this->_processFailedResult($e->getMessage());
            }

            // Handle an exception being thrown
            Mage::dispatchEvent('gene_braintree_creditcard_failed_exception', array(
                'payment' => $payment,
                'exception' => $e
            ));

            return $this->_processFailedResult($this->_getHelper()->__('There was an issue whilst trying to process your card payment, please try again or another method.'), $e);
        }

        // Log the initial sale array, no protected data is included
        Gene_Braintree_Model_Debug::log(array('_authorize:result' => $result));

        // If the transaction was 3Ds but doesn't contain a 3Ds response
        if (($this->is3DEnabled() && isset($saleArray['options']['three_d_secure']['required']) &&
                $saleArray['options']['three_d_secure']['required'] == true) &&
            (!isset($result->transaction->threeDSecureInfo) || (isset($result->transaction->threeDSecureInfo) &&
                    is_null($result->transaction->threeDSecureInfo)))
        ) {
            return $this->_processFailedResult($this->_getHelper()->__('This transaction must be passed through 3D secure, please try again or consider using an alternate payment method.'), false, $result);

        }

        // If the sale has failed
        if ($result->success != true) {
            // Dispatch an event for when a payment fails
            Mage::dispatchEvent('gene_braintree_creditcard_failed', array('payment' => $payment, 'result' => $result));

            // Return a different message for declined cards
            if (isset($result->transaction->status)) {
                // Return a custom response for processor declined messages
                if ($result->transaction->status == Braintree_Transaction::PROCESSOR_DECLINED) {
                    return $this->_processFailedResult($this->_getHelper()->__('Your transaction has been declined, please try another payment method or contacting your issuing bank.'), false, $result);

                } elseif ($result->transaction->status == Braintree_Transaction::GATEWAY_REJECTED
                    && isset($result->transaction->gatewayRejectionReason)
                    && $result->transaction->gatewayRejectionReason == Braintree_Transaction::THREE_D_SECURE
                ) {
                    // An event for when 3D secure fails
                    Mage::dispatchEvent('gene_braintree_creditcard_failed_threed', array(
                        'payment' => $payment,
                        'result' => $result
                    ));

                    return $this->_processFailedResult($this->_getHelper()->__('Your card has failed 3D secure validation, please try again or consider using an alternate payment method.'), 'Transaction failed with 3D secure', false, $result);
                }
            }

            return $this->_processFailedResult($this->_getHelper()->__('%s Please try again or attempt refreshing the page.', $this->_getWrapper()->parseMessage($result->message)), $result);
        }

        $this->_processSuccessResult($payment, $result, $amount);

        return $this;
    }

    /**
     * Build up the payment request
     *
     * @param $token
     *
     * @return array
     */
    protected function _buildPaymentRequest($token)
    {
        $paymentArray = array();

        // If we have an original token use that for the subsequent requests
        if ($originalToken = $this->_getOriginalToken()) {
            $paymentArray['paymentMethodToken'] = $originalToken;

            return $paymentArray;
        }

        // Check to see whether we're using a payment method token?
        if ($this->getPaymentMethodToken() &&
            !in_array($this->getPaymentMethodToken(), array('other', 'threedsecure'))
        ) {
            // Build our payment array
            $paymentArray['paymentMethodToken'] = $this->getPaymentMethodToken();
            unset($paymentArray['cvv']);
        } else {
            // Build our payment array with a nonce
            $paymentArray['paymentMethodNonce'] = $this->getPaymentMethodNonce();
        }

        // If the user is using a stored card with 3D secure, enable it in the request and remove CVV
        if ($this->getPaymentMethodToken() && $this->getPaymentMethodToken() == 'threedsecure') {
            // If we're using 3D secure token card don't send CVV
            unset($paymentArray['cvv']);
        }

        // If a token is present in the request use that
        if ($token) {
            // Remove this unneeded data
            unset($paymentArray['paymentMethodNonce'], $paymentArray['cvv']);

            // Send the token as the payment array
            $paymentArray['paymentMethodToken'] = $token;
        }

        return $paymentArray;
    }

    /**
     * Is 3D secure enabled based on the current data?
     *
     * @return bool
     */
    protected function _is3DEnabled()
    {
        // If we're creating the transaction from an original token we cannot use 3Ds currently
        if ($this->_getOriginalToken()) {
            return false;
        }

        if ($this->getPaymentMethodToken() && $this->getPaymentMethodToken() == 'threedsecure') {
            return true;
        } elseif ($this->getPaymentMethodToken() && $this->getPaymentMethodToken() != 'other') {
            return false;
        }

        return $this->is3DEnabled();
    }

    /**
     * Authorize the requested amount
     *
     * @param \Varien_Object $payment
     * @param float          $amount
     *
     * @return \Gene_Braintree_Model_Paymentmethod_Creditcard
     */
    public function authorize(Varien_Object $payment, $amount)
    {
        return $this->_authorize($payment, $amount, false);
    }

    /**
     * Process capturing of a payment
     *
     * @param \Varien_Object $payment
     * @param float          $amount
     *
     * @return \Mage_Payment_Model_Abstract
     */
    public function capture(Varien_Object $payment, $amount)
    {
        return $this->_captureAuthorized($payment, $amount);
    }

    /**
     * Processes successful authorize/clone result
     *
     * @param Varien_Object               $payment
     * @param Braintree_Result_Successful $result
     * @param float                       $amount
     *
     * @return Varien_Object
     */
    protected function _processSuccessResult(Varien_Object $payment, $result, $amount)
    {
        // Pass an event if the payment was a success
        Mage::dispatchEvent('gene_braintree_creditcard_success', array(
            'payment' => $payment,
            'result' => $result,
            'amount' => $amount
        ));

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
            'threeDSecure',
            'kount_id',
            'kount_session_id'
        );

        // Handle any fraud response from Braintree
        $this->handleFraud($result, $payment);

        // If 3D secure is enabled, presume it's passed
        if ($this->_is3DEnabled()
            && isset($result->transaction->threeDSecureInfo->liabilityShifted)
            && $result->transaction->threeDSecureInfo->liabilityShifted == 1
        ) {
            $additionalInfo['threeDSecure'] = Mage::helper('gene_braintree')->__('Passed');
        }

        // Iterate through and pull out any data we want
        foreach ($storeFields as $storeField) {
            if (isset($result->transaction->{$storeField}) && !empty($result->transaction->{$storeField})) {
                $additionalInfo[$storeField] = $result->transaction->{$storeField};
            } elseif ($value = $payment->getAdditionalInformation($storeField)) {
                $additionalInfo[$storeField] = $value;
            }
        }

        // Check it's not empty and store it
        if (!empty($additionalInfo)) {
            $payment->setAdditionalInformation($additionalInfo);
        }

        if (isset($result->transaction->creditCard['token']) && $result->transaction->creditCard['token']) {
            $payment->setAdditionalInformation('token', $result->transaction->creditCard['token']);

            // If the transaction is part of a multi shipping transaction store the token for the next order
            if ($payment->getMultiShipping() && !$this->_getOriginalToken()) {
                $this->_setOriginalToken($result->transaction->creditCard['token']);

                // If we shouldn't have this method saved, add it into the session to be removed once the request is
                // complete
                if (!$this->shouldSaveMethod($payment, true)) {
                    Mage::getSingleton('checkout/session')->setTemporaryPaymentToken(
                        $result->transaction->creditCard['token']
                    );
                }
            }
        }

        return $payment;
    }

}