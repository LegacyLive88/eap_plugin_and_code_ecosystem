<?php

namespace AmeliaBooking\Application\Services\Deposit;

use AmeliaBooking\Domain\Entity\Bookable\AbstractBookable;
use AmeliaBooking\Domain\ValueObjects\String\DepositType;
use AmeliaBooking\Domain\Services\Settings\SettingsService;

/**
 * Class DepositApplicationService
 *
 * @package AmeliaBooking\Application\Services\Deposit
 */
class DepositApplicationService extends AbstractDepositApplicationService
{
    /**
     * @param float            $paymentAmount
     * @param AbstractBookable $bookable
     * @param int              $persons
     *
     * @return float
     */
    public function calculateDepositAmount($paymentAmount, $bookable, $persons)
    {
        /** @var SettingsService $settingsDS */
        $settingsDS = $this->container->get('domain.settings.service');


        if ($settingsDS->isFeatureEnabled('depositPayment') === false) {
            return $paymentAmount;
        }

        if ($bookable->getDepositPayment()->getValue() !== DepositType::DISABLED) {
            switch ($bookable->getDepositPayment()->getValue()) {
                case DepositType::FIXED:
                    if ($bookable->getDepositPerPerson() && $bookable->getDepositPerPerson()->getValue()) {
                        if ($paymentAmount > $persons * $bookable->getDeposit()->getValue()) {
                            return $persons * $bookable->getDeposit()->getValue();
                        }
                    } else {
                        if ($paymentAmount > $bookable->getDeposit()->getValue()) {
                            return $bookable->getDeposit()->getValue();
                        }
                    }

                    break;

                case DepositType::PERCENTAGE:
                    $depositAmount = round($paymentAmount / 100 * $bookable->getDeposit()->getValue(), 2);

                    if ($paymentAmount > $depositAmount) {
                        return $depositAmount;
                    }

                    break;
            }
        }

        return $paymentAmount;
    }
}
