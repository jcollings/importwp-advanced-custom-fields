<?php

use ImportWPAddon\AdvancedCustomFields\Exporter\Mapper\PostMapper;
use ImportWPAddon\AdvancedCustomFields\Exporter\Mapper\TaxMapper;
use ImportWPAddon\AdvancedCustomFields\Exporter\Mapper\UserMapper;

/**
 * @param \ImportWP\EventHandler $event_handler
 * @return void
 */
function iwp_acf_register_exporter_addon($event_handler)
{
    // Exporter
    new PostMapper($event_handler);
    new TaxMapper($event_handler);
    new UserMapper($event_handler);
}

add_action('iwp/register_events', 'iwp_acf_register_exporter_addon');

iwp_register_importer_addon('Advanced Custom Fields', 'advanced-custom-fields', function ($addon) {

    /**
     * @var \ImportWP\Common\Addon\AddonInterface $addon
     */

    $importer_model = $addon->importer_model();
    $fields = iwp_acf_fields($importer_model);

    $addon->register_custom_fields('Advanced Custom Fields', function ($api) use ($fields) {

        /**
         * @var \ImportWP\Common\Addon\AddonCustomFieldsApi $api
         */

        $api->set_prefix('acf_field');

        $api->register_fields(function ($importer_model) use ($api, $fields) {

            /**
             * @var \ImportWP\Common\Model\ImporterModel $importer_model
             */

            // exclude repeater/group fields
            $fields = array_filter($fields, function ($field) {
                return !in_array($field['type'], ['repeater', 'group']);
            });

            foreach ($fields as $field) {

                switch ($field['type']) {
                    case 'image':
                    case 'gallery':
                    case 'file':
                        $type = 'attachment';
                        break;
                    default:
                    case 'text':
                        $type = 'text';
                        break;
                }

                $api->add_field($field['label'], $type . '::' . $field['key']);
            }
        });

        $api->save(function ($response, $post_id, $key, $value) use ($fields) {

            /**
             * @var \ImportWP\Common\Addon\AddonCustomFieldSaveResponse $response
             */

            $field = iwp_acf_get_field_by_key($key, $fields);
            if (!$field) {
                return;
            }

            $value = iwp_acf_process_field($response, $post_id, $field, $value);
            $object_id = iwp_acf_append_object_prefix($post_id, $response->importer_model());
            update_field($field['key'], $value, $object_id);
        });
    });

    $repeater_fields = array_filter($fields, function ($field) {
        return in_array($field['type'], ['repeater', 'group']);
    });

    foreach ($repeater_fields as $repeater_group) {

        $panel_settings = $repeater_group['type'] === 'repeater' ? [
            'type' => 'repeatable',
            'row_base' => true
        ] : [];

        $addon->register_panel('ACF - ' . $repeater_group['label'], $repeater_group['key'], function ($panel) use ($repeater_group, $fields) {

            /**
             * @var \ImportWP\Common\Addon\AddonBasePanel $panel
             */

            $fields = $repeater_group['sub_fields'];
            foreach ($fields as $field) {
                switch ($field['type']) {
                    case 'image':
                    case 'gallery':
                    case 'file':
                        $panel->register_attachment_fields($field['label'], $field['key'], $field['label'] . ' Location')
                            ->save(function ($api) use ($fields) {

                                /**
                                 * @var \ImportWP\Common\Addon\AddonFieldDataApi $api
                                 */

                                iwp_save_group_field($api, $fields, true);
                            });
                        break;
                    default:
                        $panel->register_field($field['label'], $field['key'])
                            ->save(function ($api) use ($fields) {

                                /**
                                 * @var \ImportWP\Common\Addon\AddonFieldDataApi $api
                                 */

                                iwp_save_group_field($api, $fields);
                            });
                        break;
                }
            }

            $panel->save(function ($api) use ($repeater_group) {

                /**
                 * @var \ImportWP\Common\Addon\AddonPanelDataApi $api
                 */

                $meta = $api->get_meta();

                $output = [];
                if ($repeater_group['type'] === 'repeater') {
                    foreach ($meta as $field) {
                        foreach ($field['value'] as $meta_index => $meta_value) {
                            $output[$meta_index][$field['key']] = $meta_value;
                        }
                    }
                } else {
                    foreach ($meta as $field) {
                        $output[$field['key']] = $field['value'];
                    }
                }
                $object_id = iwp_acf_append_object_prefix($api->object_id(), $api->importer_model());
                update_field($api->get_panel_id(), $output, $object_id);
            });
        }, $panel_settings);
    }
});

/**
 * Undocumented function
 *
 * @param \ImportWP\Common\Addon\AddonFieldDataApi $api
 * @param mixed $fields
 * @param boolean $is_attachment
 * @return void
 */
function iwp_save_group_field($api, $fields, $is_attachment = false)
{
    $field_id = $api->get_field_id();
    $acf_field = iwp_acf_get_field_by_key($field_id, $fields);
    if (!$acf_field) {
        return;
    }

    $section_data = $api->data();

    if (!$is_attachment) {
        $value = isset($section_data[$field_id]) ? $section_data[$field_id] : '';
    } else {
        $value = isset($section_data["{$field_id}.location"]) ? $section_data["{$field_id}.location"] : '';
    }

    $value = iwp_acf_process_field($api, $api->object_id(),  $acf_field, $value);
    $api->store_meta($field_id, $value, $api->row());
}

