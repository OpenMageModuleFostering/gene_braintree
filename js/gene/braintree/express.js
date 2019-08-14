/**
 * Express paypal button for the Braintree module
 *
 * @author Aidan Threadgold <aidan@gene.co.uk>
 */
var ppExpress = (function() {

    var config;

    /**
     * Hold the paypal integration object
     */
    var paypalIntegration;

    /**
     * API to return
     */
    var api = {
        addBtn: function(secondConfig) {

            /**
             * Required config keys
             */
            config = {
                /**
                 * Braintree token
                 */
                token: null,

                /**
                 * Currency code
                 */
                currency: null,

                /**
                 * Locale code
                 */
                locale: null,

                /**
                 * Magento price format json
                 */
                priceFormat: null,

                /**
                 * Product ID
                 */
                productId: null,

                /**
                 * Url to the braintree authorization action within the express controller
                 */
                authUrl: null,

                /**
                 * Url to redirect to on successful order
                 */
                successUrl: null,

                /**
                 * Buy button will validate product add form if it's present
                 */
                validateProductForm: true,

                /**
                 * Optional
                 * Buy now button element
                 * @returns {Element}
                 */
                buyButton: function() {
                    var button = document.createElement('button');
                    button.innerHTML = typeof Translator === "object" ? Translator.translate("Checkout with PayPal") : "Checkout with PayPal";
                    button.className = "button pp-express-buy-btn";

                    return button;
                },

                /**
                 * Optional
                 * Dom element to append the buy now button to
                 * @returns {*}
                 */
                buyButtonPlacement: function() {
                    var placement = document.getElementsByClassName("add-to-cart");
                    if (placement.length > 0) {
                        placement = placement[0];
                        return placement;
                    }

                    return false;
                }
            };

            config = $H(config).merge(secondConfig);
            for(var key in config) {
                if( config[key] === null ) {
                    console.error('Invalid value for ' + key);
                    return false;
                }
            }

            initBraintree(config);
            initDom(config);
            return true;
        },

        /**
         * Get modal's overlay element
         * @returns {Element}
         */
        getOverlay: function() {
            return document.getElementById('pp-express-overlay');
        },

        /**
         * Get the modal element
         * @returns {Element}
         */
        getModal: function() {
            return document.getElementById('pp-express-modal');
        },

        /**
         * Hide the modal
         */
        hideModal: function() {
            this.getOverlay().style.display = 'none';
            this.getModal().style.display = 'none';

            this.getModal().innerHTML = '';
        },

        /**
         * Show the modal
         */
        showModal: function() {
            this.getModal().innerHTML = '';
            this.getModal().classList.add('loading');

            this.getOverlay().style.display = 'block';
            this.getModal().style.display = 'block';
        },

        /**
         * Update the grand total display within the modal
         */
        updateShipping: function(method) {
            new Ajax.Request(config.get('shippingSaveUrl'), {
                method: 'POST',
                parameters: {
                    'submit_shipping': true,
                    'shipping_method': method
                },

                onSuccess: function (data) {
                    $('paypal-express-totals').update(data.responseText);
                },

                onFailure: function () {
                    api.hideModal();
                    alert( typeof Translator === "object" ? Translator.translate("We were unable to complete the request. Please try again.") : "We were unable to complete the request. Please try again." );
                }
            });
        }
    };

    /**
     * Setup braintree
     */
    function initBraintree(config) {
        braintree.setup(config.get('token'), "custom", {
            paypal: {
                container: "pp-express-container",
                singleUse: false,
                currency: config.get('currency'),
                locale: config.get('locale'),
                enableShippingAddress: true,
                headless: true
            },

            onReady: function (integration) {
                paypalIntegration = integration;
            },

            onPaymentMethodReceived: function (obj) {
                api.showModal();

                /* Build the order */
                new Ajax.Request(config.get('authUrl'), {
                    method: 'POST',
                    parameters: {
                        'paypal': JSON.stringify(obj),
                        'product_id': config.get('productId'),
                        'form_data': $('product_addtocart_form') ? $('product_addtocart_form').serialize() : $('pp_express_form').serialize()
                    },

                    onSuccess: function (data) {
                        api.getModal().classList.remove('loading');
                        api.getModal().innerHTML = data.responseText;
                        ajaxHandler();
                    },

                    onFailure: function () {
                        api.hideModal();
                        alert( typeof Translator === "object" ? Translator.translate("We were unable to complete the request. Please try again.") : "We were unable to complete the request. Please try again." );
                    }
                });

            }
        });
    }

    /**
     * Attach an event to forms loaded through ajax to submit them again as ajax
     * */
    function ajaxHandler() {
        var forms = api.getModal().getElementsByTagName('form'),
            i = 0;

        if (forms.length > 0) {
            for (i = 0; i < forms.length; i++) {
                forms[i].addEventListener('submit', function (e) {
                    e.preventDefault();

                    api.getModal().classList.add('loading');
                    api.getModal().innerHTML = '';

                    new Ajax.Request(this.getAttribute('action'), {
                        method: 'POST',
                        parameters: $(this).serialize(true),

                        onSuccess: function (data) {
                            if (data.responseText == 'complete') {
                                document.location = config.get('successUrl');
                                return;
                            }

                            api.getModal().classList.remove('loading');
                            api.getModal().innerHTML = data.responseText;
                            ajaxHandler();
                        },

                        onFailure: function () {
                            api.hideModal();
                            alert( typeof Translator === "object" ? Translator.translate("We were unable to complete the request. Please try again.") : "We were unable to complete the request. Please try again." );
                        }
                    });

                    return false;
                });
            }
        }
    }

    /**
     * Add the buy button and modal events
     */
    function initDom(config) {
        /* Hide the overlay on click */
        api.getOverlay().addEventListener('click', function () {
            api.hideModal();
        });

        /* Append the buy button container next the cart button */
        var placement = config.get('buyButtonPlacement')(),
            buyButton = config.get('buyButton')();

        if( !placement ) {
            console.error("Invalid Braintree PayPal express placement element");
            return;
        }

        if( !buyButton ) {
            console.error("Invalid Braintree PayPal button element");
            return;
        }

        buyButton.addEventListener('click', function (event) {
            event.preventDefault();

            /* Validate product options and start the paypal flow */
            if (validateForm()) {
                paypalIntegration.paypal.initAuthFlow();
            }
        }, false);

        placement.appendChild(buyButton);
    }

    /**
     * Validate the form
     *
     * @returns {boolean}
     */
    function validateForm()
    {
        if (config.get("validateProductForm") === false) {
            return true;
        }

        // Validate the product add to cart form
        if (typeof productAddToCartForm === 'object' && productAddToCartForm.validator.validate()) {
            if (typeof productAddToCartFormOld === 'object' && productAddToCartFormOld.validator.validate()) {
                return true;
            } else if (typeof productAddToCartFormOld !== 'object') {
                return true;
            }
        }

        return (typeof productAddToCartForm !== 'object' && typeof productAddToCartFormOld !== 'object');
    }

    return api;
})();