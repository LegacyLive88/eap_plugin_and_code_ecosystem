<?php

namespace AmeliaBooking\Infrastructure\Licence\Developer;

use AmeliaBooking\Application\Services as ApplicationServices;
use AmeliaBooking\Infrastructure\Common\Container;

/**
 * Class ApplicationService
 *
 * @package AmeliaBooking\Infrastructure\Licence\Developer
 */
class ApplicationService extends \AmeliaBooking\Infrastructure\Licence\Pro\ApplicationService
{
    /**
     * @param Container $c
     *
     * @return ApplicationServices\User\UserApplicationService
     */
    public static function getApiService($c)
    {
        return new ApplicationServices\User\APIUserApplicationService($c);
    }
}
