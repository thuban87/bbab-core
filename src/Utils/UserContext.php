<?php
declare(strict_types=1);

namespace BBAB\ServiceCenter\Utils;

/**
 * The ONE source of truth for user/organization context.
 *
 * This class solves the 20-snippet collision problem by providing
 * a single, simulation-aware method for getting the current org ID.
 *
 * IMPORTANT: The simulation constant (BBAB_SC_SIMULATED_ORG_ID) is defined
 * by SimulationBootstrap on plugins_loaded priority 1, before any code
 * uses this class.
 */
class UserContext {

    /**
     * Get the current organization ID, respecting simulation mode.
     *
     * This replaces all instances of:
     *   $org_id = get_user_meta($user_id, 'organization', true);
     *
     * IMPORTANT: The simulation constant is defined early in SimulationBootstrap.
     * By the time any shortcode or query runs, it's already set.
     */
    public static function getCurrentOrgId(): ?int {
        // Check simulation mode first (admin only)
        if (self::isSimulationActive()) {
            return self::getSimulatedOrgId();
        }

        // Normal user context
        if (!is_user_logged_in()) {
            return null;
        }

        $org_id = get_user_meta(get_current_user_id(), 'organization', true);
        return $org_id ? (int) $org_id : null;
    }

    /**
     * Get the current organization post object.
     */
    public static function getCurrentOrg(): ?\WP_Post {
        $org_id = self::getCurrentOrgId();
        if (!$org_id) {
            return null;
        }
        return get_post($org_id);
    }

    /**
     * Check if simulation mode is active.
     * The constant is defined by SimulationBootstrap on plugins_loaded (priority 1).
     */
    public static function isSimulationActive(): bool {
        return defined('BBAB_SC_SIMULATED_ORG_ID')
            && BBAB_SC_SIMULATED_ORG_ID > 0
            && current_user_can('manage_options');
    }

    /**
     * Get the simulated org ID (only valid if simulation is active).
     */
    public static function getSimulatedOrgId(): ?int {
        if (!self::isSimulationActive()) {
            return null;
        }
        return (int) BBAB_SC_SIMULATED_ORG_ID;
    }

    /**
     * Check if a user belongs to a specific organization.
     */
    public static function userBelongsToOrg(int $user_id, int $org_id): bool {
        $user_org = get_user_meta($user_id, 'organization', true);
        return (int) $user_org === $org_id;
    }

    /**
     * Check if current user is an admin.
     */
    public static function isAdmin(): bool {
        return current_user_can('manage_options');
    }

    /**
     * Get organization shortcode (the unique identifier like "GTC" or "PR3PAC").
     */
    public static function getCurrentOrgShortcode(): ?string {
        $org = self::getCurrentOrg();
        if (!$org) {
            return null;
        }
        $shortcode = get_post_meta($org->ID, 'shortcode', true);
        return $shortcode ? (string) $shortcode : null;
    }

    /**
     * Get organization name.
     */
    public static function getCurrentOrgName(): ?string {
        $org = self::getCurrentOrg();
        return $org ? $org->post_title : null;
    }

    /**
     * Get the current user ID.
     */
    public static function getCurrentUserId(): int {
        return get_current_user_id();
    }

    /**
     * Check if current request is from a logged-in user.
     */
    public static function isLoggedIn(): bool {
        return is_user_logged_in();
    }
}
