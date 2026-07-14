<?php

namespace AmeliaBooking\Application\Services\User;

use AmeliaBooking\Application\Common\Exceptions\AccessDeniedException;
use AmeliaBooking\Domain\Common\Exceptions\AuthorizationException;
use AmeliaBooking\Domain\Common\Exceptions\InvalidArgumentException;
use AmeliaBooking\Domain\Entity\Booking\Appointment\CustomerBooking;
use AmeliaBooking\Domain\Entity\User\AbstractUser;
use AmeliaBooking\Domain\Factory\User\UserFactory;
use AmeliaBooking\Infrastructure\Common\Exceptions\QueryExecutionException;

/**
 * Class UserApplicationService
 *
 * @package AmeliaBooking\Application\Services\User
 */
class APIUserApplicationService extends UserApplicationService
{
    /**
     *
     * @param string $token
     * @param string $cabinetType
     *
     * @return AbstractUser
     *
     * @throws InvalidArgumentException
     */
    public function authorization($token, $cabinetType)
    {
        return UserFactory::create(
            [
                'type' => AbstractUser::USER_ROLE_ADMIN,
                'firstName' => 'AmeliaApi',
                'lastName' => 'AmeliaApi',
                'email' => 'admin@amelia.api',
                'status' => 'visible'
            ]
        );
    }

    /**
     * @param CustomerBooking $booking
     * @param AbstractUser    $user
     * @param string          $bookingToken
     *
     * @return boolean
     */
    public function isCustomerBooking($booking, $user, $bookingToken)
    {
        return true;
    }


    /**
     * @param AbstractUser $user
     *
     * @return boolean
     *
     */
    public function isCustomer($user)
    {
        return false;
    }

    /**
     * @param AbstractUser $currentUser
     * @param string $token
     *
     * @return boolean
     */
    public function checkProviderPermissions($currentUser, $token)
    {
        return false;
    }
}
