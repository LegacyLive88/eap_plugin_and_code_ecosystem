<?php

/**
 * @copyright © Melograno Ventures. All rights reserved.
 * @licence   See COPYING.md for license details.
 */

namespace AmeliaBooking\Infrastructure\API\ApiRoutes\Bookable;

use AmeliaBooking\Application\Controller\Bookable\Service\AddServiceController;
use AmeliaBooking\Application\Controller\Bookable\Service\DeleteServiceController;
use AmeliaBooking\Application\Controller\Bookable\Service\GetServiceController;
use AmeliaBooking\Application\Controller\Bookable\Service\GetServiceDeleteEffectController;
use AmeliaBooking\Application\Controller\Bookable\Service\GetServicesController;
use AmeliaBooking\Application\Controller\Bookable\Service\UpdateServiceController;
use AmeliaBooking\Application\Controller\Bookable\Service\UpdateServicesPositionsController;
use AmeliaBooking\Application\Controller\Bookable\Service\UpdateServiceStatusController;
use AmeliaBooking\Infrastructure\API\Api;
use AmeliaBooking\Infrastructure\Common\Container;
use AmeliaBooking\Infrastructure\Repository\AbstractRepository;
use AmeliaBooking\Infrastructure\Repository\Bookable\Service\ServiceRepository;
use Slim\App;
use Slim\Http\Request;

/**
 * Class Service
 *
 * @package AmeliaBooking\Infrastructure\API\ApiRoutes\Bookable
 */
class Service
{
    /**
     * @param App $app
     */
    public static function routes(App $app, Container $container)
    {
        $app->get(
            '/api/v1/services',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new GetServicesController($container, true));
            }
        );

        $app->get(
            '/api/v1/services/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new GetServiceController($container, true));
            }
        );

        $app->post(
            '/api/v1/services',
            function ($request, $response, $args) use ($container) {
                $serviceData = $request->getParsedBody();
                if (empty($serviceData['color'])) {
                    $serviceData['color'] = '#1788FB';
                }
                if (empty($serviceData['status'])) {
                    $serviceData['status'] = 'visible';
                }
                if (empty($serviceData['description'])) {
                    $serviceData['description'] = '';
                }
                if (empty($serviceData['depositPayment'])) {
                    $serviceData['depositPayment'] = 'disabled';
                }
                if (empty($serviceData['recurringCycle'])) {
                    $serviceData['recurringCycle'] = 'disabled';
                }
                if (empty($serviceData['recurringSub'])) {
                    $serviceData['recurringSub'] = 'future';
                }
                if (empty($serviceData['recurringPayment'])) {
                    $serviceData['recurringPayment'] = 0;
                }
                if (empty($serviceData['position'])) {
                    $serviceData['position'] = 1;
                }
                if (!isset($serviceData['deposit'])) {
                    $serviceData['deposit'] = 0;
                }

                $request = $request->withParsedBody($serviceData);
                Api::callMainFunction($request, $response, $args, new AddServiceController($container, true));
            }
        );

        $app->post(
            '/api/v1/services/delete/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new DeleteServiceController($container, true));
            }
        );

        $app->post(
            '/api/v1/services/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                $getService = function () use ($container, $request, $args) {
                    return self::getAllServiceFields($container, $request, $args);
                };
                Api::callMainFunction($request, $response, $args, new UpdateServiceController($container, true), $getService);
            }
        );

        $app->get(
            '/api/v1/services/effect/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new GetServiceDeleteEffectController($container, true));
            }
        );

        $app->post(
            '/api/v1/services/status/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new UpdateServiceStatusController($container, true));
            }
        );

        $app->post(
            '/api/v1/services/positions',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new UpdateServicesPositionsController($container, true));
            }
        );
    }

    public static function getAllServiceFields(Container $container, Request $request, array $args)
    {
        /** @var ServiceRepository $repository */
        $repository  = $container->get('domain.bookable.service.repository');
        $requestBody = $request->getParsedBody();
        $entity      = $repository->getByCriteria(['services' => [$args['id']]]);
        $oldEntity   = count($entity->toArray()) > 0 ? $entity->toArray()[0] : null;
        if ($oldEntity) {
            $requestBody = array_merge($oldEntity, $requestBody);
            return $request->withParsedBody($requestBody);
        }
        return $request;
    }
}
