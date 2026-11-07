<?php
/**
 * Shared sidebar markup — used by includes/header.php and dashboard.php.
 * Expects $user (currentUser()) and optionally $pdo.
 */
$currentPage = $currentPage ?? basename($_SERVER['PHP_SELF'], '.php');
$currentDir  = $currentDir  ?? basename(dirname($_SERVER['PHP_SELF']));

if (!function_exists('navItem')) {
    function navItem(string $href, string $icon, string $label, bool $active, string $clr = '#818CF8'): string {
        $cls = $active ? 'nav-item active' : 'nav-item';
        return "<a href=\"$href\" class=\"$cls\" style=\"--item-clr:{$clr}\"><i class=\"bi bi-$icon\"></i> $label</a>";
    }
}
?>
<!-- ── Sidebar ── -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="brand-icon">FZL</div>
        <div>
            <div class="brand-name">FZL System</div>
            <div class="brand-sub"><?= __('management_system') ?></div>
        </div>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section"><?= __('nav_main') ?></div>
        <?php if (isAdmin()): ?>
        <?= navItem('/dashboard.php', 'grid-1x2', __('nav_dashboard'), $currentPage === 'dashboard', '#818CF8') ?>
        <?php endif; ?>

        <div class="nav-section"><?= __('nav_business') ?></div>
        <?= navItem('/customers/index.php', 'people',      __('nav_customers'), $currentDir === 'customers', '#C084FC') ?>
        <?php require_once __DIR__ . '/reminders.php'; $reminderCount = isset($pdo) ? overdueCount($pdo) : 0; ?>
        <a href="/reminders/index.php" class="nav-item <?= $currentDir === 'reminders' ? 'active' : '' ?>" style="--item-clr:#F87171;display:flex;align-items:center;">
            <i class="bi bi-bell"></i> Reminders
            <?php if ($reminderCount > 0): ?><span class="badge bg-danger" style="margin-inline-start:auto;"><?= $reminderCount ?></span><?php endif; ?>
        </a>
        <?= navItem('/summary/index.php',   'clipboard-data', __('nav_summary'), $currentDir === 'summary',   '#38BDF8') ?>
        <?php /* Products hidden for now — re-enable by uncommenting the line below:
        <?= navItem('/products/index.php',  'box-seam',    __('nav_products'),  $currentDir === 'products',  '#34D399') ?>
        */ ?>
        <?= navItem('/sales/index.php',     'receipt',     __('nav_sales'),     $currentDir === 'sales',     '#FB923C') ?>
        <?= navItem('/payments/index.php',  'cash-coin',   __('nav_payments'),  $currentDir === 'payments',  '#4ADE80') ?>
        <?= navItem('/stock/index.php',     'archive',     __('nav_stock'),     $currentDir === 'stock',     '#22D3EE') ?>
        <?= navItem('/accessories/index.php', 'gem',        __('nav_accessories'), $currentDir === 'accessories', '#A78BFA') ?>
        <?= navItem('/accessory-customers/index.php', 'person-badge', __('nav_acc_customers'), $currentDir === 'accessory-customers', '#F472B6') ?>
        <?= navItem('/wholesale/index.php', 'boxes',       __('nav_wholesale'), $currentDir === 'wholesale', '#14B8A6') ?>
        <?= navItem('/warehouse/index.php', 'basket3',     __('nav_warehouse'), $currentDir === 'warehouse', '#6366F1') ?>

        <?php if (isAdmin()): ?>
        <div class="nav-section"><?= __('nav_admin') ?></div>
        <?= navItem('/admin/users.php',    'person-gear',       __('nav_users'),    $currentDir === 'admin' && $currentPage === 'users',     '#F87171') ?>
        <?= navItem('/admin/reports.php',  'bar-chart',         __('nav_reports'),  $currentDir === 'admin' && $currentPage === 'reports',   '#60A5FA') ?>
        <?= navItem('/admin/settings.php', 'currency-exchange', __('nav_exchange'), $currentDir === 'admin' && $currentPage === 'settings',  '#FBBF24') ?>
        <?php endif; ?>
    </nav>

    <div class="sidebar-footer">
        <div class="s-avatar <?= $user['role'] ?>">
            <?= strtoupper(substr($user['full_name'], 0, 1)) ?>
        </div>
        <div style="min-width:0;">
            <div class="s-name text-truncate"><?= htmlspecialchars($user['full_name']) ?></div>
            <div class="s-role"><?= ucfirst($user['role']) ?></div>
        </div>
        <a href="/auth/logout.php" class="s-logout" title="<?= __('sign_out') ?>">
            <i class="bi bi-box-arrow-<?= isRTL() ? 'left' : 'right' ?>"></i>
        </a>
    </div>
</aside>