function iwp_acf_append_object_prefix($id, $importer_model)
{
    switch ($importer_model->getTemplate()) {
        case 'user':
            return 'user_' . $id;
            break;
        case 'term':
            $taxonomy = $importer_model->getSetting('taxonomy');
            return $taxonomy . '_' . $id;
            break;
        default:
            return $id;
            break;
    }
}

/**
 * @param \ImportWP\Common\Model\ImporterModel $importer_model
 * 
 * @return array
 */
function iwp_acf_fields($importer_model)
{
    switch ($importer_model->getTemplate()) {
        case 'user':
            $fields = iwp_acf_get_fields('user', 'user');
            break;
        case 'term':
            $taxonomy = $importer_model->getSetting('taxonomy');
            $fields = iwp_acf_get_fields($taxonomy, 'taxonomy');
            break;
        default:
            $post_type = $importer_model->getSetting('post_type');
            $fields = iwp_acf_get_fields($post_type, 'post');
            break;
    }

    return $fields;
}

function iwp_acf_get_fields($section, $section_type = 'post')
{
    $options = [];

    if (is_array($section)) {
        foreach ($section as $item) {
            $options = array_merge($options, iwp_acf_get_fields($item, $section_type));
        }
        return $options;
    }

    switch ($section_type) {
        case 'user':
            $args = ['user_form' => 'all'];
            break;
        case 'taxonomy':
            $args = ['taxonomy' => $section];
            break;
        default:
            $args = ['post_type' => $section];
            break;
    }

    // Allow the user to override field restrictions to show all if needed.
    $args = apply_filters('iwp_acf/get_fields_filter', $args);

    $groups = acf_get_field_groups($args);
    foreach ($groups as $group) {

        $fields = acf_get_fields($group['key']);
        foreach ($fields as $field) {
            $options[] = $field;
        }
    }
    return $options;
}

function iwp_get_custom_field_key_for_permissions($key)
{
    if (strpos($key, 'acf_field::') !== 0) {
        return $key;
    }

    $field_key = substr($key, strrpos($key, '::') + strlen('::'));
    $field_obj = get_field_object($field_key);
    if ($field_obj) {
        return $field_obj['name'];
    }

    return $key;
}
add_filter('iwp/custom_field_key', 'iwp_get_custom_field_key_for_permissions', 10);

function iwp_acf_get_field_by_key($name, $fields)
{
    $index = array_search($name, array_column($fields, 'key'));
    if ($index === false) {
        return false;
    }

    return $fields[$index];
}

/**
 * @param \ImportWP\Common\Addon\AddonCustomFieldSaveResponse $api
 * @param integer $post_id
 * @param mixed $field
 * @param mixed $value
 * @param mixed $custom_field_record
 * @param string $prefix
 * 
 * @return void
 */
function iwp_acf_process_field($api, $post_id, $field, $value)
{
    $delimiter = apply_filters('iwp/value_delimiter', ',');
    $delimiter = apply_filters('iwp/acf/value_delimiter', $delimiter);
    $delimiter = apply_filters('iwp/acf/' . trim($field['id']) . '/value_delimiter', $delimiter);

    switch ($field['type']) {
        case 'select':

            $value = explode($delimiter, $value);
            $value = array_filter(array_map('trim', $value));

            // only save the first value
            if (!$field['multiple'] && count($value) > 1) {
                $value = $value[0];
            }

            break;
        case 'file':
        case 'image':

            $serialized_id = $api->processAttachmentField($value, $post_id, ['_return' => 'id-serialize']);
            $id_array = maybe_unserialize($serialized_id);
            if (!empty($id_array) && intval($id_array[0]) > 0) {
                $value = intval($id_array[0]);
            }

            break;
        case 'gallery':

            $serialized_id = $api->processAttachmentField($value, $post_id, ['_return' => 'id-serialize']);
            $value = maybe_unserialize($serialized_id);
            break;
        case 'link':

            $value = iwp_acf_parse_serialized_value($value, [
                'title' => '',
                'url' => '',
                'target' => ''
            ]);

            break;
        case 'google_map':

            $value = iwp_acf_parse_serialized_value($value, [
                'address' => '',
                'lat' => '',
                'lng' => '',
                'zoom' => ''
            ]);

            break;
        case 'checkbox':
            $value = explode($delimiter, $value);
            break;
        case 'true_or_false':
            if (strtolower($value) == 'yes' || strtolower($value) == 'true') {
                $value = 1;
            } elseif (strtolower($value) == 'no' || strtolower($value) == 'false') {
                $value = 0;
            }
            break;
        case 'date_picker':
            // 20220218
            if (!empty($value)) {
                $value = date('Ymd', strtotime($value));
            }
            break;
        case 'date_time_picker':
            // 2022-02-18 00:00:00
            if (!empty($value)) {
                $value = date('Y-m-d H:i:s', strtotime($value));
            }
            break;
        case 'time_picker':
            // 00:00:00
            if (!empty($value)) {
                $value = date('H:i:s', strtotime($value));
            }
            break;
        case 'post_object':
            // object_id
            break;
        case 'relationship':
            // [object_id]
            break;
        case 'taxonomy':
            // [term_id]
            break;
        case 'user':
            // user_id
            break;
    }

    return apply_filters('iwp/acf/value', $value, $field, $post_id);
}

function iwp_acf_parse_serialized_value($value, $defaults = [])
{
    return array_reduce(explode('|', $value), function ($carry, $item) {

        $parts = explode('=', $item);
        if (count($parts) == 2) {
            $k = trim($parts[0]);
            $v = trim($parts[1]);
            if (isset($carry[$k])) {
                $carry[$k] = $v;
            }
        }

        return $carry;
    }, $defaults);
}
