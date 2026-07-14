<?php

/**
 * @copyright © Melograno Ventures. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace AmeliaBooking\Application\Commands\Activation;

use AmeliaBooking\Application\Commands\CommandHandler;
use AmeliaBooking\Application\Commands\CommandResult;
use AmeliaBooking\Domain\Services\Settings\SettingsService;
use AmeliaBooking\Infrastructure\WP\InstallActions\AutoUpdateHook;

/**
 * Class DeactivatePluginEnvatoCommandHandler
 *
 * @package AmeliaBooking\Application\Commands\Activation
 */
class DeactivatePluginEnvatoCommandHandler extends CommandHandler
{
    /**
     * @param DeactivatePluginEnvatoCommand $command
     *
     * @return CommandResult
     *
     */
    public function handle(DeactivatePluginEnvatoCommand $command)
    {
        $result = new CommandResult();

        /** @var SettingsService $settingsService */
        $settingsService = $this->container->get('domain.settings.service');

        // Get the purchase code from query string
        $envatoTokenEmail = trim($command->getField('params')['envatoTokenEmail']);

        // Get domain and subdomain from site URL
        $siteUrl = parse_url(AMELIA_SITE_URL, PHP_URL_HOST);
        $domain = AutoUpdateHook::getDomain($siteUrl);
        $subdomain = AutoUpdateHook::getSubDomain($siteUrl);

        // Call the Melograno Store API to check if purchase code is valid
        $ch = curl_init(
            AMELIA_STORE_API_URL . 'activation/envato/deactivate?slug=ameliabooking&envatoTokenEmail=' .
            $envatoTokenEmail . '&domain=' . $domain . '&subdomain=' . $subdomain
        );
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // Response from the Melograno Store
        $response = json_decode(curl_exec($ch));

        // Update Amelia Settings
        if ($response->deactivated === true || $response === null) {
            $settingsService->setSetting('activation', 'active', false);
            $settingsService->setSetting('activation', 'envatoTokenEmail', '');
        }

        $result->setResult(CommandResult::RESULT_SUCCESS);
        $result->setMessage('Successfully checked purchase code');
        $result->setData(
            [
            'deactivated' => $response->deactivated,
            ]
        );

        return $result;
    }
}
