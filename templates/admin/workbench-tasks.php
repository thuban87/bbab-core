<?php
/**
 * Client Tasks Sub-Page Template
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
    '' => __('Pending', 'bbab-service-center'),
    'Pending' => __('Pending', 'bbab-service-center'),
    'Completed' => __('Completed', 'bbab-service-center'),
];
?>
<div class="wrap bbab-workbench-wrap">
    <div class="bbab-workbench-header">
        <h1>
            <span class="dashicons dashicons-clipboard"></span>
            <?php esc_html_e('Client Tasks', 'bbab-service-center'); ?>
        </h1>
        <p class="bbab-text-muted">
            <?php esc_html_e('Action items clients need to complete.', 'bbab-service-center'); ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=bbab-workbench')); ?>">
                &larr; <?php esc_html_e('Back to Workbench', 'bbab-service-center'); ?>
            </a>
        </p>
    </div>

    <!-- Summary Stats Bar -->
    <div class="bbab-stats-bar">
        <div class="bbab-stat-box <?php echo $stats['total_pending'] > 0 ? 'bbab-stat-highlight' : ''; ?>">
            <span class="bbab-stat-number"><?php echo esc_html($stats['total_pending']); ?></span>
            <span class="bbab-stat-label"><?php esc_html_e('Pending Tasks', 'bbab-service-center'); ?></span>
        </div>
        <div class="bbab-stat-box <?php echo $stats['overdue'] > 0 ? 'bbab-stat-highlight' : ''; ?>">
            <span class="bbab-stat-number"><?php echo esc_html($stats['overdue']); ?></span>
            <span class="bbab-stat-label"><?php esc_html_e('Overdue', 'bbab-service-center'); ?></span>
        </div>
        <div class="bbab-stat-box">
            <span class="bbab-stat-number"><?php echo esc_html($stats['due_soon']); ?></span>
            <span class="bbab-stat-label"><?php esc_html_e('Due Soon (3 Days)', 'bbab-service-center'); ?></span>
        </div>
        <div class="bbab-stat-box bbab-stat-divider">
            <span class="bbab-stat-number"><?php echo esc_html($stats['total_completed']); ?></span>
            <span class="bbab-stat-label"><?php esc_html_e('Completed (All Time)', 'bbab-service-center'); ?></span>
        </div>
    </div>

    <!-- Filters -->
    <div class="bbab-filters-bar">
        <form method="get" action="">
            <input type="hidden" name="page" value="bbab-tasks" />

            <!-- Status Filter Pills -->
            <div class="bbab-filter-group">
                <label class="bbab-filter-label"><?php esc_html_e('Status:', 'bbab-service-center'); ?></label>
                <div class="bbab-status-pills">
                    <?php foreach ($statuses as $value => $label) : ?>
                        <a href="<?php echo esc_url(add_query_arg([
                            'page' => 'bbab-tasks',
                            'task_status' => $value,
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
                <?php $this->getListTable()->search_box(__('Search Tasks', 'bbab-service-center'), 'task'); ?>
            </div>
        </form>
    </div>

    <!-- Tasks Table -->
    <div class="bbab-list-table-wrap">
        <form method="get" action="">
            <input type="hidden" name="page" value="bbab-tasks" />
            <?php if ($current_status) : ?>
                <input type="hidden" name="task_status" value="<?php echo esc_attr($current_status); ?>" />
            <?php endif; ?>
            <?php if ($current_org) : ?>
                <input type="hidden" name="organization" value="<?php echo esc_attr($current_org); ?>" />
            <?php endif; ?>

            <?php $this->getListTable()->display(); ?>
        </form>
    </div>

    <!-- Quick Actions -->
    <div class="bbab-quick-actions">
        <a href="<?php echo esc_url(admin_url('post-new.php?post_type=client_task')); ?>" class="button button-primary">
            <span class="dashicons dashicons-plus-alt2"></span>
            <?php esc_html_e('New Client Task', 'bbab-service-center'); ?>
        </a>
        <a href="<?php echo esc_url(admin_url('edit.php?post_type=client_task')); ?>" class="button">
            <?php esc_html_e('Native WP List', 'bbab-service-center'); ?>
        </a>
    </div>

</div><!-- .bbab-workbench-wrap -->
