<?php

/**
 * @copyright © Melograno Ventures. All rights reserved.
 * @licence   See COPYING.md for license details.
 */

namespace AmeliaBooking\Infrastructure\API\ApiRoutes\Booking\Event;

use AmeliaBooking\Application\Controller\Booking\Event\AddEventController;
use AmeliaBooking\Application\Controller\Booking\Event\DeleteEventBookingController;
use AmeliaBooking\Application\Controller\Booking\Event\DeleteEventController;
use AmeliaBooking\Application\Controller\Booking\Event\GetEventController;
use AmeliaBooking\Application\Controller\Booking\Event\GetEventsController;
use AmeliaBooking\Application\Controller\Booking\Event\GetCalendarEventsController;
use AmeliaBooking\Application\Controller\Booking\Event\UpdateEventBookingController;
use AmeliaBooking\Application\Controller\Booking\Event\UpdateEventController;
use AmeliaBooking\Application\Controller\Booking\Event\UpdateEventStatusController;
use AmeliaBooking\Domain\ValueObjects\String\DepositType;
use AmeliaBooking\Infrastructure\API\Api;
use AmeliaBooking\Infrastructure\Common\Container;
use AmeliaBooking\Infrastructure\Repository\User\ProviderRepository;
use Slim\App;
use Slim\Http\Request;

/**
 * Class Event
 *
 * @package meliaBooking\Routes\API\ApiRoutes\Booking\Event
 */
class Event
{
    /**
     * @param App $app
     *
     * @throws \InvalidArgumentException
     */
    public static function routes(App $app, Container $container)
    {
        $app->get(
            '/api/v1/events',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new GetEventsController($container, true));
            }
        );

        $app->get(
            '/api/v1/events/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new GetEventController($container, true));
            }
        );

        $app->post(
            '/api/v1/events',
            function ($request, $response, $args) use ($container) {
                $eventData = $request->getParsedBody();
                if (empty($eventData['bookingOpensRec'])) {
                    $eventData['bookingOpensRec'] = 'same';
                }
                if (empty($eventData['bookingClosesRec'])) {
                    $eventData['bookingClosesRec'] = 'same';
                }
                if (empty($eventData['description'])) {
                    $eventData['description'] = '';
                }
                if (empty($eventData['depositPayment'])) {
                    $eventData['depositPayment'] = DepositType::DISABLED;
                }
                if (empty($eventData['deposit'])) {
                    $eventData['deposit'] = 0;
                }

                $request = $request->withParsedBody($eventData);
                Api::callMainFunction($request, $response, $args, new AddEventController($container, true));
            }
        );

        $app->post(
            '/api/v1/events/delete/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                $eventData = $request->getParsedBody();
                if (empty($eventData['applyGlobally'])) {
                    $eventData['applyGlobally'] = false;
                }
                $request = $request->withParsedBody($eventData);
                Api::callMainFunction($request, $response, $args, new DeleteEventController($container, true));
            }
        );

        $app->post(
            '/api/v1/events/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                $eventData = $request->getParsedBody();
                if (empty($eventData['applyGlobally'])) {
                    $eventData['applyGlobally'] = false;
                }
                $request = $request->withParsedBody($eventData);

                $getEvent = function () use ($container, $request, $args) {
                    return Api::getAllEntityFields($container->get('domain.booking.event.repository'), $request, $args);
                };

                Api::callMainFunction($request, $response, $args, new UpdateEventController($container, true), $getEvent);
            }
        );

        $app->post(
            '/api/v1/events/bookings/delete/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new DeleteEventBookingController($container, true));
            }
        );

        $app->post(
            '/api/v1/events/bookings/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new UpdateEventBookingController($container, true));
            }
        );

        $app->post(
            '/api/v1/events/status/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new UpdateEventStatusController($container, true));
            }
        );

        $app->post(
            '/api/v1/events/calendar',
            function ($request, $response, $args) use ($container) {
                $getProvider = function () use ($container, $request) {
                    return self::getProvider($container, $request);
                };
                Api::callMainFunction($request, $response, $args, new GetCalendarEventsController($container, true), $getProvider);
            }
        );
    }

    public static function getProvider(Container $container, Request $request)
    {
        /** @var ProviderRepository $providerRepository */
        $providerRepository = $container->get('domain.users.providers.repository');

        $requestBody = $request->getParsedBody();
        foreach ($requestBody['providers'] as &$provider) {
            $entity   = $providerRepository->getById($provider['id']);
            $provider = $entity->toArray();
        }
        return $request->withParsedBody($requestBody);
    }
}
