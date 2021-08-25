<?php

namespace ImportWPAddon\AdvancedCustomFields\Importer\Template;

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
        $event_handler;
        $event_handler->listen('importer.custom_fields.init', [$this, 'init']);
        $event_handler->listen('importer.custom_fields.get_fields', [$this, 'get_fields']);
        $event_handler->listen('importer.custom_fields.process_field', [$this, 'process_field']);
        $event_handler->listen('importer.custom_fields.post_process', [$this, 'post_process']);

        add_filter('iwp/custom_field_key', [$this, 'get_custom_field_key'], 10, 3);
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

    public function process($post_id, $field_key, $value, $custom_field_record, $prefix)
    {
        // try multiple
        $field_keys = explode('|', $field_key);
        $field_key = $field_keys[count($field_keys) - 1];

        $field = get_field_object($field_key);
        $field_type = $field['type'];

        switch ($field_type) {
            case 'select':

                if ($field['multiple']) {
                    $value = explode(',', $value);
                    $value = array_filter(array_map('trim', $value));
                }

                if (count($field_keys) > 1) {
                    $this->virtual_fields[implode('|', $field_keys)] = $value;
                    return $value;
                } elseif (update_field($field_key, $value, $this->prefix($post_id))) {
                    return $value;
                }
                break;
            case 'file':
            case 'image':

                $custom_field_record[$prefix . '_return'] = 'id-serialize';
                $serialized_id = $this->custom_fields->processAttachmentField($value, $post_id, $custom_field_record, $prefix);
                $id_array = maybe_unserialize($serialized_id);
                if (!empty($id_array) && intval($id_array[0]) > 0) {
                    $attachment_id = intval($id_array[0]);
                    if (count($field_keys) > 1) {
                        $this->virtual_fields[implode('|', $field_keys)] = $attachment_id;
                        return $attachment_id;
                    } elseif (update_field($field_key, $attachment_id, $this->prefix($post_id))) {
                        return $attachment_id;
                    }
                }

                break;
            case 'gallery':

                $custom_field_record[$prefix . '_return'] = 'id-serialize';
                $serialized_id = $this->custom_fields->processAttachmentField($value, $post_id, $custom_field_record, $prefix);
                $attachment_ids = maybe_unserialize($serialized_id);
                if (!empty($attachment_ids)) {
                    if (count($field_keys) > 1) {
                        $this->virtual_fields[implode('|', $field_keys)] = $attachment_ids;
                        return $attachment_ids;
                    } elseif (update_field($field_key, $attachment_ids, $this->prefix($post_id))) {
                        return $attachment_ids;
                    }
                }

                break;
            case 'google_map':
                list($address, $lat, $lng, $zoom) = explode('|', $value);
                $value = ['address' => trim($address),  'lat' => trim($lat), 'lng' => trim($lng), 'zoom' => trim($zoom)];
                if (count($field_keys) > 1) {
                    $this->virtual_fields[implode('|', $field_keys)] = $value;
                    return $value;
                } elseif (update_field($field_key, $value, $this->prefix($post_id))) {
                    return $value;
                }
                break;
            case 'checkbox':
                $value = explode(',', $value);
                if (count($field_keys) > 1) {
                    $this->virtual_fields[implode('|', $field_keys)] = $value;
                    return $value;
                } elseif (update_field($field_key, $value, $this->prefix($post_id))) {
                    return $value;
                }
                break;
            case 'taxonomy':
                $terms = explode(',', $value);
                $terms = array_filter(array_map('trim', $terms));
                $taxonomy = $field['taxonomy'];
                $value = [];

                foreach ($terms as $term) {
                    $term_result = get_term_by('name', $term, $taxonomy);
                    if ($term_result) {
                        $value[] = $term_result->term_id;
                    }
                }

                // process values and return serialized array of id's
                if (count($field_keys) > 1) {
                    $this->virtual_fields[implode('|', $field_keys)] = $value;
                    return $value;
                } elseif (update_field($field_key, $value, $this->prefix($post_id))) {
                    return $value;
                }

                break;
            case 'text':
            default:
                if (count($field_keys) > 1) {
                    $this->virtual_fields[implode('|', $field_keys)] = $value;
                    return $value;
                } elseif (update_field($field_key, $value, $this->prefix($post_id))) {
                    return $value;
                }
                break;
        }

        return false;
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
            $custom_field->virtual_fields[$last_field_key] = $processed;
        }

        return $result;
    }

    /**
     * @param string $key
     * @param TemplateInterface $template
     * @param ImporterModel $importer
     * @return string
     */
    public function get_custom_field_key($key, $template, $importer)
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
}
