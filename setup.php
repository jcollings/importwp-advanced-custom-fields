<?php

use ImportWP\EventHandler;
use ImportWPAddon\AdvancedCustomFields\Importer\Template\AdvancedCustomFields;

function iwp_acf_register_events(EventHandler $event_handler)
{
    $acf = new AdvancedCustomFields($event_handler);
}

add_action('iwp/register_events', 'iwp_acf_register_events');
