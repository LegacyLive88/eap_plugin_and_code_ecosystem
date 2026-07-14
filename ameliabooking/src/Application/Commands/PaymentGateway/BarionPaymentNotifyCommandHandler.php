<?php

namespace AmeliaBooking\Application\Commands\PaymentGateway;

use AmeliaBooking\Application\Commands\CommandHandler;
use AmeliaBooking\Application\Commands\CommandResult;
use AmeliaBooking\Application\Services\Payment\PaymentApplicationService;
use AmeliaBooking\Domain\Entity\Cache\Cache;
use AmeliaBooking\Infrastructure\Repository\Cache\CacheRepository;
use AmeliaBooking\Infrastructure\Services\Payment\BarionService;
use AmeliaBooking\Infrastructure\WP\Translations\FrontendStrings;

class BarionPaymentNotifyCommandHandler extends CommandHandler
{
    public $mandatoryFields = [
        'name',
        'paymentId'
    ];

    public function handle(BarionPaymentNotifyCommand $command)
    {
        /** @var PaymentApplicationService $paymentAS */
        $paymentAS = $this->container->get('application.payment.service');

        /** @var CacheRepository $cacheRepository */
        $cacheRepository = $this->container->get('domain.cache.repository');

        /** @var BarionService $paymentServiceBarion */
        $paymentServiceBarion = $this->container->get('infrastructure.payment.barion.service');

        $result = new CommandResult();

        $this->checkMandatoryFields($command);

        $name = $command->getField('name');
        $paymentId = $command->getField('paymentId');
        $returnUrl = $command->getField('returnUrl');

        // Call Barion to get payment state
        $paymentState = $paymentServiceBarion->getPaymentState($paymentId);

        if ($paymentState['Status'] !== 'Succeeded') {
            $result->setResult(CommandResult::RESULT_ERROR);
            $result->setMessage(FrontendStrings::getCommonStrings()['payment_error']);
            $result->setData(
                [
                    'paymentSuccessful' => false,
                    'message' => 'Payment canceled'
                ]
            );
            $result->setUrl($returnUrl);

            return $result;
        }

        /** @var Cache $cache */
        $cache = ($data = explode('_', $name)) && isset($data[0], $data[1]) ?
            $cacheRepository->getByIdAndName($data[0], $data[1]) : null;

        if (!$cache || !$cache->getPaymentId()) {
            $result->setResult(CommandResult::RESULT_ERROR);
            $result->setMessage(FrontendStrings::getCommonStrings()['payment_error']);
            $result->setData(
                [
                    'message' => 'Cache object not saved',
                    'paymentSuccessful' => false,
                ]
            );

            return $result;
        }

        $status = 'paid';
        $result = $paymentAS->updateAppointmentAndCache($data[2], $status, $cache, $paymentId);

        $returnUrl = urldecode($command->getField('returnUrl'));
        $result->setUrl($returnUrl . (strpos($returnUrl, '?') ? '&' : '?') . 'ameliaCache=' . $name);
        return $result;
    }
}
