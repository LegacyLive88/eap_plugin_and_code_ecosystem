<?php

/**
 * @copyright © Melograno Ventures. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace AmeliaBooking\Application\Controller\Coupon;

use AmeliaBooking\Application\Commands\Coupon\GetCouponsCommand;
use AmeliaBooking\Application\Controller\Controller;
use Slim\Http\Request;

/**
 * Class GetCouponsController
 *
 * @package AmeliaBooking\Application\Controller\Coupon
 */
class GetCouponsController extends Controller
{
    /**
     * Instantiates the Get Coupons command to hand it over to the Command Handler
     *
     * @param Request $request
     * @param         $args
     *
     * @return mixed
     * @throws \RuntimeException
     */
    protected function instantiateCommand(Request $request, $args)
    {
        $command = new GetCouponsCommand($args);

        $params = (array)$request->getQueryParams();

        $this->setArrayParams($params);

        if (isset($params['services'])) {
            $params['services'] = array_map('intval', $params['services']);
        }

        if (isset($params['packages'])) {
            $params['packages'] = array_map('intval', $params['packages']);
        }

        $command->setField('params', $params);

        $requestBody = $request->getQueryParams();
        $this->setCommandFields($command, $requestBody);

        return $command;
    }
}
