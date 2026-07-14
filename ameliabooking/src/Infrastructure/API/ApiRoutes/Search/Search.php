<?php

/**
 * @copyright © Melograno Ventures. All rights reserved.
 * @licence   See COPYING.md for license details.
 */

namespace AmeliaBooking\Infrastructure\API\ApiRoutes\Search;

use AmeliaBooking\Application\Controller\Search\GetSearchController;
use AmeliaBooking\Infrastructure\API\Api;
use AmeliaBooking\Infrastructure\Common\Container;
use Slim\App;

/**
 * Class Search
 *
 * @package AmeliaBooking\Infrastructure\API\ApiRoutes\Search
 */
class Search
{
    /**
     * @param App $app
     */
    public static function routes(App $app, Container $container)
    {
        $app->get(
            '/api/v1/search',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new GetSearchController($container, true));
            }
        );
    }
}
