<?php

/**
 * @copyright © Melograno Ventures. All rights reserved.
 * @licence   See COPYING.md for license details.
 */

namespace AmeliaBooking\Infrastructure\API\ApiRoutes\CustomField;

use AmeliaBooking\Application\Controller\CustomField\GetCustomFieldFileController;
use AmeliaBooking\Application\Controller\CustomField\GetCustomFieldsController;
use AmeliaBooking\Application\Controller\CustomField\AddCustomFieldController;
use AmeliaBooking\Application\Controller\CustomField\DeleteCustomFieldController;
use AmeliaBooking\Application\Controller\CustomField\UpdateCustomFieldController;
use AmeliaBooking\Application\Controller\CustomField\UpdateCustomFieldsPositionsController;
use AmeliaBooking\Infrastructure\API\Api;
use AmeliaBooking\Infrastructure\Common\Container;
use Slim\App;

/**
 * Class Category
 *
 * @package AmeliaBooking\Infrastructure\API\ApiRoutes\CustomField
 */
class CustomField
{
    /**
     * @param App $app
     */
    public static function routes(App $app, Container $container)
    {
        $app->get(
            '/api/v1/fields',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new GetCustomFieldsController($container, true));
            }
        );

        $app->get(
            '/api/v1/fields/{id:[0-9]+}/{bookingId:[0-9]+}/{index:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new GetCustomFieldFileController($container, true));
            }
        );

        $app->post(
            '/api/v1/fields',
            function ($request, $response, $args) use ($container) {
                $requestBody = $request->getParsedBody();
                if (empty($requestBody['position'])) {
                    $requestBody['position'] = 1;
                }
                if (empty($requestBody['label'])) {
                    $requestBody['label'] = '';
                }
                $requestBody['customField'] = $requestBody;
                $request = $request->withParsedBody($requestBody);
                Api::callMainFunction($request, $response, $args, new AddCustomFieldController($container, true));
            }
        );

        $app->post(
            '/api/v1/fields/delete/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new DeleteCustomFieldController($container, true));
            }
        );

        $app->post(
            '/api/v1/fields/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                $getCF = function () use ($container, $request, $args) {
                    return Api::getAllEntityFields($container->get('domain.customField.repository'), $request, $args);
                };
                Api::callMainFunction($request, $response, $args, new UpdateCustomFieldController($container, true), $getCF);
            }
        );

        $app->post(
            '/api/v1/fields/positions',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new UpdateCustomFieldsPositionsController($container, true));
            }
        );
    }
}
