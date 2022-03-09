<?php

namespace ImportWPAddon\AdvancedCustomFields\Exporter\Mapper;

use ImportWPAddon\AdvancedCustomFields\Util\Helper;

class Mapper
{
    /**
     * @var \ImportWP\EventHandler
     */
    protected $event_handler;

    /**
     * @var string
     */
    protected $acf_type;

    /**
     * @var string
     */
    protected $filter_type;

    /**
     * @param \ImportWP\EventHandler $event_handler
     */
    public function __construct($event_handler, $filter_type)
    {
        $this->event_handler = $event_handler;

        add_filter('iwp/exporter/' . $filter_type . '/custom_field_list', [$this, 'get_fields'], 10, 2);
        add_filter('iwp/exporter/' . $filter_type . '/value', [$this, 'save_fields'], 10, 4);
    }

    function get_fields($custom_fields, $template_args)
    {
        $acf_fields = Helper::get_fields($this->acf_type, $template_args);

        foreach ($acf_fields as $field) {
            $results = $this->generate_custom_field($field);
            if (!empty($results)) {
                $custom_fields = array_merge($custom_fields, $results);
            }
        }

        return $custom_fields;
    }

    function save_fields($output, $column, $record, $meta)
    {
        $matches = $this->is_acf_ewp_field_key($column);
        if ($matches) {
            $parts = explode('::', $matches[1]);
            $field_id = $parts[0];
            $field_type = $parts[1];
            $extra = isset($parts[2]) ? $parts[2] : '';

            switch ($field_type) {
                case 'gallery':
                    if (isset($meta[$field_id], $meta[$field_id][0])) {

                        $images = (array)maybe_unserialize($meta[$field_id][0]);
                        if (!empty($images)) {
                            if ($extra === 'attachment_id') {
                                $output = $images;
                            } elseif ($extra === 'attachment_url') {
                                $tmp = [];
                                foreach ($images as $image) {
                                    $img_url =  wp_get_attachment_url($image);
                                    if ($img_url) {
                                        $tmp[] = $img_url;
                                    }
                                }
                                $output = $tmp;
                            }
                        }
                    }
                    break;
                case 'file':
                case 'image':
                    if (isset($meta[$field_id], $meta[$field_id][0])) {
                        if ($extra === 'attachment_id') {
                            $output = $meta[$field_id][0];
                        } elseif ($extra === 'attachment_url') {
                            $output = wp_get_attachment_url($meta[$field_id][0]);
                        }
                    }
                    break;
                default:
                    if (isset($meta[$field_id])) {
                        $output = $meta[$field_id];
                    }
                    break;
            }
        }
        return $output;
    }

    protected function generate_custom_field($field)
    {
        $custom_fields = [];

        switch ($field['type']) {
            case 'file':
            case 'image':
            case 'gallery':
                $custom_fields[] = 'ewp_acf::' . $field['name'] . '::' . $field['type'] . '::attachment_id';
                $custom_fields[] = 'ewp_acf::' . $field['name'] . '::' . $field['type'] . '::attachment_url';
                break;
            default:
                $custom_fields[] = 'ewp_acf::' . $field['name'] . '::' . $field['type'];
                break;
        }

        return $custom_fields;
    }

    protected function is_acf_ewp_field_key($column)
    {
        if (preg_match('/^ewp_acf::(.*?)$/', $column, $matches) == 1) {
            return $matches;
        }

        return false;
    }
}
