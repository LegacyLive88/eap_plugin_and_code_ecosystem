<?php

namespace AmeliaBooking\Application\Controller\Google;

use AmeliaBooking\Application\Controller\Controller;
use AmeliaBooking\Application\Commands\Google\DisconnectFromGoogleMiddlewareAccountCommand;
use Slim\Http\Request;

class DisconnectFromGoogleMiddlewareAccountController extends Controller
{
    /**
     * Instantiates the Disconnect Google Calendar command to hand it over to the Command Handler
     *
     * @param Request $request
     * @param         $args
     *
     * @return DisconnectFromGoogleMiddlewareAccountCommand
     */
    protected function instantiateCommand(Request $request, $args)
    {
        $command = new DisconnectFromGoogleMiddlewareAccountCommand($args);

        $requestBody = $request->getParsedBody();
        $this->setCommandFields($command, $requestBody);

        return $command;
    }
}
