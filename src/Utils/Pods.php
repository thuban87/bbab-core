<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Utils;

/**
 * Safe wrapper for Pods operations.
 *
 * Provides null-safe access to Pods objects and handles
 * cases where the Pods plugin might not be active.
 */
class Pods {

    /**
     * Get a Pods object safely.
     * Returns null if Pods plugin is not active or pod doesn't exist.
     *
     * @param string   $pod_name Pod name
     * @param int|null $id       Optional post ID
     */
    public static function get(string $pod_name, ?int $id = null): ?\Pods {
        if (!function_exists('pods')) {
            Logger::error('Pods', 'Pods plugin not active');
            return null;
        }

        try {
            $pod = pods($pod_name, $id);
            if (!$pod || !$pod->valid()) {
                return null;
            }
            return $pod;
        } catch (\Exception $e) {
            Logger::error('Pods', 'Failed to get pod', [
                'pod' => $pod_name,
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Get a pod by its settings key (for type safety).
     */
    public static function getByType(string $type, ?int $id = null): ?\Pods {
        $pod_name = Settings::getPodName($type);
        return self::get($pod_name, $id);
    }

    /**
     * Check if Pods plugin is active.
     */
    public static function isActive(): bool {
        return function_exists('pods');
    }

    /**
     * Get a field value from a pod item safely.
     *
     * @param \Pods  $pod          Pods object
     * @param string $field        Field name
     * @param mixed  $default      Default value
     * @param bool   $single       Return single value (default true)
     */
    public static function field(\Pods $pod, string $field, $default = null, bool $single = true) {
        try {
            $value = $pod->field($field, $single);
            return $value !== null && $value !== '' ? $value : $default;
        } catch (\Exception $e) {
            Logger::error('Pods', 'Failed to get field', [
                'field' => $field,
                'error' => $e->getMessage()
            ]);
            return $default;
        }
    }

    /**
     * Get related items from a relationship field.
     *
     * @param \Pods  $pod   Pods object
     * @param string $field Relationship field name
     * @return array Array of related post IDs
     */
    public static function getRelated(\Pods $pod, string $field): array {
        try {
            $value = $pod->field($field);
            if (empty($value)) {
                return [];
            }

            // Handle both single and multiple relationships
            if (is_array($value)) {
                return array_map('intval', array_column($value, 'ID'));
            }

            if (is_object($value) && isset($value->ID)) {
                return [(int) $value->ID];
            }

            if (is_numeric($value)) {
                return [(int) $value];
            }

            return [];
        } catch (\Exception $e) {
            Logger::error('Pods', 'Failed to get related items', [
                'field' => $field,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
}
