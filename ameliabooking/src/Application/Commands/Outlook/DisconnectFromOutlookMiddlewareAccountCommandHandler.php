<?php

namespace AmeliaBooking\Application\Commands\Outlook;

use AmeliaBooking\Application\Commands\CommandHandler;
use AmeliaBooking\Application\Commands\CommandResult;
use AmeliaBooking\Domain\Services\Settings\SettingsService;
use AmeliaBooking\Infrastructure\Common\Exceptions\QueryExecutionException;
use AmeliaBooking\Infrastructure\Repository\User\ProviderRepository;

class DisconnectFromOutlookMiddlewareAccountCommandHandler extends CommandHandler
{
    /**
     * @throws QueryExecutionException
     */
    public function handle(DisconnectFromOutlookMiddlewareAccountCommand $command)
    {
        $result = new CommandResult();

        /** @var SettingsService $settingsService */
        $settingsService = $this->container->get('domain.settings.service');

        $outlookSettings = $settingsService->getCategorySettings('outlookCalendar');
        $outlookSettings['accessToken'] = '';
        $outlookSettings['outlookAccountData'] = null;
        $outlookSettings['mailEnabled'] = false;
        $settingsService->setCategorySettings('outlookCalendar', $outlookSettings);

        /** @var ProviderRepository $providerRepository */
        $providerRepository = $this->container->get('domain.users.providers.repository');

        $providerRepository->clearOutlookCalendarIds();
        $providerRepository->commit();

        $result->setResult(CommandResult::RESULT_SUCCESS);
        $result->setMessage('Successfully disconnected from Outlook Calendar');

        return $result;
    }
}
