<?php

namespace AmeliaBooking\Application\Commands\Booking\Package;

use AmeliaBooking\Application\Commands\CommandHandler;
use AmeliaBooking\Application\Commands\CommandResult;
use AmeliaBooking\Application\Common\Exceptions\AccessDeniedException;
use AmeliaBooking\Domain\Common\Exceptions\AuthorizationException;
use AmeliaBooking\Domain\Entity\User\AbstractUser;
use AmeliaBooking\Infrastructure\Repository\Bookable\Service\PackageCustomerServiceRepository;

/**
 * Class GetPackageBookingServicesCommandHandler
 *
 * @package AmeliaBooking\Application\Commands\Booking\Package
 */
class GetPackageBookingServicesCommandHandler extends CommandHandler
{
    /**
     * @param GetPackageBookingServicesCommand $command
     *
     * @return CommandResult
     * @throws AccessDeniedException
     */
    public function handle(GetPackageBookingServicesCommand $command)
    {
        $result = new CommandResult();

        try {
            /** @var AbstractUser $user */
            $user = $command->getUserApplicationService()->authorization(null, $command->getCabinetType());
        } catch (AuthorizationException $e) {
            $result->setResult(CommandResult::RESULT_ERROR);
            $result->setData(
                [
                    'reauthorize' => true
                ]
            );

            return $result;
        }

        $packageCustomerId = $command->getArg('id');

        /** @var PackageCustomerServiceRepository $packageCustomerServiceRepository */
        $packageCustomerServiceRepository = $this->container->get('domain.bookable.packageCustomerService.repository');

        $availableServices = $packageCustomerServiceRepository->getAvailableServiceIds($packageCustomerId);

        $result->setResult(CommandResult::RESULT_SUCCESS);
        $result->setData([
            'services' => $availableServices
        ]);

        return $result;
    }
}
