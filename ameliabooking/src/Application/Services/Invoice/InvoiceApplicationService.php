<?php

namespace AmeliaBooking\Application\Services\Invoice;

use AmeliaBooking\Application\Common\Exceptions\AccessDeniedException;
use AmeliaBooking\Application\Services\Placeholder\PlaceholderService;
use AmeliaBooking\Domain\Common\Exceptions\InvalidArgumentException;
use AmeliaBooking\Domain\Entity\Payment\Payment;
use AmeliaBooking\Domain\Services\Reservation\ReservationServiceInterface;
use AmeliaBooking\Domain\Services\Settings\SettingsService;
use AmeliaBooking\Infrastructure\Common\Exceptions\NotFoundException;
use AmeliaBooking\Infrastructure\Common\Exceptions\QueryExecutionException;
use AmeliaBooking\Infrastructure\Repository\Payment\PaymentRepository;
use AmeliaVendor\Dompdf\Dompdf;
use Slim\Exception\ContainerException;

/**
 * Class InvoiceApplicationService
 *
 * @package AmeliaBooking\Application\Services\Invoice
 */
class InvoiceApplicationService extends AbstractInvoiceApplicationService
{
    /**
     * @param int $paymentId
     * @param int|null $customerId
     * @param string $format
     *
     * @return array
     *
     * @throws InvalidArgumentException
     * @throws NotFoundException
     * @throws QueryExecutionException
     * @throws ContainerException
     * @throws AccessDeniedException
     */
    public function generateInvoice($paymentId, $customerId = null, $format = null)
    {
        /** @var SettingsService $settingsDS */
        $settingsDS = $this->container->get('domain.settings.service');

        if (!$settingsDS->isFeatureEnabled('invoices')) {
            return [];
        }

        /** @var PaymentRepository $paymentRepository */
        $paymentRepository = $this->container->get('domain.payment.repository');
        /** @var SettingsService $settingsService */
        $settingsService = $this->container->get('domain.settings.service');

        /** @var Payment $payment */
        $payment = $paymentRepository->getById($paymentId);

        if (empty($payment->getInvoiceNumber())) {
            $data = [
                'paymentId'   => $paymentId,
                'parentId'    => $payment->getParentId() ? $payment->getParentId()->getValue() : null,
                'columnName'  => $payment->getPackageCustomerId() ? 'packageCustomerId' : 'customerBookingId',
                'columnValue' => $payment->getPackageCustomerId()
                    ? $payment->getPackageCustomerId()->getValue()
                    : $payment->getCustomerBookingId()->getValue(),
            ];
            $paymentRepository->setInvoiceNumber($data);
        }

        $type = $payment->getEntity()->getValue();

        /** @var ReservationServiceInterface $reservationService */
        $reservationService = $this->container->get('application.reservation.service')->get($type);

        /** @var PlaceholderService $placeholderService */
        $placeholderService = $this->container->get("application.placeholder.{$type}.service");

        $reservation = $reservationService->getReservationByPayment($payment);

        if ($customerId && $reservation->getData()['customer']['id'] !== $customerId) {
            throw new AccessDeniedException();
        }

        $reservationData = $reservation->getData();

        $data = $placeholderService->getInvoicePlaceholdersData($reservationData);

        $invoiceData = $this->getInvoiceData($data);

        $format = $format ?: $settingsService->getSetting('notifications', 'invoiceFormat');

        if ($format === 'pdf') {
            $invoiceData['placeholders']['customer_custom_fields'] =   array_map(
                function ($el) {
                    return $el['label'] . ': ' . $el['value'];
                },
                $invoiceData['placeholders']['customer_custom_fields']
            );

            ob_start();
            include AMELIA_PATH . '/templates/invoice/invoice.inc';
            $html = ob_get_clean();

            $dompdf = new Dompdf();

            $locale = get_locale();
            $localePrefix = substr($locale, 0, 2);
            $isAsianFont = in_array($localePrefix, ['zh', 'th', 'ja', 'ko']);

            if ($isAsianFont) {
                $options = $dompdf->getOptions();
                $options->set('isRemoteEnabled', true);
                $options->set('isPhpEnabled', true);
                $options->set('isFontSubsettingEnabled', true);
                // Set a reasonable default that supports wide glyph coverage
                $options->set('defaultFont', $isAsianFont ? 'Noto Sans' : 'DejaVu Sans');
                $options->set('defaultMediaType', 'print');
                $options->set('isCssFloatEnabled', true);

                $dompdf->setOptions($options);
            }

            $dompdf->setPaper('A4');

            $dompdf->loadHtml($html);

            $dompdf->render();

            $file = [
                'name'       => 'Invoice.pdf',
                'type'       => 'application/pdf',
                'content'    => $dompdf->output()
            ];
        } else {
            $currency = $settingsService->getCategorySettings('payments')['currency'];

            $companyData = $settingsService->getCategorySettings('company');

            $invoiceData['placeholders']['company_address_components'] = $companyData['addressComponents'];

            $xmlService = new XMLInvoiceService();

            $xml = $xmlService->generateXml($invoiceData, $currency);

            $file = [
                'name'       => 'Invoice.xml',
                'type'       => 'application/xml',
                'content'    => $xml
            ];
        }


        return array_merge($file, [
            'customerId' => $reservation->getData()['customer']['id']
        ]);
    }

