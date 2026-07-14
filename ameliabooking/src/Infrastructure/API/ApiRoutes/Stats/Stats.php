<?php

/**
 * @copyright © Melograno Ventures. All rights reserved.
 * @licence   See COPYING.md for license details.
 */

namespace AmeliaBooking\Infrastructure\API\ApiRoutes\Stats;

use AmeliaBooking\Application\Controller\Stats\GetStatsController;
use AmeliaBooking\Infrastructure\API\Api;
use AmeliaBooking\Infrastructure\Common\Container;
use Slim\App;

/**
 * Class Stats
 *
 * @package AmeliaBooking\Infrastructure\API\ApiRoutes\Stats
 */
class Stats
{
    /**
     * @param App $app
     */
    public static function routes(App $app, Container $container)
    {
        $app->get(
            '/api/v1/stats',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new GetStatsController($container, true));
            }
        );
    }
}
