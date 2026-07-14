<?php

/**
 * @copyright © Melograno Ventures. All rights reserved.
 * @licence   See COPYING.md for license details.
 */

namespace AmeliaBooking\Infrastructure\API\ApiRoutes\Notification;

use AmeliaBooking\Application\Controller\Notification\AddNotificationController;
use AmeliaBooking\Application\Controller\Notification\DeleteNotificationController;
use AmeliaBooking\Application\Controller\Notification\GetNotificationsController;
use AmeliaBooking\Application\Controller\Notification\GetSMSNotificationsHistoryController;
use AmeliaBooking\Application\Controller\Notification\SendAmeliaSmsApiRequestController;
use AmeliaBooking\Application\Controller\Notification\SendScheduledNotificationsController;
use AmeliaBooking\Application\Controller\Notification\SendTestEmailController;
use AmeliaBooking\Application\Controller\Notification\SendTestWhatsAppController;
use AmeliaBooking\Application\Controller\Notification\SendUndeliveredNotificationsController;
use AmeliaBooking\Application\Controller\Notification\UpdateNotificationController;
use AmeliaBooking\Application\Controller\Notification\UpdateNotificationStatusController;
use AmeliaBooking\Application\Controller\Notification\UpdateSMSNotificationHistoryController;
use AmeliaBooking\Application\Controller\Notification\ValidateSMTPCredentialsController;
use AmeliaBooking\Application\Controller\Notification\WhatsAppWebhookController;
use AmeliaBooking\Application\Controller\Notification\WhatsAppWebhookRegisterController;
use AmeliaBooking\Domain\ValueObjects\String\NotificationStatus;
use AmeliaBooking\Infrastructure\API\Api;
use AmeliaBooking\Infrastructure\Common\Container;
use Slim\App;

/**
 * Class Notification
 *
 * @package AmeliaBooking\Infrastructure\API\ApiRoutes\Notification
 */
class Notification
{
    /**
     * @param App $app
     */
    public static function routes(App $app, Container $container)
    {
        $app->get(
            '/api/v1/notifications',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new GetNotificationsController($container, true));
            }
        );

        $app->post(
            '/api/v1/notifications',
            function ($request, $response, $args) use ($container) {
                $requestBody = $request->getParsedBody();
                if (empty($requestBody['status'])) {
                    $requestBody['status'] = NotificationStatus::ENABLED;
                }
                $request = $request->withParsedBody($requestBody);
                Api::callMainFunction($request, $response, $args, new AddNotificationController($container, true));
            }
        );

        $app->post(
            '/api/v1/notifications/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                $getNotification = function () use ($container, $request, $args) {
                    return Api::getAllEntityFields($container->get('domain.notification.repository'), $request, $args);
                };
                Api::callMainFunction($request, $response, $args, new UpdateNotificationController($container, true), $getNotification);
            }
        );

        $app->post(
            '/api/v1/notifications/status/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new UpdateNotificationStatusController($container, true));
            }
        );

        $app->post(
            '/api/v1/notifications/email/test',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new SendTestEmailController($container, true));
            }
        );

        $app->post(
            '/api/v1/notifications/whatsapp/test',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new SendTestWhatsAppController($container, true));
            }
        );

        $app->get(
            '/api/v1/notifications/scheduled/send',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new SendScheduledNotificationsController($container, true));
            }
        );

        $app->get(
            '/api/v1/notifications/undelivered/send',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new SendUndeliveredNotificationsController($container, true));
            }
        );

        $app->post(
            '/api/v1/notifications/sms',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new SendAmeliaSmsApiRequestController($container, true));
            }
        );

        $app->post(
            '/api/v1/notifications/sms/history/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new UpdateSMSNotificationHistoryController($container, true));
            }
        );

        $app->get(
            '/api/v1/notifications/sms/history',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new GetSMSNotificationsHistoryController($container, true));
            }
        );

        $app->post(
            '/api/v1/notifications/delete/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new DeleteNotificationController($container, true));
            }
        );
    }
}
