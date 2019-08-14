<?php

/**
 * Class Braintree_Payments_Model_Source_Environment
 *
 * @author Dave Macaulay <dave@gene.co.uk>
 */
class Gene_Braintree_Model_Source_Environment
{
    /**
     * Display both sandbox and production values
     *
     * @return array
     */
    public function toOptionArray()
    {
        return array(
            array(
                'value' => 'sandbox',
                'label' => Mage::helper('gene_braintree')->__('Sandbox'),
            ),
            array(
                'value' => 'production',
                'label' => Mage::helper('gene_braintree')->__('Production')
            )
        );
    }
}
