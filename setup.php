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
 * Pre populate acf repeater and group fields.
 * 
 * @since 2.7.2
 */
add_filter('iwp/importer/generate_field_map', function ($field_map, $headings, $importer_model) {

    $acf_fields = iwp_acf_fields($importer_model);

    $acf_repeater_fields = array_filter($acf_fields, function ($field) {
        return in_array($field['type'], ['repeater', 'group']);
    });


    $defaults = [
        "location" => "",
        "settings._featured" => "no",
        "settings._download" => "remote",
        "settings._ftp_host" => "",
        "settings._ftp_user" => "",
        "settings._ftp_pass" => "",
        "settings._ftp_path" => "",
        "settings._remote_url" => "",
        "settings._local_url" => "",
        "settings._enable_image_hash" => "yes",
        "settings._delimiter" => "",
        "settings._meta._enabled" => "no",
        "settings._meta._alt" => "",
        "settings._meta._title" => "",
        "settings._meta._caption" => "",
        "settings._meta._description" => ""
    ];

    foreach ($acf_repeater_fields as $repeater_group) {

        $fields = $repeater_group['sub_fields'];
        $counter = 0;
        $data = [];

        foreach ($fields as $field) {

            $value = '';

            switch ($field['type']) {
                case 'image':
                case 'gallery':
                case 'file':

                    $index = array_search($repeater_group['name'] . '.' . $field['name'] . '::url', $headings);
                    if ($index !== false) {
                        $value = sprintf('{%d}', $index);
                    } else {
                        break;
                    }

                    $tmp = wp_parse_args([
                        'location' => $value
                    ], $defaults);

                    $data = array_merge($data, array_reduce(array_keys($tmp), function ($carry, $key) use ($field, $tmp) {
                        $carry[$field['key'] . '.' . $key] = $tmp[$key];
                        return $carry;
                    }, []));

                    $value = '';
                    break;
                default:
                case 'text':

                    $index = array_search($repeater_group['name'] . '.' . $field['name'], $headings);
                    if ($index !== false) {
                        $value = sprintf('{%d}', $index);
                    }
                    break;
            }

            if ($value !== '') {
                $data[$field['key']] = $value;
            }
        }

        $row_data = array_reduce(array_keys($data), function ($carry, $key) use ($data, $counter, $repeater_group) {
            $carry[sprintf('%s.%d.%s', $repeater_group['key'], $counter, $key)] = $data[$key];
            return $carry;
        }, []);

        $field_map['map'] = array_merge($field_map['map'], $row_data);

        if (!empty($row_data)) {
            $counter++;
            $field_map['map'][$repeater_group['key'] . '._index'] = $counter;
        }
    }

    return $field_map;
}, 9, 3);

/**
 * Update importer custom field list to use acf key and value.
 * 
 * @since 2.7.2
 */
add_filter('iwp/importer/generate_field_map/custom_fields', function ($custom_fields, $fields, $importer_model) {

    $acf_fields = iwp_acf_fields($importer_model);

    $acf_custom_fields = array_filter($acf_fields, function ($field) {
        return !in_array($field['type'], ['repeater', 'group']);
    });

    foreach ($acf_custom_fields as $field) {

        $value = '';
        $type = 'text';

        switch ($field['type']) {
            case 'image':
            case 'gallery':
            case 'file':

                $type = 'attachment';
                $index = array_search('acf.' . $field['name'] . '::url', $fields);
                if ($index !== false) {
                    $value = sprintf('{%d}', $index);
                }

                break;
            case 'link':

                $parts = [
                    'title' => '',
                    'url' => '',
                    'target' => '',
                ];
                $value = iwp_acf_process_field_map_parts($field, $parts, $fields);
                break;
            case 'google_map':

                $parts = [
                    'address' => '',
                    'lat' => '',
                    'lng' => '',
                    'zoom' => ''
                ];
                $value = iwp_acf_process_field_map_parts($field, $parts, $fields);
                break;
            default:
            case 'text':

                $index = array_search('acf.' . $field['name'], $fields);
                if ($index !== false) {
                    $value = sprintf('{%d}', $index);
                }
                break;
        }

        if ($value !== '') {
            $custom_fields['acf_field::' . $type . '::' . $field['key']] = $value;
        }
    }

    return $custom_fields;
}, 10, 3);

function iwp_acf_process_field_map_parts($field, $parts, $fields)
{
    $value = '';

    foreach (array_keys($parts) as $field_id) {
        $index = array_search('acf.' . $field['name'] . '::' . $field_id, $fields);
        if ($index !== false) {
            $parts[$field_id] = sprintf('{%d}', $index);
        }
    }

    $parts = array_reduce(array_keys($parts), function ($carry, $key) use ($parts) {
        if (!empty($parts[$key])) {
            $carry[] = $key . '=' . $parts[$key];
        }
        return $carry;
    }, []);

    if (!empty($parts)) {
        $value = implode('|', $parts);
    } else {
        $index = array_search('acf.' . $field['name'], $fields);
        if ($index !== false) {
            $value = sprintf('{%d}', $index);
        }
    }

    return $value;
}

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

            $serialized_id = $api->processAttachmentField($value, $post_id, ['settings._return' => 'id-serialize']);
            $id_array = maybe_unserialize($serialized_id);
            if (!empty($id_array)) {
                $value = is_array($id_array) ? intval($id_array[0]) : intval($id_array);
            }

            break;
        case 'gallery':

            $serialized_id = $api->processAttachmentField($value, $post_id, ['settings._return' => 'id-serialize']);
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
            if (isset($field['multiple']) && $field['multiple'] === 1) {

                if (!is_array($value)) {
                    $value = explode($delimiter, $value);
                }

                $value = array_map('intval', $value);
            } else {
                $value = intval($value);
            }
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
