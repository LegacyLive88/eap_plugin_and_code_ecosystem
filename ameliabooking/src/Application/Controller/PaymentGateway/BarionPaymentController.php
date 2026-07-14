<?php

namespace AmeliaBooking\Application\Controller\PaymentGateway;

use AmeliaBooking\Application\Commands\PaymentGateway\BarionPaymentCommand;
use AmeliaBooking\Application\Controller\Controller;
use Slim\Http\Request;

class BarionPaymentController extends Controller
{
    /**
     * Fields for Barion payment that can be received from API
     *
     * @var array
     */
    protected $allowedFields = [
        'type',
        'bookings',
        'bookingStart',
        'notifyParticipants',
        'eventId',
        'serviceId',
        'providerId',
        'locationId',
        'couponCode',
        'payment',
        'recurring',
        'isCart',
        'recaptcha',
        'packageId',
        'package',
        'packageRules',
        'utcOffset',
        'locale',
        'timeZone',
        'deposit',
        'componentProps',
        'returnUrl',
    ];

    /**
     * Instantiates the Barion Payment Callback command to hand it over to the Command Handler
     *
     * @param Request $request
     * @param         $args
     *
     * @return BarionPaymentCommand
     */
    protected function instantiateCommand(Request $request, $args)
    {
        $command = new BarionPaymentCommand($args);

        $requestBody = $request->getParsedBody();
        $this->setCommandFields($command, $requestBody);

        return $command;
    }
}
