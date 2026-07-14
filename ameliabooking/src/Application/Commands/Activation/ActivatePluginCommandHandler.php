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
 * Class ActivatePluginCommandHandler
 *
 * @package AmeliaBooking\Application\Commands\Activation
 */
class ActivatePluginCommandHandler extends CommandHandler
{
    /**
     * @param ActivatePluginCommand $command
     *
     * @return CommandResult
     *
     */
    public function handle(ActivatePluginCommand $command)
    {
        $result = new CommandResult();

        /** @var SettingsService $settingsService */
        $settingsService = $this->container->get('domain.settings.service');

        // Get the purchase code from query string
        $purchaseCode = trim($command->getField('params')['purchaseCodeStore']);

        // Get domain and subdomain from site URL
        $siteUrl = parse_url(AMELIA_SITE_URL, PHP_URL_HOST);
        $domain = AutoUpdateHook::getDomain($siteUrl);
        $subdomain = AutoUpdateHook::getSubDomain($siteUrl);

        // Call the Melograno Store API to check if purchase code is valid
        $ch = curl_init(
            AMELIA_STORE_API_URL . 'activation/code?slug=ameliabooking&purchaseCode=' . $purchaseCode .
            '&domain=' . $domain . '&subdomain=' . $subdomain
        );
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, apply_filters('amelia/curlopt_ssl_verifypeer', 1));

        // Response from the Melograno Store
        $response = json_decode(curl_exec($ch));

        // Update Amelia Settings
        $settingsService->setSetting('activation', 'active', $response->valid && $response->domainRegistered);

        if ($response->valid && $response->domainRegistered) {
            $settingsService->setSetting('activation', 'purchaseCodeStore', $purchaseCode);
        }

        $result->setResult(CommandResult::RESULT_SUCCESS);
        $result->setMessage('Successfully checked purchase code');
        $result->setData(
            [
            'valid'            => $response->valid,
            'domainRegistered' => $response->domainRegistered,
            ]
        );

        return $result;
    }
}
