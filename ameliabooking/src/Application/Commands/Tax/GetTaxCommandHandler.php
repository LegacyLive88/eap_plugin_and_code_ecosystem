<?php

/**
 * @copyright © Melograno Ventures. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace AmeliaBooking\Application\Commands\Tax;

use AmeliaBooking\Application\Commands\CommandHandler;
use AmeliaBooking\Application\Commands\CommandResult;
use AmeliaBooking\Application\Common\Exceptions\AccessDeniedException;
use AmeliaBooking\Application\Services\Tax\TaxApplicationService;
use AmeliaBooking\Domain\Common\Exceptions\InvalidArgumentException;
use AmeliaBooking\Domain\Entity\Tax\Tax;
use AmeliaBooking\Domain\Entity\Entities;
use AmeliaBooking\Infrastructure\Common\Exceptions\QueryExecutionException;
use AmeliaBooking\Infrastructure\Repository\Booking\Event\EventPeriodsRepository;
use AmeliaBooking\Infrastructure\Repository\Tax\TaxRepository;

/**
 * Class GetTaxCommandHandler
 *
 * @package AmeliaBooking\Application\Commands\Tax
 */
class GetTaxCommandHandler extends CommandHandler
{
    /**
     * @param GetTaxCommand $command
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
    public function handle(GetTaxCommand $command)
    {
        if (!$command->getPermissionService()->currentUserCanRead(Entities::TAXES)) {
            throw new AccessDeniedException('You are not allowed to read tax.');
        }

        $result = new CommandResult();

        $this->checkMandatoryFields($command);

        $taxId = $command->getArg('id');

        /** @var TaxRepository $taxRepository */
        $taxRepository = $this->container->get('domain.tax.repository');

        /** @var TaxApplicationService $taxApplicationService */
        $taxApplicationService = $this->container->get('application.tax.service');

        /** @var EventPeriodsRepository $eventPeriodsRepository */
        $eventPeriodsRepository = $this->container->get('domain.booking.event.period.repository');

        /** @var Tax $tax */
        $tax = $taxRepository->getById($taxId);

        $taxApplicationService->getTaxEntities(
            $tax,
            [
                'services' => array_column($tax->getServiceList()->toArray(), 'id'),
                'events'   => array_column($tax->getEventList()->toArray(), 'id'),
                'packages' => array_column($tax->getPackageList()->toArray(), 'id'),
                'extras'   => array_column($tax->getExtraList()->toArray(), 'id'),
            ]
        );

        foreach ($tax->getEventList()->getItems() as $event) {
            $periods = $eventPeriodsRepository->getByEntityId($event->getId()->getValue(), 'eventId');
            $event->setPeriods($periods);
        }

        $result->setResult(CommandResult::RESULT_SUCCESS);
        $result->setMessage('Successfully retrieved tax.');
        $result->setData(
            [
                Entities::TAX => $tax->toArray(),
            ]
        );

        return $result;
    }
}
