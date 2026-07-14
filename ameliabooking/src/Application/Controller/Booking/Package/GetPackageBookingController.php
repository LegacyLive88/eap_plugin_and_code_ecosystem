<?php

namespace AmeliaBooking\Application\Controller\Booking\Package;

use AmeliaBooking\Application\Commands\Booking\Package\GetPackageBookingCommand;
use AmeliaBooking\Application\Controller\Controller;
use RuntimeException;
use Slim\Http\Request;

/**
 * Class GetPackageBookingController
 *
 * @package AmeliaBooking\Application\Controller\Booking\Package
 */
class GetPackageBookingController extends Controller
{
    /**
     * Instantiates the Get Appointments command to hand it over to the Command Handler
     *
     * @param Request $request
     * @param         $args
     *
     * @return GetPackageBookingCommand
     * @throws RuntimeException
     */
    protected function instantiateCommand(Request $request, $args)
    {
        $command = new GetPackageBookingCommand($args);

        $params = (array)$request->getQueryParams();

        $this->setArrayParams($params);

        $command->setField('params', $params);

        $command->setToken($request);

        return $command;
    }
}
