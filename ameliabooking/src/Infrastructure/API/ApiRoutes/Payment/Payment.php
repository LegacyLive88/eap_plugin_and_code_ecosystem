<?php

/**
 * @copyright © Melograno Ventures. All rights reserved.
 * @licence   See COPYING.md for license details.
 */

namespace AmeliaBooking\Infrastructure\API\ApiRoutes\Payment;

use AmeliaBooking\Application\Controller\Payment\AddPaymentController;
use AmeliaBooking\Application\Controller\Payment\DeletePaymentController;
use AmeliaBooking\Application\Controller\Payment\CalculatePaymentAmountController;
use AmeliaBooking\Application\Controller\Payment\GetPaymentController;
use AmeliaBooking\Application\Controller\Payment\GetPaymentsController;
use AmeliaBooking\Application\Controller\Payment\GetTransactionAmountController;
use AmeliaBooking\Application\Controller\Payment\PaymentLinkController;
use AmeliaBooking\Application\Controller\Payment\UpdatePaymentController;
use AmeliaBooking\Domain\Entity\Bookable\Service\PackageCustomer;
use AmeliaBooking\Domain\Entity\Booking\Appointment\CustomerBooking;
use AmeliaBooking\Domain\Entity\Entities;
use AmeliaBooking\Domain\Entity\User\AbstractUser;
use AmeliaBooking\Infrastructure\API\Api;
use AmeliaBooking\Infrastructure\Common\Container;
use AmeliaBooking\Infrastructure\Repository\Bookable\Service\PackageCustomerRepository;
use AmeliaBooking\Infrastructure\Repository\Bookable\Service\PackageRepository;
use AmeliaBooking\Infrastructure\Repository\Bookable\Service\ServiceRepository;
use AmeliaBooking\Infrastructure\Repository\Booking\Appointment\AppointmentRepository;
use AmeliaBooking\Infrastructure\Repository\Booking\Appointment\CustomerBookingRepository;
use AmeliaBooking\Infrastructure\Repository\Booking\Event\EventRepository;
use AmeliaBooking\Infrastructure\Repository\Payment\PaymentRepository;
use AmeliaBooking\Infrastructure\Repository\User\UserRepository;
use Slim\App;
use Slim\Http\Request;

/**
 * Class Payment
 *
 * @package AmeliaBooking\Infrastructure\API\ApiRoutes\Payment
 */
class Payment
{
    /**
     * @param App $app
     */
    public static function routes(App $app, Container $container)
    {
        $app->get(
            '/api/v1/payments',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new GetPaymentsController($container, true));
            }
        );

        $app->get(
            '/api/v1/payments/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new GetPaymentController($container, true));
            }
        );

        $app->post(
            '/api/v1/payments',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new AddPaymentController($container, true));
            }
        );

        $app->post(
            '/api/v1/payments/delete/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new DeletePaymentController($container, true));
            }
        );

        $app->post(
            '/api/v1/payments/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                $getPayment = function () use ($container, $request, $args) {
                    return Api::getAllEntityFields($container->get('domain.payment.repository'), $request, $args);
                };

                Api::callMainFunction($request, $response, $args, new UpdatePaymentController($container, true), $getPayment);
            }
        );

        $app->post(
            '/api/v1/payments/amount',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new CalculatePaymentAmountController($container, true));
            }
        );

        $app->get(
            '/api/v1/payments/transaction/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new GetTransactionAmountController($container, true));
            }
        );

        $app->post(
            '/api/v1/payments/link',
            function ($request, $response, $args) use ($container) {
                $paymentLinkFields = function () use ($container, $request, $args) {
                    return self::getFieldsForPaymentLink($container, $request, $args);
                };

                Api::callMainFunction($request, $response, $args, new PaymentLinkController($container, true), $paymentLinkFields);
            }
        );
    }

    private static function getFieldsForPaymentLink(Container $container, Request $request, array $args)
    {
        $requestBody = $request->getParsedBody();

        $type = $requestBody['data']['type'];

        $bookingId = $requestBody['data']['bookingId'];

        $packageCustomerId = $requestBody['data']['packageCustomerId'];

        switch ($type) {
            case Entities::APPOINTMENT:
                /** @var AppointmentRepository $appRepository */
                $appRepository =  $container->get('domain.booking.appointment.repository');
                /** @var UserRepository $userRepository */
                $userRepository =  $container->get('domain.users.repository');
                /** @var CustomerBookingRepository $bookingRepository */
                $bookingRepository = $container->get('domain.booking.customerBooking.repository');
                /** @var ServiceRepository $serviceRepository */
                $serviceRepository = $container->get('domain.bookable.service.repository');

                $appointment = $appRepository->getByBookingId($bookingId);
                $requestBody['data']['appointment'] = $appointment->toArray();

                $service = $serviceRepository->getById($appointment->getServiceId()->getValue());
                $requestBody['data']['service'] = $service->toArray();

                /** @var CustomerBooking $booking */
                $booking = $bookingRepository->getById($bookingId);
                $requestBody['data']['booking'] = $booking->toArray();

                /** @var AbstractUser $customer */
                $customer = $userRepository->getById($booking->getCustomerId()->getValue());
                $requestBody['data']['customer'] = $customer->toArray();

                $requestBody['data']['paymentId'] = $booking->getPayments()->toArray()[0]['id'];

                $requestBody['data']['recurring'] = null;

                break;
            case Entities::EVENT:
                /** @var EventRepository $eventRepository */
                $eventRepository =  $container->get('domain.booking.event.repository');
                /** @var UserRepository $userRepository */
                $userRepository =  $container->get('domain.users.repository');
                /** @var CustomerBookingRepository $bookingRepository */
                $bookingRepository = $container->get('domain.booking.customerBooking.repository');

                $event = $eventRepository->getByBookingId($bookingId);
                $requestBody['data']['event'] = $event->toArray();

                /** @var CustomerBooking $booking */
                $booking = $bookingRepository->getById($bookingId);
                $requestBody['data']['booking'] = $booking->toArray();

                /** @var AbstractUser $customer */
                $customer = $userRepository->getById($booking->getCustomerId()->getValue());
                $requestBody['data']['customer'] = $customer->toArray();

                $requestBody['data']['paymentId'] = $booking->getPayments()->toArray()[0]['id'];

                break;
            case Entities::PACKAGE:
                /** @var PackageRepository $packageRepository */
                $packageRepository =  $container->get('domain.bookable.package.repository');
                /** @var UserRepository $userRepository */
                $userRepository =  $container->get('domain.users.repository');
                /** @var PackageCustomerRepository $packageCustomerRepository */
                $packageCustomerRepository = $container->get('domain.bookable.packageCustomer.repository');
                /** @var PaymentRepository $paymentRepository */
                $paymentRepository = $container->get('domain.payment.repository');

                /** @var PackageCustomer $packageCustomer */
                $packageCustomer = $packageCustomerRepository->getById($packageCustomerId);
                $requestBody['data']['booking'] = null;

                $package = $packageRepository->getById($packageCustomer->getPackageId()->getValue());
                $requestBody['data']['package'] = $package->toArray();

                /** @var AbstractUser $customer */
                $customer = $userRepository->getById($packageCustomer->getCustomerId()->getValue());
                $requestBody['data']['customer'] = $customer->toArray();

                $payments = $paymentRepository->getByCriteria(['packageCustomerId' => $packageCustomerId]);
                $requestBody['data']['paymentId'] = $payments->toArray()[0]['id'];

                $requestBody['data']['packageReservations'] = [];
                break;
        }

        return $request->withParsedBody($requestBody);
    }
}
