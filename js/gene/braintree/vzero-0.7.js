/**
 * Magento Braintree class to bridge the v.zero JS SDK and Magento
 *
 * @class vZero
 * @author Dave Macaulay <dave@gene.co.uk>
 */
var vZero = Class.create();
vZero.prototype = {

    /**
     * Initialize all our required variables that we'll need later on
     *
     * @param code The payment methods code
     * @param clientToken The client token provided by the server
     * @param threeDSecure Flag to determine whether 3D secure is active, this is verified server side
     * @param hostedFields Flag to determine whether we're using hosted fields
     * @param billingName Billing name used in verification of the card
     * @param billingPostcode Billing postcode also needed to verify the card
     * @param quoteUrl The URL to update the quote totals
     * @param tokenizeUrl The URL to re-tokenize 3D secure cards
     * @param vaultToNonceUrl The end point to vault a nonce then return a new nonce
     */
    initialize: function (code, clientToken, threeDSecure, hostedFields, billingName, billingPostcode, quoteUrl, tokenizeUrl, vaultToNonceUrl) {
        this.code = code;
        this.clientToken = clientToken;
        this.threeDSecure = threeDSecure;
        this.hostedFields = hostedFields;

        if (billingName) {
            this.billingName = billingName;
        }
        if (billingPostcode) {
            this.billingPostcode = billingPostcode;
        }
        if (quoteUrl) {
            this.quoteUrl = quoteUrl;
        }
        if (tokenizeUrl) {
            this.tokenizeUrl = tokenizeUrl;
        }
        if (vaultToNonceUrl) {
            this.vaultToNonceUrl = vaultToNonceUrl;
        }

        this._hostedFieldsTokenGenerated = false;

        this.acceptedCards = false;

        this.closeMethod = false;

        // Store whether hosted fields is running or not
        this._hostedFieldsTimeout = false;

        // Store the Ajax request for the updateData
        this._updateDataXhr = false;
        this._updateDataCallbacks = [];
        this._updateDataParams = {};

        this._vaultToNonceXhr = false;
    },

    /**
     * Init the vZero integration by starting a new version of the client
     * If 3D secure is enabled we also listen out for window messages
     */
    init: function () {
        this.client = new braintree.api.Client({clientToken: this.clientToken});
    },

    /**
     * Init the hosted fields system
     *
     * @param integration
     */
    initHostedFields: function (integration) {

        // If the hosted field number element exists hosted fields is on the page and working!
        if ($$('iframe[name^="braintree-"]').length > 0) {
            return false;
        }

        // If it's already running there's no need to start another instance
        // Also block the function if braintree-hosted-submit isn't yet on the page
        if ($('braintree-hosted-submit') === null) {
            return false;
        }

        // Pass the integration through to hosted fields
        this.integration = integration;

        this._hostedFieldsTokenGenerated = false;

        // Utilise a 50ms timeout to ensure the last call of HF is ran
        clearTimeout(this._hostedFieldsTimeout);
        this._hostedFieldsTimeout = setTimeout(function () {

            if (this._hostedIntegration !== false) {
                try {
                    this._hostedIntegration.teardown(function () {
                        this._hostedIntegration = false;
                        // Setup the hosted fields client
                        this.setupHostedFieldsClient();
                    }.bind(this));
                } catch (e) {
                    this.setupHostedFieldsClient();
                }
            } else {
                // Setup the hosted fields client
                this.setupHostedFieldsClient();
            }

        }.bind(this), 50);
    },

    /**
     * Tear down hosted fields
     *
     * @param callbackFn
     */
    teardownHostedFields: function (callbackFn) {
        if (this._hostedIntegration !== false) {
            this._hostedIntegration.teardown(function () {
                this._hostedIntegration = false;

                if (typeof callbackFn === 'function') {
                    callbackFn();
                }
            }.bind(this));
        } else {
            if (typeof callbackFn === 'function') {
                callbackFn();
            }
        }
    },

    /**
     * Setup the hosted fields client utilising the Braintree JS SDK
     */
    setupHostedFieldsClient: function () {

        // If there are iframes within the fields already, don't run again!
        // This function has a delay from the original call so we need to verify everything is still good to go!
        if ($$('iframe[name^="braintree-"]').length > 0) {
            return false;
        }

        this._hostedIntegration = false;

        var hostedFieldsConfiguration = {
            id: this.integration.form,
            hostedFields: {
                styles: this.getHostedFieldsStyles(),
                number: {
                    selector: "#card-number",
                    placeholder: "0000 0000 0000 0000"
                },
                expirationMonth: {
                    selector: "#expiration-month",
                    placeholder: "MM"
                },
                expirationYear: {
                    selector: "#expiration-year",
                    placeholder: "YY"
                },
                onFieldEvent: this.hostedFieldsOnFieldEvent.bind(this)
            },
            onReady: function (integration) {
                this._hostedIntegration = integration;

                // Unset the loading state if it's present
                if ($$('#credit-card-form.loading').length) {
                    $$('#credit-card-form.loading').first().removeClassName('loading');
                }
            }.bind(this),
            onPaymentMethodReceived: this.hostedFieldsPaymentMethodReceived.bind(this),
            onError: this.hostedFieldsError.bind(this)
        };

        // Include the CVV field with the request
        if ($('cvv') !== null) {
            hostedFieldsConfiguration.hostedFields.cvv = {
                selector: "#cvv"
            };
        }

        braintree.setup(this.clientToken, "custom", hostedFieldsConfiguration);
    },

    /**
     * Return the hosted field styles
     * See: https://developers.braintreepayments.com/guides/hosted-fields/styling/javascript/v2
     *
     * @returns {*}
     */
    getHostedFieldsStyles: function () {

        // Does the integration provide it's own styling options for hosted fields?
        if (typeof this.integration.getHostedFieldsStyles === 'function') {
            return this.integration.getHostedFieldsStyles();
        }

        // Return some default styles if all else fails
        return {
            // Style all elements
            "input": {
                "font-size": "14pt",
                "color": "#3A3A3A"
            },

            // Styling element state
            ":focus": {
                "color": "black"
            },
            ".valid": {
                "color": "green"
            },
            ".invalid": {
                "color": "red"
            }
        };
    },

    /**
     * Update the card type on field event
     *
     * @param event
     */
    hostedFieldsOnFieldEvent: function (event) {
        if (event.type === "fieldStateChange") {
            if (event.card) {
                var cardMapping = {
                    'visa': 'VI',
                    'american-express': 'AE',
                    'master-card': 'MC',
                    'discover': 'DI',
                    'jcb': 'JCB',
                    'maestro': 'ME'
                };
                if (typeof cardMapping[event.card.type] !== undefined) {
                    this.updateCardType(false, cardMapping[event.card.type]);
                } else {
                    this.updateCardType(false, 'card');
                }
            }
        }
    },

    /**
     * Vault the nonce with the associated billing data
     *
     * @param nonce
     * @param callback
     */
    vaultToNonce: function (nonce, callback) {

        // Craft the parameters
        var parameters = this.getBillingAddress();
        parameters['nonce'] = nonce;

        // Start an Ajax request to retrieve a nonce from the vault
        new Ajax.Request(
            this.vaultToNonceUrl,
            {
                method: 'post',
                parameters: parameters,
                onSuccess: function (transport) {
                    // Verify we have some response text
                    if (transport && transport.responseText) {

                        // Parse as an object
                        var response;
                        try {
                            response = eval('(' + transport.responseText + ')');
                        }
                        catch (e) {
                            response = {};
                        }

                        if (response.success && response.nonce) {
                            callback(response.nonce);
                        } else {

                            // Hide the loading state
                            if (typeof this.integration.resetLoading === 'function') {
                                this.integration.resetLoading();
                            }

                            if (response.error) {
                                alert(response.error);
                            } else {
                                alert('Something wen\'t wrong and we\'re currently unable to take your payment.');
                            }
                        }
                    }
                }.bind(this),
                onFailure: function () {

                    // Hide the loading state
                    if (typeof this.integration.resetLoading === 'function') {
                        this.integration.resetLoading();
                    }

                    alert('Something wen\'t wrong and we\'re currently unable to take your payment.');

                }.bind(this)
            }
        );
    },

    /**
     * Action to call after receiving the payment method
     *
     * @param response
     */
    hostedFieldsPaymentMethodReceived: function (response) {

        // Check if validation failed or not?
        if (this.threeDSecure) {

            // Show the loading state
            if (typeof this.integration.setLoading === 'function') {
                this.integration.setLoading();
            }

            // Update the quote totals first
            this.updateData(function () {
                this.vaultToNonce(response.nonce, function (nonce) {

                    // Hide the loading state
                    if (typeof this.integration.resetLoading === 'function') {
                        this.integration.resetLoading();
                    }

                    // Verify the nonce through 3Ds
                    this.verify3dSecureNonce(nonce, {
                        onSuccess: function (response) {
                            this.hostedFieldsNonceReceived(response.nonce);
                        }.bind(this),
                        onFailure: function (response, error) {
                            alert(error);
                        }.bind(this)
                    });

                }.bind(this));
            }.bind(this));

        } else {
            this.hostedFieldsNonceReceived(response.nonce);
        }

    },

    /**
     * Once the nonce has been received update the field
     *
     * @param nonce
     */
    hostedFieldsNonceReceived: function (nonce) {

        $('creditcard-payment-nonce').value = nonce;
        $('creditcard-payment-nonce').setAttribute('value', nonce);

        if (typeof this.integration.resetLoading === 'function') {
            this.integration.resetLoading();
        }

        this._hostedFieldsTokenGenerated = true;

        // Is there a success function we're wanting to run?
        if (typeof this.integration.afterHostedFieldsNonceReceived === 'function') {
            this.integration.afterHostedFieldsNonceReceived(nonce);
        }
    },

    /**
     * Handle hosted fields throwing an error
     *
     * @param response
     * @returns {boolean}
     */
    hostedFieldsError: function (response) {

        if (typeof this.integration.resetLoading === 'function') {
            this.integration.resetLoading();
        }

        // Stop any "Cannot place two elements in #xxx" messages being shown to the user
        // These are non critical errors and the functionality will still work as expected
        if (typeof response.message !== 'undefined' && response.message.indexOf('Cannot place two elements in') == -1) {
            // Let the user know what went wrong
            alert(response.message);
        }

        this._hostedFieldsTokenGenerated = false;

        if (typeof this.integration.afterHostedFieldsError === 'function') {
            this.integration.afterHostedFieldsError(response.message);
        }

        return false;

    },

    /**
     * Is the customer attempting to use a saved card?
     *
     * @returns {boolean}
     */
    usingSavedCard: function () {
        return ($('creditcard-saved-accounts') != undefined
        && $$('#creditcard-saved-accounts input:checked[type=radio]').first() != undefined
        && $$('#creditcard-saved-accounts input:checked[type=radio]').first().value !== 'other');
    },


    /**
     * Set the 3Ds flag
     *
     * @param flag a boolean value
     */
    setThreeDSecure: function (flag) {
        this.threeDSecure = flag;
    },

    /**
     * Set the amount within the checkout, this is only used in the default integration
     * For any other checkouts see the updateData method, this is used by 3D secure
     *
     * @param amount The grand total of the order
     */
    setAmount: function (amount) {
        this.amount = parseFloat(amount);
    },

    /**
     * We sometimes need to set the billing name later on in the process
     *
     * @param billingName
     */
    setBillingName: function (billingName) {
        this.billingName = billingName;
    },

    /**
     * Return the billing name
     *
     * @returns {*}
     */
    getBillingName: function () {

        // If billingName is an object we're wanting to grab the data from elements
        if (typeof this.billingName == 'object') {

            // Combine them with a space
            return this.combineElementsValues(this.billingName);

        } else {

            // Otherwise we can presume that the billing name is a string
            return this.billingName;
        }
    },

    /**
     * Same for billing postcode
     *
     * @param billingPostcode
     */
    setBillingPostcode: function (billingPostcode) {
        this.billingPostcode = billingPostcode;
    },

    /**
     * Return the billing name
     *
     * @returns {*}
     */
    getBillingPostcode: function () {

        // If billingName is an object we're wanting to grab the data from elements
        if (typeof this.billingPostcode == 'object') {

            // Combine them with a space
            return this.combineElementsValues(this.billingPostcode);

        } else {

            // Otherwise we can presume that the billing name is a string
            return this.billingPostcode;
        }
    },

    /**
     * Push through the selected accepted cards from the admin
     *
     * @param cards an array of accepted cards
     */
    setAcceptedCards: function (cards) {
        this.acceptedCards = cards;
    },

    /**
     * Return the full billing address, if we cannot just serialize the billing address serialize everything
     *
     * @returns {array}
     */
    getBillingAddress: function () {

        // Is there a function in the integration for this action?
        if (typeof this.integration.getBillingAddress === 'function') {
            return this.integration.getBillingAddress();
        }

        var billingAddress = {};

        // If not try something generic
        if ($('co-billing-form') !== null) {
            if ($('co-billing-form').tagName == 'FORM') {
                billingAddress = $('co-billing-form').serialize(true);
            } else {
                billingAddress = this.extractBilling($('co-billing-form').up('form').serialize(true));
            }
        } else if ($('billing:firstname') !== null) {
            billingAddress = this.extractBilling($('billing:firstname').up('form').serialize(true));
        }

        if (billingAddress) {
            return billingAddress;
        }
    },

    /**
     * Extract only the serialized values that start with "billing"
     *
     * @param formData
     * @returns {{}}
     */
    extractBilling: function (formData) {
        var billing = {};
        $H(formData).each(function (data) {
            // Only include billing details, excluding passwords
            if (data.key.indexOf('billing') == 0 && data.key.indexOf('password') == -1) {
                billing[data.key] = data.value;
            }
        });
        return billing;
    },

    /**
     * Return the accepted cards
     *
     * @returns {boolean|*}
     */
    getAcceptedCards: function () {
        return this.acceptedCards;
    },


    /**
     * Combine elements values into a string
     *
     * @param elements
     * @param seperator
     * @returns {string}
     */
    combineElementsValues: function (elements, seperator) {

        // If no seperator is set use a space
        if (!seperator) {
            seperator = ' ';
        }

        // Loop through the elements and build up an array
        var response = [];
        elements.each(function (element, index) {
            if ($(element) !== undefined) {
                response[index] = $(element).value;
            }
        });

        // Join with a space
        return response.join(seperator);

    },

    /**
     * Update the card type from a card number
     *
     * @param cardNumber The card number that the user has entered
     * @param cardType The card type, if already known
     */
    updateCardType: function (cardNumber, cardType) {

        if (!cardType) {
            // Retrieve the card type
            cardType = this.getCardType(cardNumber);
        }

        if ($('gene_braintree_creditcard_cc_type') != undefined) {
            if (cardType == 'card') {
                // If we cannot detect which kind of card they're using remove the value from the select
                $('gene_braintree_creditcard_cc_type').value = '';
            } else {
                // Update the validation field
                $('gene_braintree_creditcard_cc_type').value = cardType;
            }
        }

        // Check the image exists on the page
        if ($('card-type-image') != undefined) {

            // Grab the skin image URL without the last part
            var skinImageUrl = $('card-type-image').src.substring(0, $('card-type-image').src.lastIndexOf("/"));

            // Rebuild the URL with the card type included, all card types are stored as PNG's
            $('card-type-image').setAttribute('src', skinImageUrl + "/" + cardType + ".png");

        }

    },

    /**
     * Create a new event upon the card number field
     */
    observeCardType: function () {

        if ($$('[data-genebraintree-name="number"]').first() !== undefined) {

            // Observe any blurring on the form
            Element.observe($$('[data-genebraintree-name="number"]').first(), 'keyup', function () {

                // Update the card type
                vzero.updateCardType(this.value);
            });

            // Add a space every 4 characters
            $$('[data-genebraintree-name="number"]').first().oninput = function () {
                // Add a space every 4 characters
                var number = this.value.split(" ").join("");
                if (number.length > 0) {
                    number = number.match(new RegExp('.{1,4}', 'g')).join(" ");
                }
                this.value = number;
            };

        }

    },

    /**
     * Observe all Ajax requests, this is needed on certain checkouts
     * where we're unable to easily inject into methods
     *
     * @param callback A defined callback function if needed
     * @param ignore An array of indexOf paths to ignore
     */
    observeAjaxRequests: function (callback, ignore) {

        // For every ajax request on complete update various Braintree things
        Ajax.Responders.register({
            onComplete: function (transport) {
                return this.handleAjaxRequest(transport.url, callback, ignore);
            }.bind(this)
        });

        // Is jQuery present on the page
        if (window.jQuery) {
            jQuery(document).ajaxComplete(function (event, xhr, settings) {
                return this.handleAjaxRequest(settings.url, callback, ignore)
            }.bind(this));
        }

    },

    /**
     * Handle the ajax request form the observer above
     *
     * @param url
     * @param callback
     * @param ignore
     * @returns {boolean}
     */
    handleAjaxRequest: function (url, callback, ignore) {

        // Let's check the ignore variable
        if (typeof ignore !== 'undefined' && ignore instanceof Array && ignore.length > 0) {

            // Determine whether we should ignore this request?
            var shouldIgnore = false;
            ignore.each(function (element) {
                if (url && url.indexOf(element) != -1) {
                    shouldIgnore = true;
                }
            });

            // If so, stop here!
            if (shouldIgnore === true) {
                return false;
            }
        }

        // Check the transport object has a URL and that it wasn't to our own controller
        if (url && url.indexOf('/braintree/') == -1) {

            // Some checkout implementations may require custom callbacks
            if (callback) {
                callback(url);
            } else {
                this.updateData();
            }
        }

    },

    /**
     * Make an Ajax request to the server and request up to date information regarding the quote
     *
     * @param callback A defined callback function if needed
     * @param params any extra data to be passed to the controller
     */
    updateData: function (callback, params) {

        // Push the callbacks into our array
        this._updateDataCallbacks.push(callback);
        this._updateDataParams = params;

        // If an updateData ajax request is running, cancel it
        if (this._updateDataXhr !== false) {
            this._updateDataXhr.transport.abort();
        }

        // Make a new ajax request to the server
        this._updateDataXhr = new Ajax.Request(
            this.quoteUrl,
            {
                method: 'post',
                parameters: this._updateDataParams,
                onSuccess: function (transport) {
                    // Verify we have some response text
                    if (transport && (transport.responseJSON || transport.responseText)) {

                        // Parse the response from the server
                        var response;
                        if (transport.responseJSON && typeof transport.responseJSON === 'object') {
                            response = transport.responseJSON;
                        } else if (transport.responseText) {
                            response = JSON.decode(transport.responseText);
                        }

                        if (response.billingName != undefined) {
                            this.billingName = response.billingName;
                        }
                        if (response.billingPostcode != undefined) {
                            this.billingPostcode = response.billingPostcode;
                        }
                        if (response.grandTotal != undefined) {
                            this.amount = response.grandTotal;
                        }
                        if (response.threeDSecure != undefined) {
                            this.setThreeDSecure(response.threeDSecure);
                        }

                        // If PayPal is active update it
                        if (typeof vzeroPaypal != "undefined") {

                            // Update the totals within the PayPal system
                            if (response.grandTotal != undefined && response.currencyCode != undefined) {
                                vzeroPaypal.setPricing(response.grandTotal, response.currencyCode);
                            }

                        }

                        // Reset the params
                        this._updateDataParams = {};

                        // Set the flag back
                        this._updateDataXhr = false;

                        // Run any callbacks that have been stored
                        if (this._updateDataCallbacks.length) {
                            this._updateDataCallbacks.each(function (callback) {
                                callback(response);
                            }.bind(this));
                            this._updateDataCallbacks = [];
                        }
                    }
                }.bind(this),
                onFailure: function () {

                    // Reset the params
                    this._updateDataParams = {};
                    this._updateDataXhr = false;
                    this._updateDataCallbacks = [];

                }.bind(this)
            }
        );

    },

    /**
     * Allow custom checkouts to set a custom method for closing 3D secure
     *
     * @param callback A defined callback function if needed
     */
    close3dSecureMethod: function (callback) {
        this.closeMethod = callback;
    },

    /**
     * If the user attempts to use a 3D secure vaulted card and then cancels the 3D
     * window the nonce associated with that card will become invalid, due to this
     * we have to tokenize all the 3D secure cards again
     *
     * @param callback A defined callback function if needed
     */
    tokenize3dSavedCards: function (callback) {

        // Check 3D is enabled
        if (this.threeDSecure) {

            // Verify we have elements with data-token
            if ($$('[data-token]').first() !== undefined) {

                // Gather our tokens
                var tokens = [];
                $$('[data-token]').each(function (element, index) {
                    tokens[index] = element.getAttribute('data-token');
                });

                // Make a new ajax request to the server
                new Ajax.Request(
                    this.tokenizeUrl,
                    {
                        method: 'post',
                        onSuccess: function (transport) {

                            // Verify we have some response text
                            if (transport && transport.responseText) {

                                // Parse as an object
                                try {
                                    response = eval('(' + transport.responseText + ')');
                                }
                                catch (e) {
                                    response = {};
                                }

                                // Check the response was successful
                                if (response.success) {

                                    // Loop through the returned tokens
                                    $H(response.tokens).each(function (element) {

                                        // If the token exists update it's nonce
                                        if ($$('[data-token="' + element.key + '"]').first() != undefined) {
                                            $$('[data-token="' + element.key + '"]').first().setAttribute('data-threedsecure-nonce', element.value);
                                        }
                                    });
                                }

                                if (callback) {
                                    callback(response);
                                }
                            }
                        }.bind(this),
                        parameters: {'tokens': Object.toJSON(tokens)}
                    }
                );
            } else {
                callback();
            }

        } else {
            callback();
        }
    },

    onUserClose3ds: function () {
        this._hostedFieldsTokenGenerated = false;
        // Is there a close method defined?
        if (this.closeMethod) {
            this.closeMethod();
        } else {
            checkout.setLoadWaiting(false);
        }
    },

    /**
     * Verify a nonce through 3ds
     *
     * @param nonce
     * @param options
     */
    verify3dSecureNonce: function (nonce, options) {

        var threeDSecureRequest = {
            amount: this.amount,
            creditCard: nonce,
            onUserClose: this.onUserClose3ds.bind(this)
        };

        // Run the verify function on the braintree client
        this.client.verify3DS(threeDSecureRequest, function (error, response) {

            if (!error) {
                // Run any callback functions
                if (options.onSuccess) {
                    options.onSuccess(response);
                }
            } else {
                if (options.onFailure) {
                    options.onFailure(response, error.message);
                }
            }
        });

    },

    /**
     * Make a request to Braintree for 3D secure information
     *
     * @param options Contains any callback functions which have been set
     */
    verify3dSecure: function (options) {

        var threeDSecureRequest = {
            amount: this.amount,
            creditCard: {
                number: $$('[data-genebraintree-name="number"]').first().value,
                expirationMonth: $$('[data-genebraintree-name="expiration_month"]').first().value,
                expirationYear: $$('[data-genebraintree-name="expiration_year"]').first().value,
                cardholderName: this.getBillingName()
            },
            onUserClose: this.onUserClose3ds.bind(this)
        };

        // If the CVV field exists include it
        if ($$('[data-genebraintree-name="cvv"]').first() != undefined) {
            threeDSecureRequest.creditCard.cvv = $$('[data-genebraintree-name="cvv"]').first().value;
        }

        // If we have the billing postcode add it into the request
        if (this.getBillingPostcode() != "") {
            threeDSecureRequest.creditCard.billingAddress = {
                postalCode: this.getBillingPostcode()
            };
        }

        // Run the verify function on the braintree client
        this.client.verify3DS(threeDSecureRequest, function (error, response) {

            if (!error) {

                // Store threeDSecure token and nonce in form
                $('creditcard-payment-nonce').value = response.nonce;
                $('creditcard-payment-nonce').setAttribute('value', response.nonce);

                // Run any callback functions
                if (options.onSuccess) {
                    options.onSuccess();
                }
            } else {

                // Show the error
                alert(error.message);

                if (options.onFailure) {
                    options.onFailure();
                } else {
                    checkout.setLoadWaiting(false);
                }
            }
        });

    },

    /**
     * Verify a card stored in the vault
     *
     * @param options Contains any callback functions which have been set
     */
    verify3dSecureVault: function (options) {

        // Get the payment nonce
        var paymentNonce = $$('#creditcard-saved-accounts input:checked[type=radio]').first().getAttribute('data-threedsecure-nonce');

        if (paymentNonce) {
            // Run the verify function on the braintree client
            this.client.verify3DS({
                amount: this.amount,
                creditCard: paymentNonce
            }, function (error, response) {
                if (!error) {

                    // Store threeDSecure token and nonce in form
                    $('creditcard-payment-nonce').removeAttribute('disabled');
                    $('creditcard-payment-nonce').value = response.nonce;
                    $('creditcard-payment-nonce').setAttribute('value', response.nonce);

                    // Run any callback functions
                    if (options.onSuccess) {
                        options.onSuccess();
                    }
                } else {

                    // Show the error
                    alert(error.message);

                    if (options.onFailure) {
                        options.onFailure();
                    } else {
                        checkout.setLoadWaiting(false);
                    }
                }
            });
        } else {
            alert('No payment nonce present.');

            if (options.onFailure) {
                options.onFailure();
            } else {
                checkout.setLoadWaiting(false);
            }
        }

    },

    /**
     * Process a standard card request
     *
     * @param options Contains any callback functions which have been set
     */
    processCard: function (options) {

        var tokenizeRequest = {
            number: $$('[data-genebraintree-name="number"]').first().value,
            cardholderName: this.getBillingName(),
            expirationMonth: $$('[data-genebraintree-name="expiration_month"]').first().value,
            expirationYear: $$('[data-genebraintree-name="expiration_year"]').first().value
        };

        // If the CVV field exists include it
        if ($$('[data-genebraintree-name="cvv"]').first() != undefined) {
            tokenizeRequest.cvv = $$('[data-genebraintree-name="cvv"]').first().value;
        }

        // If we have the billing postcode add it into the request
        if (this.getBillingPostcode() != "") {
            tokenizeRequest.billingAddress = {
                postalCode: this.getBillingPostcode()
            };
        }

        // Attempt to tokenize the card
        this.client.tokenizeCard(tokenizeRequest, function (errors, nonce) {

            if (!errors) {
                // Update the nonce in the form
                $('creditcard-payment-nonce').value = nonce;
                $('creditcard-payment-nonce').setAttribute('value', nonce);

                // Run any callback functions
                if (options.onSuccess) {
                    options.onSuccess();
                }
            } else {
                // Handle errors
                for (var i = 0; i < errors.length; i++) {
                    alert(errors[i].code + " " + errors[i].message);
                }

                if (options.onFailure) {
                    options.onFailure();
                } else {
                    checkout.setLoadWaiting(false);
                }
            }
        });

    },

    /**
     * Should our integrations intercept credit card payments based on the settings?
     *
     * @returns {boolean}
     */
    shouldInterceptCreditCard: function () {
        return true;
    },

    /**
     * Should our integrations intercept PayPal payments based on the settings?
     *
     * @returns {boolean}
     */
    shouldInterceptPayPal: function () {
        return true;
    },

    /**
     * Conduct a regular expression check to determine card type automatically
     *
     * @param number
     * @returns {string}
     */
    getCardType: function (number) {

        if (number) {

            if (number.match(/^4/) != null)
                return "VI";

            if (number.match(/^(34|37)/) != null)
                return "AE";

            if (number.match(/^5[1-5]/) != null)
                return "MC";

            if (number.match(/^6011/) != null)
                return "DI";

            if (number.match(/^(?:2131|1800|35)/) != null)
                return "JCB";

            if (number.match(/^(5018|5020|5038|6304|67[0-9]{2})/) != null)
                return "ME";

        }

        // Otherwise return the standard card
        return "card";
    },

    /**
     * Wrapper function which defines which method should be called
     *
     * verify3dSecureVault - used for verifying any vaulted card when 3D secure is enabled
     * verify3dSecure - verify a normal card via 3D secure
     * processCard - verify a normal card
     *
     * If the customer has choosen a vaulted card and 3D is disabled no client side interaction is needed
     *
     * @param options Object containing onSuccess, onFailure functions
     */
    process: function (options) {

        // If options isn't set, use an empty object
        options = options || {};

        // If the hosted fields token has been generated just glide through
        if (this._hostedFieldsTokenGenerated) {

            // No action required as we're using a saved card
            if (options.onSuccess) {
                options.onSuccess()
            }

        } else if (this.usingSavedCard() && $$('#creditcard-saved-accounts input:checked[type=radio]').first().hasAttribute('data-threedsecure-nonce')) {

            // The user has selected a card stored via 3D secure
            this.verify3dSecureVault(options);

        } else if (this.usingSavedCard()) {

            // No action required as we're using a saved card
            if (options.onSuccess) {
                options.onSuccess()
            }

        } else if (this.threeDSecure == true) {

            // Standard 3D secure callback
            this.verify3dSecure(options);

        } else {

            // Otherwise process the card normally
            this.processCard(options);
        }
    },

    creditCardLoaded: function () {
        return false;
    },

    paypalLoaded: function () {
        return false;
    }

};

