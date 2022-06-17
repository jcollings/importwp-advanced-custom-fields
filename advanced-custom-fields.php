<?php

/**
 * Plugin Name: Import WP - Advanced Custom Fields Importer Addon
 * Plugin URI: https://www.importwp.com
 * Description: Allow Import WP to import Advanced Custom Fields.
 * Author: James Collings <james@jclabs.co.uk>
 * Version: 2.4.2 
 * Author URI: https://www.importwp.com
 * Network: True
 */

define('IWP_ACF_MIN', '2.5.0');
define('IWP_ACF_PRO_MIN', '2.5.0');

add_action('admin_init', 'iwp_acf_check');

function iwp_acf_requirements_met()
{
    return false === (is_admin() && current_user_can('activate_plugins') &&  (!class_exists('ACF') || !function_exists('import_wp_pro') || !defined('IWP_VERSION')  || !defined('IWP_PRO_VERSION') || version_compare(IWP_VERSION, IWP_ACF_MIN, '<') || version_compare(IWP_PRO_VERSION, IWP_ACF_PRO_MIN, '<')));
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

    // Install updater
    if (file_exists($base_path . '/updater.php') && !class_exists('IWP_Updater')) {
        require_once $base_path . '/updater.php';
    }

    if (class_exists('IWP_Updater')) {
        $updater = new IWP_Updater(__FILE__, 'importwp-advanced-custom-fields');
        $updater->initialize();
    }
}
add_action('plugins_loaded', 'iwp_acf_setup', 9);

function iwp_acf_notice()
{
    echo '<div class="error">';
    echo '<p><strong>Import WP - Advanced Custom Fields Importer Addon</strong> requires that you have <strong>Import WP v' . IWP_ACF_MIN . '+ and Import WP PRO v' . IWP_ACF_PRO_MIN . '+</strong>, and <strong>Advanced Custom Fields</strong> installed.</p>';
    echo '</div>';
}
