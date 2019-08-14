<?php

/**
 * Class Gene_Braintree_Model_Wrapper_Braintree
 *
 * @author Dave Macaulay <dave@gene.co.uk>
 */
class Gene_Braintree_Model_Wrapper_Braintree extends Mage_Core_Model_Abstract
{

    CONST BRAINTREE_ENVIRONMENT_PATH = 'payment/gene_braintree/environment';
    CONST BRAINTREE_MERCHANT_ID_PATH = 'payment/gene_braintree/merchant_id';
    CONST BRAINTREE_MERCHANT_ACCOUNT_ID_PATH = 'payment/gene_braintree/merchant_account_id';
    CONST BRAINTREE_PUBLIC_KEY_PATH = 'payment/gene_braintree/public_key';
    CONST BRAINTREE_PRIVATE_KEY_PATH = 'payment/gene_braintree/private_key';

    const BRAINTREE_MULTI_CURRENCY = 'payment/gene_braintree/multi_currency_enable';
    const BRAINTREE_MULTI_CURRENCY_MAPPING = 'payment/gene_braintree/multi_currency_mapping';

    /**
     * Store the customer
     *
     * @var Braintree_Customer
     */
    private $customer;

    /**
     * Store the Braintree ID
     *
     * @var int
     */
    private $braintreeId;

    /**
     * Used to track whether the payment methods are available
     *
     * @var bool
     */
    private $validated = null;

    /**
     * If we're using a mapped currency we need to charge the grand total, instead of the base
     *
     * @var bool
     */
    private $mappedCurrency = false;

    /**
     * Store whether or not we've init the environment yet
     *
     * @var bool
     */
    private $init = false;

    /**
     * Setup the environment
     *
     * @return $this
     */
    public function init($store = null)
    {
        if(!$this->init) {

            // Setup the various configuration variables
            Braintree_Configuration::environment(Mage::getStoreConfig(self::BRAINTREE_ENVIRONMENT_PATH, $store));
            Braintree_Configuration::merchantId(Mage::getStoreConfig(self::BRAINTREE_MERCHANT_ID_PATH, $store));
            Braintree_Configuration::publicKey(Mage::getStoreConfig(self::BRAINTREE_PUBLIC_KEY_PATH, $store));
            Braintree_Configuration::privateKey(Mage::getStoreConfig(self::BRAINTREE_PRIVATE_KEY_PATH, $store));

            // Set our flag
            $this->init = true;
        }

        return $this;
    }

    /**
     * Find a transaction
     *
     * @param $transactionId
     *
     * @throws Braintree_Exception_NotFound
     */
    public function findTransaction($transactionId)
    {
        return Braintree_Transaction::find($transactionId);
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
        $this->init();

        $result = Braintree_PaymentMethodNonce::create($paymentMethodToken);
        return $result->paymentMethodNonce->nonce;
    }

    /**
     * Try and load the Braintree customer from the stored customer ID
     *
     * @param $braintreeCustomerId
     *
     * @return Braintree_Customer
     */
    public function getCustomer($braintreeCustomerId)
    {
        // Try and load it from the customer
        if(!$this->customer && !isset($this->customer[$braintreeCustomerId])) {
            try {
                $this->customer[$braintreeCustomerId] = Braintree_Customer::find($braintreeCustomerId);
            } catch (Exception $e) {
                return false;
            }
        }

        return $this->customer[$braintreeCustomerId];
    }

