<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Utils;

/**
 * WPForms field utilities.
 *
 * Helper methods for working with WPForms submissions and field values.
 */
class FormHelper {

    /**
     * Get a field value by its label from WPForms entry fields.
     *
     * @param array  $fields Entry fields array
     * @param string $label  Field label to find
     */
    public static function getValueByLabel(array $fields, string $label): ?string {
        foreach ($fields as $field) {
            if (isset($field['name']) && $field['name'] === $label) {
                return $field['value'] ?? null;
            }
        }
        return null;
    }

    /**
     * Get a field ID by its label from form configuration.
     *
     * @param array  $form_fields Form fields configuration
     * @param string $label       Field label to find
     */
    public static function getIdByLabel(array $form_fields, string $label): ?int {
        foreach ($form_fields as $id => $field) {
            if (isset($field['label']) && $field['label'] === $label) {
                return (int) $id;
            }
        }
        return null;
    }

    /**
     * Get field value by field ID from entry fields.
     *
     * @param array $fields   Entry fields array
     * @param int   $field_id Field ID to find
     */
    public static function getValueById(array $fields, int $field_id): ?string {
        return $fields[$field_id]['value'] ?? null;
    }

    /**
     * Safely get entry data with sanitization.
     *
     * @param array  $entry Entry data
     * @param string $key   Key to retrieve
     */
    public static function getEntryField(array $entry, string $key): ?string {
        if (!isset($entry[$key])) {
            return null;
        }
        return sanitize_text_field($entry[$key]);
    }

    /**
     * Check if WPForms is active.
     */
    public static function isActive(): bool {
        return function_exists('wpforms');
    }

    /**
     * Get a form by ID.
     *
     * @param int $form_id Form ID
     * @return array|null Form data or null if not found
     */
    public static function getForm(int $form_id): ?array {
        if (!self::isActive()) {
            return null;
        }

        $form = wpforms()->form->get($form_id);
        if (!$form) {
            return null;
        }

        return wpforms_decode($form->post_content);
    }
}
