<?php

namespace AmeliaBooking\Infrastructure\Licence;

use AmeliaBooking\Domain\Services\Settings\SettingsService;
use AmeliaBooking\Infrastructure\WP\SettingsService\SettingsStorage;

/**
 * Class EventListener
 *
 * @package AmeliaBooking\Infrastructure\Licence
 */
class EventListener extends Developer\EventListener
{
    /**
     * Map of license names to their corresponding class names
     */
    private static $licenseClassMap = [
        LicenceConstants::LITE => 'AmeliaBooking\Infrastructure\Licence\Lite\EventListener',
        LicenceConstants::STARTER => 'AmeliaBooking\Infrastructure\Licence\Starter\EventListener',
        LicenceConstants::BASIC => 'AmeliaBooking\Infrastructure\Licence\Basic\EventListener',
        LicenceConstants::PRO => 'AmeliaBooking\Infrastructure\Licence\Pro\EventListener',
        LicenceConstants::DEVELOPER => 'AmeliaBooking\Infrastructure\Licence\Developer\EventListener',
    ];

    /**
     * Get the appropriate license class based on settings (only in development mode)
     *
     * @return string The fully qualified class name of the license
     */
    private static function getLicenseClass()
    {
        // In production, always use the parent class (Developer)
        if (AMELIA_PRODUCTION) {
            return 'AmeliaBooking\Infrastructure\Licence\Developer\EventListener';
        }

        // In development, get the license from settings
        $settingsService = new SettingsService(new SettingsStorage());
        $currentLicense = $settingsService->getSetting('activation', 'licence');
        $currentLicense = !empty($currentLicense) ? $currentLicense : LicenceConstants::DEVELOPER;

        // Return the appropriate license class
        return self::$licenseClassMap[$currentLicense] ?? 'AmeliaBooking\Infrastructure\Licence\Developer\EventListener';
    }

    /**
     * Delegate static method calls to the appropriate license class in development mode
     *
     * @param string $method
     * @param array $arguments
     * @return mixed
     */
    public static function __callStatic($method, $arguments)
    {
        // In production, use normal inheritance (class extends were changed by build scripts)
        if (AMELIA_PRODUCTION) {
            return call_user_func_array(['parent', $method], $arguments);
        }

        // In development, dynamically load the appropriate license class
        $licenseClass = self::getLicenseClass();
        return call_user_func_array([$licenseClass, $method], $arguments);
    }
}
