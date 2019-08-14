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
                    return '.add-to-cart';
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
        updateShipping: function (method) {
            this._setLoading($('paypal-express-submit'));
            new Ajax.Request(config.get('shippingSaveUrl'), {
                method: 'POST',
                parameters: {
                    'submit_shipping': true,
                    'shipping_method': method
                },

                onSuccess: function (data) {
                    var response = this._getJson(data);
                    this._unsetLoading($('paypal-express-submit'));
                    this._updateTotals(response);
                }.bind(this),

                onFailure: function () {
                    this._unsetLoading($('paypal-express-submit'));
                    api.hideModal();
                    alert( typeof Translator === "object" ? Translator.translate("We were unable to complete the request. Please try again.") : "We were unable to complete the request. Please try again." );
                }
            });
        },

        /**
         * Prepare the coupon form by handling users hitting enter
         */
        prepareCoupon: function () {
            if ($('paypal-express-coupon')) {
                $('paypal-express-coupon').observe('keypress', function (event) {
                    var key = event.which || event.keyCode;
                    if (key == Event.KEY_RETURN) {
                        Event.stop(event);
                        this.updateCoupon();
                    }
                }.bind(this));
            }
        },

        /**
         * Allow customers to add coupons into their basket
         *
         * @param coupon
         */
        updateCoupon: function (coupon) {
            $('paypal-express-coupon-error').hide();
            if (!coupon && $('paypal-express-coupon')) {
                coupon = $('paypal-express-coupon').value;
            }

            // Only update if the coupon is set to something
            if (coupon == '') {
                return false;
            }

            this._setLoading($('paypal-express-coupon-apply'));
            new Ajax.Request(config.get('couponSaveUrl'), {
                method: 'POST',
                parameters: {
                    'coupon': coupon
                },

                onSuccess: function (data) {
                    var response = this._getJson(data);
                    this._unsetLoading($('paypal-express-coupon-apply'));
                    this._updateTotals(response);
                    if (response.success == true) {
                        $('paypal-express-coupon-remove').show();
                        $('paypal-express-coupon-apply').hide();
                    } else if (response.message) {
                        $('paypal-express-coupon-error').update(response.message).show();
                    }
                }.bind(this),

                onFailure: function () {
                    this._unsetLoading($('paypal-express-coupon-submit'));
                    api.hideModal();
                    alert( typeof Translator === "object" ? Translator.translate("We were unable to complete the request. Please try again.") : "We were unable to complete the request. Please try again." );
                }
            });
            return false;
        },

        /**
         * Allow the user the ability to remove the coupon code from their quote
         */
        removeCoupon: function () {
            $('paypal-express-coupon-error').hide();
            this._setLoading($('paypal-express-coupon-remove'));
            new Ajax.Request(config.get('couponSaveUrl'), {
                method: 'POST',
                parameters: {
                    'remove': true
                },

                onSuccess: function (data) {
                    var response = this._getJson(data);
                    this._unsetLoading($('paypal-express-coupon-remove'));
                    this._updateTotals(response);
                    if (response.success == true) {
                        $('paypal-express-coupon-remove').hide();
                        $('paypal-express-coupon-apply').show();
                        $('paypal-express-coupon').value = '';
                        $('paypal-express-coupon').focus();
                    } else if (response.message) {
                        $('paypal-express-coupon-error').update(response.message).show();
                    }
                }.bind(this),

                onFailure: function () {
                    this._unsetLoading($('paypal-express-coupon-submit'));
                    api.hideModal();
                    alert( typeof Translator === "object" ? Translator.translate("We were unable to complete the request. Please try again.") : "We were unable to complete the request. Please try again." );
                }
            });
        },

        /**
         * Update the totals from the response
         *
         * @param response
         * @private
         */
        _updateTotals: function (response) {
            if (typeof response.totals !== 'undefined') {
                $('paypal-express-totals').update(response.totals);
            }
        },

        /**
         * Return the JSON from the request
         *
         * @param data
         * @returns {*}
         * @private
         */
        _getJson: function (data) {
            if (typeof data.responseJSON !== 'undefined') {
                return data.responseJSON;
            } else if (typeof data.responseText === 'string') {
                return data.responseText.evalJSON();
            }
        },

        /**
         * Set an element to a loading state
         *
         * @param element
         * @private
         */
        _setLoading: function (element) {
            if (!element) {
                return false;
            }
            element.setAttribute('disabled', 'disabled');
            element.addClassName('loading');
        },

        /**
         * Unset the loading state
         *
         * @param element
         * @private
         */
        _unsetLoading: function (element) {
            if (!element) {
                return false;
            }
            element.removeAttribute('disabled');
            element.removeClassName('loading');
        }
    };

    /**
     * Setup braintree
     */
    function initBraintree(config) {
        var vzeroPaypal = new vZeroPayPalButton(
            config.get('token'),
            '',
            false,
            config.get('locale')
        );

        var options = {
            validate: validateForm(),
            onSuccess: function (payload) {
                api.showModal();

                /* Build the order */
                new Ajax.Request(config.get('authUrl'), {
                    method: 'POST',
                    parameters: {
                        'paypal': JSON.stringify(payload),
                        'product_id': config.get('productId'),
                        'form_data': $('product_addtocart_form') ? $('product_addtocart_form').serialize() : $('pp_express_form').serialize()
                    },

                    onSuccess: function (data) {
                        api.getModal().classList.remove('loading');
                        api.getModal().innerHTML = data.responseText;
                        api.prepareCoupon();
                        ajaxHandler();
                    },

                    onFailure: function () {
                        api.hideModal();
                        alert(typeof Translator === "object" ? Translator.translate("We were unable to complete the request. Please try again.") : "We were unable to complete the request. Please try again.");
                    }
                });
            },
            onReady: function (integration) {
                paypalIntegration = integration;
            }
        };

        // Initialize the PayPal button logic on any valid buttons on the page
        $$('[data-paypalexpress]').each(function (button) {
            vzeroPaypal.attachPayPalButtonEvent(button, options);
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