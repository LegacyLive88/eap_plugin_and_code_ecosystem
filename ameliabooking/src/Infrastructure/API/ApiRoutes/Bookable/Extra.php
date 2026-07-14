<?php

/**
 * @copyright © Melograno Ventures. All rights reserved.
 * @licence   See COPYING.md for license details.
 */

namespace AmeliaBooking\Infrastructure\API\ApiRoutes\Bookable;

use AmeliaBooking\Application\Controller\Bookable\Extra\AddExtraController;
use AmeliaBooking\Application\Controller\Bookable\Extra\DeleteExtraController;
use AmeliaBooking\Application\Controller\Bookable\Extra\GetExtraController;
use AmeliaBooking\Application\Controller\Bookable\Extra\GetExtrasController;
use AmeliaBooking\Application\Controller\Bookable\Extra\UpdateExtraController;
use AmeliaBooking\Infrastructure\API\Api;
use AmeliaBooking\Infrastructure\Common\Container;
use Slim\App;

/**
 * Class Extra
 *
 * @package AmeliaBooking\Infrastructure\API\ApiRoutes\Bookable
 */
class Extra
{
    /**
     * @param App $app
     */
    public static function routes(App $app, Container $container)
    {
        $app->get(
            '/api/v1/extras',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new GetExtrasController($container, true));
            }
        );

        $app->get(
            '/api/v1/extras/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new GetExtraController($container, true));
            }
        );


        $app->post(
            '/api/v1/extras',
            function ($request, $response, $args) use ($container) {
                $extraData = $request->getParsedBody();
                if (empty($extraData['position'])) {
                    $extraData['position'] = 1;
                }
                if (empty($extraData['maxQuantity'])) {
                    $extraData['maxQuantity'] = 1;
                }

                $request = $request->withParsedBody($extraData);
                Api::callMainFunction($request, $response, $args, new AddExtraController($container, true));
            }
        );

        $app->post(
            '/api/v1/extras/delete/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new DeleteExtraController($container, true));
            }
        );

        $app->post(
            '/api/v1/extras/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                $getExtra = function () use ($container, $request, $args) {
                    return Api::getAllEntityFields($container->get('domain.bookable.extra.repository'), $request, $args);
                };
                Api::callMainFunction($request, $response, $args, new UpdateExtraController($container, true), $getExtra);
            }
        );
    }
}
