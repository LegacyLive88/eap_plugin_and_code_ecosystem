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
 * Class ParseDomainCommandHandler
 *
 * @package AmeliaBooking\Application\Commands\Activation
 */
class ParseDomainCommandHandler extends CommandHandler
{
    /**
     * @param ParseDomainCommand $command
     *
     * @return CommandResult
     */
    public function handle(ParseDomainCommand $command)
    {
        $result = new CommandResult();

        // Get domain and subdomain from site URL
        $siteUrl = parse_url(AMELIA_SITE_URL, PHP_URL_HOST);
        $domain = AutoUpdateHook::getDomain($siteUrl);
        $subdomain = AutoUpdateHook::getSubDomain($siteUrl);

        $result->setResult(CommandResult::RESULT_SUCCESS);
        $result->setMessage('Successfully parsed domain');
        $result->setData(
            [
            'domain'    => $domain,
            'subdomain' => $subdomain
            ]
        );

        return $result;
    }
}
