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

        // Set these to default values on initialization
        this.amount = 0.00;
        this.currency = false;

        this.client = false;
    },

    /**
     * Retrieve the client from the class, or initialize the client if not already present
     *
     * @param callbackFn
     */
    getClient: function (callbackFn) {
        if (this.client !== false) {
            if (typeof callbackFn === 'function') {
                callbackFn(this.client);
            }
        } else {
            // Create a new braintree client instance
            braintree.client.create({
                authorization: this.clientToken
            }, function (clientErr, clientInstance) {
                if (clientErr) {
                    // Handle error in client creation
                    console.log(clientErr);
                    return;
                }

                this.client = clientInstance;
                callbackFn(this.client);
            }.bind(this));
        }
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
    },

    /**
     * Rebuild the button
     *
     * @deprecated due to JavaScript v3
     * @returns {boolean}
     */
    rebuildButton: function () {
        return false;
    },

    /**
     * Inject the PayPal button into the document
     *
     * @param options Object containing onSuccess method
     * @param buttonHtml
     * @param containerQuery
     * @param append
     */
    addPayPalButton: function (options, buttonHtml, containerQuery, append) {
        var container;
        buttonHtml = buttonHtml || $('braintree-paypal-button').innerHTML;
        containerQuery = containerQuery || '#paypal-container';
        append = append || false;

        // Get the container element
        if (typeof containerQuery === 'string') {
            container = $$(containerQuery).first();
        } else {
            container = containerQuery;
        }

        // Verify the container is present on the page
        if (!container) {
            console.warn('Unable to locate container ' + containerQuery + ' for PayPal button.');
            return false;
        }

        // Insert the button element
        if (append) {
            container.insert(buttonHtml);
        } else {
            container.update(buttonHtml);
        }

        // Check the container contains a valid button element
        if (!container.select('>button').length) {
            console.warn('Unable to find valid <button /> element within container.');
            return false;
        }

        // Grab the button and add a loading class
        var button = container.select('>button').first();
        button.addClassName('braintree-paypal-loading');
        button.setAttribute('disabled', 'disabled');

        // Attach our PayPal button event to our injected button
        this.attachPayPalButtonEvent(button, options);
    },

    /**
     * Attach the PayPal button event
     *
     * @param buttons
     * @param options
     */
    attachPayPalButtonEvent: function (buttons, options) {
        // Grab an instance of the Braintree client
        this.getClient(function (clientInstance) {
            // Create a new instance of PayPal
            braintree.paypal.create({
                client: clientInstance
            }, function (paypalErr, paypalInstance) {
                if (paypalErr) {
                    console.error('Error creating PayPal:', paypalErr);
                    return;
                }

                // Run the onReady callback
                if (typeof options.onReady === 'function') {
                    options.onReady(paypalInstance);
                }

                // Attach the PayPal button event
                return this._attachPayPalButtonEvent(buttons, paypalInstance, options);
            }.bind(this));
        }.bind(this));
    },

    /**
     * Attach the click event to the paypal button
     *
     * @param buttons
     * @param paypalInstance
     * @param options
     *
     * @private
     */
    _attachPayPalButtonEvent: function (buttons, paypalInstance, options) {
        if (buttons && paypalInstance) {

            // Convert the buttons to an array and handle them all at once
            if (!Array.isArray(buttons)) {
                buttons = [buttons];
            }

            // Handle each button
            buttons.each(function (button) {
                button.removeClassName('braintree-paypal-loading');
                button.removeAttribute('disabled');

                // Remove any events currently assigned to the button
                Event.stopObserving(button, 'click');

                // Observe the click event to fire the tokenization of PayPal (ie open the window)
                Event.observe(button, 'click', function (event) {
                    Event.stop(event);
                    if (typeof options.validate === 'function') {
                        if (options.validate()) {
                            // Fire the integration
                            return this._tokenizePayPal(paypalInstance, options);
                        }
                    } else {
                        // Fire the integration
                        return this._tokenizePayPal(paypalInstance, options);
                    }

                }.bind(this));
            }.bind(this));
        }
    },

    /**
     * Tokenize PayPal
     *
     * @param paypalInstance
     * @param options
     *
     * @private
     */
    _tokenizePayPal: function (paypalInstance, options) {
        var tokenizeOptions = this._buildOptions();
        if (typeof options.tokenizeRequest === 'object') {
            tokenizeOptions = Object.extend(tokenizeOptions, options.tokenizeRequest);
        }

        // Because tokenization opens a popup, this has to be called as a result of
        // customer action, like clicking a button—you cannot call this at any time.
        paypalInstance.tokenize(tokenizeOptions, function (tokenizeErr, payload) {
            // Stop if there was an error.
            if (tokenizeErr) {
                if (tokenizeErr.type !== 'CUSTOMER') {
                    console.error('Error tokenizing:', tokenizeErr);
                }
                return;
            }

            // If we have a success callback we're most likely using a non-default checkout
            if (typeof options.onSuccess === 'function') {
                options.onSuccess(payload);
            }

        }.bind(this));

    },

    /**
     * Build the options for our tokenization
     *
     * @returns {{displayName: *, amount: *, currency: *}}
     * @private
     */
    _buildOptions: function () {
        var options = {
            displayName: this.storeFrontName,
            amount: this.amount,
            currency: this.currency,
            useraction: 'commit' /* The user is committing to the order on submission of PayPal */
        };

        // Pass over the locale
        if (this.locale) {
            options.locale = this.locale;
        }

        // Determine the flow based on the singleUse parameter
        if (this.singleUse === true) {
            options.flow = 'checkout';
        } else {
            options.flow = 'vault';
        }

        return options;
    }
};