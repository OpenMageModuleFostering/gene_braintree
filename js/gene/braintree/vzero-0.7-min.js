var vZero=Class.create();vZero.prototype={initialize:function(e,t,i,n,a,s,o,r,d){this.code=e,this.clientToken=t,this.threeDSecure=i,this.hostedFields=n,a&&(this.billingName=a),s&&(this.billingPostcode=s),o&&(this.quoteUrl=o),r&&(this.tokenizeUrl=r),d&&(this.vaultToNonceUrl=d),this._hostedFieldsTokenGenerated=!1,this.acceptedCards=!1,this.closeMethod=!1,this._hostedFieldsTimeout=!1,this._updateDataXhr=!1,this._updateDataCallbacks=[],this._updateDataParams={},this._vaultToNonceXhr=!1},init:function(){this.client=new braintree.api.Client({clientToken:this.clientToken})},initHostedFields:function(e){return $$('iframe[name^="braintree-"]').length>0?!1:null===$("braintree-hosted-submit")?!1:(this.integration=e,this._hostedFieldsTokenGenerated=!1,clearTimeout(this._hostedFieldsTimeout),void(this._hostedFieldsTimeout=setTimeout(function(){if(this._hostedIntegration!==!1)try{this._hostedIntegration.teardown(function(){this._hostedIntegration=!1,this.setupHostedFieldsClient()}.bind(this))}catch(e){this.setupHostedFieldsClient()}else this.setupHostedFieldsClient()}.bind(this),50)))},teardownHostedFields:function(e){this._hostedIntegration!==!1?this._hostedIntegration.teardown(function(){this._hostedIntegration=!1,"function"==typeof e&&e()}.bind(this)):"function"==typeof e&&e()},setupHostedFieldsClient:function(){if($$('iframe[name^="braintree-"]').length>0)return!1;this._hostedIntegration=!1;var e={id:this.integration.form,hostedFields:{styles:this.getHostedFieldsStyles(),number:{selector:"#card-number",placeholder:"0000 0000 0000 0000"},expirationMonth:{selector:"#expiration-month",placeholder:"MM"},expirationYear:{selector:"#expiration-year",placeholder:"YY"},onFieldEvent:this.hostedFieldsOnFieldEvent.bind(this)},onReady:this.hostedFieldsOnReady.bind(this),onPaymentMethodReceived:this.hostedFieldsPaymentMethodReceived.bind(this),onError:this.hostedFieldsError.bind(this)};null!==$("cvv")&&(e.hostedFields.cvv={selector:"#cvv"}),braintree.setup(this.clientToken,"custom",e)},getHostedFieldsStyles:function(){return"function"==typeof this.integration.getHostedFieldsStyles?this.integration.getHostedFieldsStyles():{input:{"font-size":"14pt",color:"#3A3A3A"},":focus":{color:"black"},".valid":{color:"green"},".invalid":{color:"red"}}},hostedFieldsOnFieldEvent:function(e){if("fieldStateChange"===e.type&&e.card){var t={visa:"VI","american-express":"AE","master-card":"MC",discover:"DI",jcb:"JCB",maestro:"ME"};void 0!==typeof t[e.card.type]?this.updateCardType(!1,t[e.card.type]):this.updateCardType(!1,"card")}},vaultToNonce:function(nonce,callback){var parameters=this.getBillingAddress();parameters.nonce=nonce,new Ajax.Request(this.vaultToNonceUrl,{method:"post",parameters:parameters,onSuccess:function(transport){if(transport&&transport.responseText){var response;try{response=eval("("+transport.responseText+")")}catch(e){response={}}response.success&&response.nonce?callback(response.nonce):("function"==typeof this.integration.resetLoading&&this.integration.resetLoading(),response.error?alert(response.error):alert("Something wen't wrong and we're currently unable to take your payment."))}}.bind(this),onFailure:function(){"function"==typeof this.integration.resetLoading&&this.integration.resetLoading(),alert("Something wen't wrong and we're currently unable to take your payment.")}.bind(this)})},hostedFieldsOnReady:function(e){if(this._hostedIntegration=e,$$("#credit-card-form.loading").length&&$$("#credit-card-form.loading").first().removeClassName("loading"),this.integration.submitAfterPayment){var t=new Element("input",{type:"hidden",name:"payment[submit_after_payment]",value:1,id:"braintree-submit-after-payment"});$("payment_form_gene_braintree_creditcard").insert(t)}else $("braintree-submit-after-payment")&&$("braintree-submit-after-payment").remove()},hostedFieldsPaymentMethodReceived:function(e){this.threeDSecure?("function"==typeof this.integration.setLoading&&this.integration.setLoading(),this.updateData(function(){this.vaultToNonce(e.nonce,function(e){"function"==typeof this.integration.resetLoading&&this.integration.resetLoading(),this.verify3dSecureNonce(e,{onSuccess:function(e){this.hostedFieldsNonceReceived(e.nonce)}.bind(this),onFailure:function(e,t){alert(t)}.bind(this)})}.bind(this))}.bind(this))):this.hostedFieldsNonceReceived(e.nonce)},hostedFieldsNonceReceived:function(e){$("creditcard-payment-nonce").value=e,$("creditcard-payment-nonce").setAttribute("value",e),"function"==typeof this.integration.resetLoading&&this.integration.resetLoading(),this._hostedFieldsTokenGenerated=!0,"function"==typeof this.integration.afterHostedFieldsNonceReceived&&this.integration.afterHostedFieldsNonceReceived(e)},hostedFieldsError:function(e){return"function"==typeof this.integration.resetLoading&&this.integration.resetLoading(),"undefined"!=typeof e.message&&-1==e.message.indexOf("Cannot place two elements in")&&-1==e.message.indexOf("Unable to find element with selector")&&alert(e.message),this._hostedFieldsTokenGenerated=!1,"function"==typeof this.integration.afterHostedFieldsError&&this.integration.afterHostedFieldsError(e.message),!1},usingSavedCard:function(){return void 0!=$("creditcard-saved-accounts")&&void 0!=$$("#creditcard-saved-accounts input:checked[type=radio]").first()&&"other"!==$$("#creditcard-saved-accounts input:checked[type=radio]").first().value},setThreeDSecure:function(e){this.threeDSecure=e},setAmount:function(e){this.amount=parseFloat(e)},setBillingName:function(e){this.billingName=e},getBillingName:function(){return"object"==typeof this.billingName?this.combineElementsValues(this.billingName):this.billingName},setBillingPostcode:function(e){this.billingPostcode=e},getBillingPostcode:function(){return"object"==typeof this.billingPostcode?this.combineElementsValues(this.billingPostcode):this.billingPostcode},setAcceptedCards:function(e){this.acceptedCards=e},getBillingAddress:function(){if("function"==typeof this.integration.getBillingAddress)return this.integration.getBillingAddress();var e={};return null!==$("co-billing-form")?e="FORM"==$("co-billing-form").tagName?$("co-billing-form").serialize(!0):this.extractBilling($("co-billing-form").up("form").serialize(!0)):null!==$("billing:firstname")&&(e=this.extractBilling($("billing:firstname").up("form").serialize(!0))),e?e:void 0},extractBilling:function(e){var t={};return $H(e).each(function(e){0==e.key.indexOf("billing")&&-1==e.key.indexOf("password")&&(t[e.key]=e.value)}),t},getAcceptedCards:function(){return this.acceptedCards},combineElementsValues:function(e,t){t||(t=" ");var i=[];return e.each(function(e,t){void 0!==$(e)&&(i[t]=$(e).value)}),i.join(t)},updateCardType:function(e,t){if(t||(t=this.getCardType(e)),void 0!=$("gene_braintree_creditcard_cc_type")&&("card"==t?$("gene_braintree_creditcard_cc_type").value="":$("gene_braintree_creditcard_cc_type").value=t),void 0!=$("card-type-image")){var i=$("card-type-image").src.substring(0,$("card-type-image").src.lastIndexOf("/"));$("card-type-image").setAttribute("src",i+"/"+t+".png")}},observeCardType:function(){void 0!==$$('[data-genebraintree-name="number"]').first()&&(Element.observe($$('[data-genebraintree-name="number"]').first(),"keyup",function(){vzero.updateCardType(this.value)}),$$('[data-genebraintree-name="number"]').first().oninput=function(){var e=this.value.split(" ").join("");e.length>0&&(e=e.match(new RegExp(".{1,4}","g")).join(" ")),this.value=e})},observeAjaxRequests:function(e,t){return vZero.prototype.observingAjaxRequests?!1:(vZero.prototype.observingAjaxRequests=!0,Ajax.Responders.register({onComplete:function(i){return this.handleAjaxRequest(i.url,e,t)}.bind(this)}),void(window.jQuery&&jQuery(document).ajaxComplete(function(i,n,a){return this.handleAjaxRequest(a.url,e,t)}.bind(this))))},handleAjaxRequest:function(e,t,i){if("undefined"!=typeof i&&i instanceof Array&&i.length>0){var n=!1;if(i.each(function(t){e&&-1!=e.indexOf(t)&&(n=!0)}),n===!0)return!1}e&&-1==e.indexOf("/braintree/")&&(t?t(e):this.updateData())},updateData:function(e,t){this._updateDataCallbacks.push(e),this._updateDataParams=t,this._updateDataXhr!==!1&&this._updateDataXhr.transport.abort(),this._updateDataXhr=new Ajax.Request(this.quoteUrl,{method:"post",parameters:this._updateDataParams,onSuccess:function(e){if(e&&(e.responseJSON||e.responseText)){var t;e.responseJSON&&"object"==typeof e.responseJSON?t=e.responseJSON:e.responseText&&(t=JSON.decode(e.responseText)),void 0!=t.billingName&&(this.billingName=t.billingName),void 0!=t.billingPostcode&&(this.billingPostcode=t.billingPostcode),void 0!=t.grandTotal&&(this.amount=t.grandTotal),void 0!=t.threeDSecure&&this.setThreeDSecure(t.threeDSecure),"undefined"!=typeof vzeroPaypal&&void 0!=t.grandTotal&&void 0!=t.currencyCode&&vzeroPaypal.setPricing(t.grandTotal,t.currencyCode),this._updateDataParams={},this._updateDataXhr=!1,this._updateDataCallbacks.length&&(this._updateDataCallbacks.each(function(e){e(t)}.bind(this)),this._updateDataCallbacks=[])}}.bind(this),onFailure:function(){this._updateDataParams={},this._updateDataXhr=!1,this._updateDataCallbacks=[]}.bind(this)})},close3dSecureMethod:function(e){this.closeMethod=e},tokenize3dSavedCards:function(callback){if(this.threeDSecure)if(void 0!==$$("[data-token]").first()){var tokens=[];$$("[data-token]").each(function(e,t){tokens[t]=e.getAttribute("data-token")}),new Ajax.Request(this.tokenizeUrl,{method:"post",onSuccess:function(transport){if(transport&&transport.responseText){try{response=eval("("+transport.responseText+")")}catch(e){response={}}response.success&&$H(response.tokens).each(function(e){void 0!=$$('[data-token="'+e.key+'"]').first()&&$$('[data-token="'+e.key+'"]').first().setAttribute("data-threedsecure-nonce",e.value)}),callback&&callback(response)}}.bind(this),parameters:{tokens:Object.toJSON(tokens)}})}else callback();else callback()},onUserClose3ds:function(){this._hostedFieldsTokenGenerated=!1,this.closeMethod?this.closeMethod():checkout.setLoadWaiting(!1)},verify3dSecureNonce:function(e,t){var i={amount:this.amount,creditCard:e,onUserClose:this.onUserClose3ds.bind(this)};this.client.verify3DS(i,function(e,i){e?t.onFailure&&t.onFailure(i,e.message):t.onSuccess&&t.onSuccess(i)})},verify3dSecure:function(e){var t={amount:this.amount,creditCard:{number:$$('[data-genebraintree-name="number"]').first().value,expirationMonth:$$('[data-genebraintree-name="expiration_month"]').first().value,expirationYear:$$('[data-genebraintree-name="expiration_year"]').first().value,cardholderName:this.getBillingName()},onUserClose:this.onUserClose3ds.bind(this)};void 0!=$$('[data-genebraintree-name="cvv"]').first()&&(t.creditCard.cvv=$$('[data-genebraintree-name="cvv"]').first().value),""!=this.getBillingPostcode()&&(t.creditCard.billingAddress={postalCode:this.getBillingPostcode()}),this.client.verify3DS(t,function(t,i){t?(alert(t.message),e.onFailure?e.onFailure():checkout.setLoadWaiting(!1)):($("creditcard-payment-nonce").value=i.nonce,$("creditcard-payment-nonce").setAttribute("value",i.nonce),e.onSuccess&&e.onSuccess())})},verify3dSecureVault:function(e){var t=$$("#creditcard-saved-accounts input:checked[type=radio]").first().getAttribute("data-threedsecure-nonce");t?this.client.verify3DS({amount:this.amount,creditCard:t},function(t,i){t?(alert(t.message),e.onFailure?e.onFailure():checkout.setLoadWaiting(!1)):($("creditcard-payment-nonce").removeAttribute("disabled"),$("creditcard-payment-nonce").value=i.nonce,$("creditcard-payment-nonce").setAttribute("value",i.nonce),e.onSuccess&&e.onSuccess())}):(alert("No payment nonce present."),e.onFailure?e.onFailure():checkout.setLoadWaiting(!1))},processCard:function(e){var t={number:$$('[data-genebraintree-name="number"]').first().value,cardholderName:this.getBillingName(),expirationMonth:$$('[data-genebraintree-name="expiration_month"]').first().value,expirationYear:$$('[data-genebraintree-name="expiration_year"]').first().value};void 0!=$$('[data-genebraintree-name="cvv"]').first()&&(t.cvv=$$('[data-genebraintree-name="cvv"]').first().value),""!=this.getBillingPostcode()&&(t.billingAddress={postalCode:this.getBillingPostcode()}),this.client.tokenizeCard(t,function(t,i){if(t){for(var n=0;n<t.length;n++)alert(t[n].code+" "+t[n].message);e.onFailure?e.onFailure():checkout.setLoadWaiting(!1)}else $("creditcard-payment-nonce").value=i,$("creditcard-payment-nonce").setAttribute("value",i),e.onSuccess&&e.onSuccess()})},shouldInterceptCreditCard:function(){return"0.00"!=this.amount},shouldInterceptPayPal:function(){return!0},getCardType:function(e){if(e){if(null!=e.match(/^4/))return"VI";if(null!=e.match(/^(34|37)/))return"AE";if(null!=e.match(/^5[1-5]/))return"MC";if(null!=e.match(/^6011/))return"DI";if(null!=e.match(/^(?:2131|1800|35)/))return"JCB";if(null!=e.match(/^(5018|5020|5038|6304|67[0-9]{2})/))return"ME"}return"card"},process:function(e){e=e||{},this._hostedFieldsTokenGenerated?e.onSuccess&&e.onSuccess():this.usingSavedCard()&&$$("#creditcard-saved-accounts input:checked[type=radio]").first().hasAttribute("data-threedsecure-nonce")?this.verify3dSecureVault(e):this.usingSavedCard()?e.onSuccess&&e.onSuccess():1==this.threeDSecure?this.verify3dSecure(e):this.processCard(e)},creditCardLoaded:function(){return!1},paypalLoaded:function(){return!1}};var vZeroPayPalButton=Class.create();vZeroPayPalButton.prototype={initialize:function(e,t,i,n,a){this.clientToken=e,this.storeFrontName=t,this.singleUse=i,this.locale=n,this.futureSingleUse=a,this._paypalOptions={},this._paypalIntegration=!1,this._paypalButton=!1,this._rebuildTimer=!1,this._rebuildCount=0},setPricing:function(e,t){this.amount=parseFloat(e),this.currency=t,null==$("paypal-payment-nonce")||$("paypal-payment-nonce").value||this.rebuildButton()},rebuildButton:function(){if(clearTimeout(this._rebuildTimer),this._paypalIntegration!==!1)try{this._paypalIntegration.teardown(function(){this._paypalIntegration=!1,this.addPayPalButton(this._paypalOptions)}.bind(this))}catch(e){if("Cannot teardown integration more than once"==e.message)this._paypalIntegration=!1,this.addPayPalButton(this._paypalOptions);else{if(this._rebuildCount>=10)return!1;this._rebuildTimer=setTimeout(function(){++this._rebuildCount,this.rebuildButton()}.bind(this),200)}}},addPayPalButton:function(e,t){if(null===$("paypal-container")||null===$("braintree-paypal-button"))return!1;var i=$("braintree-paypal-button").innerHTML;if($("paypal-container").update(""),$("paypal-container").insert(i),!$("paypal-container").select(">button").length)return!1;this._paypalButton=$("paypal-container").select(">button").first(),this._paypalButton.addClassName("braintree-paypal-loading"),this._paypalButton.setAttribute("disabled","disabled"),this._paypalOptions=e,this._paypalIntegration=!1;var n={paymentMethodNonceInputField:"paypal-payment-nonce",displayName:this.storeFrontName,onPaymentMethodReceived:function(t){"function"==typeof e.onSuccess?e.onSuccess(t):(payment.switchMethod("gene_braintree_paypal"),$("paypal-payment-nonce").removeAttribute("disabled"),$("paypal-complete").remove(),window.review&&review.save())},onUnsupported:function(){alert("You need to link your PayPal account with your Braintree account in your Braintree control panel to utilise the PayPal functionality of this extension.")},onReady:function(i){this._paypalIntegration=i,this._attachPayPalButtonEvent(t),"function"==typeof e.onReady&&e.onReady(i)}.bind(this),paypal:{headless:!0}};this.locale&&(n.locale=this.locale),1==this.singleUse?(n.singleUse=!0,n.amount=this.amount,n.currency=this.currency):1==this.futureSingleUse?n.singleUse=!0:n.singleUse=!1,braintree.setup(this.clientToken,"paypal",n)},_attachPayPalButtonEvent:function(e){this._paypalIntegration&&this._paypalButton&&(this._paypalButton.removeClassName("braintree-paypal-loading"),this._paypalButton.removeAttribute("disabled"),Event.stopObserving(this._paypalButton,"click"),Event.observe(this._paypalButton,"click",function(t){Event.stop(t),"object"==typeof e&&"function"==typeof e.validateAll?e.validateAll()&&this._paypalIntegration.paypal.initAuthFlow():this._paypalIntegration.paypal.initAuthFlow()}.bind(this)))},closePayPalWindow:function(e){}};var vZeroIntegration=Class.create();vZeroIntegration.prototype={initialize:function(e,t,i,n,a,s,o){return vZeroIntegration.prototype.loaded?(console.error("Your checkout is including the Braintree resources multiple times, please resolve this."),!1):(vZeroIntegration.prototype.loaded=!0,this.vzero=e||!1,this.vzeroPaypal=t||!1,this.vzero===!1&&this.vzeroPaypal===!1?(console.warn("The vzero and vzeroPaypal objects are not initiated."),!1):(this.paypalMarkUp=i||!1,this.paypalButtonClass=n||!1,this.isOnepage=a||!1,this.config=s||{},this.submitAfterPayment=o||!1,this._methodSwitchTimeout=!1,this._hostedFieldsInit=!1,document.observe("dom:loaded",function(){this.prepareSubmitObserver(),this.preparePaymentMethodSwitchObserver()}.bind(this)),this.hostedFieldsGenerated=!1,this.vzero.close3dSecureMethod(function(){this.vzero._hostedFieldsValidationRunning=!1,this.vzero.tokenize3dSavedCards(function(){this.threeDTokenizationComplete()}.bind(this))}.bind(this)),this.isOnepage&&(this.vzero.observeCardType(),this.observeAjaxRequests(),document.observe("dom:loaded",function(){this.initSavedPayPal(),this.initDefaultMethod(),null!==$("braintree-hosted-submit")&&this.initHostedFields()}.bind(this))),void document.observe("dom:loaded",function(){this.initSavedMethods(),null!==$("braintree-hosted-submit")&&this.initHostedFields()}.bind(this))))},initSavedMethods:function(){$$('#creditcard-saved-accounts input[type="radio"], #paypal-saved-accounts input[type="radio"]').each(function(e){var t="",i="";void 0!==e.up("#creditcard-saved-accounts")?(t="#creditcard-saved-accounts",i="#credit-card-form"):void 0!==e.up("#paypal-saved-accounts")&&(t="#paypal-saved-accounts",i=".paypal-info"),$(e).stopObserving("change").observe("change",function(e){return this.showHideOtherMethod(t,i)}.bind(this))}.bind(this))},showHideOtherMethod:function(e,t){void 0!==$$(e+" input:checked[type=radio]").first()&&"other"==$$(e+" input:checked[type=radio]").first().value?void 0!==$$(t).first()&&($$(t).first().show(),$$(t+" input, "+t+" select").each(function(e){e.removeAttribute("disabled")})):void 0!==$$(e+" input:checked[type=radio]").first()&&void 0!==$$(t).first()&&($$(t).first().hide(),$$(t+" input, "+t+" select").each(function(e){e.setAttribute("disabled","disabled")}))},checkSavedOther:function(){var e="",t="";"gene_braintree_creditcard"==this.getPaymentMethod()?(e="#creditcard-saved-accounts",t="#credit-card-form"):"gene_braintree_paypal"==this.getPaymentMethod()&&(e="#paypal-saved-accounts",t=".paypal-info"),void 0!==$$(e).first()&&this.showHideOtherMethod(e,t)},initHostedFields:function(){this.vzero.hostedFields&&null!==$("braintree-hosted-submit")&&(void 0!==$("braintree-hosted-submit").up("form")?(this._hostedFieldsInit=!0,this.form=$("braintree-hosted-submit").up("form"),this.vzero.initHostedFields(this)):console.error("Hosted Fields cannot be initialized as we're unable to locate the parent form."))},afterHostedFieldsNonceReceived:function(e){return this.resetLoading(),this.vzero._hostedFieldsTokenGenerated=!0,this.hostedFieldsGenerated=!0,this.isOnepage||this.submitAfterPayment?this.submitCheckout():this.submitPayment()},afterHostedFieldsError:function(e){return this.vzero._hostedFieldsTokenGenerated=!1,this.hostedFieldsGenerated=!1,!1},initDefaultMethod:function(){this.shouldAddPayPalButton(!1)&&(this.setLoading(),this.vzero.updateData(function(){this.resetLoading(),this.updatePayPalButton("add")}.bind(this)))},observeAjaxRequests:function(){this.vzero.observeAjaxRequests(function(){this.vzero.updateData(function(){this.isOnepage&&(this.initSavedPayPal(),this.rebuildPayPalButton(),this.checkSavedOther(),this.vzero.hostedFields&&this.initHostedFields()),this.initSavedMethods()}.bind(this))}.bind(this),"undefined"!=typeof this.config.ignoreAjax?this.config.ignoreAjax:!1)},rebuildPayPalButton:function(){null==$("paypal-container")&&this.updatePayPalButton()},initSavedPayPal:function(){void 0!==$$("#paypal-saved-accounts input[type=radio]").first()&&$("paypal-saved-accounts").on("change","input[type=radio]",function(e){this.updatePayPalButton(!1,"gene_braintree_paypal")}.bind(this))},prepareSubmitObserver:function(){return!1},beforeSubmit:function(e){return this._beforeSubmit(e)},_beforeSubmit:function(e){if(this.hostedFieldsGenerated===!1&&this.vzero.hostedFields&&(void 0===$$("#creditcard-saved-accounts input:checked[type=radio]").first()||void 0!==$$("#creditcard-saved-accounts input:checked[type=radio]").first()&&"other"==$$("#creditcard-saved-accounts input:checked[type=radio]").first().value)){var t=$("braintree-hosted-submit").down("button");t.removeAttribute("disabled"),t.click()}else e();this.submitAfterPayment&&$("braintree-submit-after-payment")&&$("braintree-submit-after-payment").remove()},afterSubmit:function(){return!1},submit:function(e,t,i,n){this.shouldInterceptSubmit(e)&&(this.validateAll()?(this.setLoading(),this.beforeSubmit(function(){void 0!=$$('[data-genebraintree-name="number"]').first()&&this.vzero.updateCardType($$('[data-genebraintree-name="number"]').first().value),this.vzero.updateData(function(){this.updateBilling(),this.vzero.process({onSuccess:function(){if(this.enableDeviceData(),this.disableCreditCardForm(),this.resetLoading(),this.afterSubmit(),this.enableDisableNonce(),this.vzero._hostedFieldsTokenGenerated=!1,this.hostedFieldsGenerated=!1,"function"==typeof t)var e=t();return this.setLoading(),this.enableCreditCardForm(),e}.bind(this),onFailure:function(){return this.vzero._hostedFieldsTokenGenerated=!1,this.hostedFieldsGenerated=!1,this.resetLoading(),this.afterSubmit(),"function"==typeof i?i():void 0}.bind(this)})}.bind(this),this.getUpdateDataParams())}.bind(this))):(this.vzero._hostedFieldsTokenGenerated=!1,this.hostedFieldsGenerated=!1,this.resetLoading(),"function"==typeof n&&n()))},submitCheckout:function(){window.review&&review.save()},submitPayment:function(){payment.save&&payment.save()},enableDisableNonce:function(){"gene_braintree_creditcard"==this.getPaymentMethod()?(null!==$("creditcard-payment-nonce")&&$("creditcard-payment-nonce").removeAttribute("disabled"),null!==$("paypal-payment-nonce")&&$("paypal-payment-nonce").setAttribute("disabled","disabled")):"gene_braintree_paypal"==this.getPaymentMethod()&&(null!==$("creditcard-payment-nonce")&&$("creditcard-payment-nonce").setAttribute("disabled","disabled"),null!==$("paypal-payment-nonce")&&$("paypal-payment-nonce").removeAttribute("disabled"))},preparePaymentMethodSwitchObserver:function(){return this.defaultPaymentMethodSwitch()},defaultPaymentMethodSwitch:function(){var e=this,t=Payment.prototype.switchMethod;Payment.prototype.switchMethod=function(i){return e.paymentMethodSwitch(i),t.apply(this,arguments)}},paymentMethodSwitch:function(e){clearTimeout(this._methodSwitchTimeout),this._methodSwitchTimeout=setTimeout(function(){this.shouldAddPayPalButton(e)?this.updatePayPalButton("add",e):this.updatePayPalButton("remove",e),"gene_braintree_creditcard"==(e?e:this.getPaymentMethod())&&this.initHostedFields(),this.checkSavedOther()}.bind(this),50)},completePayPal:function(e){return this.enableDisableNonce(),this.enableDeviceData(),e.nonce&&null!==$("paypal-payment-nonce")?($("paypal-payment-nonce").value=e.nonce,$("paypal-payment-nonce").setAttribute("value",e.nonce)):console.warn("Unable to update PayPal nonce, please verify that the nonce input field has the ID: paypal-payment-nonce"),this.afterPayPalComplete(),!1},afterPayPalComplete:function(){return this.resetLoading(),this.submitCheckout()},updatePayPalButton:function(e,t){if(this.paypalMarkUp===!1)return!1;if("refresh"==e)return this.updatePayPalButton("remove"),this.updatePayPalButton("add"),!0;if(this.shouldAddPayPalButton(t)&&"remove"!=e||"add"==e)if(void 0!==$$(this.paypalButtonClass).first()){if(void 0!==$$("#paypal-complete").first()&&$$("#paypal-complete").first().visible())return!0;$$(this.paypalButtonClass).first().hide(),$$(this.paypalButtonClass).first().insert({after:this.paypalMarkUp});var i={onSuccess:this.completePayPal.bind(this),onReady:this.paypalOnReady.bind(this)};this.vzeroPaypal.addPayPalButton(i,this)}else console.warn("We're unable to find the element "+this.paypalButtonClass+". Please check your integration.");else void 0!==$$(this.paypalButtonClass).first()&&$$(this.paypalButtonClass).first().show(),void 0!==$$("#paypal-complete").first()&&$("paypal-complete").remove()},paypalOnReady:function(e){return!0},setLoading:function(){checkout.setLoadWaiting("payment")},resetLoading:function(){checkout.setLoadWaiting(!1)},enableDeviceData:function(){null!==$("device_data")&&$("device_data").removeAttribute("disabled")},disableCreditCardForm:function(){$$("#credit-card-form input, #credit-card-form select").each(function(e){"creditcard-payment-nonce"!=e.id&&"gene_braintree_creditcard_store_in_vault"!=e.id&&e.setAttribute("disabled","disabled")})},enableCreditCardForm:function(){$$("#credit-card-form input, #credit-card-form select").each(function(e){e.removeAttribute("disabled")})},updateBilling:function(){(null!==$("billing-address-select")&&""==$("billing-address-select").value||null===$("billing-address-select"))&&(null!==$("billing:firstname")&&null!==$("billing:lastname")&&this.vzero.setBillingName($("billing:firstname").value+" "+$("billing:lastname").value),null!==$("billing:postcode")&&this.vzero.setBillingPostcode($("billing:postcode").value))},getUpdateDataParams:function(){var e={};return null!==$("billing-address-select")&&""!=$("billing-address-select").value&&(e.addressId=$("billing-address-select").value),e},getPaymentMethod:function(){return payment.currentMethod},shouldInterceptSubmit:function(e){switch(e){case"creditcard":return"gene_braintree_creditcard"==this.getPaymentMethod()&&this.vzero.shouldInterceptCreditCard();break;case"paypal":return"gene_braintree_paypal"==this.getPaymentMethod()&&this.vzero.shouldInterceptCreditCard()}return!1},shouldAddPayPalButton:function(e){return"gene_braintree_paypal"==(e?e:this.getPaymentMethod())&&null===$("paypal-saved-accounts")||"gene_braintree_paypal"==(e?e:this.getPaymentMethod())&&void 0!==$$("#paypal-saved-accounts input:checked[type=radio]").first()&&"other"==$$("#paypal-saved-accounts input:checked[type=radio]").first().value},threeDTokenizationComplete:function(){this.resetLoading()},validateAll:function(){return!0}},function(){for(var e,t=function(){},i=["assert","clear","count","debug","dir","dirxml","error","exception","group","groupCollapsed","groupEnd","info","log","markTimeline","profile","profileEnd","table","time","timeEnd","timeStamp","trace","warn"],n=i.length,a=window.console=window.console||{};n--;)e=i[n],a[e]||(a[e]=t)}();