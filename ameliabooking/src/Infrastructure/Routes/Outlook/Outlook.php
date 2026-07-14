<?php

/**
 * @copyright © Melograno Ventures. All rights reserved.
 * @licence   See COPYING.md for license details.
 */

namespace AmeliaBooking\Infrastructure\Routes\Outlook;

use AmeliaBooking\Application\Controller\Outlook\DisconnectFromOutlookAccountController;
use AmeliaBooking\Application\Controller\Outlook\DisconnectFromOutlookMiddlewareAccountController;
use AmeliaBooking\Application\Controller\Outlook\FetchAccessTokenWithAuthCodeOutlookController;
use AmeliaBooking\Application\Controller\Outlook\FetchOutlookMiddlewareAccessTokenController;
use AmeliaBooking\Application\Controller\Outlook\GetOutlookAuthURLController;
use AmeliaBooking\Application\Controller\Outlook\GetOutlookMiddlewareAuthURLController;
use Slim\App;

/**
 * Class Outlook
 *
 * @package AmeliaBooking\Infrastructure\Routes\Outlook
 */
class Outlook
{
    /**
     * @param App $app
     */
    public static function routes(App $app)
    {
        $app->get('/outlook/authorization/url/{id:[0-9]+}', GetOutlookAuthURLController::class);

        $app->post('/outlook/disconnect/{id:[0-9]+}', DisconnectFromOutlookAccountController::class);

        $app->post('/outlook/authorization/token', FetchAccessTokenWithAuthCodeOutlookController::class);

        // Middleware routes for Outlook Calendar integration
        $app->post('/outlook-calendar/authorization/url', GetOutlookMiddlewareAuthURLController::class);

        $app->get('/outlook-calendar/authorization/url/{id:[0-9]+}', GetOutlookMiddlewareAuthURLController::class);

        $app->post('/outlook-calendar/authorization/url/{id:[0-9]+}', GetOutlookMiddlewareAuthURLController::class);

        $app->get('/outlook-calendar/authorization/token', FetchOutlookMiddlewareAccessTokenController::class);

        $app->post('/outlook-calendar/authorization/token', FetchOutlookMiddlewareAccessTokenController::class);

        $app->post('/outlook-calendar/disconnect', DisconnectFromOutlookMiddlewareAccountController::class);
    }
}
