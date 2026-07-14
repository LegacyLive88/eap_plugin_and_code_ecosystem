<?php

/**
 * @copyright © Melograno Ventures. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace AmeliaBooking\Application\Commands\Bookable\Package;

use AmeliaBooking\Application\Commands\CommandHandler;
use AmeliaBooking\Application\Commands\CommandResult;
use AmeliaBooking\Application\Common\Exceptions\AccessDeniedException;
use AmeliaBooking\Domain\Common\Exceptions\InvalidArgumentException;
use AmeliaBooking\Domain\Entity\Bookable\Service\Package;
use AmeliaBooking\Domain\Entity\Entities;
use AmeliaBooking\Infrastructure\Repository\Bookable\Service\PackageRepository;
use Interop\Container\Exception\ContainerException;
use Slim\Exception\ContainerValueNotFoundException;

/**
 * Class GetPackageCommandHandler
 *
 * @package AmeliaBooking\Application\Commands\Bookable\Package
 */
class GetPackageCommandHandler extends CommandHandler
{
    /**
     * @param GetPackageCommand $command
     *
     * @return CommandResult
     * @throws ContainerValueNotFoundException
     * @throws InvalidArgumentException
     * @throws AccessDeniedException
     * @throws ContainerException
     */
    public function handle(GetPackageCommand $command)
    {
        if (!$command->getPermissionService()->currentUserCanRead(Entities::PACKAGES)) {
            throw new AccessDeniedException('You are not allowed to read packages.');
        }

        $result = new CommandResult();

        $this->checkMandatoryFields($command);

        /** @var PackageRepository $packageRepository */
        $packageRepository = $this->container->get('domain.bookable.package.repository');

        $packageId = (int)$command->getField('id');

        /** @var Package $package */
        $package = $packageRepository->getById($packageId);

        if (!$package) {
            $result->setResult(CommandResult::RESULT_ERROR);
            $result->setMessage('Package not found.');
            return $result;
        }

        $packageArray = $package->toArray();

        $packageArray = apply_filters('amelia_get_package_filter', $packageArray);

        do_action('amelia_get_package', $packageArray);

        $result->setResult(CommandResult::RESULT_SUCCESS);
        $result->setMessage('Successfully retrieved package.');
        $result->setData(
            [
                Entities::PACKAGE => $packageArray
            ]
        );

        return $result;
    }
}
