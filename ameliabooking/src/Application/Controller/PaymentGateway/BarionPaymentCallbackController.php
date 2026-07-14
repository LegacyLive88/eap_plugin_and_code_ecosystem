<?php

namespace AmeliaBooking\Application\Controller\PaymentGateway;

use AmeliaBooking\Application\Commands\PaymentGateway\BarionPaymentCallbackCommand;
use AmeliaBooking\Application\Controller\Controller;
use Slim\Http\Request;

class BarionPaymentCallbackController extends Controller
{
    /**
     * Fields for Barion payment that can be received from API
     *
     * @var array
     */
    protected $allowedFields = [
        'name',
        'paymentId',
        'bookingId',
        'type',
    ];

    /**
     * Instantiates the Barion Payment Callback command to hand it over to the Command Handler
     *
     * @param Request $request
     * @param         $args
     *
     * @return BarionPaymentCallbackCommand
     */
    protected function instantiateCommand(Request $request, $args)
    {
        $command = new BarionPaymentCallbackCommand($args);

        $queryParams = $request->getQueryParams();
        $this->setCommandFields($command, $queryParams);

        return $command;
    }
}
