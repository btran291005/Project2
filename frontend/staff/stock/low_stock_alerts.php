<?php
/**
 * File: frontend/staff/stock/low_stock_alerts.php
 * Purpose: Prioritized list of low-stock/urgent-restock products for Store Staff.
 * Related: FR-STF-03, FR-STF-09
 * Calls: StaffService::getLowStockList()
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../backend/config/app_config.php';
require_once __DIR__ . '/../../../backend/config/database.php';
require_once __DIR__ . '/../../../backend/core/Logger.php';
require_once __DIR__ . '/../../../backend/core/Auth.php';
require_once __DIR__ . '/../../../backend/core/Middleware.php';
require_once __DIR__ . '/../../../backend/services/StaffService.php';

Middleware::guard([ROLE_STAFF]);

$staffService = new StaffService();
$alerts = $staffService->getLowStockList();

function restockPriorityLabel(int $currentQty, int $reorderPoint): array
{
    if ($currentQty <= 0) {
        return ['label' => 'CRITICAL', 'class' => 'status-badge-danger'];
    }

    $ratio = $reorderPoint > 0 ? $currentQty / $reorderPoint : 0;
    if ($ratio <= 0.5) {
        return ['label' => 'HIGH', 'class' => 'status-badge-danger'];
    }
    if ($ratio <= 0.8) {
        return ['label' => 'MEDIUM', 'class' => 'status-badge-warning'];
    }
    return ['label' => 'NORMAL', 'class' => 'status-badge-info'];
}

$lowCount = count($alerts);
$criticalCount = count(array_filter($alerts, fn($item) => (int) $item['current_quantity'] <= (int) $item['safety_stock']));

$activeMenu  = 'stock';
$pageTitle   = 'Low Stock Alerts';
$breadcrumbs = ['Staff', 'Stock', 'Low Stock Alerts'];
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Low Stock Alerts - InventoryDSS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/theme_variables.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/custom.css" rel="stylesheet">
</head>
<body>
    <div class="app-shell">
        <?php require __DIR__ . '/../../components/sidebar.php'; ?>

        <div class="app-content">
            <?php require __DIR__ . '/../../components/header.php'; ?>

            <main class="app-main">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
                    <div>
                        <span class="text-muted small text-uppercase fw-semibold" style="letter-spacing: .5px;">Stock Monitoring</span>
                        <h2 class="page-heading mb-1">Low Stock Alerts</h2>
                        <p class="page-subheading mb-0">List of products requiring attention by priority level, based on reorder point and recent consumption.</p>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-6 col-md-4 col-xl-3">
                        <div class="kpi-card <?= $lowCount > 0 ? 'kpi-card-warn' : '' ?>">
                            <span class="kpi-label">Products below reorder point</span>
                            <span class="kpi-value"><?= number_format($lowCount) ?></span>
                        </div>
                    </div>
                    <div class="col-6 col-md-4 col-xl-3">
                        <div class="kpi-card <?= $criticalCount > 0 ? 'kpi-card-warn' : '' ?>">
                            <span class="kpi-label">Critical items</span>
                            <span class="kpi-value"><?= number_format($criticalCount) ?></span>
                        </div>
                    </div>
                    <div class="col-12 col-md-4 col-xl-6">
                        <div class="kpi-card">
                            <span class="kpi-label">Page purpose</span>
                            <span class="kpi-value">Priority check/reorder/receiving</span>
                        </div>
                    </div>
                </div>

                <div class="panel-card mb-3">
                    <div class="panel-card-header">
                        <h3 class="panel-card-title">Priority low stock items</h3>
                        <span class="panel-card-note">Sorted by urgency (stock / reorder point).</span>
                    </div>

                    <?php if (empty($alerts)): ?>
                        <div class="empty-state">No products require urgent restocking at this time.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table data-table align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>SKU / Product</th>
                                        <th class="text-end">Current Stock</th>
                                        <th class="text-end">Reorder Point</th>
                                        <th class="text-end">Safety Stock</th>
                                        <th class="text-end">Sales 7d</th>
                                        <th>Priority</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($alerts as $item): ?>
                                        <?php $priority = restockPriorityLabel((int) $item['current_quantity'], (int) $item['reorder_point']); ?>
                                        <tr>
                                            <td>
                                                <div class="fw-semibold"><?= htmlspecialchars($item['product_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                                <div class="text-muted small"><?= htmlspecialchars($item['sku_code'], ENT_QUOTES, 'UTF-8') ?></div>
                                            </td>
                                            <td class="text-end fw-semibold"><?= number_format((int) $item['current_quantity']) ?></td>
                                            <td class="text-end text-muted"><?= number_format((int) $item['reorder_point']) ?></td>
                                            <td class="text-end text-muted"><?= number_format((int) $item['safety_stock']) ?></td>
                                            <td class="text-end"><?= number_format((int) $item['sales_volume_7d']) ?></td>
                                            <td><span class="status-badge <?= $priority['class'] ?>"><?= $priority['label'] ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="panel-card">
                    <div class="panel-card-header">
                        <h3 class="panel-card-title">Stock view</h3>
                        <a href="stock_view.php" class="panel-card-link">View all inventory</a>
                    </div>
                    <div class="panel-card-body">
                        <p class="mb-0 text-muted">The Stock View page shows all inventory, with status based on reorder and safety stock rules.</p>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <?php require __DIR__ . '/../../components/footer.php'; ?>