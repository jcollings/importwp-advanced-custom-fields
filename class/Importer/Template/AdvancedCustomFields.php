<?php

namespace ImportWPAddon\AdvancedCustomFields\Importer\Template;

use ImportWP\Common\Importer\ParsedData;
use ImportWP\Common\Importer\Template\Template;
use ImportWP\Common\Model\ImporterModel;
use ImportWP\EventHandler;
use ImportWP\Pro\Importer\Template\CustomFields;

class AdvancedCustomFields
{
    /**
     * @var CustomFields $custom_fields
     */
    private $custom_fields;
    public $virtual_fields = [];

    public function __construct(EventHandler $event_handler)
    {
        $event_handler->listen('importer.custom_fields.init', [$this, 'init']);
        $event_handler->listen('importer.custom_fields.get_fields', [$this, 'get_fields']);
        $event_handler->listen('importer.custom_fields.process_field', [$this, 'process_field']);
        $event_handler->listen('importer.custom_fields.post_process', [$this, 'post_process']);

        add_filter('iwp/custom_field_key', [$this, 'get_custom_field_key'], 10);
        add_filter('iwp/custom_field_label', [$this, 'get_custom_field_label'], 10);

        // repeater display
        $event_handler->listen('template.fields', [$this, 'register_repeater_fields']);

        // register repeater group so that data mapper splits groups
        $event_handler->listen('template.pre_process_groups', [$this, 'register_virtual_template_group']);

        // repeater save
        $event_handler->listen('template.process', [$this, 'repeater_process']);
    }

    public function init($result, $custom_fields)
    {
        $this->custom_fields = $custom_fields;
    }

    public function get_acf_fields($args)
    {
        $options = [];

        $groups = acf_get_field_groups($args);
        foreach ($groups as $group) {

            $fields = acf_get_fields($group['key']);
            foreach ($fields as $field) {

                switch ($field['type']) {
                    case 'image':
                    case 'gallery':
                    case 'file':
                        $type = 'attachment';
                        break;
                    case 'group':
                        $group_fields = acf_get_fields($field);
                        foreach ($group_fields as $group_field) {

                            $type = 'text';
                            if (in_array($group_field['type'], ['image', 'gallery', 'file'])) {
                                $type = 'attachment';
                            }

                            $options[] = [
                                'value' => 'acf_field::' . $type . '::' . $field['key'] . '|' . $group_field['key'],
                                'label' => 'ACF - ' . $group['title'] . ' - ' . $field['label'] . ' - ' . $group_field['label']
                            ];
                        }
                        break;
                    default:
                    case 'text':
                        $type = 'text';
                        break;
                }

                $options[] = [
                    'value' => 'acf_field::' . $type . '::' . $field['key'],
                    'label' => 'ACF - ' . $group['title'] . ' - ' . $field['label']
                ];
            }
        }
        return $options;
    }

    public function get_fields($fields, ImporterModel $importer_model)
    {
        $template = $importer_model->getTemplate();
        switch ($template) {
            case 'user':
                $fields = array_merge($this->get_acf_fields(['user_form' => 'all']), $fields);
                break;
            case 'term':
                $taxonomies = (array)$importer_model->getSetting('taxonomy');
                foreach ($taxonomies as $taxonomy) {
                    $fields = array_merge($this->get_acf_fields(['taxonomy' => $taxonomy]), $fields);
                }
                break;
            default:
                // Handle templates with multiple post_types
                $post_types = (array)$importer_model->getSetting('post_type');
                foreach ($post_types as $post_type) {
                    $fields = array_merge($this->get_acf_fields(['post_type' => $post_type]), $fields);
                }
                break;
        }
        return $fields;
    }

    public function set_field_prefix($prefix)
    {
        $this->field_prefix = $prefix;
    }

    public function prefix($object)
    {
        if (is_null($this->field_prefix)) {
            return $object;
        }

        return $this->field_prefix . $object;
    }

