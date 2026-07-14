<?php

namespace AmeliaBooking\Application\Controller\PaymentGateway;

use AmeliaBooking\Application\Commands\PaymentGateway\StripeValidateKeysCommand;
use AmeliaBooking\Application\Controller\Controller;
use RuntimeException;
use Slim\Http\Request;

/**
 * Class StripeValidateKeysController
 *
 * @package AmeliaBooking\Application\Controller\PaymentGateway
 */
class StripeValidateKeysController extends Controller
{
    protected $allowedFields = [
        'publishableKey',
        'secretKey',
        'testMode'
    ];

    /**
     * @param Request $request
     * @param         $args
     *
     * @return StripeValidateKeysCommand
     * @throws RuntimeException
     */
    protected function instantiateCommand(Request $request, $args): StripeValidateKeysCommand
    {
        $command = new StripeValidateKeysCommand($args);
        $requestBody = $request->getParsedBody();
        $this->setCommandFields($command, $requestBody);

        return $command;
    }
}
