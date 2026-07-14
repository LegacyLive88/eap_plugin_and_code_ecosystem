<?php

/**
 * @copyright © Melograno Ventures. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace AmeliaBooking\Infrastructure\WP\ShortcodeService;

use AmeliaBooking\Domain\Common\Exceptions\InvalidArgumentException;

/**
 * Class CabinetCustomerShortcodeService
 *
 * @package AmeliaBooking\Infrastructure\WP\ShortcodeService
 */
class CabinetCustomerShortcodeService
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
                'trigger'      => '',
                'counter'      => AmeliaBookingShortcodeService::$counter,
                'appointments' => null,
                'events'       => null,
            ],
            $atts
        );

        AmeliaBookingShortcodeService::prepareScriptsAndStyles();

        ob_start();
        include AMELIA_PATH . '/view/frontend/cabinet-customer.inc.php';
        $html = ob_get_contents();
        ob_end_clean();

        return $html;
    }
}
