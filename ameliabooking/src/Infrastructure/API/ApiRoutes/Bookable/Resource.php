<?php

/**
 * @copyright © Melograno Ventures. All rights reserved.
 * @licence   See COPYING.md for license details.
 */

namespace AmeliaBooking\Infrastructure\API\ApiRoutes\Bookable;

use AmeliaBooking\Application\Controller\Bookable\Resource\AddResourceController;
use AmeliaBooking\Application\Controller\Bookable\Resource\DeleteResourceController;
use AmeliaBooking\Application\Controller\Bookable\Resource\GetResourcesController;
use AmeliaBooking\Application\Controller\Bookable\Resource\UpdateResourceController;
use AmeliaBooking\Application\Controller\Bookable\Resource\UpdateResourceStatusController;
use AmeliaBooking\Infrastructure\API\Api;
use AmeliaBooking\Infrastructure\Common\Container;
use Slim\App;

/**
 * Class Resource
 *
 * @package AmeliaBooking\Infrastructure\API\ApiRoutes\Bookable
 */
class Resource
{
    /**
     * @param App $app
     */
    public static function routes(App $app, Container $container)
    {
        $app->get(
            '/api/v1/resources',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new GetResourcesController($container, true));
            }
        );

        $app->post(
            '/api/v1/resources',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new AddResourceController($container, true));
            }
        );

        $app->post(
            '/api/v1/resources/delete/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new DeleteResourceController($container, true));
            }
        );

        $app->post(
            '/api/v1/resources/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                $getResource = function () use ($container, $request, $args) {
                    return Api::getAllEntityFields($container->get('domain.bookable.resource.repository'), $request, $args);
                };
                Api::callMainFunction($request, $response, $args, new UpdateResourceController($container, true), $getResource);
            }
        );

        $app->post(
            '/api/v1/resources/status/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new UpdateResourceStatusController($container, true));
            }
        );
    }
}
