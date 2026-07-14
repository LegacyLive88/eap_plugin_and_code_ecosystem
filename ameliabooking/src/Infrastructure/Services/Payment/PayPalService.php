<?php

/**
 * @copyright © Melograno Ventures. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace AmeliaBooking\Infrastructure\Services\Payment;

use AmeliaBooking\Domain\Services\Payment\AbstractPaymentService;
use AmeliaBooking\Domain\Services\Payment\PaymentServiceInterface;
use AmeliaBooking\Infrastructure\Services\PayPal\PayPalClient;
use AmeliaBooking\Infrastructure\Services\PayPal\PayPalResponse;
use Exception;

/**
 * Class PayPalService
 */
class PayPalService extends AbstractPaymentService implements PaymentServiceInterface
{
    /**
     * Cached client instance
     *
     * @var PayPalClient|null
     */
    private ?PayPalClient $client = null;

    /**
     * Build (or return the cached) PayPalClient for the current settings.
     */
    private function getClient(): PayPalClient
    {
        if ($this->client === null) {
            $payPalSettings = $this->settingsService->getCategorySettings('payments')['payPal'];
            $sandboxMode    = (bool)$payPalSettings['sandboxMode'];

            $this->client = new PayPalClient(
                $sandboxMode ? $payPalSettings['testApiClientId'] : $payPalSettings['liveApiClientId'],
                $sandboxMode ? $payPalSettings['testApiSecret']   : $payPalSettings['liveApiSecret'],
                $sandboxMode
            );
        }

        return $this->client;
    }

    /**
     * Create a PayPal order and return a response object.
     *
     * @param array $data
     * @param array $transfers
     *
     * @return PayPalResponse
     * @throws Exception
     */
    public function execute($data, &$transfers)
    {
        $currency = $this->settingsService->getCategorySettings('payments')['currency'];

        $response = $this->getClient()->createOrder(
            [
                'amount'      => $data['amount'],
                'currency'    => $currency,
                'returnUrl'   => $data['returnUrl'],
                'cancelUrl'   => $data['cancelUrl'],
                'description' => !empty($data['description']) ? $data['description'] : '',
            ]
        );

        return new PayPalResponse($response);
    }

    /**
     * Capture an approved PayPal order (called after the buyer approves).
     *
     * @param array $data
     *
     * @return PayPalResponse
     * @throws Exception
     */
    public function complete($data)
    {
        $response = $this->getClient()->captureOrder($data['transactionReference']);

        return new PayPalResponse($response);
    }

    /**
     * Create an order and return its approval link for a redirect-based flow.
     *
     * @param array $data
     *
     * @return array
     * @throws Exception
     */
    public function getPaymentLink($data)
    {
        $transfers = [];

        $response = $this->execute($data, $transfers);

        if ($response->isSuccessful()) {
            $approveUrl = $response->getRedirectUrl();

            if ($approveUrl) {
                return ['link' => $approveUrl, 'status' => 200];
            }
        }

        return ['message' => $response->getMessage(), 'status' => $response->getCode()];
    }

    /**
     * Refund a captured PayPal order (full or partial).
     *
     * @param array $data
     *
     * @return array
     * @throws Exception
     */
    public function refund($data)
    {
        $payment = $this->getTransaction($data['id']);

        if ($payment) {
            $captureId = !empty($payment['purchase_units'][0]['payments']['captures'][0]['id'])
                ? $payment['purchase_units'][0]['payments']['captures'][0]['id']
                : null;

            if (!$captureId) {
                return ['error' => 'No capture found for this PayPal order'];
            }

            $currency   = $this->settingsService->getCategorySettings('payments')['currency'];
            $refundData = ['currency' => $currency];

            if (!empty($data['amount'])) {
                $refundData['amount'] = $data['amount'];
            }

            $response = new PayPalResponse($this->getClient()->refundCapture($captureId, $refundData));

            return ['error' => !$response->isSuccessful() ? $response->getMessage() ?: 'Refund failed' : false];
        }

        return ['error' => true];
    }

    /**
     * Return the total payment amount for a given PayPal order.
     *
     * @param string     $id
     * @param array|null $transfers
     *
     * @return string|null
     * @throws Exception
     */
    public function getTransactionAmount($id, $transfers)
    {
        $transaction = $this->getTransaction($id);

        if (!$transaction) {
            return null;
        }

        return !empty($transaction['purchase_units'][0]['amount']['value'])
            ? $transaction['purchase_units'][0]['amount']['value']
            : null;
    }

    /**
     * Fetch raw order data from PayPal.
     *
     * @throws Exception
     */
    private function getTransaction(string $id): ?array
    {
        $response = $this->getClient()->getOrder($id);

        return isset($response['_http_code']) && $response['_http_code'] === 200 ? $response : null;
    }
}
