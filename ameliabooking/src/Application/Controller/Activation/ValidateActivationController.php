<?php

/**
 * @copyright © Melograno Ventures. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace AmeliaBooking\Application\Controller\Activation;

use AmeliaBooking\Application\Commands\Activation\ValidateActivationCommand;
use AmeliaBooking\Application\Controller\Controller;
use Slim\Http\Request;

/**
 * Class ValidateActivationController
 *
 * @package AmeliaBooking\Application\Controller\Activation
 */
class ValidateActivationController extends Controller
{
    /**
     * Instantiates the Validate Activation command to hand it over to the Command Handler
     *
     * @param Request $request
     * @param         $args
     *
     * @return ValidateActivationCommand
     * @throws \RuntimeException
     */
    protected function instantiateCommand(Request $request, $args)
    {
        $command = new ValidateActivationCommand($args);
        $command->setField('params', $request->getQueryParams());
        $requestBody = $request->getParsedBody();
        $this->setCommandFields($command, $requestBody);

        return $command;
    }
}
