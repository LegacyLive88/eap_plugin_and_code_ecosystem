<?php

namespace AmeliaBooking\Application\Controller\Outlook;

use AmeliaBooking\Application\Commands\Outlook\GetOutlookMiddlewareAuthURLCommand;
use AmeliaBooking\Application\Controller\Controller;
use Slim\Http\Request;

class GetOutlookMiddlewareAuthURLController extends Controller
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
     * @return GetOutlookMiddlewareAuthURLCommand
     */
    protected function instantiateCommand(Request $request, $args)
    {
        $command = new GetOutlookMiddlewareAuthURLCommand($args);

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
