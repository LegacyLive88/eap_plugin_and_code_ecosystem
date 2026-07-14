<?php

/**
 * @copyright © Melograno Ventures. All rights reserved.
 * @licence   See COPYING.md for license details.
 */

namespace AmeliaBooking\Infrastructure\API\ApiRoutes\Booking\Appointment;

use AmeliaBooking\Application\Controller\Booking\Appointment\AddAppointmentController;
use AmeliaBooking\Application\Controller\Booking\Appointment\DeleteAppointmentController;
use AmeliaBooking\Application\Controller\Booking\Appointment\GetAppointmentController;
use AmeliaBooking\Application\Controller\Booking\Appointment\GetAppointmentsController;
use AmeliaBooking\Application\Controller\Booking\Appointment\UpdateAppointmentController;
use AmeliaBooking\Application\Controller\Booking\Appointment\UpdateAppointmentStatusController;
use AmeliaBooking\Application\Controller\Booking\Appointment\UpdateAppointmentTimeController;
use AmeliaBooking\Domain\Entity\Entities;
use AmeliaBooking\Infrastructure\Common\Container;
use AmeliaBooking\Infrastructure\Repository\Booking\Appointment\AppointmentRepository;
use AmeliaBooking\Infrastructure\API\Api;
use Slim\App;
use Slim\Http\Request;

/**
 * Class Appointment
 *
 * @package AmeliaBooking\Routes\API\ApiRoutes\Booking\Appointment
 */
class Appointment
{
    /**
     * @param App $app
     *
     * @throws \InvalidArgumentException
     */
    public static function routes(App $app, Container $container)
    {
        $app->get(
            '/api/v1/appointments',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new GetAppointmentsController($container, true));
            }
        );

        $app->get(
            '/api/v1/appointments/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new GetAppointmentController($container, true));
            }
        );

        $app->post(
            '/api/v1/appointments',
            function ($request, $response, $args) use ($container) {
                $requestBody = $request->getParsedBody();

                $requestBodyArray = !empty($requestBody[0]) ? $requestBody : [$requestBody];
                foreach ($requestBodyArray as $requestBodyEntry) {
                    if (empty($requestBodyEntry['notifyParticipants'])) {
                        $requestBodyEntry['notifyParticipants'] = 0;
                    }
                    $request = $request->withParsedBody($requestBodyEntry);
                    Api::callMainFunction($request, $response, $args, new AddAppointmentController($container, true));
                }
            }
        );

        $app->post(
            '/api/v1/appointments/delete/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new DeleteAppointmentController($container, true));
            }
        );

        $app->post(
            '/api/v1/appointments/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                $getAppointment = function () use ($container, $request, $args) {
                    return self::getAllEntityFields($container, $request, $args);
                };

                Api::callMainFunction($request, $response, $args, new UpdateAppointmentController($container, true), $getAppointment);
            }
        );

        $app->post(
            '/api/v1/appointments/status/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new UpdateAppointmentStatusController($container, true));
            }
        );

        $app->post(
            '/api/v1/appointments/time/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new UpdateAppointmentTimeController($container, true));
            }
        );
    }


    public static function getAllEntityFields(Container $container, Request $request, array $args)
    {
        /** @var AppointmentRepository $repository */
        $repository = $container->get('domain.booking.appointment.repository');

        $oldRequestBody = $request->getParsedBody();
        $entity         = $repository->getById($args['id']);
        $oldEntity      = $entity->toArray();
        $requestBody    = array_merge($oldEntity, $oldRequestBody);
        if (!empty($oldRequestBody['bookings'])) {
            $existingBookings = $oldEntity['bookings'] ?? [];
            $incomingBookings = $oldRequestBody['bookings'];

            $indexedBookings = [];
            foreach ($existingBookings as $booking) {
                if (!empty($booking['id'])) {
                    $indexedBookings[$booking['id']] = $booking;
                }
            }

            foreach ($incomingBookings as $incomingBooking) {
                if (!empty($incomingBooking['id']) && isset($indexedBookings[$incomingBooking['id']])) {
                    $indexedBookings[$incomingBooking['id']] = array_merge(
                        $indexedBookings[$incomingBooking['id']],
                        $incomingBooking
                    );
                } else {
                    $indexedBookings[] = $incomingBooking;
                }
            }

            $requestBody['bookings'] = array_values($indexedBookings);
        }

        return $request->withParsedBody($requestBody);
    }
}
