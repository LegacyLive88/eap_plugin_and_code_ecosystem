<?php

namespace AmeliaBooking\Application\Services\Invoice;

use AmeliaBooking\Infrastructure\WP\Translations\BackendStrings;

/**
 * Class XMLInvoiceService
 *
 * @package AmeliaBooking\Application\Services\Invoice
 */
class XMLInvoiceService
{
    /** @var \SimpleXMLElement */
    private $xml;

    /** @var string */
    private $cac;

    /** @var string */
    private $cbc;

    /** @var string */
    private $currency;

    /** @var array */
    private $invoiceData;

    /** @var array */
    private $taxTypes = [];

    /**
     * Adds header data to the XML invoice.
     *
     */
    public function addHeaderData()
    {
        $this->xml->addChild('UBLVersionID', '2.1', $this->cbc);
        $this->xml->addChild('CustomizationID', 'urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0', $this->cbc);
        $this->xml->addChild('ProfileID', 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0', $this->cbc);
        $this->xml->addChild('ID', htmlspecialchars($this->invoiceData['invoice_number']), $this->cbc);
        $this->xml->addChild('IssueDate', date('Y-m-d', strtotime($this->invoiceData['invoice_issued'])), $this->cbc);


        $this->xml->addChild('InvoiceTypeCode', '380', $this->cbc); // 380 = Commercial invoice
        $this->xml->addChild('DocumentCurrencyCode', $this->currency, $this->cbc);
        $this->xml->addChild('BuyerReference', $this->invoiceData['customer_full_name'], $this->cbc);
    }


    /**
     * Adds company data to the XML invoice.
     *
     */
    public function addCompanyData()
    {
        // Supplier (company)
        $supplierParty = $this->xml->addChild('AccountingSupplierParty', null, $this->cac);
        $supplier = $supplierParty->addChild('Party', null, $this->cac);
        $supplierName = $supplier->addChild('PartyName', null, $this->cac);
        $supplierName->addChild('Name', htmlspecialchars($this->invoiceData['company_name']), $this->cbc);

        if (!empty($this->invoiceData['company_address'])) {
            $addressComponents = $this->invoiceData['company_address_components'];
            $address = !empty($addressComponents) ? $addressComponents : $this->parseAddress($this->invoiceData['company_address']);

            if (!empty($this->invoiceData['company_country'])) {
                $address['CountryCode'] = strtoupper($this->invoiceData['company_country']);
            }
            $this->addAddressFields($address, $supplier);
        }

        if (!empty($this->invoiceData['company_vat_number'])) {
            $supplierTaxScheme = $supplier->addChild('PartyTaxScheme', null, $this->cac);
            $supplierTaxScheme->addChild('CompanyID', htmlspecialchars($this->invoiceData['company_vat_number']), $this->cbc);
            $supplierTaxSchemeChild = $supplierTaxScheme->addChild('TaxScheme', null, $this->cac);
            $supplierTaxSchemeChild->addChild('ID', 'VAT', $this->cbc);
        }

        $supplierLegalEntity = $supplier->addChild('PartyLegalEntity', null, $this->cac);
        $supplierLegalEntity->addChild('RegistrationName', htmlspecialchars($this->invoiceData['company_name']), $this->cbc);

        if (!empty($this->invoiceData['company_email']) || !empty($this->invoiceData['company_phone'])) {
            $contact = $supplier->addChild('Contact', null, $this->cac);
            if (!empty($this->invoiceData['company_phone'])) {
                $contact->addChild('Telephone', htmlspecialchars($this->invoiceData['company_phone']), $this->cbc);
            }
            if (!empty($this->invoiceData['company_email'])) {
                $contact->addChild('ElectronicMail', htmlspecialchars($this->invoiceData['company_email']), $this->cbc);
            }
        }
    }

    /**
     * Adds customer data to the XML invoice.
     *
     */
    public function addCustomerData()
    {
        $customerParty = $this->xml->addChild('AccountingCustomerParty', null, $this->cac);
        $customer = $customerParty->addChild('Party', null, $this->cac);
        $customerName = $customer->addChild('PartyName', null, $this->cac);
        $customerName->addChild('Name', htmlspecialchars($this->invoiceData['customer_full_name']), $this->cbc);

        $addressCf = array_filter(
            $this->invoiceData['customer_custom_fields'],
            function ($value) {
                return $value['type'] === 'address';
            }
        );

        $address = sizeof($addressCf) > 0 ?
            (array_values($addressCf)[0]['components'] ?? $this->parseAddress(array_values($addressCf)[0]['value'])) : null;

        $this->addAddressFields($address, $customer);

        $partyLegalEntity = $customer->addChild('PartyLegalEntity', null, $this->cac);
        $partyLegalEntity->addChild('RegistrationName', htmlspecialchars($this->invoiceData['customer_full_name']), $this->cbc);

        if (!empty($this->invoiceData['customer_email']) || !empty($this->invoiceData['customer_phone'])) {
            $contact = $customer->addChild('Contact', null, $this->cac);
            if (!empty($this->invoiceData['customer_phone'])) {
                $contact->addChild('Telephone', htmlspecialchars($this->invoiceData['customer_phone']), $this->cbc);
            }
            if (!empty($this->invoiceData['customer_email'])) {
                $contact->addChild('ElectronicMail', htmlspecialchars($this->invoiceData['customer_email']), $this->cbc);
            }
        }
    }


    /**
     * Adds address fields to the XML invoice.
     *
     * @param array $address
     * @param \SimpleXMLElement $entity
     */
    private function addAddressFields($address, $entity)
    {
        if (!empty($address)) {
            $customerAddress = $entity->addChild('PostalAddress', null, $this->cac);

            $addressFields = ['StreetName', 'AdditionalStreetName', 'CityName', 'PostalZone', 'CountrySubentity'];

            foreach ($addressFields as $tag) {
                if (!empty($address[$tag])) {
                    $customerAddress->addChild($tag, htmlspecialchars($address[$tag]), $this->cbc);
                }
            }

            if (!empty($address['Premise'])) {
                $customerAddress
                    ->addChild('AddressLine', null, $this->cac)
                    ->addChild('Line', htmlspecialchars($address['Premise']), $this->cbc);
            }

            if (!empty($address['CountryCode'])) {
                $customerAddress
                    ->addChild('Country', null, $this->cac)
                    ->addChild('IdentificationCode', htmlspecialchars(strtoupper($address['CountryCode'])), $this->cbc);
            }
        }
    }

    /**
     * Calculate tax types from invoice items.
     *
     * @param array $data
     * @return array
     */
    private function calculateTaxTypes($data)
    {
        $taxTypes = [];
        foreach ($data['items'] as $item) {
            $index = $item['invoice_tax_rate'] ?? '0';
            if ($item['invoice_tax_type'] === 'fixed') {
                $index = '0';
            }
            $itemBaseAmount = $item['invoice_unit_price'] * $item['invoice_qty'] -
                ($data['includedTax'] ? $item['invoice_tax'] : 0);
            $itemAmount = $itemBaseAmount;
            $itemTax = $item['invoice_tax'];
            $discount = 0;
            if (isset($item['invoice_discount'])) {
                $discount = $item['service_discount'] ?? $item['invoice_discount'];
                $itemAmount = $itemBaseAmount - $discount;
                $itemAmount = max($itemAmount, 0);
                if ($item['invoice_tax_type'] === 'percentage') {
                    $taxRateAmount = floatval(str_replace('%', '', $index));
                    $itemTax = round($itemAmount / 100 *
                        $taxRateAmount, 4);
                }
            }
            if (!empty($taxTypes[$index])) {
                $taxTypes[$index]['taxableAmount'] += $itemAmount + ($item['invoice_tax_type'] === 'fixed' ? $itemTax : 0);
                $taxTypes[$index]['invoice_tax'] += $itemTax;
                $taxTypes[$index]['amount'] += $itemBaseAmount;
                $taxTypes[$index]['discount'] += $discount;
            } else {
                $taxTypes[$index] = [
                    'taxableAmount' => $itemAmount + ($item['invoice_tax_type'] === 'fixed' ? $itemTax : 0),
                    'invoice_tax' => $itemTax,
                    'invoice_tax_type' => $item['invoice_tax_type'],
                    'invoice_tax_rate' => $index,
                    'amount' => $itemBaseAmount,
                    'discount' => $discount
                ];
            }
        }

        return $taxTypes;
    }


    /**
     * Adds allowance data to the XML invoice.
     *
     */
    private function addAllowanceData()
    {
        foreach ($this->taxTypes as $taxType) {
            $allowanceCharge = $this->xml->addChild('AllowanceCharge', null, $this->cac);
            $allowanceCharge->addChild('ChargeIndicator', 'false', $this->cbc);
            $allowanceCharge->addChild('AllowanceChargeReasonCode', '95', $this->cbc);
            $allowanceCharge->addChild(
                'Amount',
                number_format($taxType['discount'], 2, '.', ''),
                $this->cbc
            )->addAttribute('currencyID', $this->currency);
            $allowanceCharge->addChild(
                'BaseAmount',
                number_format($taxType['amount'], 2, '.', ''),
                $this->cbc
            )->addAttribute('currencyID', $this->currency);

            $this->addTaxCategoryNode(
                $allowanceCharge,
                $taxType,
            );
        }
    }

    /**
     * Adds charge data to the XML invoice.
     *
     * @param float $amount
     */
    private function addChargeData($amount)
    {
        $allowanceCharge = $this->xml->addChild('AllowanceCharge', null, $this->cac);
        $allowanceCharge->addChild('ChargeIndicator', 'true', $this->cbc);
        $allowanceCharge->addChild('AllowanceChargeReason', 'Fixed tax', $this->cbc);
        $allowanceCharge->addChild(
            'Amount',
            number_format($amount, 2, '.', ''),
            $this->cbc
        )->addAttribute('currencyID', $this->currency);

        $this->addTaxCategoryNode(
            $allowanceCharge,
            ['invoice_tax_type' => 'fixed'],
        );
    }

    /**
     * Adds tax total data to the XML invoice.
     *
     * @param float $totalTax
     */
    public function addTaxTotalData($totalTax)
    {
        // Tax total (summary)
        $taxTotal = $this->xml->addChild('TaxTotal', null, $this->cac);

        $taxTotal->addChild(
            'TaxAmount',
            number_format($totalTax, 2, '.', ''),
            $this->cbc
        )->addAttribute('currencyID', $this->currency);

        foreach ($this->taxTypes as $key => $tax) {
            $taxSubtotal = $taxTotal->addChild('TaxSubtotal', null, $this->cac);
            $taxSubtotal->addChild(
                'TaxableAmount',
                number_format($tax['taxableAmount'], 2, '.', ''),
                $this->cbc
            )->addAttribute('currencyID', $this->currency);
            $taxSubtotal->addChild(
                'TaxAmount',
                number_format($key ? $tax['invoice_tax'] : 0, 2, '.', ''),
                $this->cbc
            )->addAttribute('currencyID', $this->currency);


            $this->addTaxCategoryNode($taxSubtotal, $tax, false, true);
        }
    }

    /**
     * Adds legal monetary total data to the XML invoice.
     *
     * @param array $data
     */
    private function addLegalMonetaryTotalData($data)
    {
        // Legal monetary total
        $legalMonetaryTotal = $this->xml->addChild('LegalMonetaryTotal', null, $this->cac);
        $legalMonetaryTotal->addChild(
            'LineExtensionAmount',
            number_format($data['lineExtensionTotalAmount'], 2, '.', ''),
            $this->cbc
        )->addAttribute('currencyID', $this->currency);

        $taxExclusiveAmount = $data['lineExtensionTotalAmount'] - $data['discount'] + $data['totalFixedTax'];
        $legalMonetaryTotal->addChild(
            'TaxExclusiveAmount',
            number_format($taxExclusiveAmount, 2, '.', ''),
            $this->cbc
        )->addAttribute('currencyID', $this->currency);

        $legalMonetaryTotal->addChild(
            'TaxInclusiveAmount',
            number_format(
                round($taxExclusiveAmount, 2) + ($taxExclusiveAmount ? round($data['totalTax'], 2) : 0),
                2,
                '.',
                ''
            ),
            $this->cbc
        )->addAttribute('currencyID', $this->currency);

        if ($data['discount'] > 0) {
            $legalMonetaryTotal->addChild(
                'AllowanceTotalAmount',
                number_format(
                    $data['discount'],
                    2,
                    '.',
                    ''
                ),
                $this->cbc
            )->addAttribute('currencyID', $this->currency);
        }

        if ($data['totalFixedTax']) {
            $legalMonetaryTotal->addChild(
                'ChargeTotalAmount',
                number_format(
                    $data['totalFixedTax'],
                    2,
                    '.',
                    ''
                ),
                $this->cbc
            )->addAttribute('currencyID', $this->currency);
        }

        // Paid amount (if any)
        if ($data['paid'] > 0) {
            $prepaidAmount = $legalMonetaryTotal->addChild(
                'PrepaidAmount',
                number_format($data['paid'], 2, '.', ''),
                $this->cbc
            );
            $prepaidAmount->addAttribute('currencyID', $this->currency);
        }

        if ($data['subtotal'] !== $data['lineExtensionTotalAmount']) {
            $legalMonetaryTotal->addChild(
                'PayableRoundingAmount',
                number_format($data['subtotal'] - $data['lineExtensionTotalAmount'], 2, '.', ''),
                $this->cbc
            )->addAttribute('currencyID', $this->currency);
        }

        $leftToPay = $legalMonetaryTotal->addChild(
            'PayableAmount',
            number_format($data['leftToPay'], 2, '.', ''),
            $this->cbc
        );
        $leftToPay->addAttribute('currencyID', $this->currency);
    }

    /**
     * Adds line items data to the XML invoice.
     *
     * @param array $items
     * @param bool $includedTax
     */
    private function addLineItemsData($items, $includedTax)
    {
        foreach ($items as $idx => $item) {
            $invoiceLine = $this->xml->addChild('InvoiceLine', null, $this->cac);
            $invoiceLine->addChild('ID', $idx + 1, $this->cbc);
            $invoiceLineQty = $invoiceLine->addChild('InvoicedQuantity', $item['invoice_qty'], $this->cbc);
            $invoiceLineQty->addAttribute('unitCode', 'C62'); // C62 = Service
            $lineItemAmountWithoutTax = $item['invoice_unit_price'] *
                $item['invoice_qty'] - ($includedTax ? $item['invoice_tax'] : 0);
            $invoiceLine->addChild(
                'LineExtensionAmount',
                number_format($lineItemAmountWithoutTax, 2, '.', ''),
                $this->cbc
            )->addAttribute('currencyID', $this->currency);

            if (empty($item['invoice_tax'])) {
                $item['invoice_tax'] = 0;
            }

            if ($item['invoice_tax'] && $item['invoice_tax_type'] === 'fixed') {
                $itemAllowanceCharge = $invoiceLine->addChild('AllowanceCharge', null, $this->cac);
                $itemAllowanceCharge->addChild('ChargeIndicator', 'true', $this->cbc);
                $itemAllowanceCharge->addChild('AllowanceChargeReason', 'Fixed tax', $this->cbc);
                $itemAllowanceCharge->addChild(
                    'Amount',
                    number_format($item['invoice_tax'], 2, '.', ''),
                    $this->cbc
                )->addAttribute('currencyID', $this->currency);
            }

            $unitPriceWithoutTax = $item['invoice_unit_price'] -
                ($includedTax ? $item['invoice_tax'] / $item['invoice_qty'] : 0);
            $itemNode = $invoiceLine->addChild('Item', null, $this->cac);
            $itemNode->addChild('Name', htmlspecialchars($item['item_name']), $this->cbc);
            $priceNode = $invoiceLine->addChild('Price', null, $this->cac);
            $priceAmount = $priceNode->addChild(
                'PriceAmount',
                number_format($unitPriceWithoutTax, 2, '.', ''),
                $this->cbc
            );
            $priceAmount->addAttribute('currencyID', $this->currency);

            // Tax per line
            $this->addTaxCategoryNode(
                $itemNode,
                $item,
                true
            );
        }
    }

    /**
     * Generates XML content for the invoice.
     *
     * @param array $data
     * @param string $currency
     * @return string
     */
    public function generateXml($data, $currency)
    {
        $invoiceData = $data['placeholders'];

        $this->cbc = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';
        $this->cac = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

        $this->currency = $currency;

        $this->invoiceData = $invoiceData;

        $this->xml = new \SimpleXMLElement(
            '<?xml version="1.0" encoding="UTF-8"?>
                <Invoice
                    xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"
                    xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"
                    xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2"
                ></Invoice>'
        );

        // Header
        $this->addHeaderData();

        // Supplier (company)
        $this->addCompanyData();

        // Customer
        $this->addCustomerData();

        // Payment terms
        if ($data['leftToPay']) {
            $this->xml->addChild('PaymentTerms', null, $this->cac)->addChild(
                'Note',
                BackendStrings::get('payment_method_colon') . $invoiceData['invoice_method'],
                $this->cbc
            );
        }

        $this->taxTypes = $this->calculateTaxTypes($data);

        // Allowance charge (discounts)
        if ($data['discount'] > 0) {
            $this->addAllowanceData();
        }

        $totalTax = array_reduce(
            $this->taxTypes,
            function ($carry, $taxType) {
                if ($taxType['invoice_tax_type'] !== 'fixed') {
                    $carry += $taxType['invoice_tax'];
                }
                return $carry;
            },
            0
        );

        $totalFixedTax = round($data['tax']) - round($totalTax);

        // Charge (fixed tax)
        if ($totalFixedTax) {
            $this->addChargeData($totalFixedTax);
        }

        $lineExtensionTotalAmount = array_reduce($data['items'], function ($carry, $item) use ($data) {
            return $carry +
                (round($item['invoice_unit_price'], 2) * $item['invoice_qty'] -
                    ($data['includedTax'] ? round($item['invoice_tax'], 2) : 0));
        }, 0);

        $taxableAmount = round($lineExtensionTotalAmount, 2) - round($data['discount'], 2);

        // Tax total (summary)
        $this->addTaxTotalData($taxableAmount ? $totalTax : 0);

        // Legal monetary total
        $this->addLegalMonetaryTotalData(
            array_merge(
                $data,
                ['lineExtensionTotalAmount' => $lineExtensionTotalAmount, 'totalFixedTax' => $totalFixedTax, 'totalTax' => $totalTax]
            )
        );

        // Invoice lines (items)
        $this->addLineItemsData($data['items'], !!$data['includedTax']);

        return $this->xml->asXML();
    }


    /**
     * Add a TaxCategory node to a given XML parent.
     *
     * @param \SimpleXMLElement $parent
     * @param $item
     * @param bool $isLineItem
     * @return \SimpleXMLElement|null
     */
    private function addTaxCategoryNode($parent, $item, bool $isLineItem = false, $taxExemptionReason = false)
    {
        $taxCategory = $parent->addChild($isLineItem ? 'ClassifiedTaxCategory' : 'TaxCategory', null, $this->cac);

        $rate = isset($item['invoice_tax_rate']) ?
            floatval(str_replace('%', '', $item['invoice_tax_rate'])) : 0;

        if ($rate && $item['invoice_tax_type'] !== 'fixed') {
            // Standard tax
            $taxCategory->addChild('ID', 'S', $this->cbc); // Standard
            $taxCategory->addChild('Percent', number_format($rate, 2, '.', ''), $this->cbc);
        } else {
            // No tax
            $taxCategory->addChild('ID', 'E', $this->cbc); // Exempt
            $taxCategory->addChild('Percent', '0.00', $this->cbc);
            if ($taxExemptionReason) {
                $taxCategory->addChild('TaxExemptionReason', 'No taxes applied', $this->cbc);
            }
        }

        $taxScheme = $taxCategory->addChild('TaxScheme', null, $this->cac);
        $taxScheme->addChild('ID', 'VAT', $this->cbc);

        return $taxCategory;
    }

    /**
     * Parses a full address string into its components.
     *
     * @param string $fullAddress
     * @return array
     */
    private function parseAddress($fullAddress)
    {
        $result = [
            'StreetName' => '',
            'BuildingNumber' => '',
            'PostalZone' => '',
            'CityName' => '',
            'CountryCode' => ''
        ];

        $parts = explode(',', $fullAddress);

        $streetPart = trim($parts[0]);

        if (preg_match('/^(.+?)\s+(\d+\w*)$/', $streetPart, $matches)) {
            $result['StreetName'] = $matches[1];
            $result['BuildingNumber'] = $matches[2];
        } else {
            $result['StreetName'] = $streetPart;
        }

        if (empty(trim($parts[1]))) {
            return $result;
        }

        $cityPart = trim($parts[1]);

        if (preg_match('/^(\d{4,6})\s+(.+)$/', $cityPart, $matches)) {
            $result['PostalZone'] = $matches[1];
            $result['CityName'] = $matches[2];
        } else {
            $result['CityName'] = $cityPart;
        }

        if (empty(trim($parts[2]))) {
            return $result;
        }

        $result['CountryCode'] = strtoupper(trim($parts[2]));

        return $result;
    }
}
