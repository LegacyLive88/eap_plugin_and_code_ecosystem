<?php

namespace AmeliaBooking\Application\Commands\Google;

use AmeliaBooking\Application\Commands\CommandHandler;
use AmeliaBooking\Application\Commands\CommandResult;
use AmeliaBooking\Domain\Services\Settings\SettingsService;
use AmeliaBooking\Infrastructure\Common\Exceptions\QueryExecutionException;
use AmeliaBooking\Infrastructure\Repository\User\ProviderRepository;

class DisconnectFromGoogleMiddlewareAccountCommandHandler extends CommandHandler
{
    /**
     * @throws QueryExecutionException
     */
    public function handle(DisconnectFromGoogleMiddlewareAccountCommand $command)
    {
        $result = new CommandResult();

        /** @var SettingsService $settingsService */
        $settingsService = $this->container->get('domain.settings.service');

        $googleSettings = $settingsService->getCategorySettings('googleCalendar');
        $googleSettings['accessToken'] = '';
        $googleSettings['googleAccountData'] = null;
        $settingsService->setCategorySettings('googleCalendar', $googleSettings);

        /** @var ProviderRepository $providerRepository */
        $providerRepository = $this->container->get('domain.users.providers.repository');

        $providerRepository->clearGoogleCalendarIds();
        $providerRepository->commit();

        $result->setResult(CommandResult::RESULT_SUCCESS);
        $result->setMessage('Successfully disconnected from Google Calendar');

        return $result;
    }
}
