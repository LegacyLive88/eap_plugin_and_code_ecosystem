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
use AmeliaBooking\Domain\Entity\Entities;
use AmeliaBooking\Domain\Services\DateTime\DateTimeService;
use AmeliaBooking\Domain\Services\Report\ReportServiceInterface;
use AmeliaBooking\Domain\Services\Settings\SettingsService;
use AmeliaBooking\Infrastructure\WP\Translations\BackendStrings;

/**
 * Class GetPaymentsCommandHandler
 *
 * @package AmeliaBooking\Application\Commands\Report
 */
class GetPaymentsCommandHandler extends CommandHandler
{
    /**
     * @param GetPaymentsCommand $command
     *
     * @return CommandResult
     * @throws \Slim\Exception\ContainerValueNotFoundException
     * @throws AccessDeniedException
     * @throws \AmeliaBooking\Domain\Common\Exceptions\InvalidArgumentException
     * @throws \AmeliaBooking\Infrastructure\Common\Exceptions\QueryExecutionException
     */
    public function handle(GetPaymentsCommand $command)
    {
        if (!$command->getPermissionService()->currentUserCanRead(Entities::FINANCE)) {
            throw new AccessDeniedException('You are not allowed to read payments.');
        }

        /** @var ReportServiceInterface $reportService */
        $reportService = $this->container->get('infrastructure.report.csv.service');
        /** @var PaymentApplicationService $paymentAS */
        $paymentAS = $this->container->get('application.payment.service');
        /** @var SettingsService $settingsDS */
        $settingsDS = $this->container->get('domain.settings.service');

        $result = new CommandResult();

        $this->checkMandatoryFields($command);

        $params = $command->getField('params');

        if (!empty($params['dates'])) {
            $params['dates'][0] .= ' 00:00:00';
            $params['dates'][1] .= ' 23:59:59';
        }

        $paymentsData = $paymentAS->getPaymentsData($params, 0);

        $rows = [];

        $dateFormat = $settingsDS->getSetting('wordpress', 'dateFormat');
        $timeFormat = $settingsDS->getSetting('wordpress', 'timeFormat');

        foreach ($paymentsData as $payment) {
            $row = [];

            if (in_array('service', $params['fields'], true)) {
                $row[BackendStrings::get('service')] = $payment['name'];
            }

            if (in_array('bookingStart', $params['fields'], true)) {
                $row[BackendStrings::get('start_time')] =
                    DateTimeService::getCustomDateTimeObject($payment['bookingStart'])
                        ->format($dateFormat . ' ' . $timeFormat);
            }

            if (in_array('customer', $params['fields'], true)) {
                $row[BackendStrings::get('customer')] =
                    $payment['customerFirstName'] . ' ' . $payment['customerLastName'];
            }

            if (in_array('customerEmail', $params['fields'], true)) {
                $row[BackendStrings::get('customer_email')] = $payment['customerEmail'];
            }

            if (in_array('employee', $params['fields'], true)) {
                $row[BackendStrings::get('employee')] =
                    implode(', ', array_column($payment['providers'], 'fullName'));
            }

            if (in_array('employeeEmail', $params['fields'], true)) {
                $row[BackendStrings::get('employee_email')] =
                    implode(', ', array_column($payment['providers'], 'email'));
            }

            if (in_array('location', $params['fields'], true)) {
                $row[BackendStrings::get('location')] =
                    isset($payment['location']) ?
                        (!empty($payment['location']['location_address']) ?
                            $payment['location']['location_address'] :
                            $payment['location']['location_name']) : '';
            }

            if (in_array('amount', $params['fields'], true)) {
                $row[BackendStrings::get('amount')] =
                    $payment['amount'] . (!empty($payment['secondaryPayments']) ?
                        ', ' . implode(', ', array_column($payment['secondaryPayments'], 'amount')) :
                        '');
            }

            if (in_array('type', $params['fields'], true)) {
                $row[BackendStrings::get('method')] =
                    $payment['gateway'] . (!empty($payment['secondaryPayments']) ?
                        ', ' . implode(', ', array_column($payment['secondaryPayments'], 'gateway')) :
                        '');
            }

            if (in_array('status', $params['fields'], true)) {
                $row[BackendStrings::get('status')] =
                    BackendStrings::get($payment['status'])
                    . (!empty($payment['secondaryPayments']) ? ', ' . implode(
                        ', ',
                        array_map(
                            function ($val) {
                                return BackendStrings::get($val['status']);
                            },
                            $payment['secondaryPayments']
                        )
                    ) : '');
            }

            if (in_array('paymentDate', $params['fields'], true)) {
                $row[BackendStrings::get('payment_date')] =
                    DateTimeService::getCustomDateTimeObject($payment['dateTime'])
                        ->format($dateFormat . ' ' . $timeFormat)
                    . (!empty($payment['secondaryPayments']) ? ', ' . implode(
                        ', ',
                        array_map(
                            function ($val) use ($dateFormat, $timeFormat) {
                                return DateTimeService::getCustomDateTimeObject($val['dateTime'])
                                    ->format($dateFormat . ' ' . $timeFormat);
                            },
                            $payment['secondaryPayments']
                        )
                    ) : '');
            }

            if (in_array('wcOrderId', $params['fields'], true)) {
                $secondaryOrderIds = !empty($payment['secondaryPayments']) ? array_filter(array_column($payment['secondaryPayments'], 'wcOrderId')) : null;
                $row[BackendStrings::get('wc_order_id_export')] =
                    $payment['wcOrderId'] . (!empty($secondaryOrderIds) ? ', ' . implode(', ', $secondaryOrderIds) : '');
            }

            if (in_array('couponCode', $params['fields'], true)) {
                $row[BackendStrings::get('coupon_code')] = ($payment['coupon'] ? $payment['coupon']['code'] : '');
            }


            if (in_array('paymentCreated', $params['fields'], true)) {
                $row[BackendStrings::get('payment_created')] =
                    DateTimeService::getCustomDateTimeObject($payment['created'])
                        ->format($dateFormat . ' ' . $timeFormat)
                    . (!empty($payment['secondaryPayments']) ? ', ' . implode(
                        ', ',
                        array_map(
                            function ($val) use ($dateFormat, $timeFormat) {
                                return DateTimeService::getCustomDateTimeObject($val['created'])
                                    ->format($dateFormat . ' ' . $timeFormat);
                            },
                            $payment['secondaryPayments']
                        )
                    ) : '');
            }

            $row = apply_filters('amelia_before_csv_export_payments', $row, $payment);

            $rows[] = $row;
        }

        $reportService->generateReport($rows, Entities::PAYMENTS, $params['delimiter']);

        $result->setAttachment(true);

        return $result;
    }
}