    /**
     * Check to see whether this customer already exists
     *
     * @return bool|object
     */
    public function checkIsCustomer()
    {
        try {
            // Check to see that we can generate a braintree ID
            if($braintreeId = $this->getBraintreeId()) {

                // Proxy this request to the other method which has caching
                return $this->getCustomer($braintreeId);
            }
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Generate a server side token with the specified account ID
     *
     * @return mixed
     */
    public function generateToken()
    {
        // Use the class to generate the token
        return Braintree_ClientToken::generate();
    }


    /**
     * Check a customer owns the method we're trying to modify
     *
     * @param $paymentMethod
     *
     * @return bool
     */
    public function customerOwnsMethod($paymentMethod)
    {
        // Grab the customer ID from the customers account
        $customerId = Mage::getSingleton('customer/session')->getCustomer()->getBraintreeCustomerId();

        // Detect which type of payment method we've got here
        if($paymentMethod instanceof Braintree_PayPalAccount) {

            // Grab the customer
            $customer = $this->getCustomer($customerId);

            // Store all the tokens in an array
            $customerTokens = array();

            // Check the customer has PayPal Accounts
            if(isset($customer->paypalAccounts)) {

                /* @var $payPalAccount Braintree_PayPalAccount */
                foreach($customer->paypalAccounts as $payPalAccount) {
                    if(isset($payPalAccount->token)) {
                        $customerTokens[] = $payPalAccount->token;
                    }
                }
            } else {
                return false;
            }

            // Check to see if this customer account contains this token
            if(in_array($paymentMethod->token, $customerTokens)) {
                return true;
            }

            return false;

        } else if(isset($paymentMethod->customerId) && $paymentMethod->customerId == $customerId) {

            return true;
        }

        return false;
    }

    /**
     * Retrieve the Braintree ID from Magento
     *
     * @return bool|string
     */
    protected function getBraintreeId()
    {
        // Some basic caching
        if(!$this->braintreeId) {

            // Is the customer already logged in
            if (Mage::getSingleton('customer/session')->isLoggedIn()) {

                // Retrieve the current customer
                $customer = Mage::getSingleton('customer/session')->getCustomer();

                // Determine whether they have a braintree customer ID already
                if ($brainteeId = $customer->getBraintreeCustomerId()) {
                    $this->braintreeId = $customer->getBraintreeCustomerId();
                } else {
                    // If not let's create them one
                    $this->braintreeId = $this->buildCustomerId();
                    $customer->setBraintreeCustomerId($this->braintreeId)->save();
                }

            } else {
                if ((Mage::getSingleton('checkout/type_onepage')->getCheckoutMethod() == 'login_in' || Mage::getSingleton('checkout/type_onepage')->getCheckoutMethod() == Mage_Checkout_Model_Type_Onepage::METHOD_REGISTER)) {

                    // Check to see if we've already generated an ID
                    if($braintreeId = Mage::getSingleton('checkout/session')->getBraintreeCustomerId()) {
                        $this->braintreeId = $braintreeId;
                    } else {
                        // If the user plans to register let's build them an ID and store it in their session
                        $this->braintreeId = $this->buildCustomerId();
                        Mage::getSingleton('checkout/session')->setBraintreeCustomerId($this->braintreeId);
                    }
                }
            }

        }

        return $this->braintreeId;
    }


    /**
     * Validate the credentials within the admin area
     *
     * @return bool
     */
    public function validateCredentials($prettyResponse = false, $alreadyInit = false, $merchantAccountId = false)
    {
        // Try to init the environment
        try {
            if(!$alreadyInit) {

                // If we're within the admin we want to grab these values from whichever store we're modifying
                if(Mage::app()->getStore()->isAdmin()) {
                    Braintree_Configuration::environment(Mage::getSingleton('adminhtml/config_data')->getConfigDataValue(self::BRAINTREE_ENVIRONMENT_PATH));
                    Braintree_Configuration::merchantId(Mage::getSingleton('adminhtml/config_data')->getConfigDataValue(self::BRAINTREE_MERCHANT_ID_PATH));
                    Braintree_Configuration::publicKey(Mage::getSingleton('adminhtml/config_data')->getConfigDataValue(self::BRAINTREE_PUBLIC_KEY_PATH));
                    Braintree_Configuration::privateKey(Mage::getSingleton('adminhtml/config_data')->getConfigDataValue(self::BRAINTREE_PRIVATE_KEY_PATH));
                } else {
                    $this->init();
                }
            }
        } catch (Exception $e) {

            if($prettyResponse) {
                return '<span style="color: red;font-weight: bold;" id="braintree-valid-config">' . Mage::helper('gene_braintree')->__('Invalid Credentials') . '</span><br />' . Mage::helper('gene_braintree')->__('Payments cannot be processed until this is resolved, due to this the methods will be hidden within the checkout');
            }
            return false;
        }

        // Check to see if we've been passed the merchant account ID?
        if(!$merchantAccountId) {
            if(Mage::app()->getStore()->isAdmin()) {
                $merchantAccountId = Mage::getSingleton('adminhtml/config_data')->getConfigDataValue(self::BRAINTREE_MERCHANT_ACCOUNT_ID_PATH);
            } else {
                $merchantAccountId = $this->getMerchantAccountId();
            }
        }

        // Validate the merchant account ID
        try {
            Braintree_Configuration::gateway()->merchantAccount()->find($merchantAccountId);
        } catch (Exception $e) {
            if($prettyResponse) {
                return '<span style="color: orange;font-weight: bold;" id="braintree-valid-config">' . Mage::helper('gene_braintree')->__('Invalid Merchant Account ID') . '</span><br />' . Mage::helper('gene_braintree')->__('Payments cannot be processed until this is resolved. We cannot find your merchant account ID associated with the other credentials you\'ve provided, please update this field');
            }
            return false;
        }

        if($prettyResponse) {
            return '<span style="color: green;font-weight: bold;" id="braintree-valid-config">' . Mage::helper('gene_braintree')->__('Valid Credentials') . '</span><br />' . Mage::helper('gene_braintree')->__('You\'re ready to accept payments via Braintree');
        }
        return true;
    }

    /**
     * Validate the credentials once, this is used during the payment methods available check
     * @return bool
     */
    public function validateCredentialsOnce()
    {
        // Check to see if it's been validated yet
        if(is_null($this->validated)) {

            // Check the Braintree lib version is above 2.32, as this is when 3D secure appeared
            if (Braintree_Version::get() < 2.32) {
                $this->validated = false;
            } else {

                // Check that the module is fully setup
                if (!Mage::getStoreConfig(Gene_Braintree_Model_Wrapper_Braintree::BRAINTREE_ENVIRONMENT_PATH)
                    || !Mage::getStoreConfig(Gene_Braintree_Model_Wrapper_Braintree::BRAINTREE_MERCHANT_ID_PATH)
                    || !Mage::getStoreConfig(Gene_Braintree_Model_Wrapper_Braintree::BRAINTREE_PUBLIC_KEY_PATH)
                    || !Mage::getStoreConfig(Gene_Braintree_Model_Wrapper_Braintree::BRAINTREE_PRIVATE_KEY_PATH)
                ) {
                    // If not the payment methods aren't available
                    $this->validated = false;

                } else {

                    // Try and validate the stored credentials
                    if (!Mage::getModel('gene_braintree/wrapper_braintree')->validateCredentials()) {

                        // Only add this in if it's not the last notice
                        $latestNotice = Mage::getModel('adminnotification/inbox')->loadLatestNotice();

                        // Validate there is a latest notice
                        if ($latestNotice && $latestNotice->getId()) {

                            // Check to see if the title contains our error
                            // Magento does not provide a nice way of doing this that I'm aware of
                            if (strpos($latestNotice->getTitle(), 'Braintree Configuration Invalid') === false) {

                                // If it doesn't add it again!
                                Mage::getModel('adminnotification/inbox')->addMajor(Mage::helper('gene_braintree')->__('Braintree Configuration Invalid - %s - This could be stopping payments', Mage::app()->getStore()->getFrontendName()), Mage::helper('gene_braintree')->__('The configuration values in the Magento Braintree v.zero module are incorrect, until these values are corrected the system can not function. This occurred on store %s - ID: %s', Mage::app()->getStore()->getFrontendName(), Mage::app()->getStore()->getId()));
                            }

                        } else {

                            // Otherwise there hasn't been any other notices
                            Mage::getModel('adminnotification/inbox')->addMajor(Mage::helper('gene_braintree')->__('Braintree Configuration Invalid - %s - This could be stopping payments', Mage::app()->getStore()->getFrontendName()), Mage::helper('gene_braintree')->__('The configuration values in the Magento Braintree v.zero module are incorrect, until these values are corrected the system can not function. This occurred on store %s - ID: %s', Mage::app()->getStore()->getFrontendName(), Mage::app()->getStore()->getId()));
                        }

                        $this->validated = false;

                    } else {

                        // Otherwise the method validated
                        $this->validated = true;
                    }
                }
            }
        }

        return $this->validated;
    }

    /**
     * Build up the sale request
     *
     * @param $amount
     * @param array $paymentDataArray
     * @param Mage_Sales_Model_Order $order
     * @param bool $submitForSettlement
     * @param bool $deviceData
     * @param bool $storeInVault
     * @param bool $threeDSecure
     * @param array $extra
     *
     * @return array
     *
     * @throws Mage_Core_Exception
     */
    public function buildSale(
        $amount,
        array $paymentDataArray,
        Mage_Sales_Model_Order $order,
        $submitForSettlement = true,
        $deviceData = false,
        $storeInVault = false,
        $threeDSecure = false,
        $extra = array()
    ) {
        // Check we always have an ID
        if (!$order->getIncrementId()) {
            Mage::throwException('Your order has become invalid, please try refreshing.');
        }

        // Store whether or not we created a new method
        $createdMethod = false;

        // If the user is already a customer and wants to store in the vault we've gotta do something a bit special
        if($storeInVault && $this->checkIsCustomer() && isset($paymentDataArray['paymentMethodNonce'])) {

            // Create the payment method with this data
            $paymentMethodCreate = array(
                'customerId' => $this->getBraintreeId(),
                'paymentMethodNonce' => $paymentDataArray['paymentMethodNonce'],
                'billingAddress' => $this->buildAddress($order->getBillingAddress())
            );

            // Log the create array
            Gene_Braintree_Model_Debug::log(array('Braintree_PaymentMethod' => $paymentMethodCreate));

            // Create a new billing method
            $result = Braintree_PaymentMethod::create($paymentMethodCreate);

            // Log the response from Braintree
            Gene_Braintree_Model_Debug::log(array('Braintree_PaymentMethod:result' => $paymentMethodCreate));

            // Verify the storing of the card was a success
            if(isset($result->success) && $result->success == true) {

                /* @var $paymentMethod Braintree_CreditCard */
                $paymentMethod = $result->paymentMethod;

                // Check to see if the token is set
                if(isset($paymentMethod->token) && !empty($paymentMethod->token)) {

                    // We no longer need this nonce
                    unset($paymentDataArray['paymentMethodNonce']);

                    // Instead use the token
                    $paymentDataArray['paymentMethodToken'] = $paymentMethod->token;

                    // Create a flag for other methods
                    $createdMethod = true;
                }

            } else {
                Mage::throwException($result->message . Mage::helper('gene_braintree')->__(' Please try again or attempt refreshing the page.'));
            }
        }

        // Build up the initial request parameters
        $request = array(
            'amount'             => $amount,
            'orderId'            => $order->getIncrementId(),
            'merchantAccountId'  => $this->getMerchantAccountId(),
            'channel'            => 'MagentoVZero',
            'options'            => array(
                'submitForSettlement' => $submitForSettlement,
                'storeInVault'        => $storeInVault
            )
        );

        // Input the allowed payment method info
        $allowedPaymentInfo = array('paymentMethodNonce','paymentMethodToken','token','cvv');
        foreach($paymentDataArray as $key => $value) {
            if(in_array($key, $allowedPaymentInfo)) {
                if($key == 'cvv') {
                    $request['creditCard']['cvv'] = $value;
                } else {
                    $request[$key] = $value;
                }
            } else {
                Mage::throwException($key.' is not allowed within $paymentDataArray');
            }
        }

        // Include the customer if we're creating a new one
        if(!$this->checkIsCustomer() && (Mage::getSingleton('customer/session')->isLoggedIn() ||
                (Mage::getSingleton('checkout/type_onepage')->getCheckoutMethod() == 'login_in' || Mage::getSingleton('checkout/type_onepage')->getCheckoutMethod() == Mage_Checkout_Model_Type_Onepage::METHOD_REGISTER))) {
            $request['customer'] = $this->buildCustomer($order);
        } else {
            // If the customer exists but we aren't using the vault we want to pass a customer object with no ID
            $request['customer'] = $this->buildCustomer($order, false);
        }

        // Do we have any deviceData to send over?
        if ($deviceData) {
            $request['deviceData'] = $deviceData;
        }

        // Include the shipping address
        if ($order->getShippingAddress()) {
            $request['shipping'] = $this->buildAddress($order->getShippingAddress());
        }

        // Include the billing address
        if ($order->getBillingAddress()) {
            $request['billing'] = $this->buildAddress($order->getBillingAddress());
        }

        // Is 3D secure enabled?
        if($threeDSecure !== false && !$createdMethod) {
            $request['options']['three_d_secure']['required'] = true;
        }

        // Any extra information we want to supply
        if(!empty($extra) && is_array($extra)) {
            $request = array_merge($request, $extra);
        }

        return $request;
    }

    /**
     * Attempt to make the sale
     *
     * @param $saleArray
     *
     * @return stdClass
     */
    public function makeSale($saleArray)
    {
        // Call the braintree library
        return Braintree_Transaction::sale(
            $saleArray
        );
    }

    /**
     * Submit a payment for settlement
     *
     * @param $transactionId
     * @param $amount
     *
     * @throws Mage_Core_Exception
     */
    public function submitForSettlement($transactionId, $amount)
    {
        // Attempt to submit for settlement
        $result = Braintree_Transaction::submitForSettlement($transactionId, $amount);

        return $result;
    }

    /**
     * Build the customers ID, md5 a uniquid
     *
     * @param Mage_Sales_Model_Order $order
     *
     * @return string
     * @throws Mage_Core_Exception
     */
    private function buildCustomerId()
    {
        return md5(uniqid('braintree_',true));
    }

    /**
     * Build a Magento address model into a Braintree array
     *
     * @param Mage_Sales_Model_Order_Address $address
     *
     * @return array
     */
    private function buildAddress(Mage_Sales_Model_Order_Address $address)
    {
        // Build up the initial array
        $return = array(
            'firstName'         => $address->getFirstname(),
            'lastName'          => $address->getLastname(),
            'streetAddress'     => $address->getStreet1(),
            'locality'          => $address->getCity(),
            'postalCode'        => $address->getPostcode(),
            'countryCodeAlpha2' => $address->getCountry()
        );

        // Any extended address?
        if ($address->getStreet2()) {
            $return['extendedAddress'] = $address->getStreet2();
        }

        // Region
        if ($address->getRegion()) {
            $return['region'] = $address->getRegionCode();
        }

        // Check to see if we have a company
        if ($address->getCompany()) {
            $return['company'] = $address->getCompany();
        }

        return $return;
    }

    /**
     * Return the correct merchant account ID
     *
     * @return mixed
     */
    public function getMerchantAccountId()
    {
        // If multi-currency is enabled use the mapped merchant account ID
        if($currencyCode = $this->hasMappedCurrencyCode()) {

            // Return the mapped currency code
            return $currencyCode;
        }

        // Otherwise return the one from the store
        return Mage::getStoreConfig(self::BRAINTREE_MERCHANT_ACCOUNT_ID_PATH);
    }

    /**
     * If we have a mapped currency code return it
     *
     * @return bool
     */
    public function hasMappedCurrencyCode()
    {
        // If multi-currency is enabled use the mapped merchant account ID
        if($this->currencyMappingEnabled()) {

            // Retrieve the mapping from the config
            $mapping = Mage::helper('core')->jsonDecode(Mage::getStoreConfig(self::BRAINTREE_MULTI_CURRENCY_MAPPING));

            // Verify it decoded correctly
            if(is_array($mapping) && !empty($mapping)) {

                $currency = $this->getCurrencyCode();

                // Verify we have a mapping value for this currency
                if(isset($mapping[$currency]) && !empty($mapping[$currency])) {

                    // These should never have spaces in so make sure we trim it
                    return trim($mapping[$currency]);
                }
            }
        }

        return false;
    }

    /**
     * Return the users current currency code
     *
     * @return bool|string
     */
    public function getCurrencyCode()
    {
        // If we're in the admin get the currency code from the admin session quote
        if(Mage::app()->getStore()->isAdmin()) {
            return $this->getAdminCurrency();
        }

        // Retrieve the current from the session
        return Mage::app()->getStore()->getCurrentCurrencyCode();
    }

    /**
     * Do we have currency mapping enabled?
     *
     * @return bool
     */
    public function currencyMappingEnabled()
    {
        return Mage::getStoreConfigFlag(self::BRAINTREE_MULTI_CURRENCY)
            && Mage::getStoreConfig(self::BRAINTREE_MULTI_CURRENCY_MAPPING)
            && (Mage::app()->getStore()->getCurrentCurrencyCode() || (Mage::app()->getStore()->isAdmin() && $this->getAdminCurrency()));
    }

    /**
     * If we have a mapped currency code we need to convert the currency
     *
     * @param $amount
     *
     * @return mixed
     */
    public function getCaptureAmount(Mage_Sales_Model_Order $order, $amount)
    {
        // If we've got a mapped currency code the amount is going to change
        if($this->hasMappedCurrencyCode()) {

            // Convert the current
            $convertedCurrency = Mage::helper('directory')->currencyConvert($amount, $order->getBaseCurrencyCode(), $this->getCurrencyCode());

            // Format it to a precision of 2
            $options = array(
                'currency' => $this->getCurrencyCode(),
                'display' => ''
            );

            return Mage::app()->getLocale()->currency($this->getCurrencyCode())->toCurrency($convertedCurrency, $options);
        }

        return $amount;
    }

    /**
     * Retrieve the admin currency
     *
     * @return bool
     */
    private function getAdminCurrency()
    {
        $order = Mage::app()->getRequest()->getPost('order');
        if(isset($order['currency']) && !empty($order['currency'])) {
            return $order['currency'];
        }

        return false;
    }

    /**
     * Build up the customers data onto an object
     *
     * @param Mage_Sales_Model_Order $order
     *
     * @return array
     */
    private function buildCustomer(Mage_Sales_Model_Order $order, $includeId = true)
    {
        $customer = array(
            'firstName' => $order->getCustomerFirstname(),
            'lastName'  => $order->getCustomerLastname(),
            'email'     => $order->getCustomerEmail(),
            'phone'     => $order->getBillingAddress()->getTelephone()
        );

        // Shall we include the customer ID?
        if($includeId) {
            $customer['id'] = $this->getBraintreeId();
        }

        // Handle empty data with alternatives
        if(empty($customer['firstName'])) {
            $customer['firstName'] = $order->getBillingAddress()->getFirstname();
        }
        if(empty($customer['lastName'])) {
            $customer['lastName'] = $order->getBillingAddress()->getLastname();
        }
        if(empty($customer['email'])) {
            $customer['email'] = $order->getBillingAddress()->getEmail();
        }

        return $customer;
    }

}