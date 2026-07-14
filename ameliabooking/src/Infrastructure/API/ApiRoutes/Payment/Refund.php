<?php

/**
 * @copyright © Melograno Ventures. All rights reserved.
 * @licence   See COPYING.md for license details.
 */

namespace AmeliaBooking\Infrastructure\API\ApiRoutes\Payment;

use AmeliaBooking\Application\Controller\Payment\RefundPaymentController;
use AmeliaBooking\Infrastructure\API\Api;
use AmeliaBooking\Infrastructure\Common\Container;
use Slim\App;

/**
 * Class Refund
 *
 * @package AmeliaBooking\Infrastructure\API\ApiRoutes\Payment
 */
class Refund
{
    /**
     * @param App $app
     */
    public static function routes(App $app, Container $container)
    {
        $app->post(
            '/api/v1/payments/refund/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new RefundPaymentController($container, true));
            }
        );
    }
}
