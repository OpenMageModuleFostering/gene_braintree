<?php

/**
 * Class Gene_Braintree_Block_Adminhtml_System_Config_Braintree_Currency
 *
 * @author Dave Macaulay <dave@gene.co.uk>
 */
class Gene_Braintree_Block_Adminhtml_System_Config_Braintree_Currency
    extends Mage_Adminhtml_Block_System_Config_Form_Field
{
    /**
     * Enter description here...
     *
     * @param Varien_Data_Form_Element_Abstract $element
     * @return string
     */
    protected function _getElementHtml(Varien_Data_Form_Element_Abstract $element)
    {
        return $this->getCurrencyTableHtml($element);
    }

    /**
     * Inform the user there version will not work
     * @return string
     */
    private function getCurrencyTableHtml(Varien_Data_Form_Element_Abstract $element)
    {
        // Store ID = scope ID
        $storeId = Mage::getSingleton('adminhtml/config_data')->getScopeId();

        // Retrieve the currencies
        $currencies = Mage::app()->getStore($storeId)->getAvailableCurrencyCodes();

        // Retrieve the values
        $values = $element->getValue();

        // Build our response
        $response = '<input type="hidden" id="payment_gene_braintree_multi_currency_mapping" />
        <table width="100%" cellspacing="6" cellpadding="4">
            <tr>
                <th width="35%">' . $this->__('Currency Code') . '</th>
                <th width="65%">' . $this->__('Merchant Account ID') . '</th>
            </tr>';

        // Loop through each currency and add a value
        foreach($currencies as $currency) {
            $response .= '<tr>
                <td> ' . $currency . '</td>
                <td><input class="input-text" type="text" name=" ' . $element->getName() . '[' . $currency . ']" style="width: 100%;" value="'. (isset($values->$currency) ? $values->$currency : '') . '" /></td>
            </tr>';
        }

        $response .= '</table>';

        return $response;
    }
}
