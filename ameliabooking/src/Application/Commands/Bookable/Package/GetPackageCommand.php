<?php

/**
 * @copyright © Melograno Ventures. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace AmeliaBooking\Application\Commands\Bookable\Package;

use AmeliaBooking\Application\Commands\Command;

/**
 * Class GetPackageCommand
 *
 * @package AmeliaBooking\Application\Commands\Bookable\Package
 */
class GetPackageCommand extends Command
{
    /**
     * GetPackageCommand constructor.
     *
     * @param $args
     */
    public function __construct($args)
    {
        parent::__construct($args);
        if (isset($args['id'])) {
            $this->setField('id', $args['id']);
        }
    }
}
