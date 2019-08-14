<?php

/**
 * Class Gene_Braintree_Model_Source_Creditcard_FormIntegration
 *
 * @author Dave Macaulay <dave@gene.co.uk>
 */
class Gene_Braintree_Model_Source_Creditcard_FormIntegration
{

    const INTEGRATION_ACTION_XML_PATH = 'payment/gene_braintree_creditcard/form_integration';

    const INTEGRATION_DEFAULT = 'default';
    const INTEGRATION_HOSTED = 'hosted';

    /**
     * Possible actions on order place
     *
     * @return array
     */
    public function toOptionArray()
    {
        return array(
            array(
                'value' => self::INTEGRATION_DEFAULT,
                'label' => Mage::helper('gene_braintree')->__('Default')
            ),
            array(
                'value' => self::INTEGRATION_HOSTED,
                'label' => Mage::helper('gene_braintree')->__('Hosted Fields')
            ),
        );
    }
}
