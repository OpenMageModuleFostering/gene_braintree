<?php

/**
 * Class Gene_Braintree_Helper_Data
 *
 * @author Dave Macaulay <dave@gene.co.uk>
 */
class Gene_Braintree_Helper_Data extends Mage_Core_Helper_Abstract
{
    /**
     * Return all of the possible statuses as an array
     *
     * @return array
     */
    public function getStatusesAsArray()
    {
        return array(
            Braintree_Transaction::AUTHORIZATION_EXPIRED => $this->__('Authorization Expired'),
            Braintree_Transaction::AUTHORIZING => $this->__('Authorizing'),
            Braintree_Transaction::AUTHORIZED => $this->__('Authorized'),
            Braintree_Transaction::GATEWAY_REJECTED => $this->__('Gateway Rejected'),
            Braintree_Transaction::FAILED => $this->__('Failed'),
            Braintree_Transaction::PROCESSOR_DECLINED => $this->__('Processor Declined'),
            Braintree_Transaction::SETTLED => $this->__('Settled'),
            Braintree_Transaction::SETTLING => $this->__('Settling'),
            Braintree_Transaction::SUBMITTED_FOR_SETTLEMENT => $this->__('Submitted For Settlement'),
            Braintree_Transaction::VOIDED => $this->__('Voided'),
            Braintree_Transaction::UNRECOGNIZED => $this->__('Unrecognized'),
            Braintree_Transaction::SETTLEMENT_DECLINED => $this->__('Settlement Declined'),
            Braintree_Transaction::SETTLEMENT_PENDING => $this->__('Settlement Pending')
        );
    }

    /**
     * Force the prices to two decimal places
     * Magento sometimes doesn't return certain totals in the correct format, yet Braintree requires them to always
     * be in two decimal places, thus the need for this function
     *
     * @param $price
     *
     * @return string
     */
    public function formatPrice($price)
    {
        return number_format($price, 2, '.', '');
    }
}