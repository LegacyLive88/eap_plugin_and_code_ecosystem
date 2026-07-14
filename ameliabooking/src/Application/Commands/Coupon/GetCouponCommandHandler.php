<?php

/**
 * @copyright © Melograno Ventures. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace AmeliaBooking\Application\Commands\Coupon;

use AmeliaBooking\Application\Commands\CommandHandler;
use AmeliaBooking\Application\Commands\CommandResult;
use AmeliaBooking\Application\Common\Exceptions\AccessDeniedException;
use AmeliaBooking\Domain\Common\Exceptions\InvalidArgumentException;
use AmeliaBooking\Domain\Entity\Coupon\Coupon;
use AmeliaBooking\Domain\Entity\Entities;
use AmeliaBooking\Domain\ValueObjects\Number\Integer\WholeNumber;
use AmeliaBooking\Infrastructure\Common\Exceptions\QueryExecutionException;
use AmeliaBooking\Infrastructure\Repository\Bookable\Service\PackageCustomerRepository;
use AmeliaBooking\Infrastructure\Repository\Coupon\CouponRepository;

/**
 * Class GetCouponCommandHandler
 *
 * @package AmeliaBooking\Application\Commands\Coupon
 */
class GetCouponCommandHandler extends CommandHandler
{
    /**
     * @param GetCouponCommand $command
     *
     * @return CommandResult
     * @throws \Slim\Exception\ContainerValueNotFoundException
     * @throws \Slim\Exception\ContainerException
     * @throws \InvalidArgumentException
     * @throws QueryExecutionException
     * @throws InvalidArgumentException
     * @throws AccessDeniedException
     * @throws \AmeliaBooking\Infrastructure\Common\Exceptions\NotFoundException
     */
    public function handle(GetCouponCommand $command)
    {
        if (!$command->getPermissionService()->currentUserCanRead(Entities::COUPONS)) {
            throw new AccessDeniedException('You are not allowed to read coupon.');
        }

        $result = new CommandResult();

        $this->checkMandatoryFields($command);

        /** @var CouponRepository $couponRepository */
        $couponRepository = $this->container->get('domain.coupon.repository');

        /** @var Coupon $coupon */
        $coupon = $couponRepository->getById($command->getArg('id'));

        /** @var PackageCustomerRepository $packageCustomerRepository */
        $packageCustomerRepository = $this->container->get('domain.bookable.packageCustomer.repository');

        $packageCustomerRecords = $packageCustomerRepository->getByEntityId(
            $coupon->getId()->getValue(),
            'couponId'
        );

        $coupon->setUsed(new WholeNumber($coupon->getUsed()->getValue() + $packageCustomerRecords->length()));

        $couponArray = $coupon->toArray();

        $couponArray = apply_filters('amelia_get_coupon_filter', $couponArray);

        do_action('amelia_get_coupon', $couponArray);

        $result->setResult(CommandResult::RESULT_SUCCESS);
        $result->setMessage('Successfully retrieved coupon.');
        $result->setData(
            [
                Entities::COUPON => $couponArray,
            ]
        );

        return $result;
    }
}
