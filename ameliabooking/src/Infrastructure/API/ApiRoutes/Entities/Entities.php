<?php

/**
 * @copyright © Melograno Ventures. All rights reserved.
 * @licence   See COPYING.md for license details.
 */

namespace AmeliaBooking\Infrastructure\API\ApiRoutes\Entities;

use AmeliaBooking\Application\Controller\Entities\GetEntitiesController;
use AmeliaBooking\Infrastructure\API\Api;
use AmeliaBooking\Infrastructure\Common\Container;
use Slim\App;

/**
 * Class Entities
 *
 * @package AmeliaBooking\Infrastructure\API\ApiRoutes\Entities
 */
class Entities
{
    /**
     * @param App $app
     * @param Container $container
     */
    public static function routes(App $app, Container $container)
    {
        $app->get(
            '/api/v1/entities',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new GetEntitiesController($container, true));
            }
        );
    }
}
