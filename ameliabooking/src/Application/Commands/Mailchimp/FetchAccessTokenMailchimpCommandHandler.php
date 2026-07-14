<?php

namespace AmeliaBooking\Application\Commands\Mailchimp;

use AmeliaBooking\Application\Commands\CommandHandler;
use AmeliaBooking\Application\Commands\CommandResult;
use AmeliaBooking\Application\Common\Exceptions\AccessDeniedException;
use AmeliaBooking\Domain\Common\Exceptions\InvalidArgumentException;
use AmeliaBooking\Application\Services\Validation\ValidationService;
use AmeliaBooking\Domain\Entity\Entities;
use AmeliaBooking\Domain\Services\Settings\SettingsService;
use AmeliaBooking\Infrastructure\Common\Exceptions\QueryExecutionException;
use AmeliaBooking\Infrastructure\Services\Mailchimp\AbstractMailchimpService;

class FetchAccessTokenMailchimpCommandHandler extends CommandHandler
{
    /**
     * @throws InvalidArgumentException
     * @throws QueryExecutionException
     * @throws AccessDeniedException
     */
    public function handle(FetchAccessTokenMailchimpCommand $command): CommandResult
    {
        if (!$this->getContainer()->getPermissionsService()->currentUserCanWrite(Entities::SETTINGS)) {
            throw new AccessDeniedException('You are not allowed to write settings.');
        }

        $params = $command->getFields();

        $stringToVerify = "/mailchimp/authorization/token&access_token=" . $params['access_token'];

        if (!ValidationService::verifySignature($stringToVerify, 'middleware', !empty($params['signature']) ? $params['signature'] : null)) {
            throw new AccessDeniedException('Signature mismatch.');
        }

        $result = new CommandResult();

        $this->checkMandatoryFields($command);

        /** @var AbstractMailchimpService $mailchimpService */
        $mailchimpService = $this->container->get('infrastructure.mailchimp.service');

        /** @var SettingsService $settingsService */
        $settingsService = $this->container->get('domain.settings.service');

        $accessToken = $command->getFields()['access_token'];

        if (empty($accessToken)) {
            $result->setResult(CommandResult::RESULT_ERROR);
            $result->setMessage('Failed to fetch access token');
            $result->setData(
                [
                    'error' => $command->getFields()['error'],
                ]
            );

            $result->setUrl(AMELIA_SITE_URL . '/wp-admin/admin.php?page=wpamelia-settings&mailchimp=1&mailchimp_error=1');

            return $result;
        }

        $mailchimpSettings = $settingsService->getCategorySettings('mailchimp');
        $mailchimpSettings['accessToken'] = $accessToken;
        $mailchimpSettings['server'] = $mailchimpService->getMetadataServerName($accessToken);
        $settingsService->setCategorySettings('mailchimp', $mailchimpSettings);

        $lists = $mailchimpService->getLists();

        $result->setResult(CommandResult::RESULT_SUCCESS);
        $result->setMessage('Successfully fetched access token');
        $result->setData(
            [
                'lists' => $lists
            ]
        );

        $result->setUrl(AMELIA_SITE_URL . '/wp-admin/admin.php?page=wpamelia-features-integrations#/integrations/mailchimp');

        return $result;
    }
}
