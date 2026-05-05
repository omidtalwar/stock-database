<?php
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$currentDir  = basename(dirname($_SERVER['PHP_SELF']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FZL - <?= $pageTitle ?? 'Management' ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root {
            --sidebar-width: 260px;
            --primary: #e94560;
            --dark-bg: #1a1a2e;
            --sidebar-bg: #16213e;
            --sidebar-hover: #0f3460;
        }
        body { background: #f0f2f5; font-family: 'Segoe UI', sans-serif; }
        .sidebar {
            width: var(--sidebar-width); position: fixed; top: 0; left: 0;
            height: 100vh; background: var(--sidebar-bg); z-index: 1000;
            overflow-y: auto; transition: all 0.3s;
        }
        .sidebar-brand {
            background: linear-gradient(135deg, var(--primary), #c23152);
            padding: 20px; text-align: center;
        }
        .brand-text { font-size: 1.8rem; font-weight: 900; color: #fff; letter-spacing: 4px; }
        .brand-sub { color: rgba(255,255,255,0.7); font-size: 0.75rem; }
        .sidebar-menu { padding: 16px 0; }
        .menu-section { padding: 8px 20px 4px; color: rgba(255,255,255,0.4); font-size: 0.7rem; text-transform: uppercase; letter-spacing: 1px; }
        .menu-item { display: flex; align-items: center; padding: 10px 20px; color: rgba(255,255,255,0.75); text-decoration: none; border-left: 3px solid transparent; transition: all 0.2s; }
        .menu-item:hover { background: var(--sidebar-hover); color: #fff; border-left-color: var(--primary); }
        .menu-item.active { background: var(--sidebar-hover); color: #fff; border-left-color: var(--primary); }
        .menu-item i { width: 22px; margin-right: 10px; font-size: 1rem; }
        .main-content { margin-left: var(--sidebar-width); min-height: 100vh; }
        .topbar {
            background: #fff; padding: 12px 24px; display: flex; align-items: center;
            justify-content: space-between; box-shadow: 0 1px 4px rgba(0,0,0,0.08); position: sticky; top: 0; z-index: 100;
        }
        .topbar-title { font-size: 1.1rem; font-weight: 600; color: #333; }
        .content-area { padding: 24px; }
        .card { border: none; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.07); }
        .card-header { background: #fff; border-bottom: 1px solid #f0f0f0; border-radius: 12px 12px 0 0 !important; padding: 16px 20px; }
        .stat-card { border-radius: 12px; border: none; overflow: hidden; }
        .stat-card .card-body { padding: 20px; }
        .stat-icon { width: 50px; height: 50px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.4rem; }
        .badge-role-admin { background: #fff3cd; color: #856404; }
        .badge-role-assistant { background: #d1ecf1; color: #0c5460; }
        .btn-primary { background: var(--primary); border-color: var(--primary); }
        .btn-primary:hover { background: #c23152; border-color: #c23152; }
        .table th { background: #f8f9fa; font-weight: 600; font-size: 0.85rem; color: #555; }
        .page-header { margin-bottom: 24px; }
        .sidebar-user { padding: 16px 20px; border-top: 1px solid rgba(255,255,255,0.1); margin-top: auto; }
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.show { transform: translateX(0); }
            .main-content { margin-left: 0; }
        }
    </style>
</head>
<body>
<?php $user = currentUser(); ?>
<div class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="brand-text">FZL</div>
        <div class="brand-sub">Management System</div>
    </div>
    <div class="sidebar-menu">
        <div class="menu-section">Main</div>
        <a href="/fzl/dashboard.php" class="menu-item <?= $currentPage === 'dashboard' ? 'active' : '' ?>">
            <i class="bi bi-speedometer2"></i> Dashboard
        </a>

        <div class="menu-section">Business</div>
        <a href="/fzl/customers/index.php" class="menu-item <?= $currentDir === 'customers' ? 'active' : '' ?>">
            <i class="bi bi-people"></i> Customers
        </a>
        <a href="/fzl/products/index.php" class="menu-item <?= $currentDir === 'products' ? 'active' : '' ?>">
            <i class="bi bi-box-seam"></i> Products
        </a>
        <a href="/fzl/sales/index.php" class="menu-item <?= $currentDir === 'sales' ? 'active' : '' ?>">
            <i class="bi bi-receipt"></i> Sales / Invoices
        </a>
        <a href="/fzl/payments/index.php" class="menu-item <?= $currentDir === 'payments' ? 'active' : '' ?>">
            <i class="bi bi-cash-coin"></i> Payments
        </a>
        <a href="/fzl/stock/index.php" class="menu-item <?= $currentDir === 'stock' ? 'active' : '' ?>">
            <i class="bi bi-archive"></i> Stock Log
        </a>

        <?php if (isAdmin()): ?>
        <div class="menu-section">Admin</div>
        <a href="/fzl/admin/users.php" class="menu-item <?= ($currentDir === 'admin' && $currentPage === 'users') ? 'active' : '' ?>">
            <i class="bi bi-person-gear"></i> User Management
        </a>
        <a href="/fzl/admin/reports.php" class="menu-item <?= ($currentDir === 'admin' && $currentPage === 'reports') ? 'active' : '' ?>">
            <i class="bi bi-bar-chart"></i> Reports
        </a>
        <a href="/fzl/admin/settings.php" class="menu-item <?= ($currentDir === 'admin' && $currentPage === 'settings') ? 'active' : '' ?>">
            <i class="bi bi-currency-exchange"></i> Exchange Rate
        </a>
        <?php endif; ?>
    </div>
    <div class="sidebar-user">
        <div class="d-flex align-items-center">
            <div class="bg-primary rounded-circle d-flex align-items-center justify-content-center text-white me-2"
                 style="width:36px;height:36px;background:var(--primary) !important;font-size:0.9rem;font-weight:700;">
                <?= strtoupper(substr($user['full_name'], 0, 1)) ?>
            </div>
            <div>
                <div class="text-white small fw-semibold"><?= htmlspecialchars($user['full_name']) ?></div>
                <div class="badge badge-role-<?= $user['role'] ?> rounded-pill" style="font-size:0.65rem;">
                    <?= ucfirst($user['role']) ?>
                </div>
            </div>
            <a href="/fzl/auth/logout.php" class="ms-auto text-white-50" title="Logout">
                <i class="bi bi-box-arrow-right"></i>
            </a>
        </div>
    </div>
</div>

<div class="main-content">
    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="btn btn-sm btn-light d-md-none" id="sidebarToggle">
                <i class="bi bi-list fs-5"></i>
            </button>
            <span class="topbar-title"><?= $pageTitle ?? 'Dashboard' ?></span>
        </div>
        <div class="d-flex align-items-center gap-3">
            <span class="text-muted small"><?= date('d M Y') ?></span>
            <a href="/fzl/auth/logout.php" class="btn btn-sm btn-outline-danger">
                <i class="bi bi-box-arrow-right me-1"></i>Logout
            </a>
        </div>
    </div>
    <div class="content-area">
        <?= flashMessage() ?>
