<?php

/**
 * @copyright © Melograno Ventures. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace AmeliaBooking\Application\Controller\Bookable\Resource;

use AmeliaBooking\Application\Commands\Bookable\Resource\GetResourceCommand;
use AmeliaBooking\Application\Controller\Controller;
use RuntimeException;
use Slim\Http\Request;

/**
 * Class GetResourceController
 *
 * @package AmeliaBooking\Application\Controller\Bookable\Resource
 */
class GetResourceController extends Controller
{
    /**
     * Instantiates the Get Resource command to hand it over to the Command Handler
     *
     * @param Request $request
     * @param         $args
     *
     * @return GetResourceCommand
     * @throws RuntimeException
     */
    protected function instantiateCommand(Request $request, $args)
    {
        $command = new GetResourceCommand($args);

        $requestBody = $request->getParsedBody();

        $this->setCommandFields($command, $requestBody);

        return $command;
    }
}
