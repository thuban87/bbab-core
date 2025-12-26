<?php
/**
 * Invoices Sub-Page Template
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
    '' => __('All Unpaid', 'bbab-service-center'),
    'Draft' => __('Draft', 'bbab-service-center'),
    'Pending' => __('Pending', 'bbab-service-center'),
    'Partial' => __('Partial', 'bbab-service-center'),
    'Overdue' => __('Overdue', 'bbab-service-center'),
    'Paid' => __('Paid', 'bbab-service-center'),
    'Cancelled' => __('Cancelled', 'bbab-service-center'),
];

$current_month = date_i18n('F');
?>
<div class="wrap bbab-workbench-wrap">
    <div class="bbab-workbench-header">
        <h1>
            <span class="dashicons dashicons-media-text"></span>
            <?php esc_html_e('Invoices', 'bbab-service-center'); ?>
        </h1>
        <p class="bbab-text-muted">
            <?php esc_html_e('View and manage all invoices.', 'bbab-service-center'); ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=bbab-workbench')); ?>">
                &larr; <?php esc_html_e('Back to Workbench', 'bbab-service-center'); ?>
            </a>
        </p>
    </div>

    <!-- Summary Stats Bar -->
    <div class="bbab-stats-bar">
        <div class="bbab-stat-box">
            <span class="bbab-stat-number"><?php echo esc_html($stats['total_draft']); ?></span>
            <span class="bbab-stat-label"><?php esc_html_e('Draft', 'bbab-service-center'); ?></span>
        </div>
        <div class="bbab-stat-box">
            <span class="bbab-stat-number"><?php echo esc_html($stats['total_pending']); ?></span>
            <span class="bbab-stat-label"><?php esc_html_e('Pending', 'bbab-service-center'); ?></span>
        </div>
        <div class="bbab-stat-box">
            <span class="bbab-stat-number"><?php echo esc_html($stats['total_partial']); ?></span>
            <span class="bbab-stat-label"><?php esc_html_e('Partial', 'bbab-service-center'); ?></span>
        </div>
        <div class="bbab-stat-box">
            <span class="bbab-stat-number"><?php echo esc_html($stats['total_overdue']); ?></span>
            <span class="bbab-stat-label"><?php esc_html_e('Overdue', 'bbab-service-center'); ?></span>
        </div>
        <div class="bbab-stat-box bbab-stat-divider bbab-stat-highlight">
            <span class="bbab-stat-number">$<?php echo esc_html(number_format($stats['outstanding_amount'], 0)); ?></span>
            <span class="bbab-stat-label"><?php esc_html_e('Total Owed (All Time)', 'bbab-service-center'); ?></span>
        </div>
        <div class="bbab-stat-box">
            <span class="bbab-stat-number">$<?php echo esc_html(number_format($stats['invoiced_this_month'], 0)); ?></span>
            <span class="bbab-stat-label"><?php echo esc_html(sprintf(__('New in %s', 'bbab-service-center'), $current_month)); ?></span>
        </div>
        <div class="bbab-stat-box">
            <span class="bbab-stat-number">$<?php echo esc_html(number_format($stats['paid_this_month'], 0)); ?></span>
            <span class="bbab-stat-label"><?php echo esc_html(sprintf(__('Collected in %s', 'bbab-service-center'), $current_month)); ?></span>
        </div>
    </div>

    <!-- Filters -->
    <div class="bbab-filters-bar">
        <form method="get" action="">
            <input type="hidden" name="page" value="bbab-invoices" />

            <!-- Status Filter Pills -->
            <div class="bbab-filter-group">
                <label class="bbab-filter-label"><?php esc_html_e('Status:', 'bbab-service-center'); ?></label>
                <div class="bbab-status-pills">
                    <?php foreach ($statuses as $value => $label) : ?>
                        <a href="<?php echo esc_url(add_query_arg([
                            'page' => 'bbab-invoices',
                            'invoice_status' => $value,
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
                <?php $this->getListTable()->search_box(__('Search Invoices', 'bbab-service-center'), 'invoice'); ?>
            </div>
        </form>
    </div>

    <!-- Invoices Table -->
    <div class="bbab-list-table-wrap">
        <form method="get" action="">
            <input type="hidden" name="page" value="bbab-invoices" />
            <?php if ($current_status) : ?>
                <input type="hidden" name="invoice_status" value="<?php echo esc_attr($current_status); ?>" />
            <?php endif; ?>
            <?php if ($current_org) : ?>
                <input type="hidden" name="organization" value="<?php echo esc_attr($current_org); ?>" />
            <?php endif; ?>

            <?php $this->getListTable()->display(); ?>
        </form>
    </div>

    <!-- Quick Actions -->
    <div class="bbab-quick-actions">
        <a href="<?php echo esc_url(admin_url('post-new.php?post_type=invoice')); ?>" class="button button-primary">
            <span class="dashicons dashicons-plus-alt2"></span>
            <?php esc_html_e('New Invoice', 'bbab-service-center'); ?>
        </a>
        <a href="<?php echo esc_url(admin_url('edit.php?post_type=invoice')); ?>" class="button">
            <?php esc_html_e('Native WP List', 'bbab-service-center'); ?>
        </a>
    </div>

</div><!-- .bbab-workbench-wrap -->
