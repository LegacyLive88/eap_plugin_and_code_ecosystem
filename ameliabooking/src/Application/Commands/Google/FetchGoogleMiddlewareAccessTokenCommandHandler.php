<?php

namespace AmeliaBooking\Application\Commands\Google;

use AmeliaBooking\Application\Commands\CommandHandler;
use AmeliaBooking\Application\Commands\CommandResult;
use AmeliaBooking\Domain\Common\Exceptions\InvalidArgumentException;
use AmeliaBooking\Domain\Factory\Google\GoogleCalendarFactory;
use AmeliaBooking\Domain\Services\Settings\SettingsService;
use AmeliaBooking\Infrastructure\Common\Exceptions\QueryExecutionException;
use AmeliaBooking\Infrastructure\Repository\Google\GoogleCalendarRepository;
use AmeliaBooking\Infrastructure\Repository\User\ProviderRepository;
use AmeliaBooking\Infrastructure\Services\Google\AbstractGoogleCalendarMiddlewareService;

class FetchGoogleMiddlewareAccessTokenCommandHandler extends CommandHandler
{
    /**
     * @throws InvalidArgumentException
     * @throws QueryExecutionException
     */
    public function handle(FetchGoogleMiddlewareAccessTokenCommand $command)
    {
        $result = new CommandResult();

        $accessToken = $command->getField('params')['access_token'] ?? null;

        $providerId = $command->getField('params')['providerId'] ?? null;

        $returnUrl = $command->getField('params')['returnUrl'] ?? null;

        $isBackend = $command->getField('params')['isBackend'] ?? null;

        if (!$accessToken) {
            $result->setResult(CommandResult::RESULT_ERROR);
            $result->setMessage('Access token is required');
            return $result;
        }

        if ($providerId) {
            /** @var ProviderRepository $providerRepository */
            $providerRepository = $this->container->get('domain.users.providers.repository');

            /** @var GoogleCalendarRepository $googleCalendarRepository */
            $googleCalendarRepository = $this->container->get('domain.google.calendar.repository');

            $googleCalendar = GoogleCalendarFactory::create(['token' => $accessToken]);
            $googleCalendarRepository->beginTransaction();

            if (!$googleCalendarRepository->add($googleCalendar, $providerId)) {
                $googleCalendarRepository->rollback();
            }

            $googleCalendarRepository->commit();

            do_action('amelia_after_google_calendar_added', $googleCalendar ? $googleCalendar->toArray() : null, $providerId);

            $providerRepository->updateFieldById($providerId, null, 'googleCalendarId');

            $result->setResult(CommandResult::RESULT_SUCCESS);
            $result->setMessage('Successfully fetched access token');

            if ($returnUrl) {
                $result->setUrl($returnUrl);
            } else {
                $result->setUrl(AMELIA_SITE_URL . '/wp-admin/admin.php?page=wpamelia-employees#/manage/' . $providerId . '/integrations/google-calendar');
            }

            return $result;
        }

        /** @var SettingsService $settingsService */
        $settingsService = $this->container->get('domain.settings.service');

        /** @var AbstractGoogleCalendarMiddlewareService  $googleCalendarMiddlewareService */
        $googleCalendarMiddlewareService = $this->container->get('infrastructure.google.calendar.middleware.service');

        $googleSettings = $settingsService->getCategorySettings('googleCalendar');
        $googleAccountData = $googleCalendarMiddlewareService->getUserInfo($accessToken);
        $googleSettings['googleAccountData'] = [
            'name'    => $googleAccountData['name'],
            'email'   => $googleAccountData['email'],
            'picture' => $googleAccountData['picture']
        ];
        $googleSettings['accessToken'] = $accessToken;
        $settingsService->setCategorySettings('googleCalendar', $googleSettings);

        $result->setResult(CommandResult::RESULT_SUCCESS);
        $result->setMessage('Successfully fetched access token');
        $result->setData($googleAccountData);

        $result->setUrl(
            AMELIA_SITE_URL . '/wp-admin/admin.php?page=wpamelia-features-integrations#/integrations/google/general'
        );

        return $result;
    }
}
