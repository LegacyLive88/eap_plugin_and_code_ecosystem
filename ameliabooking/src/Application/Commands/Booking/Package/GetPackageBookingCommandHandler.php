<?php

namespace AmeliaBooking\Application\Commands\Booking\Package;

use AmeliaBooking\Application\Commands\CommandHandler;
use AmeliaBooking\Application\Commands\CommandResult;
use AmeliaBooking\Application\Common\Exceptions\AccessDeniedException;
use AmeliaBooking\Application\Services\Payment\PaymentApplicationService;
use AmeliaBooking\Application\Services\User\ProviderApplicationService;
use AmeliaBooking\Domain\Collection\Collection;
use AmeliaBooking\Domain\Common\Exceptions\AuthorizationException;
use AmeliaBooking\Domain\Common\Exceptions\InvalidArgumentException;
use AmeliaBooking\Domain\Entity\Booking\Appointment\Appointment;
use AmeliaBooking\Domain\Entity\Entities;
use AmeliaBooking\Domain\Entity\User\AbstractUser;
use AmeliaBooking\Domain\Services\DateTime\DateTimeService;
use AmeliaBooking\Domain\Services\Settings\SettingsService;
use AmeliaBooking\Domain\ValueObjects\String\BookableType;
use AmeliaBooking\Infrastructure\Common\Exceptions\QueryExecutionException;
use AmeliaBooking\Infrastructure\Repository\Bookable\Service\PackageCustomerRepository;
use AmeliaBooking\Infrastructure\Repository\Booking\Appointment\AppointmentRepository;
use AmeliaBooking\Infrastructure\Repository\Booking\Appointment\CustomerBookingRepository;
use Interop\Container\Exception\ContainerException;

/**
 * Class GetPackageBookingCommandHandler
 *
 * @package AmeliaBooking\Application\Commands\Booking\Package
 */
