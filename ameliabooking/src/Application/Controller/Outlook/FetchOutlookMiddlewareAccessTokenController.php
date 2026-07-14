<?php

namespace AmeliaBooking\Application\Controller\Outlook;

use AmeliaBooking\Application\Commands\Outlook\FetchOutlookMiddlewareAccessTokenCommand;
use AmeliaBooking\Application\Controller\Controller;
use Slim\Http\Request;

class FetchOutlookMiddlewareAccessTokenController extends Controller
{
    /**
     *
     * @param Request $request
     * @param         $args
     *
     * @return FetchOutlookMiddlewareAccessTokenCommand
     */
    protected function instantiateCommand(Request $request, $args)
    {
        $command = new FetchOutlookMiddlewareAccessTokenCommand($args);

        $requestBody = $request->getParsedBody();
        $params = $request->getQueryParams();

        $command->setField('params', $params);

        $this->setCommandFields($command, $requestBody);

        return $command;
    }
}
