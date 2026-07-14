<?php

namespace AmeliaBooking\Application\Commands\Outlook;

use AmeliaBooking\Application\Commands\CommandHandler;
use AmeliaBooking\Application\Commands\CommandResult;
use AmeliaBooking\Domain\Common\Exceptions\InvalidArgumentException;
use AmeliaBooking\Domain\Factory\Outlook\OutlookCalendarFactory;
use AmeliaBooking\Domain\Services\Settings\SettingsService;
use AmeliaBooking\Infrastructure\Common\Exceptions\QueryExecutionException;
use AmeliaBooking\Infrastructure\Repository\Outlook\OutlookCalendarRepository;
use AmeliaBooking\Infrastructure\Repository\User\ProviderRepository;
use AmeliaBooking\Infrastructure\Services\Outlook\AbstractOutlookCalendarMiddlewareService;

class FetchOutlookMiddlewareAccessTokenCommandHandler extends CommandHandler
{
    /**
     * @throws QueryExecutionException
     * @throws InvalidArgumentException
     */
    public function handle(FetchOutlookMiddlewareAccessTokenCommand $command): CommandResult
    {
        $result = new CommandResult();

        $accessToken = $command->getField('params')['access_token'] ?? null;

        $providerId = $command->getField('params')['providerId'] ?? null;

        $returnUrl = $command->getField('params')['returnUrl'] ?? null;

        if (!$accessToken) {
            $result->setResult(CommandResult::RESULT_ERROR);
            $result->setMessage('Access token is required');
            return $result;
        }

        if ($providerId) {
            /** @var ProviderRepository $providerRepository */
            $providerRepository = $this->container->get('domain.users.providers.repository');

            /** @var OutlookCalendarRepository $outlookCalendarRepository */
            $outlookCalendarRepository = $this->container->get('domain.outlook.calendar.repository');

            $outlookCalendar = OutlookCalendarFactory::create(['token' => $accessToken]);
            $outlookCalendarRepository->beginTransaction();

            if (!$outlookCalendarRepository->add($outlookCalendar, $providerId)) {
                $outlookCalendarRepository->rollback();
            }

            $outlookCalendarRepository->commit();

            do_action('amelia_after_outlook_calendar_added', $outlookCalendar ? $outlookCalendar->toArray() : null, $providerId);

            $providerRepository->updateFieldById($providerId, null, 'outlookCalendarId');

            $result->setResult(CommandResult::RESULT_SUCCESS);
            $result->setMessage('Successfully fetched access token');

            if ($returnUrl) {
                $result->setUrl($returnUrl);
                return $result;
            }

            $result->setUrl(AMELIA_SITE_URL . '/wp-admin/admin.php?page=wpamelia-employees#/manage/' . $providerId . '/integrations/outlook-calendar');

            return $result;
        }

        /** @var SettingsService $settingsService */
        $settingsService = $this->container->get('domain.settings.service');

        $outlookSettings = $settingsService->getCategorySettings('outlookCalendar');
        $outlookSettings['accessToken'] = $accessToken;
        /** @var AbstractOutlookCalendarMiddlewareService  $outlookCalendarMiddlewareService */
        $outlookCalendarMiddlewareService = $this->container->get('infrastructure.outlook.calendar.middleware.service');
        $outlookAccountData = $outlookCalendarMiddlewareService->getUserInfo($accessToken);
        $outlookSettings['mailEnabled'] = true;

        $outlookSettings['outlookAccountData'] = [
            'name'    => $outlookAccountData['name'],
            'email'   => $outlookAccountData['email'],
            'picture' => $outlookAccountData['picture']
        ];

        $settingsService->setCategorySettings('outlookCalendar', $outlookSettings);

        $result->setResult(CommandResult::RESULT_SUCCESS);
        $result->setMessage('Successfully fetched access token');
        $result->setData($outlookAccountData);

        $result->setUrl(
            AMELIA_SITE_URL . '/wp-admin/admin.php?page=wpamelia-features-integrations#/integrations/microsoft/general'
        );

        return $result;
    }
}
