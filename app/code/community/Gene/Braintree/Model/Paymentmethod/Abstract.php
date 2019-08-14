<?php

/**
 * Class Gene_Braintree_Model_Paymentmethod_Abstract
 *
 * @author Dave Macaulay <dave@gene.co.uk>
 */
abstract class Gene_Braintree_Model_Paymentmethod_Abstract extends Mage_Payment_Model_Method_Abstract
{
    /**
     * The decision responses from braintree
     */
    const ADVANCED_FRAUD_REVIEW = 'Review';
    const ADVANCED_FRAUD_DECLINE = 'Decline';

    /**
     * Verify that the module has been setup
     *
     * @param null $quote
     *
     * @return bool
     */
    public function isAvailable($quote = null)
    {
        // Check Magento's internal methods allow us to run
        if(parent::isAvailable($quote)) {

            // Validate the configuration is okay
            return $this->_getWrapper()->validateCredentialsOnce();

        } else {

            // Otherwise it's a no
            return false;
        }
    }

    /**
     * Return the helper
     *
     * @return Mage_Payment_Helper_Data
     */
    protected function _getHelper()
    {
        return Mage::helper('gene_braintree');
    }

    /**
     * Return the wrapper class
     *
     * @return Gene_Braintree_Model_Wrapper_Braintree
     */
    protected function _getWrapper()
    {
        return Mage::getSingleton('gene_braintree/wrapper_braintree');
    }

    /**
     * Return configuration values
     *
     * @param $key
     *
     * @return mixed
     */
    protected function _getConfig($key)
    {
        return Mage::getStoreConfig('payment/'.$this->_code.'/'.$key);
    }

    /**
     * Handle any risk decision returned from Braintree
     *
     * @param                $result
     * @param \Varien_Object $payment
     *
     * @return $this
     */
    protected function handleFraud($result, Varien_Object $payment)
    {
        // Verify we have risk data
        if(isset($result->transaction) && isset($result->transaction->riskData) && isset($result->transaction->riskData->decision)) {

            // If the decision is to review the payment mark the payment as such
            if($result->transaction->riskData->decision == self::ADVANCED_FRAUD_REVIEW || $result->transaction->riskData->decision == self::ADVANCED_FRAUD_DECLINE) {

                // Mark the payment as pending
                $payment->setIsTransactionPending(true);

                // If the payment got marked as fraud/decline, we mark it as fraud
                if($result->transaction->riskData->decision == self::ADVANCED_FRAUD_DECLINE) {
                    $payment->setIsFraudDetected(true);
                }
            }
        }

        return $this;
    }

    /**
     * Refund specified amount for payment
     *
     * @param \Varien_Object $payment
     * @param float          $amount
     *
     * @return $this
     * @throws \Mage_Core_Exception
     */
    public function refund(Varien_Object $payment, $amount)
    {
        try {
            // Attempt to load the invoice
            /* @var $invoice Mage_Sales_Model_Order_Invoice */
            $invoice = $payment->getCreditmemo()->getInvoice();
            if(!$invoice) {
                Mage::throwException('Unable to load invoice from credit memo.');
            }

            // Init the environment
            $this->_getWrapper()->init($payment->getOrder()->getStoreId());

            // Convert the refund amount
            $refundAmount = $this->_getWrapper()->getCaptureAmount($payment->getOrder(), $amount);

            // Retrieve the transaction ID
            $transactionId = $this->_getWrapper()->getCleanTransactionId($invoice->getTransactionId());

            // Load the transaction from Braintree
            $transaction = Braintree_Transaction::find($transactionId);

            // If the transaction hasn't yet settled we can't do partial refunds
            if ($transaction->status === Braintree_Transaction::SUBMITTED_FOR_SETTLEMENT) {

                // If we're doing a partial refund and it's not settled it's a no go
                if ($transaction->amount != $refundAmount) {
                    Mage::throwException($this->_getHelper()->__('This transaction has not yet settled, please wait until the transaction has settled to process a partial refund.'));
                }
            }

            // Swap between refund and void
            $result = ($transaction->status === Braintree_Transaction::SETTLED || $transaction->status == Braintree_Transaction::SETTLING || (isset($transaction->paypal) && isset($transaction->paypal['paymentId']) && !empty($transaction->paypal['paymentId'])))
                ? Braintree_Transaction::refund($transactionId, $refundAmount)
                : Braintree_Transaction::void($transactionId);

            // If it's a success close the transaction
            if ($result->success) {

                // Pass over the transaction ID
                $payment->getCreditmemo()->setRefundTransactionId($result->transaction->id);

                // Only close the transaction once the
                if($transaction->amount == $refundAmount) {

                    $payment->setIsTransactionClosed(1);

                    // Mark the invoice as canceled if the invoice was completely refunded
                    $invoice->setState(Mage_Sales_Model_Order_Invoice::STATE_CANCELED);
                }

            } else {
                if($result->errors->deepSize() > 0) {
                    Mage::throwException($this->_getWrapper()->parseErrors($result->errors->deepAll()));
                } else {
                    Mage::throwException('An unknown error has occurred whilst trying to process the transaction');
                }
            }

        } catch (Exception $e) {
            Mage::throwException($this->_getHelper()->__('An error occurred whilst trying to process the refund: ') . $e->getMessage());
        }

        return $this;
    }

    /**
     * Set transaction ID into creditmemo for informational purposes
     *
     * @param Mage_Sales_Model_Order_Creditmemo $creditmemo
     * @param Mage_Sales_Model_Order_Payment $payment
     * @return Mage_Payment_Model_Method_Abstract
     */
    public function processCreditmemo($creditmemo, $payment)
    {
        // Copy the refund transaction ID from the credit memo
        $creditmemo->setTransactionId($creditmemo->getRefundTransactionId());
        return $this;
    }
}