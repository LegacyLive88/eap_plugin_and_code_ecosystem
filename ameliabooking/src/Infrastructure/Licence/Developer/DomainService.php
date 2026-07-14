<?php

namespace AmeliaBooking\Infrastructure\Licence\Developer;

use AmeliaBooking\Domain\Services as DomainServices;
use Interop\Container\Exception\ContainerException;

/**
 * Class DomainService
 *
 * @package AmeliaBooking\Infrastructure\Licence\Developer
 */
class DomainService extends \AmeliaBooking\Infrastructure\Licence\Pro\DomainService
{
    /**
     * Container $c
     *
     * @return DomainServices\Permissions\PermissionsService
     * @throws ContainerException
     */
    public static function getPermissionService($c)
    {
        return new DomainServices\Permissions\PermissionsService(
            $c,
            new \AmeliaBooking\Infrastructure\WP\PermissionsService\ApiPermissionsChecker()
        );
    }

    /**
     * @return DomainServices\Api\BasicApiService
     */
    public static function getApiService()
    {
        return new DomainServices\Api\ApiService();
    }
}
