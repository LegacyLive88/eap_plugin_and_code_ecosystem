<?php

namespace AmeliaBooking\Application\Commands\Google;

use AmeliaBooking\Application\Commands\Command;

class GetGoogleMiddlewareAuthURLCommand extends Command
{
    /**
     * GetGoogleMiddlewareAuthURLCommand constructor.
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
