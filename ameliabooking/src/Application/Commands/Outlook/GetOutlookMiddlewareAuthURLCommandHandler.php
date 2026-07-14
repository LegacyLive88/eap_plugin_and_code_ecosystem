<?php

namespace AmeliaBooking\Application\Commands\Outlook;

use AmeliaBooking\Application\Commands\CommandHandler;
use AmeliaBooking\Application\Commands\CommandResult;
use AmeliaBooking\Infrastructure\Services\Outlook\AbstractOutlookCalendarMiddlewareService;

class GetOutlookMiddlewareAuthURLCommandHandler extends CommandHandler
{
    public function handle(GetOutlookMiddlewareAuthURLCommand $command)
    {
        $result = new CommandResult();

        /** @var AbstractOutlookCalendarMiddlewareService $outlookCalendarMiddlewareService */
        $outlookCalendarMiddlewareService = $this->container->get('infrastructure.outlook.calendar.middleware.service');

        $providerId = (int)$command->getField('id');
        $returnUrl = $command->getField('redirectUri');
        $isBackend = (bool)$command->getField('isBackend');

        $authUrl = $outlookCalendarMiddlewareService->getAuthUrl($providerId, $returnUrl, $isBackend);
        $result->setResult(CommandResult::RESULT_SUCCESS);
        $result->setMessage('Successfully retrieved outlook authorization URL');
        $result->setData(
            [
                'authUrl' => filter_var($authUrl, FILTER_SANITIZE_URL)
            ]
        );

        return $result;
    }
}
