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

    public function __construct(EventHandler $event_handler)
    {
        $event_handler;
        $event_handler->listen('importer.custom_fields.init', [$this, 'init']);
        $event_handler->listen('importer.custom_fields.get_fields', [$this, 'get_fields']);
        $event_handler->listen('importer.custom_fields.process_field', [$this, 'process_field']);
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
                $fields = array_merge($fields, $this->get_acf_fields(['user_form' => 'all']));
                break;
            case 'term':
                $taxonomy = $importer_model->getSetting('taxonomy');
                $fields = array_merge($fields, $this->get_acf_fields(['taxonomy' => $taxonomy]));
                break;
            default:
                $post_type = $importer_model->getSetting('post_type');
                $fields = array_merge($fields, $this->get_acf_fields(['post_type' => $post_type]));
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
        $field = get_field_object($field_key);
        $field_type = $field['type'];

        switch ($field_type) {
            case 'file':
            case 'image':

                $custom_field_record[$prefix . '_return'] = 'id-serialize';
                $serialized_id = $this->custom_fields->processAttachmentField($value, $post_id, $custom_field_record, $prefix);
                $id_array = maybe_unserialize($serialized_id);
                if (!empty($id_array) && intval($id_array[0]) > 0) {
                    $attachment_id = intval($id_array[0]);
                    if (update_field($field_key, $attachment_id, $this->prefix($post_id))) {
                        return $attachment_id;
                    }
                }

                break;
            case 'gallery':

                $custom_field_record[$prefix . '_return'] = 'id-serialize';
                $serialized_id = $this->custom_fields->processAttachmentField($value, $post_id, $custom_field_record, $prefix);
                $attachment_ids = maybe_unserialize($serialized_id);
                if (!empty($attachment_ids)) {
                    if (update_field($field_key, $attachment_ids, $this->prefix($post_id))) {
                        return $attachment_ids;
                    }
                }

                break;
            case 'text':
            default:
                if (update_field($field_key, $value, $this->prefix($post_id))) {
                    return $value;
                }
                break;
        }

        return false;
    }

    public function process_field($result, $post_id, $key, $value, $custom_field_record, $prefix, $importer_model, $custom_field)
    {
        if (strpos($key, 'acf_field::') !== 0) {
            return;
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
        $result = $this->process($post_id, $field_key, $value, $custom_field_record, $prefix);
        if ($result) {
            $custom_field->virtual_fields[$field_key] = $result;
        }
    }
}
