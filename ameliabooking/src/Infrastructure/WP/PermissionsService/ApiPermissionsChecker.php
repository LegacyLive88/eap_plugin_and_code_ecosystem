<?php

/**
 * WP Infrastructure layer implementation of the permissions service.
 */

namespace AmeliaBooking\Infrastructure\WP\PermissionsService;

use AmeliaBooking\Domain\Entity\User\AbstractUser;
use AmeliaBooking\Domain\Services\Permissions\PermissionsCheckerInterface;

/**
 * Class ApiPermissionsChecker
 *
 * @package AmeliaBooking\Infrastructure\WP\PermissionsService
 */
class ApiPermissionsChecker implements PermissionsCheckerInterface
{
    /**
     * @param AbstractUser $user
     * @param string       $object
     * @param string       $permission
     *
     * @return bool
     */
    public function checkPermissions($user, $object, $permission)
    {
        return true;
    }
}
