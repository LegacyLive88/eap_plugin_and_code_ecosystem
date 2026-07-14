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
 * Class ValidateActivationCommandHandler
 *
 * @package AmeliaBooking\Application\Commands\Activation
 */
class ValidateActivationCommandHandler extends CommandHandler
{
    public function handle(): CommandResult
    {
        $result = new CommandResult();

        /** @var SettingsService $settingsService */
        $settingsService = $this->container->get('domain.settings.service');

        $response = AutoUpdateHook::checkInfo(false, 'plugin_information', (object) [ 'slug' => AMELIA_PLUGIN_SLUG ]);

        $isValid = false;

        if (is_object($response) && isset($response->valid)) {
            $isValid = (bool)$response->valid;
        }

        if ($isValid === false) {
            $settingsService->setSetting('activation', 'active', false);
            $settingsService->setSetting('activation', 'purchaseCodeStore', '');
            $settingsService->setSetting('activation', 'envatoTokenEmail', '');
        }

        $result->setResult(CommandResult::RESULT_SUCCESS);
        $result->setMessage('Successfully validated activation.');
        $result->setData(['valid' => $isValid]);

        return $result;
    }
}
