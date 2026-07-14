<?php

namespace AmeliaBooking\Application\Commands\Location;

use AmeliaBooking\Application\Commands\CommandResult;
use AmeliaBooking\Application\Commands\CommandHandler;
use AmeliaBooking\Application\Commands\SortParamsTrait;
use AmeliaBooking\Application\Common\Exceptions\AccessDeniedException;
use AmeliaBooking\Domain\Collection\AbstractCollection;
use AmeliaBooking\Domain\Common\Exceptions\InvalidArgumentException;
use AmeliaBooking\Domain\Entity\Entities;
use AmeliaBooking\Infrastructure\Common\Exceptions\QueryExecutionException;
use AmeliaBooking\Infrastructure\Repository\Location\LocationRepository;

/**
 * Class GetLocationsCommandHandler
 *
 * @package AmeliaBooking\Application\Commands\Location
 */
class GetLocationsCommandHandler extends CommandHandler
{
    use SortParamsTrait;

    /**
     * @param GetLocationsCommand $command
     *
     * @return CommandResult
     * @throws \Slim\Exception\ContainerValueNotFoundException
     * @throws QueryExecutionException
     * @throws InvalidArgumentException
     * @throws AccessDeniedException
     */
    public function handle(GetLocationsCommand $command)
    {
        if (!$command->getPermissionService()->currentUserCanRead(Entities::LOCATIONS)) {
            throw new AccessDeniedException('You are not allowed to read locations');
        }

        $result = new CommandResult();

        $params = $command->getField('params');

        $params = $this->parseSortParams($params, ['name']);

        $itemsPerPage = !empty($params['limit']) ? $params['limit'] : 10;

        /** @var LocationRepository $locationRepository */
        $locationRepository = $this->getContainer()->get('domain.locations.repository');

        $locations = $locationRepository->getFiltered($params, $itemsPerPage);

        if (!$locations instanceof AbstractCollection) {
            $result->setResult(CommandResult::RESULT_ERROR);
            $result->setMessage('Could not get locations');

            return $result;
        }

        $locationsArray = $locations->toArray();

        $locationsArray = apply_filters('amelia_get_locations_filter', $locationsArray);

        do_action('amelia_get_locations', $locationsArray);

        $result->setResult(CommandResult::RESULT_SUCCESS);
        $result->setMessage('Successfully retrieved locations.');
        $result->setData(
            [
            Entities::LOCATIONS => $locationsArray,
            'countFiltered'     => (int)$locationRepository->getCount($command->getField('params')),
            'countTotal'        => (int)$locationRepository->getCount([])
            ]
        );

        return $result;
    }
}
