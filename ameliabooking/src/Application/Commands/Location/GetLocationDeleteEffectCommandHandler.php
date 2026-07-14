<?php

namespace AmeliaBooking\Application\Commands\Location;

use AmeliaBooking\Application\Common\Exceptions\AccessDeniedException;
use AmeliaBooking\Domain\Collection\Collection;
use AmeliaBooking\Domain\Common\Exceptions\InvalidArgumentException;
use AmeliaBooking\Domain\Entity\Entities;
use AmeliaBooking\Application\Commands\CommandResult;
use AmeliaBooking\Application\Commands\CommandHandler;
use AmeliaBooking\Domain\Repository\Location\LocationRepositoryInterface;

/**
 * Class GetLocationDeleteEffectCommandHandler
 *
 * @package AmeliaBooking\Application\Commands\Location
 */
class GetLocationDeleteEffectCommandHandler extends CommandHandler
{
    /**
     * @param GetLocationDeleteEffectCommand $command
     *
     * @return CommandResult
     * @throws \Slim\Exception\ContainerValueNotFoundException
     * @throws InvalidArgumentException
     * @throws AccessDeniedException
     */
    public function handle(GetLocationDeleteEffectCommand $command)
    {
        if (!$command->getPermissionService()->currentUserCanRead(Entities::LOCATIONS)) {
            throw new AccessDeniedException('You are not allowed to read location');
        }

        $result = new CommandResult();

        $this->checkMandatoryFields($command);

        /** @var LocationRepositoryInterface $locationRepository */
        $locationRepository = $this->getContainer()->get('domain.locations.repository');

        /** @var Collection $services */
        $services      = $locationRepository->getServicesById($command->getArg('id'));

        $messageKey = '';
        $messageData = null;

        if ($services->length()) {
            $messageKey = 'red_delete_location_effect';
            $messageData = ['count' => $services->length()];
        }

        $result->setResult(CommandResult::RESULT_SUCCESS);
        $result->setMessage('Successfully retrieved message.');
        $result->setData(
            [
            'valid'   => true,
                'messageKey'  => $messageKey,
                'messageData' => $messageData,
            ]
        );

        return $result;
    }
}
