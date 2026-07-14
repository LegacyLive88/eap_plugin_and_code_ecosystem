<?php

namespace AmeliaBooking\Application\Commands\Google;

use AmeliaBooking\Application\Commands\CommandHandler;
use AmeliaBooking\Application\Commands\CommandResult;
use AmeliaBooking\Infrastructure\Services\Google\AbstractGoogleCalendarMiddlewareService;

class GetGoogleMiddlewareAuthURLCommandHandler extends CommandHandler
{
    public function handle(GetGoogleMiddlewareAuthURLCommand $command)
    {
        $result = new CommandResult();

        /** @var AbstractGoogleCalendarMiddlewareService $googleCalendarMiddlewareService */
        $googleCalendarMiddlewareService = $this->container->get('infrastructure.google.calendar.middleware.service');

        $providerId = (int)$command->getField('id');
        $returnUrl = $command->getField('redirectUri');
        $isBackend = $command->getField('isBackend');

        $authUrl = $googleCalendarMiddlewareService->getAuthUrl($providerId, $returnUrl, $isBackend);
        $result->setResult(CommandResult::RESULT_SUCCESS);
        $result->setMessage('Successfully retrieved google authorization URL');
        $result->setData(
            [
                'authUrl' => filter_var($authUrl, FILTER_SANITIZE_URL)
            ]
        );

        return $result;
    }
}
