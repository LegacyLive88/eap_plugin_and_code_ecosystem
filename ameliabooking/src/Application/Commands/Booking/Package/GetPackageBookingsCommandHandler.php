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
use AmeliaBooking\Domain\Entity\Entities;
use AmeliaBooking\Domain\Entity\User\AbstractUser;
use AmeliaBooking\Domain\Services\DateTime\DateTimeService;
use AmeliaBooking\Domain\Services\Settings\SettingsService;
use AmeliaBooking\Domain\ValueObjects\String\BookableType;
use AmeliaBooking\Infrastructure\Common\Exceptions\QueryExecutionException;
use AmeliaBooking\Infrastructure\Repository\Bookable\Service\PackageCustomerRepository;
use AmeliaBooking\Infrastructure\Repository\Booking\Appointment\CustomerBookingRepository;
use Interop\Container\Exception\ContainerException;

/**
 * Class GetPackageBookingsCommandHandler
 *
 * @package AmeliaBooking\Application\Commands\Booking\Package
 */
class GetPackageBookingsCommandHandler extends CommandHandler
{
    /**
     * @param GetPackageBookingsCommand $command
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
    public function handle(GetPackageBookingsCommand $command)
    {
        $result = new CommandResult();

        /** @var PackageCustomerRepository $packageCustomerRepository */
        $packageCustomerRepository = $this->container->get('domain.bookable.packageCustomer.repository');

        /** @var PaymentApplicationService $paymentAS */
        $paymentAS = $this->container->get('application.payment.service');

        /** @var SettingsService $settingsDS */
        $settingsDS = $this->container->get('domain.settings.service');

        /** @var ProviderApplicationService $providerAS */
        $providerAS = $this->container->get('application.user.provider.service');

        $params = $command->getField('params');

        if (!empty($params['dates'][0])) {
            $params['dates'][0] .= ' 00:00:00';
        }

        if (!empty($params['dates'][1])) {
            $params['dates'][1] .= ' 23:59:59';
        }

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

        if ($user && $user->getType() === Entities::PROVIDER) {
            $params['providers'] = [$user->getId()->getValue()];
        }

        if ($user && $user->getType() === Entities::CUSTOMER) {
            $params['customers'] = [$user->getId()->getValue()];
        }

        $packageCustomerIds = $packageCustomerRepository->getFilteredIds($params, $params['limit']);

        if (empty($packageCustomerIds)) {
            $result->setResult(CommandResult::RESULT_SUCCESS);
            $result->setMessage('Successfully retrieved package purchases');
            $result->setData(
                [
                    'packageBookings' => [],
                    'filteredCount'   => 0,
                    'totalCount'      => sizeof($packageCustomerRepository->getFilteredIds())
                ]
            );

            return $result;
        }

        /** @var Collection $packageCustomers */
        $packageCustomers = $packageCustomerRepository->getFiltered(
            $packageCustomerIds,
            ['fetchAppointmentProviders' => true],
            !empty($params['sort']) ? $params['sort'] : null
        );

        /** @var CustomerBookingRepository $bookingRepository */
        $bookingRepository = $this->container->get('domain.booking.customerBooking.repository');

        $allBookingStatuses = [];
        foreach ($packageCustomerIds as $packageCustomerId) {
            try {
                $bookingRows = $bookingRepository->getByPackageCustomerId($packageCustomerId);
                $allBookingStatuses[$packageCustomerId] = [];
                foreach ($bookingRows as $row) {
                    $allBookingStatuses[$packageCustomerId][$row['appointmentId']] = $row['status'];
                }
            } catch (\Exception $e) {
                $allBookingStatuses[$packageCustomerId] = [];
            }
        }

        $customersNoShowCountIds = [];

        $noShowTagEnabled = $settingsDS->isFeatureEnabled('noShowTag');

        $packageCustomersArray = $packageCustomers->toArray();

        foreach ($packageCustomersArray as &$packagePurchase) {
            if (!empty($packagePurchase['customerId']) && !in_array($packagePurchase['customerId'], $customersNoShowCountIds)) {
                $customersNoShowCountIds[] = $packagePurchase['customerId'];
            }

            $reservationEntity = [
                'packageCustomer' => $packagePurchase,
                'price' => $packagePurchase['price'],
                'calculatedPrice' => $packagePurchase['package']['calculatedPrice'],
                'discount' => 0
            ];

            $employees = [];

            if (!empty($packagePurchase['appointments'])) {
                foreach ($packagePurchase['appointments'] as $appointment) {
                    if (empty($employees[$appointment['providerId']])) {
                        $employees[$appointment['providerId']] = [
                            'id' => $appointment['provider']['id'],
                            'firstName' => $appointment['provider']['firstName'],
                            'lastName' => $appointment['provider']['lastName'],
                            'picture' => $appointment['provider']['pictureThumbPath'],
                            'badge' => !empty($appointment['provider']['badgeId']) ? $providerAS->getBadge($appointment['provider']['badgeId']) : null,
                        ];
                    }
                }
            }

            // Calculate booked count using actual booking statuses for this package customer
            $bookedCount = 0;
            if (!empty($allBookingStatuses[$packagePurchase['id']])) {
                foreach ($allBookingStatuses[$packagePurchase['id']] as $appointmentId => $bookingStatus) {
                    if (in_array($bookingStatus, ['approved', 'pending'], true)) {
                        $bookedCount++;
                    }
                }
            }

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
                    'total' => !empty($packagePurchase['bookingsCount']) ?
                        $packagePurchase['bookingsCount'] :
                        array_sum(array_column($packagePurchase['packageCustomerServices'], 'bookingsCount')),
                ],
                'payment' => [
                    'status' => $paymentAS->getFullStatus(['payments' => $packagePurchase['payments']], BookableType::PACKAGE, $reservationEntity),
                    'total'  => $paymentAS->calculateAppointmentPrice([], BookableType::PACKAGE, $reservationEntity),
                ],
                'employees' => array_values($employees)
            ];
        }


        if ($noShowTagEnabled && $customersNoShowCountIds) {
            /** @var CustomerBookingRepository $bookingRepository */
            $bookingRepository = $this->container->get('domain.booking.customerBooking.repository');

            $customersNoShowCount = $bookingRepository->countByNoShowStatus($customersNoShowCountIds);

            foreach ($packageCustomersArray as &$packageBooking) {
                if (!empty($customersNoShowCount[$packageBooking['customer']['id']])) {
                    $packageBooking['customer']['noShowCount'] = $customersNoShowCount[$packageBooking['customer']['id']]['count'];
                }
            }
        }


        $result->setResult(CommandResult::RESULT_SUCCESS);
        $result->setMessage('Successfully retrieved package purchases');
        $result->setData(
            [
                'packageBookings' => $packageCustomersArray,
                'filteredCount'   => sizeof($packageCustomerRepository->getFilteredIds($params)),
                'totalCount'      => sizeof($packageCustomerRepository->getFilteredIds())
            ]
        );

        return $result;
    }
}
