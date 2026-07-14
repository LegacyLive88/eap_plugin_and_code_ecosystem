<?php

/**
 * @copyright © Melograno Ventures. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace AmeliaBooking\Application\Commands\Bookable\Resource;

use AmeliaBooking\Application\Commands\CommandHandler;
use AmeliaBooking\Application\Commands\CommandResult;
use AmeliaBooking\Application\Common\Exceptions\AccessDeniedException;
use AmeliaBooking\Application\Services\Resource\AbstractResourceApplicationService;
use AmeliaBooking\Domain\Collection\Collection;
use AmeliaBooking\Domain\Common\Exceptions\InvalidArgumentException;
use AmeliaBooking\Domain\Entity\Entities;
use AmeliaBooking\Infrastructure\Common\Exceptions\NotFoundException;
use AmeliaBooking\Infrastructure\Common\Exceptions\QueryExecutionException;
use AmeliaBooking\Infrastructure\Repository\Bookable\Service\ResourceRepository;
use AmeliaBooking\Infrastructure\Repository\Bookable\Service\ServiceRepository;
use AmeliaBooking\Infrastructure\Repository\Location\LocationRepository;
use AmeliaBooking\Infrastructure\Repository\User\ProviderRepository;
use AmeliaBooking\Infrastructure\Repository\Bookable\Service\CategoryRepository;
use Slim\Exception\ContainerValueNotFoundException;

/**
 * Class GetResourcesCommandHandler
 *
 * @package AmeliaBooking\Application\Commands\Bookable\Resource
 */
class GetResourcesCommandHandler extends CommandHandler
{
    /**
     * @param GetResourcesCommand $command
     *
     * @return CommandResult
     * @throws ContainerValueNotFoundException
     * @throws InvalidArgumentException
     * @throws AccessDeniedException
     * @throws QueryExecutionException
     * @throws NotFoundException
     */
    public function handle(GetResourcesCommand $command)
    {
        if (!$command->getPermissionService()->currentUserCanRead(Entities::RESOURCES)) {
            throw new AccessDeniedException('You are not allowed to read resources.');
        }

        $result = new CommandResult();

        $this->checkMandatoryFields($command);

        /** @var ResourceRepository $resourceRepository */
        $resourceRepository = $this->container->get('domain.bookable.resource.repository');

        /** @var ServiceRepository $serviceRepository */
        $serviceRepository = $this->container->get('domain.bookable.service.repository');

        /** @var LocationRepository $locationRepository */
        $locationRepository = $this->container->get('domain.locations.repository');

        /** @var ProviderRepository $providerRepository */
        $providerRepository = $this->container->get('domain.users.providers.repository');

        /** @var CategoryRepository $categoryRepository */
        $categoryRepository = $this->container->get('domain.bookable.category.repository');

        $params = $command->getField('params');

        /** @var AbstractResourceApplicationService $resourceApplicationService */
        $resourceApplicationService = $this->container->get('application.resource.service');

        /** @var Collection $allServices */
        $allServices = $serviceRepository->getAllIndexedById();

        /** @var Collection $allCategories */
        $allCategories = $categoryRepository->getAllIndexedById();

        /** @var Collection $allLocations */
        $allLocations = $locationRepository->getAllIndexedById();

        /** @var Collection $allProviders */
        $allProviders = $providerRepository->getByFieldValue('type', Entities::PROVIDER);

        /** @var Collection $resources */
        $resources = $resourceApplicationService->getAll($params);

        $resourcesArray = $resources->toArray();

        // Process each resource to include related entities
        foreach ($resourcesArray as &$resource) {
            $serviceIds = [];
            $locationIds = [];
            $providerIds = [];

            $resource['services'] = [];
            $resource['locations'] = [];
            $resource['employees'] = [];

            foreach ($resource['entities'] as $entity) {
                switch ($entity['entityType']) {
                    case 'service':
                        $serviceIds[] = $entity['entityId'];
                        break;
                    case 'location':
                        $locationIds[] = $entity['entityId'];
                        break;
                    case 'employee':
                        $providerIds[] = $entity['entityId'];
                        break;
                }
            }

            /** @var Collection $services */
            $services = new Collection();

            foreach (array_unique($serviceIds) as $serviceId) {
                $services->addItem($allServices->getItem($serviceId), $serviceId);
            }

            if ($services->length()) {
                $servicesArray = $services->toArray();

                $categoryIds = [];
                foreach ($servicesArray as $service) {
                    if (!empty($service['categoryId'])) {
                        $categoryIds[] = $service['categoryId'];
                    }
                }

                $categories = new Collection();

                foreach (array_unique($categoryIds) as $categoryId) {
                    $categories->addItem($allCategories->getItem($categoryId), $categoryId);
                }

                $resource['services'] = array_map(function ($service) use ($categories) {
                    $categoryData = null;
                    if (!empty($service['categoryId']) && $categories->keyExists($service['categoryId'])) {
                        $categoryData = $categories->getItem($service['categoryId'])->getName()->getValue();
                    }

                    return [
                        'id' => $service['id'],
                        'name' => $service['name'],
                        'color' => $service['color'],
                        'category' => $categoryData,
                    ];
                }, $servicesArray);
            }

            /** @var Collection $locations */
            $locations = new Collection();

            foreach (array_unique($locationIds) as $locationId) {
                $locations->addItem($allLocations->getItem($locationId), $locationId);
            }

            if ($locations->length()) {
                $resource['locations'] = array_map(function ($location) {
                    return [
                        'id' => $location['id'],
                        'name' => $location['name'],
                        'address' => $location['address'],
                        'pictureThumbPath' => $location['pictureThumbPath']
                    ];
                }, $locations->toArray());
            }

            $providers = new Collection();

            foreach (array_unique($providerIds) as $providerId) {
                $providers->addItem($allProviders->getItem($providerId), $providerId);
            }

            if ($providers->length()) {
                $resource['employees'] = array_map(function ($provider) {
                    return [
                        'id' => $provider['id'],
                        'firstName' => $provider['firstName'],
                        'lastName' => $provider['lastName'],
                        'pictureThumbPath' => $provider['pictureThumbPath'],
                        'email' => $provider['email'],
                        'phone' => $provider['phone']
                    ];
                }, $providers->toArray());
            }
        }

        $resourcesArray = apply_filters('amelia_get_resources_filter', $resourcesArray);

        do_action('amelia_get_resources', $resourcesArray);

        $result->setResult(CommandResult::RESULT_SUCCESS);
        $result->setMessage('Successfully retrieved resources.');
        $result->setData(
            [
                Entities::RESOURCES => $resourcesArray,
                'totalCount' => (int) $resourceRepository->getCount([]),
                'filteredCount' => (int) $resourceRepository->getCount($params),
            ]
        );

        return $result;
    }
}
