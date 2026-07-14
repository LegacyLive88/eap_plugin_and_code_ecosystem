<?php

/**
 * @copyright © Melograno Ventures. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace AmeliaBooking\Application\Commands\Bookable\Package;

use AmeliaBooking\Application\Commands\CommandHandler;
use AmeliaBooking\Application\Commands\CommandResult;
use AmeliaBooking\Application\Common\Exceptions\AccessDeniedException;
use AmeliaBooking\Domain\Collection\Collection;
use AmeliaBooking\Domain\Common\Exceptions\InvalidArgumentException;
use AmeliaBooking\Domain\Entity\Entities;
use AmeliaBooking\Infrastructure\Common\Exceptions\QueryExecutionException;
use AmeliaBooking\Infrastructure\Repository\Bookable\Service\PackageRepository;

/**
 * Class GetPackagesCommandHandler
 *
 * @package AmeliaBooking\Application\Commands\Bookable\Package
 */
class GetPackagesCommandHandler extends CommandHandler
{
    /**
     * @param GetPackagesCommand $command
     *
     * @return CommandResult
     * @throws \Slim\Exception\ContainerValueNotFoundException
     * @throws QueryExecutionException
     * @throws InvalidArgumentException
     * @throws AccessDeniedException
     */
    public function handle(GetPackagesCommand $command)
    {
        if (!$command->getPermissionService()->currentUserCanRead(Entities::PACKAGES)) {
            throw new AccessDeniedException('You are not allowed to read packages.');
        }

        $result = new CommandResult();

        $params = $command->getField('params');

        if (empty($params['sort'])) {
            $params['sort'] = 'id';
        }

        $this->checkMandatoryFields($command);

        /** @var PackageRepository $packageRepository */
        $packageRepository = $this->container->get('domain.bookable.package.repository');

        $packages = $packageRepository->getByCriteria($params);

        $packagesArray = $packages->toArray();

        $packagesArray = apply_filters('amelia_get_packages_filter', $packagesArray);

        do_action('amelia_get_packages', $packagesArray);

        $result->setResult(CommandResult::RESULT_SUCCESS);
        $result->setMessage('Successfully retrieved packages.');
        $result->setData(
            [
                Entities::PACKAGES => $packagesArray,
                'totalCount' => (int)$packageRepository->getCount([]),
                'filteredCount' => (int)$packageRepository->getCount($params),
            ]
        );

        return $result;
    }
}
