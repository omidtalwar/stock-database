<?php
require_once '../includes/session.php';
requireLogin();
require_once '../includes/lang.php';

$pageTitle = __('accessories_title');

require_once '../includes/header.php';
?>

<div class="page-header d-flex align-items-center justify-content-between">
    <div>
        <h4 class="mb-1"><?= __('accessories_title') ?></h4>
        <p class="text-muted small mb-0"><?= __('accessories_sub') ?></p>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body text-center py-5">
        <div class="mx-auto mb-3"
             style="width:56px;height:56px;border-radius:14px;background:rgba(167,139,250,0.14);display:flex;align-items:center;justify-content:center;color:#7C3AED;font-size:1.55rem;">
            <i class="bi bi-gem"></i>
        </div>
        <h5 class="mb-2"><?= __('accessories_placeholder_title') ?></h5>
        <p class="text-muted mb-0" style="max-width:420px;margin:0 auto;">
            <?= __('accessories_placeholder_text') ?>
        </p>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
