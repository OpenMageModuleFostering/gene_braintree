<?php

/**
 * Class Gene_Braintree_Block_Creditcard_Info
 *
 * @author Dave Macaulay <dave@gene.co.uk>
 */
class Gene_Braintree_Block_Creditcard_Info extends Mage_Payment_Block_Info
{

    /**
     * Use a custom template
     */
    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('gene/braintree/creditcard/info.phtml');
    }

    /**
     * Return the currently viewed order
     *
     * @return \Mage_Sales_Model_Order
     */
    protected function getOrder()
    {
        if(Mage::registry('current_order')) {
            return Mage::registry('current_order');
        } else if(Mage::registry('current_invoice')) {
            return Mage::registry('current_invoice')->getOrder();
        }
    }

    /**
     * Prepare information specific to current payment method
     *
     * @param null | array $transport
     *
     * @return Varien_Object
     */
    protected function _prepareSpecificInformation($transport = null)
    {
        // Get the original transport data
        $transport = parent::_prepareSpecificInformation($transport);

        // Build up the data we wish to pass through
        $data = array(
            $this->__('Card Number (Last 4)')     => $this->getInfo()->getCcLast4(),
            $this->__('Credit Card Type')         => $this->getInfo()->getCcType()
        );

        // Check we're in the admin area
        if(Mage::app()->getStore()->isAdmin()) {

            // Transaction ID won't matter for customers
            $data[$this->__('Braintree Transaction ID')] = $this->getInfo()->getLastTransId();

            // Add in the current status
            try {
                $transaction = Mage::getModel('gene_braintree/wrapper_braintree')->init($this->getOrder()->getStoreId())->findTransaction($this->getInfo()->getLastTransId());
                if ($transaction) {
                    $data[$this->__('Status')] = $this->convertStatus($transaction->status);
                } else {
                    $data[$this->__('Status')] = $this->__('<span style="color:red;"><strong>Warning:</strong> Cannot load payment in Braintree.</span>');
                }
            } catch (Exception $e) {
                $data[$this->__('Status')] = $this->__('<span style="color:red;"><strong>Warning:</strong> Unable to connect to Braintree to load transaction.</span>');
            }

            // What additional information should we show
            $additionalInfoHeadings = array(
                'avsErrorResponseCode'         => $this->__('AVS Error Response Code'),
                'avsPostalCodeResponseCode'    => $this->__('AVS Postal Response Code'),
                'avsStreetAddressResponseCode' => $this->__('AVS Street Address Response Code'),
                'cvvResponseCode'              => $this->__('CVV Response Code'),
                'gatewayRejectionReason'       => $this->__('Gateway Rejection Reason'),
                'processorAuthorizationCode'   => $this->__('Processor Autorization Code'),
                'processorResponseCode'        => $this->__('Processor Response Code'),
                'processorResponseText'        => $this->__('Processor Response Text'),
                'threeDSecure'                 => $this->__('3D Secure')
            );

            // Add any of the data that we've recorded into the view
            foreach($additionalInfoHeadings as $key => $heading) {
                if($infoData = $this->getInfo()->getAdditionalInformation($key)) {
                    $data[$heading] = $infoData;
                }
            }

        }

        // Add the data to the class variable
        $transport->setData(array_merge($data, $transport->getData()));
        $this->_paymentSpecificInformation = $transport->getData();

        // And return it
        return $transport;
    }

    /**
     * Make the status nicer to read
     *
     * @param $status
     *
     * @return string
     */
    private function convertStatus($status)
    {
        switch($status){
            case 'authorized':
                return '<span style="color: #40A500;"> ' . Mage::helper('gene_braintree')->__('Authorized') . '</span>';
            break;
            case 'submitted_for_settlement':
                return '<span style="color: #40A500;">' . Mage::helper('gene_braintree')->__('Submitted For Settlement') . '</span>';
            break;
            case 'settled':
                return '<span style="color: #40A500;">' . Mage::helper('gene_braintree')->__('Settled') . '</span>';
                break;
            case 'voided':
                return '<span style="color: #ed4737;">' . Mage::helper('gene_braintree')->__('Voided') . '</span>';
            break;
        }

        return ucwords($status);
    }

}