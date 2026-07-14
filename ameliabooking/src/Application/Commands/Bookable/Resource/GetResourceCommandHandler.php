<?php

/**
 * @copyright © Melograno Ventures. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace AmeliaBooking\Application\Commands\Bookable\Resource;

use AmeliaBooking\Application\Commands\CommandHandler;
use AmeliaBooking\Application\Commands\CommandResult;
use AmeliaBooking\Application\Common\Exceptions\AccessDeniedException;
use AmeliaBooking\Domain\Common\Exceptions\InvalidArgumentException;
use AmeliaBooking\Domain\Entity\Bookable\Service\Resource;
use AmeliaBooking\Domain\Entity\Entities;
use AmeliaBooking\Infrastructure\Common\Exceptions\QueryExecutionException;
use AmeliaBooking\Infrastructure\Repository\Bookable\Service\ResourceRepository;
use Interop\Container\Exception\ContainerException;
use Slim\Exception\ContainerValueNotFoundException;

/**
 * Class GetResourceCommandHandler
 *
 * @package AmeliaBooking\Application\Commands\Bookable\Resource
 */
class GetResourceCommandHandler extends CommandHandler
{
    /**
     * @param GetResourceCommand $command
     *
     * @return CommandResult
     * @throws ContainerValueNotFoundException
     * @throws InvalidArgumentException
     * @throws AccessDeniedException
     * @throws ContainerException
     * @throws QueryExecutionException
     */
    public function handle(GetResourceCommand $command)
    {
        if (!$command->getPermissionService()->currentUserCanRead(Entities::RESOURCES)) {
            throw new AccessDeniedException('You are not allowed to read resources.');
        }

        $result = new CommandResult();

        $this->checkMandatoryFields($command);

        /** @var ResourceRepository $resourceRepository */
        $resourceRepository = $this->container->get('domain.bookable.resource.repository');

        /** @var Resource $resource */
        $resource = $resourceRepository->getById((int)$command->getField('id'));

        if (!$resource) {
            $result->setResult(CommandResult::RESULT_ERROR);
            $result->setMessage('Resource not found.');
            return $result;
        }

        $resourceArray = $resource->toArray();

        if (isset($resourceArray['entities']) && is_array($resourceArray['entities'])) {
            $transformedEntities = [];
            foreach ($resourceArray['entities'] as $entity) {
                $transformedEntities[] = [
                    'entityId' => $entity['entityId'],
                    'entityType' => $entity['entityType']
                ];
            }
            $resourceArray['entities'] = $transformedEntities;
        }

        $resourceArray = apply_filters('amelia_get_resource_filter', $resourceArray);

        do_action('amelia_get_resource', $resourceArray);

        $result->setResult(CommandResult::RESULT_SUCCESS);
        $result->setMessage('Successfully retrieved resource.');
        $result->setData(
            [
                Entities::RESOURCE => $resourceArray
            ]
        );

        return $result;
    }
}
