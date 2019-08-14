<?php

/**
 * Class Gene_Braintree_CheckoutController
 *
 * @author Dave Macaulay <dave@gene.co.uk>
 */
class Gene_Braintree_CheckoutController extends Mage_Core_Controller_Front_Action
{
    /**
     * The front-end is requesting the grand total of the quote
     */
    public function quoteTotalAction()
    {
        // Grab the quote
        $quote = Mage::getSingleton('checkout/type_onepage')->getQuote();

        // Build up our JSON response
        $jsonResponse = array(
            'billingName' => $quote->getBillingAddress()->getName(),
            'billingPostcode' => $quote->getBillingAddress()->getPostcode(),
            'grandTotal' => number_format($quote->getGrandTotal(), 2),
            'currencyCode' => $quote->getQuoteCurrencyCode()
        );

        // Set the response
        $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($jsonResponse));
        return false;
    }

    /**
     * Tokenize the card tokens via Ajax
     */
    public function tokenizeCardAction()
    {
        // Are tokens set in the request
        if($tokens = $this->getRequest()->getParam('tokens')) {

            // Build up our response
            $jsonResponse = array(
                'success' => true,
                'tokens' => array()
            );

            // Json decode the tokens
            $tokens = Mage::helper('core')->jsonDecode($tokens);
            if(is_array($tokens)) {

                // Loop through each token and tokenize it again
                foreach($tokens as $token) {
                    $jsonResponse['tokens'][$token] = Mage::getSingleton('gene_braintree/wrapper_braintree')->getThreeDSecureVaultNonce($token);
                }

                // Set the response
                $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($jsonResponse));
                return false;
            }
        }
    }

}