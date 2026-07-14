<?php

/**
 * @copyright © Melograno Ventures. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace AmeliaBooking\Application\Commands\Report;

use AmeliaBooking\Application\Commands\CommandHandler;
use AmeliaBooking\Application\Commands\CommandResult;
use AmeliaBooking\Application\Common\Exceptions\AccessDeniedException;
use AmeliaBooking\Application\Services\Payment\PaymentApplicationService;
use AmeliaBooking\Domain\Entity\Booking\Event\CustomerBookingEventTicket;
use AmeliaBooking\Domain\Entity\Entities;
use AmeliaBooking\Domain\Entity\User\Customer;
use AmeliaBooking\Domain\Services\DateTime\DateTimeService;
use AmeliaBooking\Domain\Services\Report\ReportServiceInterface;
use AmeliaBooking\Domain\Services\Settings\SettingsService;
use AmeliaBooking\Infrastructure\Repository\Booking\Appointment\CustomerBookingRepository;
use AmeliaBooking\Infrastructure\WP\Translations\BackendStrings;

/**
 * Class GetEventBookingsCommandHandler
 *
 * @package AmeliaBooking\Application\Commands\Report
 */
class GetEventBookingsCommandHandler extends CommandHandler
{
    /**
     * @param GetEventBookingsCommand $command
     *
     * @return CommandResult
     * @throws \Slim\Exception\ContainerValueNotFoundException
     * @throws AccessDeniedException
     * @throws \AmeliaBooking\Domain\Common\Exceptions\InvalidArgumentException
     * @throws \AmeliaBooking\Infrastructure\Common\Exceptions\QueryExecutionException
     */
    public function handle(GetEventBookingsCommand $command)
    {
        if (!$command->getPermissionService()->currentUserCanRead(Entities::EVENTS)) {
            throw new AccessDeniedException('You are not allowed to read events.');
        }

        $result = new CommandResult();

        $this->checkMandatoryFields($command);

        $params = $command->getField('params');

        if (!empty($params['dates'])) {
            if (!empty($params['dates'][0])) {
                $params['dates'][0] .= ' 00:00:00';
            }
            if (!empty($params['dates'][1])) {
                $params['dates'][1] .= ' 23:59:59';
            }
        }

        /** @var CustomerBookingRepository $bookingRepository */
        $bookingRepository = $this->container->get('domain.booking.customerBooking.repository');

        /** @var ReportServiceInterface $reportService */
        $reportService = $this->container->get('infrastructure.report.csv.service');

        /** @var SettingsService $settingsDomainService */
        $settingsDomainService = $this->container->get('domain.settings.service');

        /** @var PaymentApplicationService $paymentAS */
        $paymentAS     = $this->container->get('application.payment.service');

        $rows = [];

        $fields = $command->getField('params')['fields'];

        $delimiter = $command->getField('params')['delimiter'];

        $dateFormat = $settingsDomainService->getSetting('wordpress', 'dateFormat');
        $timeFormat = $settingsDomainService->getSetting('wordpress', 'timeFormat');

        $row = [];

        $bookingIds = $bookingRepository->getEventBookingIdsByCriteria($params);

        $bookings = $bookingRepository->getEventBookingsByIds(
            $bookingIds,
            array_merge(
                !empty($params['dates']) ? ['dates' => $params['dates']] : [],
                [
                    'fetchBookingsPayments' => true,
                    'fetchBookingsCoupons' => true,
                    'fetchProviders' => true,
                    'fetchCustomers' => true,
                    'fetchEvent' => true,
                ]
            )
        );

        foreach ($bookings as $booking) {
            /** @var Customer $customer */
            $customer = $booking['customer'];

            $infoJson = !empty($booking['info']) ? json_decode($booking['info'], true) : null;

            $customerInfo = $infoJson ?: $customer;

            if (in_array('attendee', $fields, true)) {
                $row[BackendStrings::get('attendee')] =
                    $customerInfo['firstName'] . ' ' . $customerInfo['lastName'] .
                    (!empty($customerInfo['email']) ? ' ' . $customerInfo['email']  : '') .
                    (!empty($customerInfo['phone']) ? ' ' . $customerInfo['phone']  : '');
            }

            if (in_array('organizer', $fields, true)) {
                $row[BackendStrings::get('event_organizer')] = !empty($booking['event']['organizer']) ?
                    $booking['event']['organizer']['firstName'] . ' ' . $booking['event']['organizer']['lastName'] .
                    (!empty($booking['event']['organizer']['email']) ? ' ' . $booking['event']['organizer']['email']  : '') .
                    (!empty($booking['event']['organizer']['phone']) ? ' ' . $booking['event']['organizer']['phone']  : '')
                    : '';
            }

            if (in_array('name', $fields, true)) {
                $row[BackendStrings::get('event_name')] = $booking['event']['name'];
            }

            if (in_array('date', $fields, true)) {
                $dateString = explode(' ', array_values($booking['event']['periods'])[0]['periodStart'])[0];
                $row[BackendStrings::get('date')] = DateTimeService::getCustomDateTimeObject($dateString)->format($dateFormat);
            }

            if (in_array('time', $fields, true)) {
                $timeString = explode(' ', array_values($booking['event']['periods'])[0]['periodStart'])[1];
                $row[BackendStrings::get('time')] = DateTimeService::getCustomDateTimeObject($timeString)->format($timeFormat);
            }

            if (in_array('status', $fields, true)) {
                $row[BackendStrings::get('event_book_status')] = ucfirst($booking['status']);
            }

            if (in_array('price', $fields, true)) {
                $row[BackendStrings::get('price')] = $booking['price'];
            }

            if (in_array('paymentStatus', $fields, true)) {
                $paymentStatus = $paymentAS->getFullStatus($booking, Entities::EVENT);
                $row[BackendStrings::get('payment_status')] =
                    (
                    $paymentStatus === 'partiallyPaid' ?
                        BackendStrings::get('partially_paid') :
                        BackendStrings::get($paymentStatus)
                    );
            }

            if (in_array('booked', $fields, true)) {
                $persons = $booking['persons'];
                if (!empty($booking['event']['customPricing']) && !empty($booking['ticketsData'])) {
                    /** @var CustomerBookingEventTicket $bookedTicket */
                    foreach ($booking['ticketsData'] as $bookedTicket) {
                        $persons += $bookedTicket['persons'];
                    }
                }
                $row[BackendStrings::get('booked')] = $persons;
            }

            if (in_array('code', $fields, true)) {
                $row[BackendStrings::get('code')] = (!empty($booking['token']) ? substr($booking['token'], 0, 5) : '');
            }

            $row = apply_filters('amelia_before_csv_export_event_bookings', $row, $booking);

            $rows[] = $row;
        }

        $reportService->generateReport(
            $rows,
            BackendStrings::get('red_event_bookings_export'),
            $delimiter
        );

        $result->setAttachment(true);

        return $result;
    }
}
