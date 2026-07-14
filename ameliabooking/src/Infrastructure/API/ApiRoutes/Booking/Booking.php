<?php

/**
 * @copyright Â© Melograno Ventures. All rights reserved.
 * @licence   See COPYING.md for license details.
 */

namespace AmeliaBooking\Infrastructure\API\ApiRoutes\Booking;

use AmeliaBooking\Application\Controller\Booking\Appointment\DeleteBookingController;
use AmeliaBooking\Application\Controller\Booking\Appointment\ReassignBookingController;
use AmeliaBooking\Application\Controller\Booking\Appointment\SuccessfulBookingController;
use AmeliaBooking\Application\Controller\Booking\Appointment\AddBookingController;
use AmeliaBooking\Application\Controller\Booking\Appointment\UpdateBookingStatusController;
use AmeliaBooking\Application\Controller\Booking\Event\UpdateEventBookingController;
use AmeliaBooking\Infrastructure\Common\Container;
use AmeliaBooking\Infrastructure\API\Api;
use AmeliaBooking\Infrastructure\Repository\Booking\Appointment\CustomerBookingRepository;
use Slim\App;

/**
 * Class Booking
 *
 * @package AmeliaBooking\Infrastructure\API\ApiRoutes\Booking
 */
class Booking
{
    /**
     * @param App $app
     *
     * @throws \InvalidArgumentException
     */
    public static function routes(App $app, Container $container)
    {
        $app->post(
            '/api/v1/bookings/cancel/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                /** @var CustomerBookingRepository $bookingRepository */
                $bookingRepository = $container->get('domain.booking.customerBooking.repository');

                $booking = $bookingRepository->getById((int)$args['id']);

                if ($booking && !$booking->getAppointmentId()) {
                    $requestBody = [
                        'bookings' => [
                            ['status' => 'canceled']
                        ]
                    ];

                    $request = $request->withParsedBody($requestBody);
                    Api::callMainFunction($request, $response, $args, new UpdateEventBookingController($container, true));
                } else {
                    $requestBody = [
                        'status' => 'canceled'
                    ];

                    $request = $request->withParsedBody($requestBody);
                    Api::callMainFunction($request, $response, $args, new UpdateBookingStatusController($container, true));
                }
            }
        );

        $app->post(
            '/api/v1/bookings/delete/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new DeleteBookingController($container, true));
            }
        );

        $app->post(
            '/api/v1/bookings/reassign/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new ReassignBookingController($container, true));
            }
        );

        $app->post(
            '/api/v1/bookings',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new AddBookingController($container, true));
            }
        );

        $app->post(
            '/api/v1/bookings/success/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                $addCustomer = function () use ($container, $request) {
                    $requestBody = $request->getParsedBody();
                    return Api::getAllEntityFields($container->get('domain.users.repository'), $request, ['id' => $requestBody['customerId']], 'customer');
                };
                Api::callMainFunction($request, $response, $args, new SuccessfulBookingController($container, true), $addCustomer);
            }
        );
    }
}
