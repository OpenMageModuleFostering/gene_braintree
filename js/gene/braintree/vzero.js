
/**
 * Magento Braintree functionality wrapped up into a neat class
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
     * @param billingName Billing name used in verification of the card
     * @param billingPostcode Billing postcode also needed to verify the card
     */
    initialize: function (code, clientToken, threeDSecure, billingName, billingPostcode, quoteUrl, tokenizeUrl) {
        this.code = code;
        this.clientToken = clientToken;
        this.threeDSecure = threeDSecure;

        if(billingName) {
            this.billingName = billingName;
        }
        if(billingPostcode) {
            this.billingPostcode = billingPostcode;
        }
        if(quoteUrl) {
            this.quoteUrl = quoteUrl;
        }
        if(tokenizeUrl) {
            this.tokenizeUrl = tokenizeUrl;
        }

        this.acceptedCards = false;

        this.closeMethod = false;
    },

    /**
     * Init the vZero integration by starting a new version of the client
     * If 3D secure is enabled we also listen out for window messages
     */
    init: function() {

        // Different environments depending on 3D secure
        if(this.threeDSecure == true) {

            // Add an event in for messages
            if (window.addEventListener){
                window.addEventListener("message", this.receiveMessage.bind(this), false);
            } else {
                window.attachEvent("onmessage", this.receiveMessage.bind(this));
            }
        }

        this.client = new braintree.api.Client({clientToken: this.clientToken});

    },

    /**
     * Set the 3Ds flag
     *
     * @param flag a boolean value
     */
    setThreeDSecure: function(flag) {
        this.threeDSecure = flag;
    },

    /**
     * Set the amount within the checkout, this is only used in the default integration
     * For any other checkouts see the updateData method, this is used by 3D secure
     *
     * @param amount The grand total of the order
     */
    setAmount: function(amount) {
        this.amount = parseFloat(amount);
    },

    /**
     * We sometimes need to set the billing name later on in the process
     *
     * @param billingName
     */
    setBillingName: function(billingName) {
        this.billingName = billingName;
    },

    /**
     * Return the billing name
     *
     * @returns {*}
     */
    getBillingName: function() {

        // If billingName is an object we're wanting to grab the data from elements
        if(typeof this.billingName == 'object') {

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
    setBillingPostcode: function(billingPostcode) {
        this.billingPostcode = billingPostcode;
    },

    /**
     * Return the billing name
     *
     * @returns {*}
     */
    getBillingPostcode: function() {

        // If billingName is an object we're wanting to grab the data from elements
        if(typeof this.billingPostcode == 'object') {

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
    setAcceptedCards: function(cards) {
        this.acceptedCards = cards;
    },

    /**
     * Return the accepted cards
     *
     * @returns {boolean|*}
     */
    getAcceptedCards: function() {
        return this.acceptedCards;
    },


    /**
     * Combine elements values into a string
     *
     * @param elements
     * @param seperator
     * @returns {string}
     */
    combineElementsValues: function(elements, seperator) {

        // If no seperator is set use a space
        if(!seperator) {
            seperator = ' ';
        }

        // Loop through the elements and build up an array
        var response = [];
        elements.each(function(element, index) {
            if($(element) !== undefined) {
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
     */
    updateCardType: function(cardNumber) {

        // Retrieve the card type
        var cardType = vzero.getCardType(cardNumber);

        if (cardType == 'card') {
            // If we cannot detect which kind of card they're using remove the value from the select
            $('gene_braintree_creditcard_cc_type').value = '';
        } else {
            // Update the validation field
            $('gene_braintree_creditcard_cc_type').value = cardType;
        }

        // Check the image exists on the page
        if($('card-type-image') != undefined) {

            // Grab the skin image URL without the last part
            var skinImageUrl = $('card-type-image').src.substring(0, $('card-type-image').src.lastIndexOf("/"));

            // Rebuild the URL with the card type included, all card types are stored as PNG's
            $('card-type-image').setAttribute('src', skinImageUrl + "/" + cardType + ".png");

        }

    },

    /**
     * Create a new event upon the card number field
     */
    observeCardType: function() {

        if(!$$('[data-genebraintree-name="number"]').first() != undefined) {

            // Observe any blurring on the form
            Element.observe($$('[data-genebraintree-name="number"]').first(), 'keyup', function (event) {
                vzero.updateCardType(this.value);
            });

        }

    },

    /**
     * Observe all Ajax requests, this is needed on certain checkouts
     * where we're unable to easily inject into methods
     *
     * @param callback A defined callback function if needed
     */
    observeAjaxRequests: function(callback) {

        // For every ajax request on complete update various Braintree things
        Ajax.Responders.register({
            onComplete: function(transport) {

                // Check the transport object has a URL and that it wasn't to our own controller
                if(transport.url && transport.url.indexOf('braintree') == -1) {

                    // Some checkout implementations may require custom callbacks
                    if(callback) {
                        callback();
                    } else {
                        this.updateData();
                    }
                }
            }.bind(this)
        });

    },

    /**
     * Make an Ajax request to the server and request up to date information regarding the quote
     *
     * @param callback A defined callback function if needed
     * @param params any extra data to be passed to the controller
     */
    updateData: function(callback, params) {

        // Make a new ajax request to the server
        new Ajax.Request(
            this.quoteUrl,
            {
                method:'post',
                parameters: params,
                onSuccess: function(transport) {

                    // Verify we have some response text
                    if (transport && transport.responseText) {

                        // Parse as an object
                        try {
                            response = eval('(' + transport.responseText + ')');
                        }
                        catch (e) {
                            response = {};
                        }

                        if(response.billingName != undefined) {
                            this.billingName = response.billingName;
                        }
                        if(response.billingPostcode != undefined) {
                            this.billingPostcode = response.billingPostcode;
                        }
                        if(response.grandTotal != undefined) {
                            this.amount = response.grandTotal;
                        }
                        if(response.threeDSecure != undefined) {
                            this.setThreeDSecure(response.threeDSecure);
                        }

                        // If PayPal is active update it
                        if(typeof vzeroPaypal != "undefined") {

                            // Update the totals within the PayPal system
                            if(response.grandTotal != undefined && response.currencyCode != undefined) {
                                vzeroPaypal.setPricing(response.grandTotal, response.currencyCode);
                            }

                        }

                        if(callback) {
                            callback(response);
                        }
                    }
                }.bind(this)
            }
        );

    },

    /**
     * Handle the user closing the 3D secure interface
     *
     * @param event
     * @todo this functionality is not officially documented, waiting for official release of onClose method
     */
    receiveMessage: function(event) {

        // If the user closed the window unset the load waiting
        if(event.data == 'user_closed=true') {

            // Is there a close method defined?
            if(this.closeMethod) {
                this.closeMethod();
            } else {
                checkout.setLoadWaiting(false);
            }
        }
    },

    /**
     * Allow custom checkouts to set a custom method for closing 3D secure
     *
     * @param callback A defined callback function if needed
     */
    close3dSecureMethod: function(callback) {
        this.closeMethod = callback;
    },

    /**
     * If the user attempts to use a 3D secure vaulted card and then cancels the 3D
     * window the nonce associated with that card will become invalid, due to this
     * we have to tokenize all the 3D secure cards again
     *
     * @param callback A defined callback function if needed
     */
    tokenize3dSavedCards: function(callback) {

        // Check 3D is enabled
        if(this.threeDSecure) {

            // Verify we have elements with data-token
            if($$('[data-token]').first() != undefined) {

                // Gather our tokens
                tokens = [];
                $$('[data-token]').each(function (element, index) {
                    tokens[index] = element.getAttribute('data-token');
                });

                // Make a new ajax request to the server
                new Ajax.Request(
                    this.tokenizeUrl,
                    {
                        method:'post',
                        onSuccess: function(transport) {

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
                                if(response.success) {

                                    // Loop through the returned tokens
                                    $H(response.tokens).each(function (element) {

                                        // If the token exists update it's nonce
                                        if($$('[data-token="' + element.key + '"]').first() != undefined) {
                                            $$('[data-token="' + element.key + '"]').first().setAttribute('data-threedsecure-nonce', element.value);
                                        }
                                    });
                                }

                                if(callback) {
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

    /**
     * Make a request to Braintree for 3D secure information
     *
     * @param options Contains any callback functions which have been set
     */
    verify3dSecure: function(options) {

        var threeDSecureRequest = {
            amount: this.amount,
            creditCard: {
                number: $$('[data-genebraintree-name="number"]').first().value,
                expirationMonth: $$('[data-genebraintree-name="expiration_month"]').first().value,
                expirationYear: $$('[data-genebraintree-name="expiration_year"]').first().value,
                cardholderName: this.getBillingName()
            }
        };

        // If the CVV field exists include it
        if($$('[data-genebraintree-name="cvv"]').first() != undefined) {
            threeDSecureRequest.creditCard.cvv = $$('[data-genebraintree-name="cvv"]').first().value;
        }

        // If we have the billing postcode add it into the request
        if(this.getBillingPostcode() != "") {
            threeDSecureRequest.creditCard.billingAddress = {
                postalCode: this.getBillingPostcode()
            };
        }

        // Run the verify function on the braintree client
        this.client.verify3DS(threeDSecureRequest, function (error, response) {

            if (!error) {

                // Store threeDSecure token and nonce in form
                $('creditcard-payment-nonce').value = response.nonce;

                // Run any callback functions
                if(options.onSuccess) {
                    options.onSuccess();
                }
            } else {

                // Show the error
                alert(error.message);

                if(options.onFailure) {
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
    verify3dSecureVault: function(options) {

        // Get the payment nonce
        var paymentNonce = $$('#creditcard-saved-accounts input:checked[type=radio]').first().getAttribute('data-threedsecure-nonce');

        if(paymentNonce) {
            // Run the verify function on the braintree client
            this.client.verify3DS({
                amount: this.amount,
                creditCard: paymentNonce
            }, function (error, response) {

                if (!error) {

                    // Store threeDSecure token and nonce in form
                    $('creditcard-payment-nonce').removeAttribute('disabled');
                    $('creditcard-payment-nonce').value = response.nonce;

                    // Run any callback functions
                    if (options.onSuccess) {
                        options.onSuccess();
                    }
                } else {

                    // Show the error
                    alert(error.message);

                    if(options.onFailure) {
                        options.onFailure();
                    } else {
                        checkout.setLoadWaiting(false);
                    }
                }
            });
        } else {

            alert('No payment nonce present.');

            if(options.onFailure) {
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
    processCard: function(options) {

        var tokenizeRequest = {
            number: $$('[data-genebraintree-name="number"]').first().value,
            cardholderName: this.getBillingName(),
            expirationMonth: $$('[data-genebraintree-name="expiration_month"]').first().value,
            expirationYear: $$('[data-genebraintree-name="expiration_year"]').first().value
        };

        // If the CVV field exists include it
        if($$('[data-genebraintree-name="cvv"]').first() != undefined) {
            tokenizeRequest.cvv = $$('[data-genebraintree-name="cvv"]').first().value;
        }

        // If we have the billing postcode add it into the request
        if(this.getBillingPostcode() != "") {
            tokenizeRequest.billingAddress = {
                postalCode: this.getBillingPostcode()
            };
        }

        // Attempt to tokenize the card
        this.client.tokenizeCard(tokenizeRequest, function (errors, nonce) {

            if(!errors) {
                // Update the nonce in the form
                $('creditcard-payment-nonce').value = nonce;

                // Run any callback functions
                if(options.onSuccess) {
                    options.onSuccess();
                }
            } else {
                // Handle errors
                for (var i = 0; i < errors.length; i++) {
                    alert(errors[i].code + " " + errors[i].message);
                }

                if(options.onFailure) {
                    options.onFailure();
                } else {
                    checkout.setLoadWaiting(false);
                }
            }
        });

    },

    /**
     * Conduct a regular expression check to determine card type automatically
     *
     * @param number
     * @returns {string}
     */
    getCardType: function(number) {

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
    process: function(options) {

        // We have to handle vaulted 3D secure cards differently
        if ($('creditcard-saved-accounts') != undefined
            && $$('#creditcard-saved-accounts input:checked[type=radio]').first() != undefined
            && $$('#creditcard-saved-accounts input:checked[type=radio]').first().hasAttribute('data-threedsecure-nonce'))
        {
            // The user has selected a card stored via 3D secure
            this.verify3dSecureVault(options);

        } else if($('creditcard-saved-accounts') != undefined
            && $$('#creditcard-saved-accounts input:checked[type=radio]').first() != undefined
            && $$('#creditcard-saved-accounts input:checked[type=radio]').first().value !== 'other')
        {
            // No action required as we're using a saved card
            if(options.onSuccess) {
                options.onSuccess()
            }

        } else if(this.threeDSecure == true) {

            // Standard 3D secure callback
            this.verify3dSecure(options);

        } else {

            // Otherwise process the card normally
            this.processCard(options);
        }
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
     * @param locale The locale for the pamynet
     * @param futureSingleUse When using future payments should we process the transaction as a single payment?
     */
    initialize: function (clientToken, storeFrontName, singleUse, locale, futureSingleUse) {
        this.clientToken = clientToken;
        this.storeFrontName = storeFrontName;
        this.singleUse = singleUse;
        this.locale = locale;
        this.futureSingleUse = futureSingleUse;

        this.PayPalClient = false;
    },

    /**
     * Update the pricing information for the PayPal button
     * If the PayPalClient has already been created we also update the _clientOptions
     * so the PayPal modal window displays the correct values
     *
     * @param amount The amount formatted to two decimal places
     * @param currency The currency code
     */
    setPricing: function(amount, currency) {

        // Set them into the class
        this.amount = parseFloat(amount);
        this.currency = currency;

        // If the client exists update the clientOptions
        if(this.PayPalClient._clientOptions != undefined) {
            this.PayPalClient._clientOptions.amount = parseFloat(amount);
            this.PayPalClient._clientOptions.currency = currency;
        }
    },

    /**
     * Inject the PayPal button into the document
     *
     * @param options Object containing onSuccess method
     */
    addPayPalButton: function(options) {

        // Build up our setup configuration
        var setupConfiguration = {
            container: "paypal-container",
            paymentMethodNonceInputField: "paypal-payment-nonce",
            displayName: this.storeFrontName,
            onPaymentMethodReceived: function(obj) {

                // If a callback is defined we're doing something crazy!
                if(typeof options != 'undefined' && options.onSuccess) {
                    options.onSuccess(obj);
                } else {
                    // Force check
                    payment.switchMethod('gene_braintree_paypal');

                    // Re-enable the form
                    $('paypal-payment-nonce').disabled = false;

                    // Remove the PayPal button
                    $('paypal-complete').remove();

                    // Submit the checkout steps
                    review.save();
                }

            },
            onUnsupported: function() {
                alert('Sadly your browser does not support purchasing with PayPal.');
            }
        };

        // Detect single use
        if(this.singleUse == true) {

            setupConfiguration.singleUse = true;
            setupConfiguration.amount = this.amount;
            setupConfiguration.currency = this.currency;
            setupConfiguration.locale = this.locale;

        } else if(this.futureSingleUse == true) {

            setupConfiguration.singleUse = true;

        }

        // Start a new version of the client and assign for later modifications
        this.PayPalClient = braintree.setup(this.clientToken, "paypal", setupConfiguration);
    },

    /**
     * Allow closing of the PayPal window
     *
     * @param callback A defined callback function if needed
     */
    closePayPalWindow: function(callback) {

        // Make sure the client is active
        if(this.PayPalClient != undefined) {
            this.PayPalClient._close();

            if(callback) {
                callback();
            }
        }
    }

};