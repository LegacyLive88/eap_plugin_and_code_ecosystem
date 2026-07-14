<?php

namespace AmeliaBooking\Application\Controller\Booking\Package;

use AmeliaBooking\Application\Commands\Booking\Package\GetPackageBookingServicesCommand;
use AmeliaBooking\Application\Controller\Controller;
use RuntimeException;
use Slim\Http\Request;

/**
 * Class GetPackageBookingServicesController
 *
 * @package AmeliaBooking\Application\Controller\Booking\Package
 */
class GetPackageBookingServicesController extends Controller
{
    /**
     * @param Request $request
     * @param         $args
     *
     * @return GetPackageBookingServicesCommand
     * @throws RuntimeException
     */
    protected function instantiateCommand(Request $request, $args)
    {
        $command = new GetPackageBookingServicesCommand($args);

        $params = (array)$request->getQueryParams();

        $this->setArrayParams($params);

        $command->setField('params', $params);

        $command->setToken($request);

        return $command;
    }
}
