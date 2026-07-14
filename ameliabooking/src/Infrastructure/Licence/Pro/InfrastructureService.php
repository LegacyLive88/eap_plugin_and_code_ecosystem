<?php

namespace AmeliaBooking\Infrastructure\Licence\Pro;

use AmeliaBooking\Infrastructure\Common\Container;
use AmeliaBooking\Infrastructure\Services as InfrastructureServices;

/**
 * Class InfrastructureService
 *
 * @package AmeliaBooking\Infrastructure\Licence\Pro
 */
class InfrastructureService extends \AmeliaBooking\Infrastructure\Licence\Basic\InfrastructureService
{
    /**
     * @param Container $c
     *
     * @return InfrastructureServices\QrCode\AbstractQrCodeInfrastructureService
     */

    public static function getQrCodeService($c)
    {
        return new InfrastructureServices\QrCode\QrCodeInfrastructureService($c);
    }
}
