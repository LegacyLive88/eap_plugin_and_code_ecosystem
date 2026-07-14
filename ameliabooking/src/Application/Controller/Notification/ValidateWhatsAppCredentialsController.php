<?php

namespace AmeliaBooking\Application\Controller\Notification;

use AmeliaBooking\Application\Commands\Notification\ValidateWhatsAppCredentialsCommand;
use AmeliaBooking\Application\Controller\Controller;
use Slim\Http\Request;

/**
 * Class ValidateWhatsAppCredentialsController
 *
 * @package AmeliaBooking\Application\Controller\Notification
 */
class ValidateWhatsAppCredentialsController extends Controller
{
    /**
     * Fields for notification that can be received from front-end
     *
     * @var array
     */
    protected $allowedFields = [
        'businessId',
        'phoneId',
        'token'
    ];

    /**
     * Validates WhatsApp credentials
     *
     * @param Request $request
     * @param         $args
     *
     * @return ValidateWhatsAppCredentialsCommand
     * @throws \RuntimeException
     */
    protected function instantiateCommand(Request $request, $args)
    {
        $command     = new ValidateWhatsAppCredentialsCommand($args);
        $requestBody = $request->getParsedBody();
        $this->setCommandFields($command, $requestBody);

        return $command;
    }
}
