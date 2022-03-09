<?php

namespace ImportWPAddon\AdvancedCustomFields\Exporter\Mapper;

class UserMapper extends Mapper
{
    /**
     * @param \ImportWP\EventHandler $event_handler
     */
    public function __construct($event_handler)
    {
        parent::__construct($event_handler, 'user');
        $this->acf_type = 'user';
    }
}
