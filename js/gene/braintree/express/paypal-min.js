var BraintreePayPalExpress=Class.create(BraintreeExpressAbstract,{vzeroPayPal:!1,_init:function(){this.vzeroPayPal=new vZeroPayPalButton(this.config.token,"",!1,this.config.locale)},attachToButtons:function(a){var t={validate:this.validateForm,onSuccess:function(a){var t={paypal:JSON.stringify(a)};"undefined"!=typeof this.config.productId&&(t.product_id=this.config.productId,t.form_data=$("product_addtocart_form")?$("product_addtocart_form").serialize():$("pp_express_form").serialize()),this.initModal(t)}.bind(this)};a.each(function(a){a.up().addClassName("braintree-paypal-express-container")}),this.vzeroPayPal.attachPayPalButtonEvent(a,t)}});