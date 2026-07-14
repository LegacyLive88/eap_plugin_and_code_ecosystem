<?php

namespace AmeliaBooking\Application\Commands\PaymentGateway;

use AmeliaBooking\Application\Commands\CommandHandler;
use AmeliaBooking\Application\Commands\CommandResult;
use AmeliaBooking\Application\Services\Booking\BookingApplicationService;
use AmeliaBooking\Application\Services\Payment\PaymentApplicationService;
use AmeliaBooking\Application\Services\Reservation\AbstractReservationService;
use AmeliaBooking\Domain\Common\Exceptions\InvalidArgumentException;
use AmeliaBooking\Domain\Entity\Booking\Reservation;
use AmeliaBooking\Domain\Entity\Cache\Cache;
use AmeliaBooking\Domain\Entity\Entities;
use AmeliaBooking\Domain\Factory\Cache\CacheFactory;
use AmeliaBooking\Domain\ValueObjects\Number\Integer\Id;
use AmeliaBooking\Domain\ValueObjects\String\PaymentType;
use AmeliaBooking\Domain\ValueObjects\String\Token;
use AmeliaBooking\Infrastructure\Common\Exceptions\QueryExecutionException;
use AmeliaBooking\Infrastructure\Repository\Cache\CacheRepository;
use AmeliaBooking\Infrastructure\Services\Payment\BarionService;
use AmeliaBooking\Infrastructure\WP\Translations\FrontendStrings;
use Exception;
use Interop\Container\Exception\ContainerException;

class BarionPaymentCommandHandler extends CommandHandler
{
    public $mandatoryFields = [
        'bookings',
        'payment'
    ];

