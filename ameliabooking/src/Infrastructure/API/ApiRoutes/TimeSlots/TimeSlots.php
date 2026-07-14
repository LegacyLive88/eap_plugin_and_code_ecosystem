<?php

/**
 * @copyright © Melograno Ventures. All rights reserved.
 * @licence   See COPYING.md for license details.
 */

namespace AmeliaBooking\Infrastructure\API\ApiRoutes\TimeSlots;

use AmeliaBooking\Application\Controller\Booking\Appointment\GetTimeSlotsController;
use AmeliaBooking\Infrastructure\API\Api;
use AmeliaBooking\Infrastructure\Common\Container;
use Slim\App;

/**
 * Class TimeSlots
 *
 * @package AmeliaBooking\Infrastructure\API\ApiRoutes\TimeSlots
 */
class TimeSlots
{
    /**
     * @param App $app
     */
    public static function routes(App $app, Container $container)
    {
        $app->get(
            '/api/v1/slots',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new GetTimeSlotsController($container, true));
            }
        );
    }
}
