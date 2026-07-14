<?php

/**
 * @copyright © Melograno Ventures. All rights reserved.
 * @licence   See COPYING.md for license details.
 */

namespace AmeliaBooking\Infrastructure\API\ApiRoutes\Bookable;

use AmeliaBooking\Application\Controller\Bookable\Category\AddCategoryController;
use AmeliaBooking\Application\Controller\Bookable\Category\DeleteCategoryController;
use AmeliaBooking\Application\Controller\Bookable\Category\GetCategoriesController;
use AmeliaBooking\Application\Controller\Bookable\Category\GetCategoryController;
use AmeliaBooking\Application\Controller\Bookable\Category\UpdateCategoriesPositionsController;
use AmeliaBooking\Application\Controller\Bookable\Category\UpdateCategoryController;
use AmeliaBooking\Infrastructure\API\Api;
use AmeliaBooking\Infrastructure\Common\Container;
use Slim\App;

/**
 * Class Category
 *
 * @package AmeliaBooking\Infrastructure\API\ApiRoutes\Bookable
 */
class Category
{
    /**
     * @param App $app
     */
    public static function routes(App $app, Container $container)
    {
        $app->get(
            '/api/v1/categories',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new GetCategoriesController($container, true));
            }
        );

        $app->get(
            '/api/v1/categories/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new GetCategoryController($container, true));
            }
        );

        $app->post(
            '/api/v1/categories',
            function ($request, $response, $args) use ($container) {
                $categoryData = $request->getParsedBody();
                if (empty($categoryData['color'])) {
                    $categoryData['color'] = '#1788FB';
                }
                if (empty($categoryData['status'])) {
                    $categoryData['status'] = 'visible';
                }
                $request = $request->withParsedBody($categoryData);
                Api::callMainFunction($request, $response, $args, new AddCategoryController($container, true));
            }
        );

        $app->post(
            '/api/v1/categories/delete/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new DeleteCategoryController($container, true));
            }
        );

        $app->post(
            '/api/v1/categories/{id:[0-9]+}',
            function ($request, $response, $args) use ($container) {
                $getCategory = function () use ($container, $request, $args) {
                    return Api::getAllEntityFields($container->get('domain.bookable.category.repository'), $request, $args);
                };

                Api::callMainFunction($request, $response, $args, new UpdateCategoryController($container, true), $getCategory);
            }
        );

        $app->post(
            '/api/v1/categories/positions',
            function ($request, $response, $args) use ($container) {
                Api::callMainFunction($request, $response, $args, new UpdateCategoriesPositionsController($container, true));
            }
        );
    }
}
