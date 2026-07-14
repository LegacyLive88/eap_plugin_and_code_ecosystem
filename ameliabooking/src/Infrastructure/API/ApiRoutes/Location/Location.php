<?php

/**
 * @copyright © Melograno Ventures. All rights reserved.
 * @licence   See COPYING.md for license details.
 */

namespace AmeliaBooking\Infrastructure\API\ApiRoutes\Location;

use AmeliaBooking\Application\Controller\Location\AddLocationController;
use AmeliaBooking\Application\Controller\Location\DeleteLocationController;
use AmeliaBooking\Application\Controller\Location\GetLocationController;
use AmeliaBooking\Application\Controller\Location\GetLocationsController;
use AmeliaBooking\Application\Controller\Location\UpdateLocationController;
use AmeliaBooking\Application\Controller\Location\UpdateLocationStatusController;
use AmeliaBooking\Application\Controller\Location\GetLocationDeleteEffectController;
use AmeliaBooking\Domain\ValueObjects\String\Status;
use AmeliaBooking\Infrastructure\API\Api;
use AmeliaBooking\Infrastructure\Common\Container;
use Slim\App;

/**
 * Class Location
 *
 * @package AmeliaBooking\Infrastructure\API\ApiRoutes\Location
 */
class Location
{
    /**
     * @param App $app
     */
    public static function routes(App $app, Container $container)
    {
        $app->get(
            '/api/v1/locations/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new GetLocationController($container, true));
            }
        );

        $app->get(
            '/api/v1/locations',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new GetLocationsController($container, true));
            }
        );

        $app->post(
            '/api/v1/locations',
            function ($request, $response, $args) use ($container) {
                $locationData = $request->getParsedBody();
                if (empty($locationData['latitude'])) {
                    $locationData['latitude'] = 40.7484405;
                }
                if (empty($locationData['longitude'])) {
                    $locationData['longitude'] = -73.9878531;
                }
                if (empty($locationData['phone'])) {
                    $locationData['phone'] = '';
                }
                if (empty($locationData['address'])) {
                    $locationData['address'] = '';
                }
                if (empty($locationData['status'])) {
                    $locationData['status'] = Status::VISIBLE;
                }

                $request = $request->withParsedBody($locationData);
                Api::callMainFunction($request, $response, $args, new AddLocationController($container, true));
            }
        );

        $app->post(
            '/api/v1/locations/delete/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new DeleteLocationController($container, true));
            }
        );

        $app->post(
            '/api/v1/locations/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                $getLocation = function () use ($container, $request, $args) {
                    return Api::getAllEntityFields($container->get('domain.locations.repository'), $request, $args);
                };
                Api::callMainFunction($request, $response, $args, new UpdateLocationController($container, true), $getLocation);
            }
        );

        $app->post(
            '/api/v1/locations/status/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new UpdateLocationStatusController($container, true));
            }
        );

        $app->get(
            '/api/v1/locations/effect/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new GetLocationDeleteEffectController($container, true));
            }
        );
    }
}
