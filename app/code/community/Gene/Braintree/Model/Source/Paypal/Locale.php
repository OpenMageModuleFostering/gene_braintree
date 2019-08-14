<?php

/**
 * Class Gene_Braintree_Model_Source_Paypal_Locale
 * @author Dave Macaulay <dave@gene.co.uk>
 */
class Gene_Braintree_Model_Source_Paypal_Locale
{

    /**
     * Return the array of options
     * @return array
     */
    public function getArray()
    {
        return array(
            'en_au' => Mage::helper('gene_braintree')->__('Australia'),
            'en_ca' => Mage::helper('gene_braintree')->__('Canada'),
            'fr_fr' => Mage::helper('gene_braintree')->__('France'),
            'de_de' => Mage::helper('gene_braintree')->__('Germany'),
            'en_gb' => Mage::helper('gene_braintree')->__('Great Britain & Ireland'),
            'zh_hk' => Mage::helper('gene_braintree')->__('Hong Kong'),
            'it_it' => Mage::helper('gene_braintree')->__('Italy'),
            'es_es' => Mage::helper('gene_braintree')->__('Spain'),
            'en_us' => Mage::helper('gene_braintree')->__('United States')
        );
    }
    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray()
    {
        $response = array();
        foreach($this->getArray() as $key => $value) {
            $response[] = array(
                'value' => $key,
                'label' => $value
            );
        }
        return $response;
    }

    /**
     * Get options in "key-value" format
     *
     * @return array
     */
    public function toArray()
    {
        return $this->getArray();
    }

}