class GetPackageBookingCommandHandler extends CommandHandler
{
    /**
     * @param GetPackageBookingCommand $command
     *
     * @return CommandResult
     *
     * @throws AccessDeniedException
     * @throws ContainerException
     * @throws InvalidArgumentException
     * @throws QueryExecutionException
     * @throws \DateInvalidTimeZoneException
     * @throws \DateMalformedStringException
     */
    public function handle(GetPackageBookingCommand $command)
    {
        $result = new CommandResult();

        /** @var PackageCustomerRepository $packageCustomerRepository */
        $packageCustomerRepository = $this->container->get('domain.bookable.packageCustomer.repository');

        /** @var PaymentApplicationService $paymentAS */
        $paymentAS = $this->container->get('application.payment.service');

        /** @var SettingsService $settingsDS */
        $settingsDS = $this->container->get('domain.settings.service');

        /** @var AppointmentRepository $appointmentRepo */
        $appointmentRepo = $this->container->get('domain.booking.appointment.repository');

        try {
            /** @var AbstractUser $user */
            $user = $command->getUserApplicationService()->authorization(null, $command->getCabinetType());
        } catch (AuthorizationException $e) {
            $result->setResult(CommandResult::RESULT_ERROR);
            $result->setData(
                [
                    'reauthorize' => true
                ]
            );

            return $result;
        }

        $packageBookingId = $command->getArg('id');

        /** @var CustomerBookingRepository $bookingRepository */
        $bookingRepository = $this->container->get('domain.booking.customerBooking.repository');

        $appointmentBooking = [];
        try {
            $bookingRows = $bookingRepository->getByPackageCustomerId($packageBookingId);

            foreach ($bookingRows as $row) {
                $appointmentBooking[$row['appointmentId']] = [
                    'bookingId' => $row['id'],
                    'status' => $row['status']
                ];
            }
        } catch (\Exception $e) {
            throw new QueryExecutionException('Unable to get package bookings');
        }

        /** @var Collection $packageCustomers */
        $packageCustomers = $packageCustomerRepository->getFiltered([$packageBookingId], ['fetchPackageServices' => true]);

        $customersNoShowCountIds = [];

        $noShowTagEnabled = $settingsDS->isFeatureEnabled('noShowTag');

        $packagePurchase = $packageCustomers->toArray()[0];

        if ($user && $user->getType() === Entities::CUSTOMER && $packagePurchase['customerId'] !== $user->getId()->getValue()) {
            throw new AccessDeniedException('You are not allowed to read this package booking.');
        }

        if (!empty($packagePurchase['customerId']) && !in_array($packagePurchase['customerId'], $customersNoShowCountIds)) {
            $customersNoShowCountIds[] = $packagePurchase['customerId'];
        }

        $reservationEntity = [
            'packageCustomer' => $packagePurchase,
            'price' => $packagePurchase['price'],
            'calculatedPrice' => $packagePurchase['package']['calculatedPrice'],
            'discount' => 0
        ];

        if ($packagePurchase['status'] === 'approved') {
            if (
                !empty($packagePurchase['end']) &&
                DateTimeService::getCustomDateTimeObjectFromUtc($packagePurchase['end']) < DateTimeService::getNowDateTimeObject()
            ) {
                $status = 'expired';
            } else {
                $status = 'active';
            }
        } else {
            $status = 'canceled';
        }

        $appointments = new Collection();

        $appointmentIds = array_column($packagePurchase['appointments'], 'id');

        if (!empty($appointmentIds)) {
            $appointments = $appointmentRepo->getFiltered(['ids' => $appointmentIds]);
        }

        $wcTax = 0;
        $wcDiscount = 0;

        foreach ($packagePurchase['payments'] as $payment) {
            $paymentAS->addWcFields($payment);

            $wcTax += !empty($payment['wcItemTaxValue']) ? $payment['wcItemTaxValue'] : 0;

            $wcDiscount += !empty($payment['wcItemCouponValue']) ? $payment['wcItemCouponValue'] : 0;
        }

        // Calculate booked count using the actual booking statuses for this package customer
        $bookedCount = 0;
        foreach ($appointmentBooking as $appointmentId => $bookingData) {
            if (in_array($bookingData['status'], ['approved', 'pending'], true)) {
                $bookedCount++;
            }
        }

        $packagePurchase = [
            'id' => $packagePurchase['id'],
            'date' => explode(' ', DateTimeService::getCustomDateTimeFromUtc($packagePurchase['purchased']))[0],
            'booked' => $bookedCount,
            'customer' => [
                'id' => $packagePurchase['customer']['id'],
                'firstName' => $packagePurchase['customer']['firstName'],
                'lastName' => $packagePurchase['customer']['lastName'],
                'note' => $packagePurchase['customer']['note'],
            ],
            'expirationDate' => !empty($packagePurchase['end']) ? DateTimeService::getCustomDateTimeFromUtc($packagePurchase['end']) : null,
            'status' => $status,
            'package' => [
                'id' => $packagePurchase['package']['id'],
                'name' => $packagePurchase['package']['name'],
                'color' => $packagePurchase['package']['color'],
                'services' => array_map(
                    function ($bookable) {
                        return $bookable['service'];
                    },
                    $packagePurchase['package']['bookable']
                ),
                'pictureThumbPath' => $packagePurchase['package']['pictureThumbPath'],
                'total' => !empty($packagePurchase['bookingsCount']) ?
                    $packagePurchase['bookingsCount'] :
                    array_sum(array_column($packagePurchase['packageCustomerServices'], 'bookingsCount')),
            ],
            'appointments' => [],
            'payment' => [
                'status' => $paymentAS->getFullStatus(['payments' => $packagePurchase['payments']], BookableType::PACKAGE, $reservationEntity),
                'total'  => $paymentAS->calculateAppointmentPrice([], BookableType::PACKAGE, $reservationEntity) + $wcTax - $wcDiscount,
                'paymentMethods' => array_map(
                    function ($payment) {
                        return $payment['gateway'];
                    },
                    $packagePurchase['payments']
                ),
            ],
        ];

        /** @var Appointment $appointment */
        foreach ($appointments->getItems() as $appointment) {
            $date =  $appointment->getBookingStart()->getValue()->format('Y-m-d H:i:s');
            $appointmentId = $appointment->getId()->getValue();

            // Get booking ID and status from the map created earlier
            $bookingId = null;
            $bookingStatus = $appointment->getStatus()->getValue();

            if (isset($appointmentBooking[$appointmentId])) {
                $bookingId = $appointmentBooking[$appointmentId]['bookingId'];
                $bookingStatus = $appointmentBooking[$appointmentId]['status'];
            }

            $packagePurchase['appointments'][] = [
                'id' => $appointmentId,
                'bookingId' => $bookingId,
                'startDate' => explode(' ', $date)[0],
                'startTime' => explode(' ', $date)[1],
                'status' => $bookingStatus,
                'service' => $appointment->getService() ? [
                    'id' => $appointment->getService()->getId()->getValue(),
                    'name' => $appointment->getService()->getName()->getValue(),
                ] : null,
                'bookingsCount' => $appointment->getBookings()->length()
            ];
        }

        if ($noShowTagEnabled && $customersNoShowCountIds) {
            /** @var CustomerBookingRepository $bookingRepository */
            $bookingRepository = $this->container->get('domain.booking.customerBooking.repository');

            $customersNoShowCount = $bookingRepository->countByNoShowStatus($customersNoShowCountIds);

            if (!empty($customersNoShowCount[$packagePurchase['customer']['id']])) {
                $packagePurchase['customer']['noShowCount'] = $customersNoShowCount[$packagePurchase['customer']['id']]['count'];
            }
        }

        $result->setResult(CommandResult::RESULT_SUCCESS);
        $result->setMessage('Successfully retrieved package purchases');
        $result->setData(
            [
                'packageBooking'  => $packagePurchase,
            ]
        );

        return $result;
    }
}
