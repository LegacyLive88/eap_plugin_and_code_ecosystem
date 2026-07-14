<?php

/**
 * @copyright © Melograno Ventures. All rights reserved.
 * @licence   See COPYING.md for license details.
 */

namespace AmeliaBooking\Infrastructure\API\ApiRoutes\Coupon;

use AmeliaBooking\Application\Controller\Coupon\AddCouponController;
use AmeliaBooking\Application\Controller\Coupon\DeleteCouponController;
use AmeliaBooking\Application\Controller\Coupon\GetCouponController;
use AmeliaBooking\Application\Controller\Coupon\GetCouponsController;
use AmeliaBooking\Application\Controller\Coupon\UpdateCouponController;
use AmeliaBooking\Application\Controller\Coupon\UpdateCouponStatusController;
use AmeliaBooking\Application\Controller\Coupon\GetValidCouponController;
use AmeliaBooking\Domain\Collection\Collection;
use AmeliaBooking\Domain\ValueObjects\Number\Integer\WholeNumber;
use AmeliaBooking\Domain\ValueObjects\String\Status;
use AmeliaBooking\Infrastructure\API\Api;
use AmeliaBooking\Infrastructure\Common\Container;
use AmeliaBooking\Infrastructure\Repository\Bookable\Service\PackageCustomerRepository;
use AmeliaBooking\Infrastructure\Repository\Coupon\CouponRepository;
use Slim\App;

/**
 * Class Coupon
 *
 * @package AmeliaBooking\Infrastructure\API\ApiRoutes\Coupon
 */
class Coupon
{
    /**
     * @param App $app
     */
    public static function routes(App $app, Container $container)
    {
        $app->get(
            '/api/v1/coupons',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new GetCouponsController($container, true));
            }
        );

        $app->get(
            '/api/v1/coupons/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                $response = Api::callMainFunction($request, $response, $args, new GetCouponController($container, true));

                /** @var CouponRepository $couponRepository */
                $couponRepository = $container->get('domain.coupon.repository');

                $responseBody = json_decode($response->getBody(), true);
                $coupon       = $responseBody['data']['coupon'];

                /** @var Collection $couponsWithUsedBookings */
                $couponsWithUsedBookings = $couponRepository->getAllByCriteria(['couponIds' => [$coupon['id']]]);

                if ($couponsWithUsedBookings->length()) {
                    /** @var PackageCustomerRepository $packageCustomerRepository */
                    $packageCustomerRepository = $container->get('domain.bookable.packageCustomer.repository');

                    $packageCustomerRecords = $packageCustomerRepository->getByEntityId($coupon['id'], 'couponId');

                    $coupon['used'] = $couponsWithUsedBookings->toArray()[0]['used'] + $packageCustomerRecords->length();
                }

                $responseBody['data']['coupon'] = $coupon;
                return $response->withJson($responseBody);
            }
        );

        $app->post(
            '/api/v1/coupons',
            function ($request, $response, $args) use ($container) {
                $couponData = $request->getParsedBody();
                if (empty($couponData['discount'])) {
                    $couponData['discount'] = 0;
                }
                if (empty($couponData['deduction'])) {
                    $couponData['deduction'] = 0;
                }
                if (empty($couponData['status'])) {
                    $couponData['status'] = Status::VISIBLE;
                }
                if (empty($couponData['services'])) {
                    $couponData['services'] = [];
                }
                if (empty($couponData['events'])) {
                    $couponData['events'] = [];
                }
                if (empty($couponData['packages'])) {
                    $couponData['packages'] = [];
                }

                $request = $request->withParsedBody($couponData);
                Api::callMainFunction($request, $response, $args, new AddCouponController($container, true));
            }
        );

        $app->post(
            '/api/v1/coupons/delete/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new DeleteCouponController($container, true));
            }
        );

        $app->post(
            '/api/v1/coupons/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                $getCoupon = function () use ($container, $request, $args) {
                    return Api::getAllEntityFields($container->get('domain.coupon.repository'), $request, $args);
                };

                Api::callMainFunction($request, $response, $args, new UpdateCouponController($container, true), $getCoupon);
            }
        );

        $app->post(
            '/api/v1/coupons/status/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new UpdateCouponStatusController($container, true));
            }
        );

        $app->get(
            '/api/v1/coupons/validate',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new GetValidCouponController($container, true));
            }
        );

        $app->post(
            '/api/v1/coupons/validate',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new GetValidCouponController($container, true));
            }
        );
    }
}
