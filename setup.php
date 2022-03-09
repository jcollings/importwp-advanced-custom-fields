<?php

use ImportWP\EventHandler;
use ImportWPAddon\AdvancedCustomFields\Exporter\Mapper\PostMapper;
use ImportWPAddon\AdvancedCustomFields\Exporter\Mapper\TaxMapper;
use ImportWPAddon\AdvancedCustomFields\Exporter\Mapper\UserMapper;
use ImportWPAddon\AdvancedCustomFields\Importer\Template\AdvancedCustomFields;

function iwp_acf_register_events(EventHandler $event_handler)
{
    $acf = new AdvancedCustomFields($event_handler);

    // Exporter
    new PostMapper($event_handler);
    new TaxMapper($event_handler);
    new UserMapper($event_handler);
}

add_action('iwp/register_events', 'iwp_acf_register_events');
