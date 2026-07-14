<?php

namespace AmeliaBooking\Application\Commands\PaymentGateway;

use AmeliaBooking\Application\Commands\CommandHandler;
use AmeliaBooking\Application\Commands\CommandResult;
use AmeliaBooking\Application\Services\Reservation\AbstractReservationService;
use AmeliaBooking\Domain\Collection\Collection;
use AmeliaBooking\Domain\Common\Exceptions\InvalidArgumentException;
use AmeliaBooking\Domain\Entity\Booking\Appointment\CustomerBooking;
use AmeliaBooking\Domain\Entity\Booking\Reservation;
use AmeliaBooking\Domain\Entity\Cache\Cache;
use AmeliaBooking\Domain\Factory\Booking\Appointment\CustomerBookingFactory;
use AmeliaBooking\Domain\Services\Reservation\ReservationServiceInterface;
use AmeliaBooking\Infrastructure\Common\Exceptions\NotFoundException;
use AmeliaBooking\Infrastructure\Common\Exceptions\QueryExecutionException;
use AmeliaBooking\Infrastructure\Repository\Cache\CacheRepository;
use AmeliaBooking\Infrastructure\Services\Payment\BarionService;
use Exception;
use Interop\Container\Exception\ContainerException;

class BarionPaymentCallbackCommandHandler extends CommandHandler
{
    /**
     * @throws InvalidArgumentException|QueryExecutionException
     * @throws ContainerException
     * @throws NotFoundException
     * @throws Exception
     */
    public function handle(BarionPaymentCallbackCommand $command)
    {
        $result = new CommandResult();

        /** @var BarionService $paymentService */
        $paymentService = $this->container->get('infrastructure.payment.barion.service');

        /** @var CacheRepository $cacheRepository */
        $cacheRepository = $this->container->get('domain.cache.repository');

        $paymentId = $command->getField('paymentId');
        $bookingId = $command->getField('bookingId');
        $type = $command->getField('type');

        /** @var Cache $cache */
        $cache = ($data = explode('_', $command->getField('name'))) && isset($data[0], $data[1]) ?
            $cacheRepository->getByIdAndName($data[0], $data[1]) : null;

        $cacheData = json_decode($cache->getData()->getValue(), true);
        if (!$cacheData) {
            return $result;
        }

        /** @var AbstractReservationService $reservationService */
        $reservationService = $this->container->get('application.reservation.service')->get($type);

        $response = $cacheData['response'] ?? null;

        try {
            // Get Barion payment status
            $barionResponse = $paymentService->getPaymentState($paymentId);

            if (!empty($barionResponse['Status']) && $barionResponse['Status'] !== 'Succeeded') {
                $reservation = $this->getNew($reservationService, $bookingId, $response);
                $reservationService->deleteReservation($reservation);

                $result->setResult(CommandResult::RESULT_SUCCESS);
                $result->setMessage('Payment failed. Reservation deleted.');
                return $result;
            }

            $result->setResult(CommandResult::RESULT_SUCCESS);
            $result->setMessage('Payment succeeded.');
        } catch (Exception $e) {
            $result->setResult(CommandResult::RESULT_ERROR);
            $result->setMessage('Callback processing failed: ' . $e->getMessage());
        }

        return $result;
    }

    /**
     * @param ReservationServiceInterface $reservationService
     * @param $bookingId
     * @param $response
     * @return Reservation
     * @throws ContainerException
     * @throws InvalidArgumentException
     * @throws QueryExecutionException
     */
    public function getNew(ReservationServiceInterface $reservationService, $bookingId, $response)
    {
        $reservation = $reservationService->getNew(true, true, true);

        $reservation->setReservation($reservationService->getReservationByBookingId($bookingId));
        /** @var CustomerBooking $booking */
        $booking = CustomerBookingFactory::create($response['booking']);
        $reservation->setBooking($booking);

        $recurringArray = $response['recurring'] ?? [];

        $recurringCollection = new Collection();
        foreach ($recurringArray as $recurringReservation) {
            $recurringCollection->addItem($recurringReservation);
        }
        $reservation->setRecurring($recurringCollection);

        $packageReservationsArray = $response['package'] ?? [];
        $packageCollection = new Collection();
        foreach ($packageReservationsArray as $packageReservation) {
            $packageCollection->addItem($packageReservation);
        }
        $reservation->setPackageReservations($packageCollection);

        $packageCustomerServicesArray = $response['packageCustomerServices'] ?? [];
        $packageCustomerServicesCollection = new Collection();
        foreach ($packageCustomerServicesArray as $packageCustomerService) {
            $packageCustomerServicesCollection->addItem($packageCustomerService);
        }
        $reservation->setPackageCustomerServices($packageCustomerServicesCollection);
        return $reservation;
    }
}
