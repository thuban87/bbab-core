<?php
/**
 * Service Requests Sub-Page Template
 *
 * Variables available:
 * - $stats           Summary statistics array
 * - $organizations   Array of organizations for filter
 * - $current_status  Currently selected status filter
 * - $current_org     Currently selected organization filter
 *
 * @package BBAB\ServiceCenter\Admin\Workbench
 * @since   2.0.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

$statuses = [
    '' => __('Open', 'bbab-service-center'),
    'New' => __('New', 'bbab-service-center'),
    'Acknowledged' => __('Acknowledged', 'bbab-service-center'),
    'In Progress' => __('In Progress', 'bbab-service-center'),
    'Waiting on Client' => __('Waiting on Client', 'bbab-service-center'),
    'On Hold' => __('On Hold', 'bbab-service-center'),
    'Completed' => __('Completed', 'bbab-service-center'),
    'Cancelled' => __('Cancelled', 'bbab-service-center'),
];

$current_month = date_i18n('F');
?>
<div class="wrap bbab-workbench-wrap">
    <div class="bbab-workbench-header">
        <h1>
            <span class="dashicons dashicons-sos"></span>
            <?php esc_html_e('Service Requests', 'bbab-service-center'); ?>
        </h1>
        <p class="bbab-text-muted">
            <?php esc_html_e('View and manage all service requests.', 'bbab-service-center'); ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=bbab-workbench')); ?>">
                &larr; <?php esc_html_e('Back to Workbench', 'bbab-service-center'); ?>
            </a>
        </p>
    </div>

    <!-- Summary Stats Bar -->
    <div class="bbab-stats-bar">
        <div class="bbab-stat-box <?php echo $stats['total_new'] > 0 ? 'bbab-stat-highlight' : ''; ?>">
            <span class="bbab-stat-number"><?php echo esc_html($stats['total_new']); ?></span>
            <span class="bbab-stat-label"><?php esc_html_e('New', 'bbab-service-center'); ?></span>
        </div>
        <div class="bbab-stat-box">
            <span class="bbab-stat-number"><?php echo esc_html($stats['total_acknowledged']); ?></span>
            <span class="bbab-stat-label"><?php esc_html_e('Acknowledged', 'bbab-service-center'); ?></span>
        </div>
        <div class="bbab-stat-box">
            <span class="bbab-stat-number"><?php echo esc_html($stats['total_in_progress']); ?></span>
            <span class="bbab-stat-label"><?php esc_html_e('In Progress', 'bbab-service-center'); ?></span>
        </div>
        <div class="bbab-stat-box">
            <span class="bbab-stat-number"><?php echo esc_html($stats['total_waiting']); ?></span>
            <span class="bbab-stat-label"><?php esc_html_e('Waiting', 'bbab-service-center'); ?></span>
        </div>
        <div class="bbab-stat-box">
            <span class="bbab-stat-number"><?php echo esc_html($stats['total_on_hold']); ?></span>
            <span class="bbab-stat-label"><?php esc_html_e('On Hold', 'bbab-service-center'); ?></span>
        </div>
        <div class="bbab-stat-box bbab-stat-divider">
            <span class="bbab-stat-number"><?php echo esc_html(number_format($stats['hours_this_month'], 1)); ?></span>
            <span class="bbab-stat-label"><?php echo esc_html(sprintf(__('Hours in %s', 'bbab-service-center'), $current_month)); ?></span>
        </div>
        <div class="bbab-stat-box">
            <span class="bbab-stat-number"><?php echo esc_html($stats['completed_this_month']); ?></span>
            <span class="bbab-stat-label"><?php echo esc_html(sprintf(__('Completed (%s)', 'bbab-service-center'), $current_month)); ?></span>
        </div>
    </div>

    <!-- Filters -->
    <div class="bbab-filters-bar">
        <form method="get" action="">
            <input type="hidden" name="page" value="bbab-requests" />

            <!-- Status Filter Pills -->
            <div class="bbab-filter-group">
                <label class="bbab-filter-label"><?php esc_html_e('Status:', 'bbab-service-center'); ?></label>
                <div class="bbab-status-pills">
                    <?php foreach ($statuses as $value => $label) : ?>
                        <a href="<?php echo esc_url(add_query_arg([
                            'page' => 'bbab-requests',
                            'request_status' => $value,
                            'organization' => $current_org ?: null,
                        ], admin_url('admin.php'))); ?>"
                           class="bbab-status-pill <?php echo $current_status === $value ? 'active' : ''; ?>">
                            <?php echo esc_html($label); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Organization Filter -->
            <div class="bbab-filter-group">
                <label class="bbab-filter-label" for="organization"><?php esc_html_e('Client:', 'bbab-service-center'); ?></label>
                <select name="organization" id="organization" class="bbab-client-select" onchange="this.form.submit()">
                    <option value=""><?php esc_html_e('All Clients', 'bbab-service-center'); ?></option>
                    <?php foreach ($organizations as $org) : ?>
                        <option value="<?php echo esc_attr($org['id']); ?>" <?php selected($current_org, $org['id']); ?>>
                            <?php echo esc_html($org['shortcode'] ? $org['shortcode'] . ' - ' . $org['name'] : $org['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Search -->
            <div class="bbab-filter-group bbab-filter-search">
                <?php $this->getListTable()->search_box(__('Search Requests', 'bbab-service-center'), 'request'); ?>
            </div>
        </form>
    </div>

    <!-- Requests Table -->
    <div class="bbab-list-table-wrap">
        <form method="get" action="">
            <input type="hidden" name="page" value="bbab-requests" />
            <?php if ($current_status) : ?>
                <input type="hidden" name="request_status" value="<?php echo esc_attr($current_status); ?>" />
            <?php endif; ?>
            <?php if ($current_org) : ?>
                <input type="hidden" name="organization" value="<?php echo esc_attr($current_org); ?>" />
            <?php endif; ?>

            <?php $this->getListTable()->display(); ?>
        </form>
    </div>

    <!-- Quick Actions -->
    <div class="bbab-quick-actions">
        <a href="<?php echo esc_url(admin_url('post-new.php?post_type=service_request')); ?>" class="button button-primary">
            <span class="dashicons dashicons-plus-alt2"></span>
            <?php esc_html_e('New Service Request', 'bbab-service-center'); ?>
        </a>
        <a href="<?php echo esc_url(admin_url('edit.php?post_type=service_request')); ?>" class="button">
            <?php esc_html_e('Native WP List', 'bbab-service-center'); ?>
        </a>
    </div>

</div><!-- .bbab-workbench-wrap -->
