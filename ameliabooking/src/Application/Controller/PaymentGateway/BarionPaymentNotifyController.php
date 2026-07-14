<?php

namespace AmeliaBooking\Application\Controller\PaymentGateway;

use AmeliaBooking\Application\Commands\PaymentGateway\BarionPaymentNotifyCommand;
use AmeliaBooking\Application\Controller\Controller;
use RuntimeException;
use Slim\Http\Request;

class BarionPaymentNotifyController extends Controller
{
    /**
     * Fields for Barion payment that can be received from API
     *
     * @var array
     */
    protected $allowedFields = [
        'name',
        'paymentId',
        'returnUrl',
    ];

    /**
     * Instantiates the Barion Payment Notify command to hand it over to the Command Handler
     *
     * @param Request $request
     * @param         $args
     *
     * @return BarionPaymentNotifyCommand
     * @throws RuntimeException
     */
    protected function instantiateCommand(Request $request, $args)
    {
        $command = new BarionPaymentNotifyCommand($args);

        $this->setCommandFields($command, $request->getParsedBody());

        $this->setCommandFields($command, $request->getQueryParams());

        return $command;
    }
}
