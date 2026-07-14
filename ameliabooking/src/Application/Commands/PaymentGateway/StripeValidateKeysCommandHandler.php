<?php

namespace AmeliaBooking\Application\Commands\PaymentGateway;

use AmeliaBooking\Application\Commands\CommandHandler;
use AmeliaBooking\Application\Commands\CommandResult;
use AmeliaBooking\Application\Common\Exceptions\AccessDeniedException;
use AmeliaBooking\Domain\Entity\Entities;
use AmeliaBooking\Infrastructure\Services\Payment\StripeService;
use AmeliaVendor\Psr\Container\ContainerExceptionInterface;
use Exception;

/**
 * Class ValidateStripeKeysCommandHandler
 *
 * @package AmeliaBooking\Application\Commands\Settings
 */
class StripeValidateKeysCommandHandler extends CommandHandler
{
    protected $mandatoryFields = [
        'publishableKey',
        'secretKey',
        'testMode'
    ];

    /**
     * @param StripeValidateKeysCommand $command
     *
     * @return CommandResult
     * @throws Exception | ContainerExceptionInterface | AccessDeniedException
     */
    public function handle(StripeValidateKeysCommand $command): CommandResult
    {
        $result = new CommandResult();

        if (!$this->getContainer()->getPermissionsService()->currentUserCanRead(Entities::SETTINGS)) {
            throw new AccessDeniedException('You are not allowed to read settings.');
        }

        $this->checkMandatoryFields($command);

        /** @var StripeService $stripeService */
        $stripeService = $this->getContainer()->get('infrastructure.payment.stripe.service');

        $publishableKey = $command->getField('publishableKey');
        $secretKey = $command->getField('secretKey');
        $testMode = $command->getField('testMode');

        $validation = $stripeService->validateKeys($publishableKey, $secretKey, $testMode);

        if ($validation['valid']) {
            $result->setResult(CommandResult::RESULT_SUCCESS);
            $result->setMessage($validation['message']);
            $result->setData($validation);
        } else {
            $result->setResult(CommandResult::RESULT_ERROR);
            $result->setMessage($validation['message']);
            $result->setData($validation);
        }

        return $result;
    }
}
