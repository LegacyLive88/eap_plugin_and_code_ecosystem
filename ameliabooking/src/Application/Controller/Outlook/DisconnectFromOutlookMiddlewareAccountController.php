<?php

namespace AmeliaBooking\Application\Controller\Outlook;

use AmeliaBooking\Application\Commands\Outlook\DisconnectFromOutlookMiddlewareAccountCommand;
use AmeliaBooking\Application\Controller\Controller;
use Slim\Http\Request;

class DisconnectFromOutlookMiddlewareAccountController extends Controller
{
    /**
     *
     * @param Request $request
     * @param         $args
     *
     * @return DisconnectFromOutlookMiddlewareAccountCommand
     */
    protected function instantiateCommand(Request $request, $args)
    {
        $command = new DisconnectFromOutlookMiddlewareAccountCommand($args);

        $requestBody = $request->getParsedBody();
        $this->setCommandFields($command, $requestBody);

        return $command;
    }
}
