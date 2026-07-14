<?php

/**
 * @copyright © Melograno Ventures. All rights reserved.
 * @licence   See COPYING.md for license details.
 */

namespace AmeliaBooking\Infrastructure\API\ApiRoutes\Bookable;

use AmeliaBooking\Application\Controller\Bookable\Package\AddPackageController;
use AmeliaBooking\Application\Controller\Bookable\Package\AddPackageCustomerController;
use AmeliaBooking\Application\Controller\Bookable\Package\DeletePackageController;
use AmeliaBooking\Application\Controller\Bookable\Package\DeletePackageCustomerController;
use AmeliaBooking\Application\Controller\Bookable\Package\GetPackageController;
use AmeliaBooking\Application\Controller\Bookable\Package\GetPackageDeleteEffectController;
use AmeliaBooking\Application\Controller\Bookable\Package\GetPackagesController;
use AmeliaBooking\Application\Controller\Bookable\Package\UpdatePackageController;
use AmeliaBooking\Application\Controller\Bookable\Package\UpdatePackageCustomerController;
use AmeliaBooking\Application\Controller\Bookable\Package\UpdatePackagesPositionsController;
use AmeliaBooking\Application\Controller\Bookable\Package\UpdatePackageStatusController;
use AmeliaBooking\Application\Controller\Booking\Appointment\GetAppointmentsController;
use AmeliaBooking\Domain\ValueObjects\String\Status;
use AmeliaBooking\Infrastructure\API\Api;
use AmeliaBooking\Infrastructure\Common\Container;
use Slim\App;
use Slim\Http\Response;

/**
 * Class Package
 *
 * @package AmeliaBooking\Infrastructure\API\ApiRoutes\Bookable
 */
class Package
{
    /**
     * @param App $app
     */
    public static function routes(App $app, Container $container)
    {
        $app->get(
            '/api/v1/packages',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new GetPackagesController($container, true));
            }
        );

        $app->get(
            '/api/v1/packages/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new GetPackageController($container, true));
            }
        );

        $app->post(
            '/api/v1/packages',
            function ($request, $response, $args) use ($container) {
                $requestBody = $request->getParsedBody();
                if (empty($requestBody['calculatedPrice'])) {
                    $requestBody['calculatedPrice'] = true;
                }
                if (empty($requestBody['color'])) {
                    $requestBody['color'] = '#1788FB';
                }
                if (empty($requestBody['status'])) {
                    $requestBody['status'] = Status::VISIBLE;
                }
                if (empty($requestBody['discount'])) {
                    $requestBody['discount'] = 0;
                }
                if (empty($requestBody['quantity'])) {
                    $requestBody['quantity'] = 1;
                }
                if (empty($requestBody['depositPayment'])) {
                    $requestBody['depositPayment'] = 'disabled';
                }
                if (!isset($requestBody['deposit'])) {
                    $requestBody['deposit'] = 0;
                }
                if (empty($requestBody['position'])) {
                    $requestBody['position'] = 1;
                }
                if (empty($requestBody['position'])) {
                    $requestBody['position'] = 1;
                }
                if (empty($requestBody['description'])) {
                    $requestBody['description'] = '';
                }

                $request = $request->withParsedBody($requestBody);
                Api::callMainFunction($request, $response, $args, new AddPackageController($container, true));
            }
        );

        $app->post(
            '/api/v1/packages/delete/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new DeletePackageController($container, true));
            }
        );

        $app->post(
            '/api/v1/packages/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                $getPackage = function () use ($container, $request, $args) {
                    return Api::getAllEntityFields($container->get('domain.bookable.package.repository'), $request, $args);
                };
                Api::callMainFunction($request, $response, $args, new UpdatePackageController($container, true), $getPackage);
            }
        );

        $app->get(
            '/api/v1/packages/effect/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new GetPackageDeleteEffectController($container, true));
            }
        );

        $app->post(
            '/api/v1/packages/status/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new UpdatePackageStatusController($container, true));
            }
        );

        $app->post(
            '/api/v1/packages/positions',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new UpdatePackagesPositionsController($container, true));
            }
        );

        $app->post(
            '/api/v1/packages/customers',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new AddPackageCustomerController($container, true));
            }
        );

        $app->post(
            '/api/v1/packages/customers/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new UpdatePackageCustomerController($container, true));
            }
        );

        $app->post(
            '/api/v1/packages/customers/delete/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new DeletePackageCustomerController($container, true));
            }
        );

        $app->get(
            '/api/v1/package-purchases/slots',
            function ($request, $response, $args) use ($container) {
                $packageData = $request->getQueryParams();
                $packageData['packageBookings'] = true;
                if (!empty($packageData['customerId'])) {
                    $packageData['customers'] = explode(',', $packageData['customerId']);
                    unset($packageData['customerId']);
                }
                $request = $request->withQueryParams($packageData);

                $response = Api::callMainFunction($request, $response, $args, new GetAppointmentsController($container, true));

                $responseBody = json_decode($response->getBody(), true);
                $availablePackageBookings = $responseBody['data']['availablePackageBookings'];
                foreach ($availablePackageBookings as &$availablePackageBooking) {
                    foreach ($availablePackageBooking['packages'] as &$customerPackages) {
                        $totalPurchases = [];
                        foreach ($customerPackages['services'] as &$service) {
                            foreach ($service['bookings'] as $booking) {
                                $totalPurchases[$booking['packageCustomerId']]['purchase'][] =
                                    [
                                        'serviceId' => $service['serviceId'],
                                        'available' => $booking['count'],
                                        'total' => $booking['total']
                                    ];
                                $totalPurchases[$booking['packageCustomerId']]['purchased']         = $booking['purchased'];
                                $totalPurchases[$booking['packageCustomerId']]['packageCustomerId'] = $booking['packageCustomerId'];
                                $totalPurchases[$booking['packageCustomerId']]['expirationDate']    = !empty($booking['end']) ? $booking['end'] : null;
                            }
                        }
                        $customerPackages['purchases'] = array_values($totalPurchases);
                        unset($customerPackages['services']);
                    }
                }
                $responseBody['data']    = $availablePackageBookings;
                $responseBody['message'] = 'Successfully retrieved available package slots';

                return $response->withJson($responseBody);
            }
        );
    }
}