/**
 * Separate class to handle functionality around the vZero PayPal button
 *
 * @class vZeroPayPalButton
 * @author Dave Macaulay <dave@gene.co.uk>
 */
var vZeroPayPalButton = Class.create();
vZeroPayPalButton.prototype = {

    /**
     * Initialize the PayPal button class
     *
     * @param clientToken Client token generated from server
     * @param storeFrontName The store name to show within the PayPal modal window
     * @param singleUse Should the system attempt to open in single payment mode?
     * @param locale The locale for the payment
     * @param futureSingleUse When using future payments should we process the transaction as a single payment?
     */
    initialize: function (clientToken, storeFrontName, singleUse, locale, futureSingleUse) {
        this.clientToken = clientToken;
        this.storeFrontName = storeFrontName;
        this.singleUse = singleUse;
        this.locale = locale;
        this.futureSingleUse = futureSingleUse;

        this._paypalOptions = {};
        this._paypalIntegration = false;
        this._paypalButton = false;

        this._rebuildTimer = false;
        this._rebuildCount = 0;
    },

    /**
     * Update the pricing information for the PayPal button
     * If the PayPalClient has already been created we also update the _clientOptions
     * so the PayPal modal window displays the correct values
     *
     * @param amount The amount formatted to two decimal places
     * @param currency The currency code
     */
    setPricing: function (amount, currency) {

        // Set them into the class
        this.amount = parseFloat(amount);
        this.currency = currency;

        if ($('paypal-payment-nonce') != null && !$('paypal-payment-nonce').value) {
            // As the amounts and currency has been updated let's update the button by rebuilding it
            // But only if we don't have a nonce
            this.rebuildButton();
        }

    },

    /**
     * Rebuild the PayPal button
     */
    rebuildButton: function () {

        clearTimeout(this._rebuildTimer);
        if (this._paypalIntegration !== false) {
            try {
                // Wait 100ms to rebuild the button
                this._paypalIntegration.teardown(function () {
                    this._paypalIntegration = false;
                    // Re-add the button with the same options
                    this.addPayPalButton(this._paypalOptions);
                }.bind(this));
            } catch (e) {
                // This error means the integration has already been torn down
                if (e.message == 'Cannot teardown integration more than once') {
                    this._paypalIntegration = false;
                    this.addPayPalButton(this._paypalOptions);
                } else if (this._rebuildCount >= 10) {
                    // Infinite loops are bad kids
                    return false;
                } else {
                    // Check to see if the teardown function will work once the integration is built fully
                    this._rebuildTimer = setTimeout(function () {
                        ++this._rebuildCount;
                        this.rebuildButton();
                    }.bind(this), 200);
                }
            }
        }
    },

    /**
     * Inject the PayPal button into the document
     *
     * @param options Object containing onSuccess method
     * @param integration Object the integration class
     */
    addPayPalButton: function (options, checkoutIntegration) {

        // If the container isn't present on the page we can't add the button
        if ($('paypal-container') === null || $('braintree-paypal-button') === null) {
            return false;
        }

        // Add our custom button into the view
        var buttonHtml = $('braintree-paypal-button').innerHTML;
        $('paypal-container').update('');
        $('paypal-container').insert(buttonHtml);

        // Assign the new button
        if (!$('paypal-container').select('>button').length) {
            return false;
        }
        this._paypalButton = $('paypal-container').select('>button').first();
        this._paypalButton.addClassName('braintree-paypal-loading');
        this._paypalButton.setAttribute('disabled', 'disabled');

        // Store the options as we may need to rebuild the button
        this._paypalOptions = options;
        this._paypalIntegration = false;

        // Build up our setup configuration
        var setupConfiguration = {
            paymentMethodNonceInputField: "paypal-payment-nonce",
            displayName: this.storeFrontName,
            onPaymentMethodReceived: function (obj) {

                // If we have a success callback we're most likely using a non-default checkout
                if (typeof options.onSuccess === 'function') {
                    options.onSuccess(obj);
                } else {
                    // Otherwise we're using the default checkout
                    payment.switchMethod('gene_braintree_paypal');

                    // Re-enable the form
                    $('paypal-payment-nonce').removeAttribute('disabled');

                    // Remove the PayPal button
                    $('paypal-complete').remove();

                    // Submit the checkout steps
                    window.review && review.save();
                }

            },
            onUnsupported: function () {
                alert('You need to link your PayPal account with your Braintree account in your Braintree control panel to utilise the PayPal functionality of this extension.');
            },
            onReady: function (integration) {
                this._paypalIntegration = integration;
                this._attachPayPalButtonEvent(checkoutIntegration);
                if (typeof options.onReady === 'function') {
                    options.onReady(integration);
                }
            }.bind(this),
            paypal: {
                headless: true
            }
        };

        // Pass the locale over to the PayPal instance
        if (this.locale) {
            setupConfiguration.locale = this.locale;
        }

        // Single use requires some extra data to be sent through, this is so the PayPal window can display the correct total etc
        if (this.singleUse == true) {

            setupConfiguration.singleUse = true;
            setupConfiguration.amount = this.amount;
            setupConfiguration.currency = this.currency;

        } else if (this.futureSingleUse == true) {

            setupConfiguration.singleUse = true;

        } else {

            setupConfiguration.singleUse = false;

        }

        // Start a new version of the client and assign for later modifications
        braintree.setup(this.clientToken, "paypal", setupConfiguration);
    },

    /**
     * Attach the click event to the paypal button
     *
     * @param integration
     *
     * @private
     */
    _attachPayPalButtonEvent: function (checkoutIntegration) {
        if (this._paypalIntegration && this._paypalButton) {
            this._paypalButton.removeClassName('braintree-paypal-loading');
            this._paypalButton.removeAttribute('disabled');

            Event.stopObserving(this._paypalButton, 'click');
            Event.observe(this._paypalButton, 'click', function (event) {
                Event.stop(event);
                if (typeof checkoutIntegration == 'object' && typeof checkoutIntegration.validateAll === 'function') {
                    if (checkoutIntegration.validateAll()) {
                        // Fire the integration
                        this._paypalIntegration.paypal.initAuthFlow();
                    }
                } else {
                    // Fire the integration
                    this._paypalIntegration.paypal.initAuthFlow();
                }

            }.bind(this));
        }
    },

    /**
     * Allow closing of the PayPal window
     *
     * @param callback A defined callback function if needed
     * @deprecated since version 1.0.4.1
     */
    closePayPalWindow: function (callback) {
        // Function deprecated in favour of using event propagation
    }

};

