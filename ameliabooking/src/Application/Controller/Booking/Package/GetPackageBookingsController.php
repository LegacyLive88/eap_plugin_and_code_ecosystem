<?php

namespace AmeliaBooking\Application\Controller\Booking\Package;

use AmeliaBooking\Application\Commands\Booking\Package\GetPackageBookingsCommand;
use AmeliaBooking\Application\Controller\Controller;
use RuntimeException;
use Slim\Http\Request;

/**
 * Class GetPackageBookingsController
 *
 * @package AmeliaBooking\Application\Controller\Booking\Package
 */
class GetPackageBookingsController extends Controller
{
    /**
     * Instantiates the Get Appointments command to hand it over to the Command Handler
     *
     * @param Request $request
     * @param         $args
     *
     * @return GetPackageBookingsCommand
     * @throws RuntimeException
     */
    protected function instantiateCommand(Request $request, $args)
    {
        $command = new GetPackageBookingsCommand($args);

        $params = (array)$request->getQueryParams();

        $this->setArrayParams($params, ['status', 'availability']);

        $command->setField('params', $params);

        $command->setToken($request);

        return $command;
    }
}
