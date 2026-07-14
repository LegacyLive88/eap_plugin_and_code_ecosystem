<?php

namespace AmeliaBooking\Application\Commands\Location;

use AmeliaBooking\Application\Commands\CommandResult;
use AmeliaBooking\Application\Commands\CommandHandler;
use AmeliaBooking\Application\Common\Exceptions\AccessDeniedException;
use AmeliaBooking\Domain\Common\Exceptions\InvalidArgumentException;
use AmeliaBooking\Domain\Entity\Entities;
use AmeliaBooking\Domain\Entity\Location\Location;
use AmeliaBooking\Domain\Repository\Location\LocationRepositoryInterface;
use AmeliaBooking\Infrastructure\Repository\Location\LocationRepository;

/**
 * Class GetLocationCommandHandler
 *
 * @package AmeliaBooking\Application\Commands\Location
 */
class GetLocationCommandHandler extends CommandHandler
{
    /**
     * @param GetLocationCommand $command
     *
     * @return CommandResult
     * @throws \Slim\Exception\ContainerValueNotFoundException
     * @throws InvalidArgumentException
     * @throws AccessDeniedException
     */
    public function handle(GetLocationCommand $command)
    {
        if (!$command->getPermissionService()->currentUserCanRead(Entities::LOCATIONS)) {
            throw new AccessDeniedException('You are not allowed to read location');
        }

        $result = new CommandResult();

        $this->checkMandatoryFields($command);

        /** @var LocationRepository $locationRepository */
        $locationRepository = $this->getContainer()->get('domain.locations.repository');

        $locations = $locationRepository->getByIdWithEntities($command->getArg('id'));

        if ($locations->length() === 0) {
            $result->setResult(CommandResult::RESULT_ERROR);
            $result->setMessage('Could not retrieve location');

            return $result;
        }

        $locationArray = $locations->toArray()[0];

        $locationArray = apply_filters('amelia_get_location_filter', $locationArray);

        do_action('amelia_get_location', $locationArray);

        $locationArray['providerList'] = array_map(
            function ($provider) {
                return [
                'id' => $provider['id'],
                'firstName' => $provider['firstName'],
                'lastName' => $provider['lastName'],
                'email' => $provider['email'],
                'phone' => $provider['phone'],
                'pictureThumbPath' => $provider['pictureThumbPath'],
                ];
            },
            $locationArray['providerList']
        );

        $locationArray['eventList'] = array_map(
            function ($event) {
                return [
                    'id' => $event['id'],
                    'name' => $event['name'],
                    'color' => $event['color'],
                    'periods' => $event['periods']
                ];
            },
            $locationArray['eventList']
        );

        $locationArray['serviceList'] = array_map(
            function ($service) {
                return [
                    'id' => $service['id'],
                    'name' => $service['name'],
                    'category' => $service['category']['name'],
                    'color' => $service['color']
                ];
            },
            $locationArray['serviceList']
        );


        $result->setResult(CommandResult::RESULT_SUCCESS);
        $result->setMessage('Successfully retrieved location.');
        $result->setData(
            [
            Entities::LOCATION => $locationArray
            ]
        );

        return $result;
    }
}
