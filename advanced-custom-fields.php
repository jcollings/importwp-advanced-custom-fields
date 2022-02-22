<?php

/**
 * Plugin Name: Import WP - Advanced Custom Fields Importer Addon
 * Plugin URI: https://www.importwp.com
 * Description: Allow Import WP to import Advanced Custom Fields.
 * Author: James Collings <james@jclabs.co.uk>
 * Version: 2.3.0 
 * Author URI: https://www.importwp.com
 * Network: True
 */

add_action('admin_init', 'iwp_acf_check');

function iwp_acf_requirements_met()
{
    return false === (is_admin() && current_user_can('activate_plugins') &&  (!class_exists('ACF') || !function_exists('import_wp_pro') || !defined('IWP_VERSION') || version_compare(IWP_VERSION, '2.4.1', '<')));
}

function iwp_acf_check()
{
    if (!iwp_acf_requirements_met()) {

        add_action('admin_notices', 'iwp_acf_notice');

        deactivate_plugins(plugin_basename(__FILE__));

        if (isset($_GET['activate'])) {
            unset($_GET['activate']);
        }
    }
}

function iwp_acf_setup()
{
    if (!iwp_acf_requirements_met()) {
        return;
    }

    $base_path = dirname(__FILE__);

    require_once $base_path . '/class/autoload.php';
    require_once $base_path . '/setup.php';
}
add_action('plugins_loaded', 'iwp_acf_setup', 9);

function iwp_acf_notice()
{
    echo '<div class="error">';
    echo '<p><strong>Import WP - Advanced Custom Fields Importer Addon</strong> requires that you have <strong>Import WP PRO v2.4.1 or newer</strong>, and <strong>Advanced Custom Fields</strong> installed.</p>';
    echo '</div>';
}
