<?php

/**
 * @copyright © Melograno Ventures. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace AmeliaBooking\Infrastructure\WP\ShortcodeService;

use AmeliaBooking\Domain\Common\Exceptions\InvalidArgumentException;

/**
 * Class CabinetEmployeeShortcodeService
 *
 * @package AmeliaBooking\Infrastructure\WP\ShortcodeService
 */
class CabinetEmployeeShortcodeService
{
    /**
     * @param array $atts
     * @return string
     * @throws InvalidArgumentException
     */
    public static function shortcodeHandler($atts)
    {
        $atts = shortcode_atts(
            [
                'trigger'        => '',
                'counter'        => AmeliaBookingShortcodeService::$counter,
                'appointments'   => null,
                'events'         => null,
                'profile-hidden' => null,
            ],
            $atts
        );

        AmeliaBookingShortcodeService::prepareScriptsAndStyles();

        ob_start();
        include AMELIA_PATH . '/view/frontend/cabinet-employee.inc.php';
        $html = ob_get_contents();
        ob_end_clean();

        return $html;
    }
}
