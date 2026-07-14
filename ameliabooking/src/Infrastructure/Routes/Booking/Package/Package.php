<?php

/**
 * @copyright Â© Melograno Ventures. All rights reserved.
 * @licence   See COPYING.md for license details.
 */

namespace AmeliaBooking\Infrastructure\Routes\Booking\Package;

use AmeliaBooking\Application\Controller\Booking\Package\GetPackageBookingController;
use AmeliaBooking\Application\Controller\Booking\Package\GetPackageBookingsController;
use AmeliaBooking\Application\Controller\Booking\Package\GetPackageBookingServicesController;
use Slim\App;

/**
 * Class Booking
 *
 * @package AmeliaBooking\Infrastructure\Routes\Booking
 */
class Package
{
    /**
     * @param App $app
     *
     * @throws \InvalidArgumentException
     */
    public static function routes(App $app)
    {
        $app->get('/bookings/packages', GetPackageBookingsController::class);

        $app->get('/bookings/packages/{id:[0-9]+}', GetPackageBookingController::class);

        $app->get('/bookings/packages/{id:[0-9]+}/services', GetPackageBookingServicesController::class);
    }
}