    /**
     * @throws ContainerException
     * @throws InvalidArgumentException
     * @throws QueryExecutionException
     * @throws Exception
     */
    public function handle(BarionPaymentCommand $command)
    {
        $result = new CommandResult();

        $this->checkMandatoryFields($command);

        $type = $command->getField('type') ?: Entities::APPOINTMENT;

        /** @var AbstractReservationService $reservationService */
        $reservationService = $this->container->get('application.reservation.service')->get($type);

        /** @var PaymentApplicationService $paymentAS */
        $paymentAS = $this->container->get('application.payment.service');

        /** @var BookingApplicationService $bookingAS */
        $bookingAS = $this->container->get('application.booking.booking.service');

        /** @var BarionService $paymentServiceBarion */
        $paymentServiceBarion = $this->container->get('infrastructure.payment.barion.service');

        /** @var CacheRepository $cacheRepository */
        $cacheRepository = $this->container->get('domain.cache.repository');

        $bookingData = $bookingAS->getAppointmentData($command->getFields());

        $bookingData = apply_filters('amelia_before_barion_redirect_filter', $bookingData);

        do_action('amelia_before_barion_redirect', $bookingData);

        /** @var Reservation $reservation */
        $reservation = $reservationService->getNew(true, true, true);

        $reservationService->processBooking(
            $result,
            $bookingAS->getAppointmentData($command->getFields()),
            $reservation,
            false
        );

        if ($result->getResult() === CommandResult::RESULT_ERROR) {
            return $result;
        }

        $paymentAmount = $reservationService->getReservationPaymentAmount($reservation);

        if (!$paymentAmount) {
            $result->setResult(CommandResult::RESULT_ERROR);
            $result->setMessage(FrontendStrings::getCommonStrings()['payment_error']);
            $result->setData(
                [
                    'paymentSuccessful' => false,
                    'onSitePayment' => true
                ]
            );

            return $result;
        }

        $token = new Token();

        /** @var Cache $cache */
        $cache = CacheFactory::create(
            [
                'name' => $token->getValue(),
                'data' => json_encode(
                    [
                        'status'      => null,
                        'request'     => $command->getField('componentProps'),
                        'bookingData' => $command->getFields(),
                    ]
                ),
            ]
        );

        $cacheId = $cacheRepository->add($cache);

        $cache->setId(new Id($cacheId));

        /** @var Reservation $reservation */
        $reservation = $reservationService->getNew(true, true, true);

        $result = $reservationService->processRequest(
            $bookingAS->getAppointmentData($command->getFields()),
            $reservation,
            true
        );

        if ($result->getResult() === CommandResult::RESULT_ERROR) {
            return $result;
        }

        $additionalInformation = $paymentAS->getBookingInformationForPaymentSettings(
            $reservation,
            PaymentType::BARION
        );

        $identifier = $cacheId . '_' . $token->getValue() . '_' . $type;

        $returnUrl = $command->getField('returnUrl');

        $redirectUrl = AMELIA_ACTION_URL . '/payment/barion/notify&name=' . $identifier . '&returnUrl=' . $returnUrl;

        if ($type === Entities::PACKAGE) {
            $bookingId = $bookingData['packageId'] ?? null;
            if (
                $reservation->getPackageCustomer() &&
                $reservation->getPackageCustomer()->getPayments() &&
                $reservation->getPackageCustomer()->getPayments()->length() > 0
            ) {
                $paymentItems = $reservation->getPackageCustomer()->getPayments()->getItems();
                $paymentId = !empty($paymentItems) ? array_key_first($paymentItems) : null;
            } else {
                $paymentId = null;
            }
        } else {
            $bookingId = $reservation->getBooking()->getId()->getValue();
            $paymentId = $reservation->getBooking()->getPayments()->getItems()[0]->getId()->getValue();
        }
        $callbackUrl = (
            AMELIA_DEV
                ? str_replace(
                    'localhost',
                    AMELIA_NGROK_URL,
                    AMELIA_ACTION_URL
                )
                : AMELIA_ACTION_URL
            ) . '/payment/barion/callback&name=' . $identifier . '&bookingId=' . $bookingId . '&type=' . $type;

        $orderData = [
            'amount'      => $paymentAmount,
            'reservation' => $reservation ?: [],
            'returnUrl'   => $returnUrl,
            'redirectUrl' => $redirectUrl,
            'info'        => $additionalInformation,
            'callbackUrl' => $callbackUrl,
            'paymentId'   => $paymentId ?: null,
        ];

        $transfers = [];

        try {
            $barionPaymentResponse = $paymentServiceBarion->execute($orderData, $transfers);
        } catch (Exception $e) {
            $reservationService->deleteReservation($reservation);

            $result->setResult(CommandResult::RESULT_ERROR);
            $result->setMessage(FrontendStrings::getCommonStrings()['payment_error']);
            $result->setData(
                [
                    'message' => $e->getMessage(),
                    'paymentSuccessful' => false,
                ]
            );

            return $result;
        }

        if (!empty($barionPaymentResponse['Errors'])) {
            $reservationService->deleteReservation($reservation);

            $result->setResult(CommandResult::RESULT_ERROR);
            $result->setMessage(FrontendStrings::getCommonStrings()['payment_error']);
            $result->setData(
                [
                    'message' => $paymentServiceBarion->getErrorMessage($barionPaymentResponse['Errors']),
                    'paymentSuccessful' => false,
                ]
            );

            return $result;
        }

        if ($result->getResult() === CommandResult::RESULT_ERROR) {
            $reservationService->deleteReservation($reservation);
            return $result;
        }

        $bookings = $this->getBookingIdsAndTokens($result);

        $result = $paymentAS->updateCache($result, $command->getFields(), $cache, $reservation);
        $result->setResult(CommandResult::RESULT_SUCCESS);
        $result->setMessage('Proceed to Barion Checkout Page');
        $result->setData(
            [
                'redirectUrl' => $barionPaymentResponse['GatewayUrl'],
                'paymentSuccessful' => true,
                'bookings' => $bookings,
                'packageCustomer' => $result->getData()['packageCustomerId'] ? [
                    'id' => $result->getData()['packageCustomerId'],
                    'token' => $result->getData()['packageCustomerToken'] ?: '',
                ] : null
            ]
        );

        return $result;
    }

    /**
     * @param CommandResult $result
     * @return array
     */
    public function getBookingIdsAndTokens(CommandResult $result)
    {
        $bookings = [];

        if ($result->getData()['type'] !== 'package') {
            $bookings[] = [
                'id' => $result->getData()['booking']['id'],
                'token' => $result->getData()['booking']['token']
            ];
            $recurringBookings = $result->getData()['recurring'] ?? [];
            foreach ($recurringBookings as $recurring) {
                $bookings[] = [
                    'id'    => $recurring['booking']['id'],
                    'token' => $recurring['booking']['token'],
                ];
            }
        }
        return $bookings;
    }
}
