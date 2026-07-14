<?php

namespace AmeliaBooking\Application\Controller\Google;

use AmeliaBooking\Application\Commands\Google\GetGoogleMiddlewareAuthURLCommand;
use AmeliaBooking\Application\Controller\Controller;
use Slim\Http\Request;

class GetGoogleMiddlewareAuthURLController extends Controller
{
    public $allowedFields = [
        'redirectUri',
        'isBackend'
    ];
    /**
     * Instantiates the Get Google Auth URL command to hand it over to the Command Handler
     *
     * @param Request $request
     * @param         $args
     *
     * @return GetGoogleMiddlewareAuthURLCommand
     */
    protected function instantiateCommand(Request $request, $args)
    {
        $command = new GetGoogleMiddlewareAuthURLCommand($args);

        $params = (array)$request->getQueryParams();
        if (isset($params['redirectUri'])) {
            $command->setField('redirectUri', $params['redirectUri']);
            unset($params['redirectUri']);
        }
        $requestBody = $request->getParsedBody();
        $this->setCommandFields($command, $requestBody);

        return $command;
    }
}
