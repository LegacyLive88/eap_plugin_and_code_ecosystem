<?php

/**
 * @copyright © Melograno Ventures. All rights reserved.
 * @licence   See COPYING.md for license details.
 */

namespace AmeliaBooking\Infrastructure\Routes\Google;

use AmeliaBooking\Application\Controller\Google\DisconnectFromGoogleAccountController;
use AmeliaBooking\Application\Controller\Google\DisconnectFromGoogleMiddlewareAccountController;
use AmeliaBooking\Application\Controller\Google\FetchAccessTokenWithAuthCodeController;
use AmeliaBooking\Application\Controller\Google\FetchGoogleMiddlewareAccessTokenController;
use AmeliaBooking\Application\Controller\Google\GetGoogleAuthURLController;
use AmeliaBooking\Application\Controller\Google\GetGoogleMiddlewareAuthURLController;
use AmeliaBooking\Application\Controller\Google\VerifyRecaptchaController;
use Slim\App;

/**
 * Class Google
 *
 * @package AmeliaBooking\Infrastructure\Routes\Google
 */
class Google
{
    /**
     * @param App $app
     */
    public static function routes(App $app)
    {
        $app->get('/google/authorization/url/{id:[0-9]+}', GetGoogleAuthURLController::class);

        $app->post('/google/authorization/url/{id:[0-9]+}', GetGoogleAuthURLController::class);

        $app->post('/google/disconnect/{id:[0-9]+}', DisconnectFromGoogleAccountController::class);

        $app->post('/google/authorization/token', FetchAccessTokenWithAuthCodeController::class);

        $app->post('/google/recaptcha/verify', VerifyRecaptchaController::class);

        // Middleware routes for Google Calendar integration

        $app->get('/google-calendar/authorization/url', GetGoogleMiddlewareAuthURLController::class);

        $app->post('/google-calendar/authorization/url', GetGoogleMiddlewareAuthURLController::class);

        $app->get('/google-calendar/authorization/url/{id:[0-9]+}', GetGoogleMiddlewareAuthURLController::class);

        $app->post('/google-calendar/authorization/url/{id:[0-9]+}', GetGoogleMiddlewareAuthURLController::class);

        $app->get('/google-calendar/authorization/token', FetchGoogleMiddlewareAccessTokenController::class);

        $app->post('/google-calendar/authorization/token', FetchGoogleMiddlewareAccessTokenController::class);

        $app->post('/google-calendar/disconnect', DisconnectFromGoogleMiddlewareAccountController::class);
    }
}