    /**
     * @param array $data
     *
     * @return array
     */
    private function getInvoiceData($data)
    {
        $itemsWithTax = array_filter(
            $data['items'],
            function ($item) {
                return !empty($item['invoice_tax']);
            }
        );
        $taxEnabled   = !empty($itemsWithTax) && !empty(array_values($itemsWithTax)[0]['invoice_tax']);
        $includedTax  = !empty($itemsWithTax) && !array_values($itemsWithTax)[0]['invoice_tax_excluded'];

        $invoiceSubtotal = array_sum(array_column($data['items'], 'invoice_subtotal'));
        $invoiceDiscount = array_sum(array_map(function ($item) {
            return !empty($item['service_discount']) ? $item['service_discount'] : $item['invoice_discount'];
        }, $data['items']));
        $invoiceTax      = $data['invoice_tax'];

        $invoiceTotal = $invoiceSubtotal - $invoiceDiscount;
        $invoiceTotal += $invoiceTotal ? $invoiceTax : 0;

        $invoicePaid = array_sum(array_column($data['items'], 'invoice_paid_amount'));

        $invoicePaid = ($invoicePaid < $invoiceTotal) ? $invoicePaid : 0;

        $invoiceLeftToPay = $invoiceTotal - $invoicePaid;

        $filename = !empty($data['company_logo']) ? $data['company_logo'] : null;
        $mime = null;
        $img = null;
        if ($filename) {
            $filePath = str_replace(AMELIA_UPLOADS_URL, AMELIA_UPLOADS_PATH, $filename);

            if (file_exists($filePath) && is_readable($filePath)) {
                $mime = pathinfo($filePath, PATHINFO_EXTENSION);
                $imgContent = file_get_contents($filePath);
                if ($imgContent !== false) {
                    $img = base64_encode($imgContent);
                }
            }
        }

        $data['customer_custom_fields'] = array_values($data['customer_custom_fields']);

        return [
            'items' => $data['items'],
            'taxEnabled' => $taxEnabled,
            'includedTax' => $includedTax,
            'subtotal' => $invoiceSubtotal,
            'discount' => $invoiceDiscount,
            'tax' => $invoiceTax,
            'total' => $invoiceTotal,
            'paid' => $invoicePaid,
            'leftToPay' => $invoiceLeftToPay,
            'companyLogo' => ($img && $mime) ? ['filename' => $filename, 'mime' => $mime, 'img' => $img] : null,
            'placeholders' => $data,
        ];
    }
}
