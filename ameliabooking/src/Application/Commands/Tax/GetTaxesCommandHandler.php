<?php

/**
 * @copyright © Melograno Ventures. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace AmeliaBooking\Application\Commands\Tax;

use AmeliaBooking\Application\Commands\CommandHandler;
use AmeliaBooking\Application\Commands\CommandResult;
use AmeliaBooking\Application\Commands\SortParamsTrait;
use AmeliaBooking\Application\Common\Exceptions\AccessDeniedException;
use AmeliaBooking\Application\Services\Tax\TaxApplicationService;
use AmeliaBooking\Domain\Collection\Collection;
use AmeliaBooking\Domain\Common\Exceptions\InvalidArgumentException;
use AmeliaBooking\Domain\Entity\Entities;
use AmeliaBooking\Domain\Entity\Tax\Tax;
use AmeliaBooking\Domain\Services\Settings\SettingsService;
use AmeliaBooking\Infrastructure\Common\Exceptions\NotFoundException;
use AmeliaBooking\Infrastructure\Common\Exceptions\QueryExecutionException;
use AmeliaBooking\Infrastructure\Repository\Tax\TaxRepository;
use Slim\Exception\ContainerException;
use Slim\Exception\ContainerValueNotFoundException;

/**
 * Class GetTaxesCommandHandler
 *
 * @package AmeliaBooking\Application\Commands\Tax
 */
class GetTaxesCommandHandler extends CommandHandler
{
    use SortParamsTrait;

    /**
     * @param GetTaxesCommand $command
     *
     * @return CommandResult
     * @throws ContainerException
     * @throws \InvalidArgumentException
     * @throws ContainerValueNotFoundException
     * @throws QueryExecutionException
     * @throws InvalidArgumentException
     * @throws AccessDeniedException
     */
    public function handle(GetTaxesCommand $command)
    {
        if (!$command->getPermissionService()->currentUserCanRead(Entities::TAXES)) {
            throw new AccessDeniedException('You are not allowed to read taxes.');
        }

        $result = new CommandResult();

        $this->checkMandatoryFields($command);

        $params = $command->getField('params');

        /** @var TaxRepository $taxRepository */
        $taxRepository = $this->container->get('domain.tax.repository');

        /** @var TaxApplicationService $taxApplicationService */
        $taxApplicationService = $this->container->get('application.tax.service');

        /** @var SettingsService $settingsService */
        $settingsService = $this->container->get('domain.settings.service');

        $params = $this->parseSortParams($params, ['name', 'type']);

        /** @var Collection $taxes */
        $taxes = $taxRepository->getFiltered(
            $params,
            $params['limit'] ??
            $settingsService->getSetting('general', 'itemsPerPage')
        );

        /** @var Collection $taxes */
        $taxes = $taxes->length() ? $taxRepository->getWithEntities(
            ['ids' => $taxes->keys(), 'events' => true]
        ) : new Collection();

        /** @var Tax $tax */
        foreach ($taxes->getItems() as $tax) {
            $taxApplicationService->getTaxEntities(
                $tax,
                [
                    'services' => array_column($tax->getServiceList()->toArray(), 'id'),
                    'events'   => array_column($tax->getEventList()->toArray(), 'id'),
                    'packages' => array_column($tax->getPackageList()->toArray(), 'id'),
                    'extras'   => array_column($tax->getExtraList()->toArray(), 'id'),
                ]
            );
        }

        $result->setResult(CommandResult::RESULT_SUCCESS);
        $result->setMessage('Successfully retrieved taxes.');
        $result->setData(
            [
                Entities::TAXES => $taxes->toArray(),
                'filteredCount' => (int)$taxRepository->getCount($command->getField('params')),
                'totalCount'    => (int)$taxRepository->getCount([]),
            ]
        );

        return $result;
    }
}