    function set_value($post_id, $field, $value, $custom_field_record, $prefix)
    {

        switch ($field['type']) {
            case 'select':

                $value = explode(',', $value);
                $value = array_filter(array_map('trim', $value));

                // only save the first value
                if (!$field['multiple'] && count($value) > 1) {
                    $value = $value[0];
                }

                break;
            case 'file':
            case 'image':

                $custom_field_record[$prefix . '_return'] = 'id-serialize';
                $serialized_id = $this->custom_fields->processAttachmentField($value, $post_id, $custom_field_record, $prefix);
                $id_array = maybe_unserialize($serialized_id);
                if (!empty($id_array) && intval($id_array[0]) > 0) {
                    $value = intval($id_array[0]);
                }

                break;
            case 'gallery':

                $custom_field_record[$prefix . '_return'] = 'id-serialize';
                $serialized_id = $this->custom_fields->processAttachmentField($value, $post_id, $custom_field_record, $prefix);
                $value = maybe_unserialize($serialized_id);
                break;
            case 'link':

                $value = $this->parse_serialized_value($value, [
                    'title' => '',
                    'url' => '',
                    'target' => ''
                ]);

                break;
            case 'google_map':

                $value = $this->parse_serialized_value($value, [
                    'address' => '',
                    'lat' => '',
                    'lng' => '',
                    'zoom' => ''
                ]);

                break;
            case 'checkbox':
                $value = explode(',', $value);
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
                $value = date('Ymd', strtotime($value));
                break;
            case 'date_time_picker':
                // 2022-02-18 00:00:00
                $value = date('Y-m-d H:i:s', strtotime($value));
                break;
            case 'time_picker':
                // 00:00:00
                $value = date('H:i:s', strtotime($value));
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

        return $value;
    }

    public function process($post_id, $field_key, $value, $custom_field_record, $prefix)
    {
        // try multiple
        $field_keys = explode('|', $field_key);
        $field_key = $field_keys[count($field_keys) - 1];

        $field = get_field_object($field_key);
        $value = $this->set_value($post_id, $field, $value, $custom_field_record, $prefix);

        if (count($field_keys) > 1) {
            $this->virtual_fields[implode('|', $field_keys)] = $value;
            return $value;
        } elseif (update_field($field_key, $value, $this->prefix($post_id))) {
            return $value;
        }

        return false;
    }

    function parse_serialized_value($value, $defaults = [])
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

    public function process_field($result, $post_id, $key, $value, $custom_field_record, $prefix, $importer_model, $custom_field)
    {
        if (strpos($key, 'acf_field::') !== 0) {
            return $result;
        }

        switch ($importer_model->getTemplate()) {
            case 'user':
                $this->set_field_prefix('user_');
                break;
            case 'term':
                $taxonomy = $importer_model->getSetting('taxonomy');
                $this->set_field_prefix($taxonomy . '_');
                break;
            default:
                $this->set_field_prefix(null);
                break;
        }

        $field_key = substr($key, strrpos($key, '::') + strlen('::'));

        // Handle list of multiple keys
        $field_keys = explode('|', $field_key);
        $last_field_key = end($field_keys);


        $processed = $this->process($post_id, $field_key, $value, $custom_field_record, $prefix);
        if ($processed) {
            $custom_field->virtual_fields[$key] = $processed;
        }

        return $result;
    }

    /**
     * @param string $key
     * @param TemplateInterface $template
     * @return string
     */
    public function get_custom_field_key($key)
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

    /**
     * @param string $key
     * @return string
     */
    public function get_custom_field_label($key)
    {
        if (strpos($key, 'acf_field::') !== 0) {
            return $key;
        }

        $field_key = substr($key, strrpos($key, '::') + strlen('::'));
        $field_obj = get_field_object($field_key);
        if ($field_obj) {
            return $field_obj['label'];
        }

        return $key;
    }

    /**
     * Update nested fields
     * 
     * @param array $output
     * @param mixed $post_id
     * @param ImporterModel $importer_model
     * @param CustomFields $custom_fields
     * @return array
     */
    public function post_process($output, $post_id, $importer_model, $custom_fields)
    {

        $tmp = [];

        foreach ($this->virtual_fields as $field_path => $field_value) {

            $field_path_parts = explode('|', $field_path);

            $loc = &$tmp;
            foreach ($field_path_parts as $i => $field_path_part) {
                if (count($field_path_parts) - 1 === $i) {
                    $loc[$field_path_part] = $field_value;
                } else {
                    if (!isset($loc[$field_path_part])) {
                        $loc[$field_path_part] = [];
                    }

                    $loc = &$loc[$field_path_part];
                }
            }
        }

        foreach ($tmp as $group => $sub_fields) {
            update_field($group, $sub_fields, $this->prefix($post_id));
        }

        $this->virtual_fields = [];
        return $output;
    }

    /**
     * Register template fields
     *
     * @param array $fields
     * @param Template $template
     * @param ImporterModel $importer_model
     * @return array
     */
    public function register_repeater_fields($fields, $template, $importer_model)
    {

        $field_group_args = false;

        // get list of repeater fields based on current template
        switch ($importer_model->getTemplate()) {
            case 'user':
                $field_group_args = ['user_form' => 'all'];
                break;
            case 'term':
                $taxonomy = $importer_model->getSetting('taxonomy');
                $field_group_args = ['taxonomy' => $taxonomy];
                break;
            default:
                $post_type = $importer_model->getSetting('post_type');
                $field_group_args = ['post_type' => $post_type];
                break;
        }

        if (!$field_group_args) {
            return $fields;
        }

        $groups = acf_get_field_groups($field_group_args);
        if (empty($groups)) {
            return $fields;
        }

        $repeater_groups = [];

        foreach ($groups as $group) {

            $group_fields = acf_get_fields($group['key']);
            foreach ($group_fields as $field) {

                if ($field['type'] !== 'repeater' && $field['type'] !== 'group') {
                    continue;
                }

                $group = [
                    'title' => 'ACF ' . ucfirst($field['type']) . ' - ' . $field['label'],
                    'id' => 'acf_repeater-' . $field['key'],
                    'fields' => $field['sub_fields']
                ];

                $repeater_fields = [];

                foreach ($field['sub_fields'] as $sub_field) {
                    switch ($sub_field['type']) {
                        case 'file':
                        case 'image':
                        case 'gallery':
                            $repeater_fields[] = $template->register_attachment_fields($sub_field['label'], $sub_field['key'], $sub_field['label'], ['type' => 'group']);
                            break;
                        default:
                            $repeater_fields[] = $template->register_core_field($sub_field['label'], $sub_field['key']);
                            break;
                    }
                }

                $group_args = $field['type'] === 'repeater' ? [
                    'type' => 'repeatable',
                    'row_base' => true
                ] : [
                    'type' => 'group',
                    'row_base' => false
                ];

                $repeater_groups[] = $template->register_group($group['title'], $group['id'], $repeater_fields, $group_args);
            }
        }

        return array_merge($fields, $repeater_groups);
    }

    /**
     * Process repeater group data
     *
     * @param integer $id
     * @param ParsedData $data
     * @param ImporterModel $importer_model
     * @param Template $template
     * @return integer
     */
    public function repeater_process($id, $data, $importer_model, $template)
    {

        foreach ($this->_acf_data_groups as $group_id) {

            $matches = [];
            if (!preg_match('/^acf_repeater-([^\.]+)$/', $group_id, $matches)) {
                continue;
            }

            $group_id = $matches[1];
            $group_row_id = 'acf_repeater-' . $group_id;
            $group_fields = $data->getData($group_row_id);

            $master_field = acf_get_field($group_id);
            $master_allowed_fields = array_reduce($master_field['sub_fields'], function ($carry, $item) {
                $carry[$item['key']] = $item['type'];
                return $carry;
            }, []);

            // group only has 1 row, repeater is 0+
            $default_row_count = $master_field['type'] == 'group' ? 1 : 0;
            $row_count = isset($group_fields[$group_row_id . '._index']) ? intval($group_fields[$group_row_id . '._index']) : $default_row_count;

            $output = [];

            $permission_group_key = 'custom_fields.' . $this->get_custom_field_key('acf_field::' . $group_id);

            for ($i = 0; $i < $row_count; $i++) {

                $row = [];

                // group doesn't have multiple rows
                if ($master_field['type'] === 'repeater') {
                    $prefix = $group_row_id . '.' . $i;
                } else {
                    $prefix = $group_row_id;
                }

                $row_fields = array_filter($group_fields, function ($k) use ($prefix) {
                    return strpos($k, $prefix) === 0;
                }, ARRAY_FILTER_USE_KEY);

                // store in a temp variable so can be processed the same as sub rows
                $sub_rows = [$row_fields];

                if (isset($row_fields[$prefix . '.row_base']) && !empty($row_fields[$prefix . '.row_base'])) {
                    $sub_rows = $data->getData('acf_repeater-' . $group_id . '.' . $i);
                }

                foreach ($sub_rows as $custom_field_row) {

                    $tmp = [];

                    $field_set = [];
                    foreach ($master_allowed_fields as $field_id => $field_type) {
                        $field_set = [];
                        foreach ($custom_field_row as $k => $v) {


                            if (strpos($k, $prefix . '.' . $field_id) !== 0) {
                                continue;
                            }

                            // Can we save this group?
                            $permission_key = 'custom_fields.' . $this->get_custom_field_key('acf_field::' . $group_id) . '.' . $this->get_custom_field_key('acf_field::' . $field_id);
                            $allowed = $data->permission()->validate([
                                $permission_key => '',
                                $permission_group_key => ''
                            ], $data->getMethod(), 'custom_fields');
                            if (empty($allowed)) {
                                continue 2;
                            }

                            $field_set[substr($k, strlen($prefix) + 1)] = $v;
                        }

                        $acf_field = get_field_object($field_id);
                        $value = in_array($acf_field['type'], ['image', 'gallery', 'file']) && isset($field_set[$field_id . '.location']) ? $field_set[$field_id . '.location'] : $field_set[$field_id];
                        $tmp[$field_id] = $this->set_value($id, $acf_field, $value, $field_set, $field_id . '.');
                    }

                    $row[] = $tmp;
                }

                $output[] = $row;
            }

            // convert multi to single array
            $rows = array_reduce($output, function ($carry, $item) {
                $carry = array_merge($carry, $item);
                return $carry;
            }, []);

            $permission_group_key = 'custom_fields.' . $this->get_custom_field_key('acf_field::' . $group_id);
            $allowed = $data->permission()->validate([$permission_group_key => ''], $data->getMethod(), 'custom_fields');
            if (!empty($allowed)) {
                // group doesn't store nested data
                update_field($group_id, $master_field['type'] === 'repeater' ? $rows : $rows[0], $id);
            }
        }

        return $id;
    }

    /**
     * Register virtual groups
     *
     * @param string[] $groups
     * @param ParsedData $data
     * @param Template $template
     * @return void
     */
    public function register_virtual_template_group($groups, $data, $template)
    {
        $this->_acf_data_groups = [];

        $map = $template->get_importer()->getMap();

        foreach ($map as $field_id => $field_map) {

            $matches = [];
            if (!preg_match('/^acf_repeater-([^\.]+)\./', $field_id, $matches)) {
                continue;
            }

            $group_id = 'acf_repeater-' . $matches[1];

            if (in_array($group_id, $this->_acf_data_groups)) {
                continue;
            }

            $this->_acf_data_groups[] = $group_id;
        }

        return array_merge($groups, $this->_acf_data_groups);
    }
}
