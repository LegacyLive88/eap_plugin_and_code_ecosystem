<?php

namespace AmeliaBooking\Application\Commands\Notification;

use AmeliaBooking\Application\Commands\CommandHandler;
use AmeliaBooking\Application\Commands\CommandResult;
use AmeliaBooking\Application\Common\Exceptions\AccessDeniedException;
use AmeliaBooking\Application\Services\Notification\AbstractWhatsAppNotificationService;
use AmeliaBooking\Domain\Common\Exceptions\InvalidArgumentException;
use AmeliaBooking\Domain\Entity\Entities;
use AmeliaBooking\Domain\Services\Settings\SettingsService;
use AmeliaBooking\Infrastructure\Common\Exceptions\QueryExecutionException;
use Exception;
use Slim\Exception\ContainerException;
use Slim\Exception\ContainerValueNotFoundException;

/**
 * Class ValidateWhatsAppCredentialsCommandHandler
 *
 * @package AmeliaBooking\Application\Commands\Notification
 */
class ValidateWhatsAppCredentialsCommandHandler extends CommandHandler
{
    /**
     * @param ValidateWhatsAppCredentialsCommand $command
     *
     * @return CommandResult
     * @throws ContainerValueNotFoundException
     * @throws AccessDeniedException
     * @throws InvalidArgumentException
     * @throws QueryExecutionException
     * @throws ContainerException
     * @throws Exception
     */
    public function handle(ValidateWhatsAppCredentialsCommand $command)
    {
        $result = new CommandResult();

        if (!$this->getContainer()->getPermissionsService()->currentUserCanRead(Entities::SETTINGS)) {
            throw new AccessDeniedException('You are not allowed to read settings.');
        }

        /** @var AbstractWhatsAppNotificationService $whatsAppNotificationService */
        $whatsAppNotificationService = $this->container->get('application.whatsAppNotification.service');
        /** @var SettingsService $settingsService */
        $settingsService = $this->container->get('domain.settings.service');

        $token      = $command->getField('token');
        $businessId = $command->getField('businessId');
        $phoneId    = $command->getField('phoneId');

        if (empty($token) || empty($businessId) || empty($phoneId)) {
            $result->setResult(CommandResult::RESULT_ERROR);
            $result->setMessage('All WhatsApp credentials fields are required.');

            return $result;
        }

        if (
            $settingsService->getSetting('featuresIntegrations', 'whatsapp')['enabled']
            && !$whatsAppNotificationService->validateCredentials($token, $businessId, $phoneId)
        ) {
            $result->setResult(CommandResult::RESULT_ERROR);
            $result->setMessage('Make sure you are using the correct WhatsApp credentials.');

            return $result;
        }

        $tokenInfo = $whatsAppNotificationService->getWhatsAppTokenInfo($token);
        if (empty($tokenInfo['data'])) {
            $result->setResult(CommandResult::RESULT_ERROR);
            $result->setMessage('Unable to retrieve token information. Please check your credentials and try again.');

            return $result;
        }

        $result->setResult(CommandResult::RESULT_SUCCESS);
        $result->setData([
            'token_expires_at' => $tokenInfo['data']['expires_at']
        ]);
        $result->setMessage('WhatsApp credentials are valid.');

        return $result;
    }
}
