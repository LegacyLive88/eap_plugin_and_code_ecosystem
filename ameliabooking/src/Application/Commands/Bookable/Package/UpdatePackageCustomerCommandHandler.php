<?php

/**
 * @copyright © Melograno Ventures. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace AmeliaBooking\Application\Commands\Bookable\Package;

use AmeliaBooking\Application\Commands\CommandHandler;
use AmeliaBooking\Application\Commands\CommandResult;
use AmeliaBooking\Application\Common\Exceptions\AccessDeniedException;
use AmeliaBooking\Application\Services\User\UserApplicationService;
use AmeliaBooking\Domain\Common\Exceptions\InvalidArgumentException;
use AmeliaBooking\Domain\Entity\Bookable\Service\PackageCustomer;
use AmeliaBooking\Domain\Entity\Entities;
use AmeliaBooking\Domain\Entity\User\AbstractUser;
use AmeliaBooking\Domain\Factory\Bookable\Service\PackageCustomerFactory;
use AmeliaBooking\Domain\Services\DateTime\DateTimeService;
use AmeliaBooking\Infrastructure\Common\Exceptions\NotFoundException;
use AmeliaBooking\Infrastructure\Common\Exceptions\QueryExecutionException;
use AmeliaBooking\Infrastructure\Repository\Bookable\Service\PackageCustomerRepository;
use Interop\Container\Exception\ContainerException;
use Slim\Exception\ContainerValueNotFoundException;

/**
 * Class UpdatePackageCustomerCommandHandler
 *
 * @package AmeliaBooking\Application\Commands\Bookable\Package
 */
class UpdatePackageCustomerCommandHandler extends CommandHandler
{
    /**
     * @param UpdatePackageCustomerCommand $command
     *
     * @return CommandResult
     * @throws ContainerValueNotFoundException
     * @throws InvalidArgumentException
     * @throws AccessDeniedException
     * @throws ContainerException
     * @throws QueryExecutionException
     * @throws NotFoundException
     */
    public function handle(UpdatePackageCustomerCommand $command)
    {
        $result = new CommandResult();

        /** @var UserApplicationService $userAS */
        $userAS = $this->getContainer()->get('application.user.service');

        /** @var AbstractUser|null $user */
        $user = null;

        if (!$command->getPermissionService()->currentUserCanWrite(Entities::PACKAGES)) {
            /** @var AbstractUser $user */
            $user = $userAS->getAuthenticatedUser($command->getToken(), false, 'customerCabinet');

            if ($user === null) {
                $result->setResult(CommandResult::RESULT_ERROR);
                $result->setMessage('Could not retrieve user');
                $result->setData(
                    [
                        'reauthorize' => true
                    ]
                );

                return $result;
            }
        }

        $this->checkMandatoryFields($command);

        /** @var PackageCustomerRepository $packageCustomerRepository */
        $packageCustomerRepository = $this->container->get('domain.bookable.packageCustomer.repository');

        $packageCustomerId = $command->getArg('id');

        $packageCustomerId = apply_filters('amelia_before_package_customer_status_updated_filter', $packageCustomerId, $command->getField('status'));

        /** @var PackageCustomer $oldPackageCustomer */
        $oldPackageCustomer = $packageCustomerRepository->getById($packageCustomerId);

        if ($user && $oldPackageCustomer->getCustomerId()->getValue() !== $user->getId()->getValue()) {
            throw new AccessDeniedException('You are not allowed to update status');
        }

        $packageCustomerArray = $command->getFields();

        $oldPackageCustomerArray = $oldPackageCustomer->toArray();

        $wasExpired = !empty($oldPackageCustomerArray['end']) &&
            DateTimeService::getCustomDateTimeObjectFromUtc($oldPackageCustomerArray['end']) < DateTimeService::getNowDateTimeObject();

        if (isset($packageCustomerArray['expirationDate'])) {
            if ($packageCustomerArray['expirationDate'] !== '') {
                $packageCustomerArray['end'] = DateTimeService::getCustomDateTimeObjectInUtc(
                    $packageCustomerArray['expirationDate']
                )->format('Y-m-d H:i:s');
            } else {
                $packageCustomerArray['end'] = null;
            }
        }

        if (isset($packageCustomerArray['status'])) {
            if ($packageCustomerArray['status'] === 'expired') {
                $packageCustomerArray['status'] = 'approved';
                if (empty($oldPackageCustomerArray['end']) || !$wasExpired) {
                    $packageCustomerArray['end'] = DateTimeService::getNowDateTimeObjectInUtc()->format('Y-m-d H:i:s');
                }
            }
            if ($packageCustomerArray['status'] === 'active') {
                if ($wasExpired) {
                    $packageCustomerArray['end'] = null;
                }
                $packageCustomerArray['status'] = 'approved';
            }
        }

        $packageCustomer = array_merge($oldPackageCustomerArray, $packageCustomerArray);

        $newPackageCustomer = PackageCustomerFactory::create($packageCustomer);

        do_action('amelia_before_package_customer_status_updated', $newPackageCustomer->toArray(), $command->getField('status'));

        $packageCustomerRepository->update(
            $command->getArg('id'),
            $newPackageCustomer
        );

        do_action('amelia_after_package_customer_status_updated', $newPackageCustomer->toArray(), $command->getField('status'));

        if ($packageCustomer['status'] === 'approved') {
            if (
                !empty($packageCustomer['end']) &&
                DateTimeService::getCustomDateTimeObjectFromUtc($packageCustomer['end']) < DateTimeService::getNowDateTimeObject()
            ) {
                $status = 'expired';
            } else {
                $status = 'active';
            }
        } else {
            $status = 'canceled';
        }
        $packageCustomer['status'] = $status;
        $packageCustomer['end']    = !empty($packageCustomer['end']) ? DateTimeService::getCustomDateTimeFromUtc($packageCustomer['end']) : null;

        $result->setResult(CommandResult::RESULT_SUCCESS);
        $result->setMessage('Successfully updated package');
        $result->setData(
            [
                'packageCustomer' => $packageCustomer,
            ]
        );

        return $result;
    }
}
