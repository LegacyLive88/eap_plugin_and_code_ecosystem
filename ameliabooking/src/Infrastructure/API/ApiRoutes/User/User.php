<?php

/**
 * @copyright © Melograno Ventures. All rights reserved.
 * @licence   See COPYING.md for license details.
 */

namespace AmeliaBooking\Infrastructure\API\ApiRoutes\User;

use AmeliaBooking\Application\Controller\User\Customer\ReauthorizeController;
use AmeliaBooking\Application\Controller\User\Customer\GetCustomersController;
use AmeliaBooking\Application\Controller\User\Customer\GetCustomerController;
use AmeliaBooking\Application\Controller\User\Customer\AddCustomerController;
use AmeliaBooking\Application\Controller\User\Customer\UpdateCustomerController;
use AmeliaBooking\Application\Controller\User\DeleteUserController;
use AmeliaBooking\Application\Controller\User\GetCurrentUserController;
use AmeliaBooking\Application\Controller\User\GetUserDeleteEffectController;
use AmeliaBooking\Application\Controller\User\GetWPUsersController;
use AmeliaBooking\Application\Controller\User\LoginCabinetController;
use AmeliaBooking\Application\Controller\User\LogoutCabinetController;
use AmeliaBooking\Application\Controller\User\Provider\UpdateProviderStatusController;
use AmeliaBooking\Application\Controller\User\Provider\GetProviderController;
use AmeliaBooking\Application\Controller\User\Provider\GetProvidersController;
use AmeliaBooking\Application\Controller\User\Provider\AddProviderController;
use AmeliaBooking\Application\Controller\User\Provider\UpdateProviderController;
use AmeliaBooking\Domain\Entity\Entities;
use AmeliaBooking\Infrastructure\API\Api;
use AmeliaBooking\Infrastructure\Common\Container;
use AmeliaBooking\Infrastructure\Repository\User\ProviderRepository;
use Slim\App;
use Slim\Http\Request;

/**
 * Class User
 *
 * @package AmeliaBooking\Infrastructure\API\ApiRoutes\User
 */
class User
{
    /**
     * @param App $app
     */
    public static function routes(App $app, Container $container)
    {
        $app->get(
            '/api/v1/users/current',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new GetCurrentUserController($container, true));
            }
        );

        $app->get(
            '/api/v1/users/wp-users',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new GetWPUsersController($container, true));
            }
        );

        $app->post(
            '/api/v1/users/authenticate',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new LoginCabinetController($container, true));
            }
        );

        $app->post(
            '/api/v1/users/logout',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new LogoutCabinetController($container, true));
            }
        );

        // Customers
        $app->get(
            '/api/v1/users/customers/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new GetCustomerController($container, true));
            }
        );

        $app->get(
            '/api/v1/users/customers',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new GetCustomersController($container, true));
            }
        );

        $app->post(
            '/api/v1/users/customers',
            function ($request, $response, $args) use ($container) {
                $requestBody = $request->getParsedBody();

                $requestBody['type'] = Entities::CUSTOMER;
                if (empty($requestBody['email'])) {
                    $requestBody['email'] = '';
                }
                $request = $request->withParsedBody($requestBody);
                Api::callMainFunction($request, $response, $args, new AddCustomerController($container, true));
            }
        );

        $app->post(
            '/api/v1/users/customers/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                $getCustomer = function () use ($container, $request, $args) {
                    return Api::getAllEntityFields($container->get('domain.users.repository'), $request, $args);
                };
                Api::callMainFunction($request, $response, $args, new UpdateCustomerController($container, true), $getCustomer);
            }
        );

        $app->post(
            '/api/v1/users/customers/delete/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new DeleteUserController($container, true));
            }
        );

        $app->get(
            '/api/v1/users/customers/effect/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new GetUserDeleteEffectController($container, true));
            }
        );

        $app->post(
            '/api/v1/users/customers/reauthorize',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new ReauthorizeController($container, true));
            }
        );

        // Providers
        $app->get(
            '/api/v1/users/providers/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new GetProviderController($container, true));
            }
        );

        $app->get(
            '/api/v1/users/providers',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new GetProvidersController($container, true));
            }
        );

        $app->post(
            '/api/v1/users/providers',
            function ($request, $response, $args) use ($container) {
                $requestBody         = $request->getParsedBody();
                $requestBody['type'] = Entities::PROVIDER;
                $request = $request->withParsedBody($requestBody);
                Api::callMainFunction($request, $response, $args, new AddProviderController($container, true));
            }
        );

        $app->post(
            '/api/v1/users/providers/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                $getEmployee = function () use ($container, $request, $args) {
                    return self::getEmployeeServices($container, $request, $args);
                };

                Api::callMainFunction($request, $response, $args, new UpdateProviderController($container, true), $getEmployee);
            }
        );

        $app->post(
            '/api/v1/users/providers/status/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new UpdateProviderStatusController($container, true));
            }
        );

        $app->post(
            '/api/v1/users/providers/delete/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new DeleteUserController($container, true));
            }
        );

        $app->get(
            '/api/v1/users/providers/effect/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new GetUserDeleteEffectController($container, true));
            }
        );
    }


    public static function getEmployeeServices(Container $container, Request $request, array $args)
    {
        /** @var ProviderRepository $repository */
        $repository = $container->get('domain.users.providers.repository');

        $oldRequestBody = $request->getParsedBody();
        if (!isset($oldRequestBody['serviceList'])) {
            $entity = $repository->getWithSchedule(['providers' => [$args['id']]]);
            if ($entity->length() > 0) {
                $oldEntity = $entity->toArray()[0];
                $oldRequestBody['serviceList'] = $oldEntity['serviceList'];
            }
        }

        return $request->withParsedBody($oldRequestBody);
    }
}