/**
 * The integration class for the Default checkout
 *
 * @class vZeroIntegration
 * @author Dave Macaulay <dave@gene.co.uk>
 */
var vZeroIntegration = Class.create();
vZeroIntegration.prototype = {

    /**
     * Create an instance of the integration
     *
     * @param vzero The vZero class that's being used by the checkout
     * @param vzeroPaypal The vZero PayPal object
     * @param paypalMarkUp The markup used for the PayPal button
     * @param paypalButtonClass The class of the button we need to replace with the above mark up
     * @param isOnepage Is the integration a onepage checkout?
     * @param config Any further config the integration wants to push into the class
     * @param onReady function fired after integration is "ready"
     */
    initialize: function (vzero, vzeroPaypal, paypalMarkUp, paypalButtonClass, isOnepage, config, onReady) {

        // Only allow the system to be initialized twice
        if (vZeroIntegration.prototype.loaded) {
            console.error('Your checkout is including the Braintree resources multiple times, please resolve this.');
            return false;
        }
        vZeroIntegration.prototype.loaded = true;

        this.vzero = vzero || false;
        this.vzeroPaypal = vzeroPaypal || false;

        // If both methods aren't present don't run the integration
        if (this.vzero === false && this.vzeroPaypal === false) {
            console.warn('The vzero and vzeroPaypal objects are not initiated.');
            return false;
        }

        this.paypalMarkUp = paypalMarkUp || false;
        this.paypalButtonClass = paypalButtonClass || false;

        this.isOnepage = isOnepage || false;

        this.config = config || {};

        this._methodSwitchTimeout = false;

        // Hosted fields hasn't been initialized yet
        this._hostedFieldsInit = false;

        // Wait for the DOM to finish loading before creating observers
        document.observe("dom:loaded", function () {

            // Call the function which is going to intercept the submit event
            this.prepareSubmitObserver();
            this.preparePaymentMethodSwitchObserver();

        }.bind(this));

        // Has the hosted fields method been generated successfully?
        this.hostedFieldsGenerated = false;

        // Attach events for when the 3Ds window is closed
        this.vzero.close3dSecureMethod(function () {

            // As hosted fields validation can still be true when a customer closes the modal clear it
            this.vzero._hostedFieldsValidationRunning = false;

            // Re-tokenize all the saved cards
            this.vzero.tokenize3dSavedCards(function () {
                this.threeDTokenizationComplete();
            }.bind(this));

        }.bind(this));

        // On onepage checkouts we need to do some other magic
        if (this.isOnepage) {
            this.vzero.observeCardType();
            this.observeAjaxRequests();

            document.observe("dom:loaded", function () {
                this.initSavedPayPal();
                this.initDefaultMethod();

                if ($('braintree-hosted-submit') !== null) {
                    this.initHostedFields();
                }
            }.bind(this));
        }

        document.observe("dom:loaded", function () {
            // Saved methods need events to!
            this.initSavedMethods();

            if ($('braintree-hosted-submit') !== null) {
                this.initHostedFields();
            }
        }.bind(this));

    },

    /**
     * Init the saved method events
     */
    initSavedMethods: function () {

        // Loop through each saved card being selected
        $$('#creditcard-saved-accounts input[type="radio"], #paypal-saved-accounts input[type="radio"]').each(function (element) {

            // Determine which method we're observing
            var parentElement = '';
            var targetElement = '';
            if (element.up('#creditcard-saved-accounts') !== undefined) {
                parentElement = '#creditcard-saved-accounts';
                targetElement = '#credit-card-form';
            } else if (element.up('#paypal-saved-accounts') !== undefined) {
                parentElement = '#paypal-saved-accounts';
                targetElement = '.paypal-info';
            }

            // Observe the elements changing
            $(element).stopObserving('change').observe('change', function (event) {
                return this.showHideOtherMethod(parentElement, targetElement);
            }.bind(this));

        }.bind(this));

    },

    /**
     * Hide or show the "other" method for both PayPal & Credit Card
     *
     * @param parentElement
     * @param targetElement
     */
    showHideOtherMethod: function (parentElement, targetElement) {

        // Has the user selected other?
        if ($$(parentElement + ' input:checked[type=radio]').first() !== undefined && $$(parentElement + ' input:checked[type=radio]').first().value == 'other') {

            if ($$(targetElement).first() !== undefined) {

                // Show the credit card form
                $$(targetElement).first().show();

                // Enable the credit card form all the elements in the credit card form
                $$(targetElement + ' input, ' + targetElement + ' select').each(function (formElement) {
                    formElement.removeAttribute('disabled');
                });

            }

        } else if ($$(parentElement + ' input:checked[type=radio]').first() !== undefined) {

            if ($$(targetElement).first() !== undefined) {

                // Hide the new credit card form
                $$(targetElement).first().hide();

                // Disable all the elements in the credit card form
                $$(targetElement + ' input, ' + targetElement + ' select').each(function (formElement) {
                    formElement.setAttribute('disabled', 'disabled');
                });

            }

        }
    },

    /**
     * Check to see if the "Other" option is selected and show the div correctly
     */
    checkSavedOther: function () {
        var parentElement = '';
        var targetElement = '';

        if (this.getPaymentMethod() == 'gene_braintree_creditcard') {
            parentElement = '#creditcard-saved-accounts';
            targetElement = '#credit-card-form';
        } else if (this.getPaymentMethod() == 'gene_braintree_paypal') {
            parentElement = '#paypal-saved-accounts';
            targetElement = '.paypal-info';
        }

        // Only run this action if the parent element exists on the page
        if ($$(parentElement).first() !== undefined) {
            this.showHideOtherMethod(parentElement, targetElement);
        }
    },

    /**
     * Init hosted fields
     */
    initHostedFields: function () {

        // Only init hosted fields if it's enabled
        if (this.vzero.hostedFields) {

            // Verify the form is on the page
            if ($('braintree-hosted-submit') !== null) {

                // Verify this checkout has a form (would be weird to have a formless checkout, but you never know!)
                if ($('braintree-hosted-submit').up('form') !== undefined) {

                    // Flag hosted fields being init
                    this._hostedFieldsInit = true;

                    // Store the form in the integration class
                    this.form = $('braintree-hosted-submit').up('form');

                    // Init hosted fields upon the form
                    this.vzero.initHostedFields(this);

                } else {
                    console.error('Hosted Fields cannot be initialized as we\'re unable to locate the parent form.');
                }
            }
        }
    },

    /**
     * After a successful hosted fields call
     *
     * @param nonce
     * @returns {*}
     */
    afterHostedFieldsNonceReceived: function (nonce) {
        this.resetLoading();
        this.vzero._hostedFieldsTokenGenerated = true;
        this.hostedFieldsGenerated = true;
        if (this.isOnepage) {
            return this.submitCheckout();
        } else {
            return this.submitPayment();
        }
    },

    /**
     * Handle hosted fields throwing an error
     *
     * @param message
     * @returns {boolean}
     */
    afterHostedFieldsError: function (message) {
        this.vzero._hostedFieldsTokenGenerated = false;
        this.hostedFieldsGenerated = false;
        return false;
    },

    /**
     * Init the default payment methods
     */
    initDefaultMethod: function () {
        if (this.shouldAddPayPalButton(false)) {
            this.setLoading();
            this.vzero.updateData(function () {
                this.resetLoading();
                this.updatePayPalButton('add');
            }.bind(this));
        }
    },

    /**
     * Observe any Ajax requests and refresh the PayPal button or update the checkouts data
     */
    observeAjaxRequests: function () {
        this.vzero.observeAjaxRequests(function () {
            this.vzero.updateData(function () {

                // The Ajax request might kill our events
                if (this.isOnepage) {
                    this.initSavedPayPal();
                    this.rebuildPayPalButton();
                    this.checkSavedOther();

                    // If hosted fields is enabled init the environment
                    if (this.vzero.hostedFields) {
                        this.initHostedFields();
                    }
                }

                // Make sure we're observing the saved methods correctly
                this.initSavedMethods();

            }.bind(this));
        }.bind(this), (typeof this.config.ignoreAjax !== 'undefined' ? this.config.ignoreAjax : false))
    },

    /**
     * Rebuild the PayPal button if it's been removed
     */
    rebuildPayPalButton: function () {

        // Check to see if the DOM element has been removed?
        if ($('paypal-container') == null) {
            this.updatePayPalButton();
        }

    },

    /**
     * Handle saved PayPals being present on the page
     */
    initSavedPayPal: function () {

        // If we have any saved accounts we'll need to do something jammy
        if ($$('#paypal-saved-accounts input[type=radio]').first() !== undefined) {
            $('paypal-saved-accounts').on('change', 'input[type=radio]', function (event) {

                // Update the PayPal button accordingly
                this.updatePayPalButton(false, 'gene_braintree_paypal');

            }.bind(this));
        }

    },

    /**
     * Set the submit function to be used
     *
     * This should be overridden within each checkouts .phtml file
     * vZeroIntegration.prototype.prepareSubmitObserver = function() {}
     *
     * @returns {boolean}
     */
    prepareSubmitObserver: function () {
        return false;
    },

    /**
     * Event to run before submit
     * Should always return _beforeSubmit
     *
     * @returns {boolean}
     */
    beforeSubmit: function (callback) {
        return this._beforeSubmit(callback);
    },

    /**
     * Private before submit function
     *
     * @param callback
     * @private
     */
    _beforeSubmit: function (callback) {
        // If hosted fields is activated, and we're 100% not using a saved card
        if (this.hostedFieldsGenerated === false && this.vzero.hostedFields && ($$('#creditcard-saved-accounts input:checked[type=radio]').first() === undefined || ($$('#creditcard-saved-accounts input:checked[type=radio]').first() !== undefined && $$('#creditcard-saved-accounts input:checked[type=radio]').first().value == 'other'))) {
            // Fake the form being submitted
            var button = $('braintree-hosted-submit').down('button');
            button.removeAttribute('disabled');
            button.click();
        } else {
            callback();
        }
    },

    /**
     * Event to run after submit
     *
     * @returns {boolean}
     */
    afterSubmit: function () {
        return false;
    },

    /**
     * Submit the integration to tokenize the card
     *
     * @param type
     * @param successCallback
     * @param failedCallback
     * @param validateFailedCallback
     */
    submit: function (type, successCallback, failedCallback, validateFailedCallback) {

        // Check we actually want to intercept this credit card transaction?
        if (this.shouldInterceptSubmit(type)) {

            // Validate the form before submission
            if (this.validateAll()) {

                // Show the loading information
                this.setLoading();

                // Call the before submit function
                this.beforeSubmit(function () {

                    // Always attempt to update the card type on submission
                    if ($$('[data-genebraintree-name="number"]').first() != undefined) {
                        this.vzero.updateCardType($$('[data-genebraintree-name="number"]').first().value);
                    }

                    // Update the data within the vZero object
                    this.vzero.updateData(
                        function () {

                            // Update the billing details if they're present on the page
                            this.updateBilling();

                            // Process the data on the page
                            this.vzero.process({
                                onSuccess: function () {

                                    // Make some modifications to the form
                                    this.enableDeviceData();
                                    this.disableCreditCardForm();

                                    // Unset the loading, as this can block success functions
                                    this.resetLoading();
                                    this.afterSubmit();

                                    // Enable/disable the correct nonce input fields
                                    this.enableDisableNonce();

                                    this.vzero._hostedFieldsTokenGenerated = false;
                                    this.hostedFieldsGenerated = false;

                                    // Call the callback function
                                    if (typeof successCallback === 'function') {
                                        var response = successCallback();
                                    }

                                    // Enable loading again, as things are happening!
                                    this.setLoading();

                                    this.enableCreditCardForm();
                                    return response;

                                }.bind(this),
                                onFailure: function () {

                                    this.vzero._hostedFieldsTokenGenerated = false;
                                    this.hostedFieldsGenerated = false;

                                    this.resetLoading();
                                    this.afterSubmit();
                                    if (typeof failedCallback === 'function') {
                                        return failedCallback();
                                    }
                                }.bind(this)
                            })
                        }.bind(this),
                        this.getUpdateDataParams()
                    );

                }.bind(this));

            } else {

                this.vzero._hostedFieldsTokenGenerated = false;
                this.hostedFieldsGenerated = false;

                this.resetLoading();
                if (typeof validateFailedCallback === 'function') {
                    validateFailedCallback();
                }
            }
        }
    },

    /**
     * Submit the entire checkout
     */
    submitCheckout: function () {
        // Submit the checkout steps
        window.review && review.save();
    },

    /**
     * How to submit the payment section
     */
    submitPayment: function () {
        payment.save && payment.save();
    },

    /**
     * Enable/disable the correct nonce input fields
     */
    enableDisableNonce: function () {
        // Make sure the nonce inputs aren't going to interfere
        if (this.getPaymentMethod() == 'gene_braintree_creditcard') {
            if ($('creditcard-payment-nonce') !== null) {
                $('creditcard-payment-nonce').removeAttribute('disabled');
            }
            if ($('paypal-payment-nonce') !== null) {
                $('paypal-payment-nonce').setAttribute('disabled', 'disabled');
            }
        } else if (this.getPaymentMethod() == 'gene_braintree_paypal') {
            if ($('creditcard-payment-nonce') !== null) {
                $('creditcard-payment-nonce').setAttribute('disabled', 'disabled');
            }
            if ($('paypal-payment-nonce') !== null) {
                $('paypal-payment-nonce').removeAttribute('disabled');
            }
        }
    },

    /**
     * Replace the PayPal button at the correct time
     *
     * This should be overridden within each checkouts .phtml file
     * vZeroIntegration.prototype.preparePaymentMethodSwitchObserver = function() {}
     */
    preparePaymentMethodSwitchObserver: function () {
        return this.defaultPaymentMethodSwitch();
    },

    /**
     * If the checkout uses the Magento standard Payment.prototype.switchMethod we can utilise this function
     */
    defaultPaymentMethodSwitch: function () {

        // Store a pointer to the vZero integration
        var vzeroIntegration = this;

        // Store the original payment method
        var paymentSwitchOriginal = Payment.prototype.switchMethod;

        // Intercept the save function
        Payment.prototype.switchMethod = function (method) {

            // Run our method switch function
            vzeroIntegration.paymentMethodSwitch(method);

            // Run the original function
            return paymentSwitchOriginal.apply(this, arguments);
        };

    },

    /**
     * Function to run when the customer changes payment method
     * @param method
     */
    paymentMethodSwitch: function (method) {

        // Wait for 50ms to see if this function is called again, only ever run the last instance
        clearTimeout(this._methodSwitchTimeout);
        this._methodSwitchTimeout = setTimeout(function () {

            // Should we add a PayPal button?
            if (this.shouldAddPayPalButton(method)) {
                this.updatePayPalButton('add', method);
            } else {
                this.updatePayPalButton('remove', method);
            }

            // Has the user enabled hosted fields?
            if ((method ? method : this.getPaymentMethod()) == 'gene_braintree_creditcard') {
                this.initHostedFields();
            }

            // Check to see if the other information should be displayed
            this.checkSavedOther();

        }.bind(this), 50);

    },

    /**
     * Complete a PayPal transaction
     *
     * @returns {boolean}
     */
    completePayPal: function (obj) {

        // Make sure the nonces are the correct way around
        this.enableDisableNonce();

        // Enable the device data
        this.enableDeviceData();

        if (obj.nonce && $('paypal-payment-nonce') !== null) {
            $('paypal-payment-nonce').value = obj.nonce;
            $('paypal-payment-nonce').setAttribute('value', obj.nonce);
        } else {
            console.warn('Unable to update PayPal nonce, please verify that the nonce input field has the ID: paypal-payment-nonce');
        }

        // Check the callback type is a function
        this.afterPayPalComplete();

        return false;
    },

    /**
     * Any operations that need to happen after the PayPal integration has completed
     *
     * @returns {boolean}
     */
    afterPayPalComplete: function () {
        this.resetLoading();
        return this.submitCheckout();
    },

    /**
     * Update the PayPal button, if we should add the button do so, otherwise remove it if it exists
     */
    updatePayPalButton: function (action, method) {

        if (this.paypalMarkUp === false) {
            return false;
        }

        if (action == 'refresh') {
            this.updatePayPalButton('remove');
            this.updatePayPalButton('add');
            return true;
        }

        // Check to see if we should be adding a PayPal button?
        if ((this.shouldAddPayPalButton(method) && action != 'remove') || action == 'add') {

            // Hide the checkout button
            if ($$(this.paypalButtonClass).first() !== undefined) {

                // Does a button already exist on the page and is visible?
                if ($$('#paypal-complete').first() !== undefined && $$('#paypal-complete').first().visible()) {
                    return true;
                }

                $$(this.paypalButtonClass).first().hide();

                // Insert out PayPal button
                $$(this.paypalButtonClass).first().insert({after: this.paypalMarkUp});

                var buttonParams = {
                    onSuccess: this.completePayPal.bind(this),
                    onReady: this.paypalOnReady.bind(this)
                };

                // Add in the PayPal button
                this.vzeroPaypal.addPayPalButton(buttonParams, this);

            } else {
                console.warn('We\'re unable to find the element ' + this.paypalButtonClass + '. Please check your integration.');
            }

        } else {

            // If not we need to remove it
            // Revert our madness
            if ($$(this.paypalButtonClass).first() !== undefined) {
                $$(this.paypalButtonClass).first().show();
            }

            // Remove the PayPal element
            if ($$('#paypal-complete').first() !== undefined) {
                $('paypal-complete').remove();
            }
        }

    },

    /**
     * Attach a click event handler to the button to validate the form
     *
     * @param integration
     */
    paypalOnReady: function (integration) {
        return true;
    },

    /**
     * Set the loading state
     */
    setLoading: function () {
        checkout.setLoadWaiting('payment');
    },

    /**
     * Reset the loading state
     */
    resetLoading: function () {
        checkout.setLoadWaiting(false);
    },

    /**
     * Make sure the device data field isn't disabled
     */
    enableDeviceData: function () {
        if ($('device_data') !== null) {
            $('device_data').removeAttribute('disabled');
        }
    },

    /**
     * Disable all fields in the credit card form so they don't end up going server side
     */
    disableCreditCardForm: function () {
        $$('#credit-card-form input, #credit-card-form select').each(function (formElement) {
            if (formElement.id != 'creditcard-payment-nonce' && formElement.id != 'gene_braintree_creditcard_store_in_vault') {
                formElement.setAttribute('disabled', 'disabled');
            }
        });
    },

    /**
     * Re-enable the credit card form, just in case there was an error etc
     */
    enableCreditCardForm: function () {
        $$('#credit-card-form input, #credit-card-form select').each(function (formElement) {
            formElement.removeAttribute('disabled');
        });
    },

    /**
     * Update the billing of the vZero object
     *
     * @returns {boolean}
     */
    updateBilling: function () {

        // Verify we're not using a saved address
        if (($('billing-address-select') !== null && $('billing-address-select').value == '') || $('billing-address-select') === null) {

            // Grab these directly from the form and update
            if ($('billing:firstname') !== null && $('billing:lastname') !== null) {
                this.vzero.setBillingName($('billing:firstname').value + ' ' + $('billing:lastname').value);
            }
            if ($('billing:postcode') !== null) {
                this.vzero.setBillingPostcode($('billing:postcode').value);
            }
        }
    },

    /**
     * Any extra data we need to pass through to the updateData call
     *
     * @returns {{}}
     */
    getUpdateDataParams: function () {
        var parameters = {};

        // If the billing address is selected and we're wanting to ship to that address we need to pass the addressId
        if ($('billing-address-select') !== null && $('billing-address-select').value != '') {
            parameters.addressId = $('billing-address-select').value;
        }

        return parameters;
    },

    /**
     * Return the current payment method
     *
     * @returns {*}
     */
    getPaymentMethod: function () {
        return payment.currentMethod;
    },

    /**
     * Should we intercept the save action of the checkout
     *
     * @param type
     * @returns {*}
     */
    shouldInterceptSubmit: function (type) {
        switch (type) {
            case 'creditcard':
                return (this.getPaymentMethod() == 'gene_braintree_creditcard' && this.vzero.shouldInterceptCreditCard());
                break;
            case 'paypal':
                return (this.getPaymentMethod() == 'gene_braintree_paypal' && this.vzero.shouldInterceptCreditCard());
                break;
        }
        return false;
    },

    /**
     * Should we be adding a PayPal button?
     * @returns {boolean}
     */
    shouldAddPayPalButton: function (method) {
        return (((method ? method : this.getPaymentMethod()) == 'gene_braintree_paypal' && $('paypal-saved-accounts') === null) || ((method ? method : this.getPaymentMethod()) == 'gene_braintree_paypal' && ($$('#paypal-saved-accounts input:checked[type=radio]').first() !== undefined && $$('#paypal-saved-accounts input:checked[type=radio]').first().value == 'other')));
    },

    /**
     * Function to run once 3D retokenization is complete
     */
    threeDTokenizationComplete: function () {
        this.resetLoading();
    },

    /**
     * Validate the entire form
     *
     * @returns {boolean}
     */
    validateAll: function () {
        return true;
    }

};

// Avoid 'console' errors in browsers that lack a console.
(function () {
    var method;
    var noop = function () {
    };
    var methods = [
        'assert', 'clear', 'count', 'debug', 'dir', 'dirxml', 'error',
        'exception', 'group', 'groupCollapsed', 'groupEnd', 'info', 'log',
        'markTimeline', 'profile', 'profileEnd', 'table', 'time', 'timeEnd',
        'timeStamp', 'trace', 'warn'
    ];
    var length = methods.length;
    var console = (window.console = window.console || {});

    while (length--) {
        method = methods[length];

        // Only stub undefined methods.
        if (!console[method]) {
            console[method] = noop;
        }
    }
}());