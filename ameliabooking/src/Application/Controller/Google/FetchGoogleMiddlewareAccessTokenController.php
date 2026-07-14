<?php

namespace AmeliaBooking\Application\Controller\Google;

use AmeliaBooking\Application\Commands\Google\FetchGoogleMiddlewareAccessTokenCommand;
use AmeliaBooking\Application\Controller\Controller;
use Slim\Http\Request;

class FetchGoogleMiddlewareAccessTokenController extends Controller
{
    /**
     * Instantiates the Fetch Google Calendar Access Token command to hand it over to the Command Handler
     *
     * @param Request $request
     * @param         $args
     *
     * @return FetchGoogleMiddlewareAccessTokenCommand
     */
    protected function instantiateCommand(Request $request, $args)
    {
        $command = new FetchGoogleMiddlewareAccessTokenCommand($args);

        $requestBody = $request->getParsedBody();
        $params = $request->getQueryParams();

        $command->setField('params', $params);

        $this->setCommandFields($command, $requestBody);

        return $command;
    }
}
