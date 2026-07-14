<?php

/**
 * @copyright © Melograno Ventures. All rights reserved.
 * @licence   See COPYING.md for license details.
 */

namespace AmeliaBooking\Infrastructure\API;

use AmeliaBooking\Application\Common\Exceptions\AccessDeniedException;
use AmeliaBooking\Application\Controller\Controller;
use AmeliaBooking\Domain\Services\Api\ApiService;
use AmeliaBooking\Domain\Services\Settings\SettingsService;
use AmeliaBooking\Infrastructure\API\ApiRoutes\Bookable\Category;
use AmeliaBooking\Infrastructure\API\ApiRoutes\Bookable\Extra;
use AmeliaBooking\Infrastructure\API\ApiRoutes\Bookable\Package;
use AmeliaBooking\Infrastructure\API\ApiRoutes\Bookable\Resource;
use AmeliaBooking\Infrastructure\API\ApiRoutes\Bookable\Service;
use AmeliaBooking\Infrastructure\API\ApiRoutes\Coupon\Coupon;
use AmeliaBooking\Infrastructure\API\ApiRoutes\CustomField\CustomField;
use AmeliaBooking\Infrastructure\API\ApiRoutes\Entities\Entities;
use AmeliaBooking\Infrastructure\API\ApiRoutes\Location\Location;
use AmeliaBooking\Infrastructure\API\ApiRoutes\Notification\Notification;
use AmeliaBooking\Infrastructure\API\ApiRoutes\Payment\Payment;
use AmeliaBooking\Infrastructure\API\ApiRoutes\Payment\Refund;
use AmeliaBooking\Infrastructure\API\ApiRoutes\Search\Search;
use AmeliaBooking\Infrastructure\API\ApiRoutes\Stats\Stats;
use AmeliaBooking\Infrastructure\API\ApiRoutes\TimeSlots\TimeSlots;
use AmeliaBooking\Infrastructure\API\ApiRoutes\User\User;
use AmeliaBooking\Infrastructure\Common\Container;
use AmeliaBooking\Infrastructure\API\ApiRoutes\Booking\Appointment\Appointment;
use AmeliaBooking\Infrastructure\API\ApiRoutes\Booking\Booking;
use AmeliaBooking\Infrastructure\API\ApiRoutes\Booking\Event\Event;
use AmeliaBooking\Infrastructure\Common\Exceptions\NotFoundException;
use AmeliaBooking\Infrastructure\Repository\AbstractRepository;
use AmeliaBooking\Infrastructure\WP\SettingsService\SettingsStorage;
use Slim\App;
use Slim\Http\Request;
use Slim\Http\Response;

/**
 * Class Api
 *
 * API Routes for the Amelia app
 *
 * @package AmeliaBooking\Infrastructure\API
 */
class Api
{
    /**
     * @param App $app
     * @param Container $container
     */
    public static function routes(App $app, Container $container)
    {

        Booking::routes($app, $container);

        Appointment::routes($app, $container);

        Event::routes($app, $container);

        Entities::routes($app, $container);

        TimeSlots::routes($app, $container);

        Search::routes($app, $container);

        Coupon::routes($app, $container);

        Payment::routes($app, $container);

        Refund::routes($app, $container);

        Category::routes($app, $container);

        Service::routes($app, $container);

        Extra::routes($app, $container);

        Package::routes($app, $container);

        Resource::routes($app, $container);

        Location::routes($app, $container);

        Notification::routes($app, $container);

        CustomField::routes($app, $container);

        User::routes($app, $container);

        Stats::routes($app, $container);
    }


    /**
     * @param Controller $controller
     *
     * @throws AccessDeniedException
     * @throws NotFoundException
     */
    public static function callMainFunction(Request $request, Response $response, $args, $controller, ?callable $additionalFunctions = null)
    {
        /** @var SettingsService $settingsService */
        $settingsService = new SettingsService(new SettingsStorage());

        if (!$settingsService->isFeatureEnabled('apis')) {
            throw new AccessDeniedException('API is not enabled. Please enable API in the features page.');
        }

        $isApiKeyValid    = false;
        $apiKeysGenerated = $settingsService->getSetting('apiKeys', 'apiKeys');
        if (!empty($apiKeysGenerated) && !empty($request->getHeaders()['HTTP_AMELIA']) && $request->getHeaders()['HTTP_AMELIA'][0]) {
            $apiService    = new ApiService();
            $isApiKeyValid = $apiService->checkApiKeys($request->getHeaders()['HTTP_AMELIA'][0], $apiKeysGenerated);
        }

        if ($isApiKeyValid) {
            if (!empty($additionalFunctions)) {
                $request = $additionalFunctions();
            }
            try {
                return $controller->__invoke($request, $response, $args, $isApiKeyValid);
            } catch (NotFoundException $e) {
                throw new NotFoundException('Record not found.');
            }
        }

        throw new AccessDeniedException(
            !empty($request->getHeaders()['HTTP_AMELIA'][0]) ?
            'Invalid API key. Please provide a valid API key to access this resource.' :
            'Missing API key. Please provide a valid API key to access this resource.'
        );
    }

    public static function getAllEntityFields(AbstractRepository $repository, Request $request, array $args, $key = null)
    {
        $oldRequestBody = $request->getParsedBody();
        $entity         = $repository->getById($args['id']);
        $oldEntity      = $entity->toArray();
        if ($key === null) {
            $requestBody = array_merge($oldEntity, $oldRequestBody);
        } else {
            $requestBody = $request->getParsedBody();
            if (!isset($requestBody[$key])) {
                $requestBody[$key] = [];
            }
            $requestBody[$key] = array_merge($oldEntity, $requestBody[$key]);
        }
        return $request->withParsedBody($requestBody);
    }
}
