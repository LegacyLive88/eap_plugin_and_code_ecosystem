<?php

/**
 * @copyright © Melograno Ventures. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace AmeliaBooking\Application\Commands\Invoice;

use AmeliaBooking\Application\Commands\CommandHandler;
use AmeliaBooking\Application\Commands\CommandResult;
use AmeliaBooking\Application\Common\Exceptions\AccessDeniedException;
use AmeliaBooking\Application\Services\Invoice\AbstractInvoiceApplicationService;
use AmeliaBooking\Application\Services\Notification\EmailNotificationService;
use AmeliaBooking\Application\Services\User\UserApplicationService;
use AmeliaBooking\Domain\Common\Exceptions\InvalidArgumentException;
use AmeliaBooking\Domain\Entity\Entities;
use AmeliaBooking\Domain\Entity\User\AbstractUser;
use AmeliaBooking\Domain\Services\Settings\SettingsService;
use AmeliaBooking\Infrastructure\Common\Exceptions\QueryExecutionException;

/**
 * Class GenerateInvoiceCommandHandler
 *
 * @package AmeliaBooking\Application\Commands\Invoice
 */
class GenerateInvoiceCommandHandler extends CommandHandler
{
    /**
     * @param GenerateInvoiceCommand $command
     *
     * @return CommandResult
     * @throws \Slim\Exception\ContainerValueNotFoundException
     * @throws QueryExecutionException
     * @throws InvalidArgumentException
     * @throws AccessDeniedException
     */
    public function handle(GenerateInvoiceCommand $command)
    {
        $result = new CommandResult();

        /** @var UserApplicationService $userAS */
        $userAS = $this->getContainer()->get('application.user.service');

        /** @var AbstractUser|null $currentUser */
        $currentUser = null;
        if (!$command->getPermissionService()->currentUserCanRead(Entities::FINANCE)) {
            if ($command->getToken()) {
                $currentUser = $userAS->getAuthenticatedUser($command->getToken(), false, 'customerCabinet');
                if ($currentUser === null) {
                    $result->setResult(CommandResult::RESULT_ERROR);
                    $result->setMessage('Could not retrieve user');
                    $result->setData(
                        [
                            'reauthorize' => true
                        ]
                    );

                    return $result;
                }
            } else {
                throw new AccessDeniedException('You are not allowed to generate this invoice.');
            }
        }


        /** @var SettingsService $settingsDS */
        $settingsDS = $this->container->get('domain.settings.service');

        if (!$settingsDS->isFeatureEnabled('invoices')) {
            $result->setMessage('Invoices are not enabled.');
            $result->setResult(CommandResult::RESULT_ERROR);
            return $result;
        }

        /** @var AbstractInvoiceApplicationService $invoiceService */
        $invoiceService = $this->container->get('application.invoice.service');

        $paymentId = $command->getArg('id');

        try {
            $file = $invoiceService->generateInvoice($paymentId, $currentUser ? $currentUser->getId()->getValue() : null, $command->getField('format'));
        } catch (AccessDeniedException $e) {
            $result->setMessage('You are not allowed to generate this invoice.');
            $result->setResult(CommandResult::RESULT_ERROR);
            return $result;
        } catch (\Exception $e) {
            $result->setMessage($e->getMessage());
            $result->setResult(CommandResult::RESULT_ERROR);
            return $result;
        }

        $customerId = $file['customerId'];

        unset($file['customerId']);

        if ($command->getField('sendEmail')) {
            /** @var EmailNotificationService $emailNotificationService */
            $emailNotificationService = $this->container->get('application.emailNotification.service');
            $emailNotificationService->sendInvoiceNotification($customerId, $file);
            $result->setMessage('Successfully sent email with invoice');
        } else {
            $file['content'] = base64_encode($file['content']);

            $result->setAttachment(true);
            $result->setFile($file);
            $result->setMessage('Successfully generated invoice PDF');
        }

        $result->setResult(CommandResult::RESULT_SUCCESS);

        return $result;
    }
}
