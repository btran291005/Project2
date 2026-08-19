<?php
/**
 * File: frontend/manager/product_pfm.php
 * Purpose: Product Performance Analysis - xếp hạng sản phẩm theo số lượng
 * bán, kèm turnover ratio (theo số lượng và theo giá trị COGS). Dùng
 * ManagerService::getProductPerformanceReport($fromDate, $toDate).
 * Related: FR-MGR-09
 *
 * QUAN TRỌNG (giữ đúng disclaimer từ ManagerService): "Revenue" hiển thị ở
 * đây thực chất là SỐ LƯỢNG bán, không phải doanh thu bằng tiền, vì hệ
 * thống chưa có cột giá bán lẻ - trang này PHẢI hiển thị rõ ghi chú này cho
 * Manager, không được ngầm hiểu là số liệu tài chính thật.
 *
 * Style/layout đồng bộ frontend/manager/dashboard.php (app-shell + panel-card).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../backend/config/app_config.php';
require_once __DIR__ . '/../../../backend/config/database.php';
require_once __DIR__ . '/../../../backend/core/Logger.php';
require_once __DIR__ . '/../../../backend/core/Auth.php';
require_once __DIR__ . '/../../../backend/core/Middleware.php';
require_once __DIR__ . '/../../../backend/services/ManagerService.php';

Middleware::guard([ROLE_MANAGER]);

$managerService = new ManagerService();

// Mặc định: 30 ngày gần nhất, cho phép Manager tự chọn khoảng ngày khác qua form GET
$fromDate = $_GET['from_date'] ?? date('Y-m-d', strtotime('-30 days'));
$toDate   = $_GET['to_date'] ?? date('Y-m-d 23:59:59');

$report = $managerService->getProductPerformanceReport($fromDate, $toDate . ' 23:59:59');

$topSellers  = array_slice($report['products'], 0, 10);
$slowMoving  = array_slice(array_reverse($report['products']), 0, 10);

$pageTitle   = 'Product Performance';
$breadcrumbs = ['Manager', 'Product Performance'];
$activeMenu  = 'product_pfm';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product Performance - InventoryDSS</title>
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

                <a href="performance_analytics.php" class="btn btn-outline-secondary btn-sm mb-3">&larr; Reports Overview</a>

                <div class="mb-4">
                    <h2 class="page-heading mb-1">Product Performance Analysis</h2>
                    <p class="page-subheading mb-0">Ranks products by sales volume and inventory turnover (FR-MGR-09).</p>
                </div>

                <div class="alert alert-secondary small">
                    The system does not yet store retail selling prices — the metrics below use
                    <strong>quantity sold</strong> as a proxy for demand, and
                    <strong>COGS ÷ inventory value</strong> (at cost) as a proxy for inventory turnover.
                    This is not actual revenue/financial data.
                </div>

                <!-- Date range filter -->
                <div class="panel-card mb-3">
                    <form method="get" class="filter-bar p-1">
                        <div>
                            <label class="form-label">From date</label>
                            <input type="date" name="from_date" class="form-control form-control-sm" value="<?= htmlspecialchars($fromDate, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div>
                            <label class="form-label">To date</label>
                            <input type="date" name="to_date" class="form-control form-control-sm" value="<?= htmlspecialchars(substr($toDate, 0, 10), ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div>
                            <button type="submit" class="btn btn-brand btn-sm">Apply</button>
                        </div>
                    </form>
                </div>

                <div class="row g-3">
                    <!-- Best-sellers -->
                    <div class="col-12 col-xl-6">
                        <div class="panel-card h-100">
                            <div class="panel-card-header">
                                <h3 class="panel-card-title">Best Sellers</h3>
                                <span class="panel-card-note">Prioritize for display / stock replenishment</span>
                            </div>

                            <?php if (empty($topSellers)): ?>
                                <div class="empty-state">No sales data in this time range.</div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm data-table mb-0">
                                        <thead>
                                            <tr>
                                                <th>SKU</th>
                                                <th>Product</th>
                                                <th class="text-end">Qty Sold</th>
                                                <th class="text-end">Turnover (Value)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($topSellers as $row): ?>
                                                <tr>
                                                    <td class="text-muted"><?= htmlspecialchars($row['sku_code'], ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td><?= htmlspecialchars($row['product_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td class="text-end">
                                                        <span class="stock-pill stock-pill-warn"><?= number_format((int) $row['total_quantity_sold']) ?></span>
                                                    </td>
                                                    <td class="text-end text-muted">
                                                        <?= $row['turnover_value_ratio'] !== null ? number_format((float) $row['turnover_value_ratio'], 2) : '—' ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Slow-moving -->
                    <div class="col-12 col-xl-6">
                        <div class="panel-card h-100">
                            <div class="panel-card-header">
                                <h3 class="panel-card-title">Slowest Moving</h3>
                                <span class="panel-card-note">Consider phase-out or promotions</span>
                            </div>

                            <?php if (empty($slowMoving)): ?>
                                <div class="empty-state">No data.</div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm data-table mb-0">
                                        <thead>
                                            <tr>
                                                <th>SKU</th>
                                                <th>Product</th>
                                                <th class="text-end">Qty Sold</th>
                                                <th class="text-end">Stock</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($slowMoving as $row): ?>
                                                <tr>
                                                    <td class="text-muted"><?= htmlspecialchars($row['sku_code'], ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td><?= htmlspecialchars($row['product_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td class="text-end">
                                                        <span class="stock-pill stock-pill-critical"><?= number_format((int) $row['total_quantity_sold']) ?></span>
                                                    </td>
                                                    <td class="text-end text-muted"><?= number_format((int) $row['current_stock']) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Full ranking -->
                <div class="row g-3 mt-0">
                    <div class="col-12">
                        <div class="panel-card">
                            <div class="panel-card-header">
                                <h3 class="panel-card-title">
                                    Full Ranking
                                    <span class="badge-count"><?= count($report['products']) ?></span>
                                </h3>
                            </div>

                            <?php if (empty($report['products'])): ?>
                                <div class="empty-state">No data.</div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table data-table align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>SKU</th>
                                                <th>Product</th>
                                                <th class="text-end">Qty Sold</th>
                                                <th class="text-end">Stock</th>
                                                <th class="text-end">Turnover (Qty)</th>
                                                <th class="text-end">Turnover (Value)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($report['products'] as $i => $row): ?>
                                                <tr>
                                                    <td class="text-muted"><?= $i + 1 ?></td>
                                                    <td class="text-muted"><?= htmlspecialchars($row['sku_code'], ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td class="fw-semibold"><?= htmlspecialchars($row['product_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td class="text-end"><?= number_format((int) $row['total_quantity_sold']) ?></td>
                                                    <td class="text-end text-muted"><?= number_format((int) $row['current_stock']) ?></td>
                                                    <td class="text-end text-muted">
                                                        <?= $row['turnover_ratio_approx'] !== null ? number_format((float) $row['turnover_ratio_approx'], 2) : '—' ?>
                                                    </td>
                                                    <td class="text-end text-muted">
                                                        <?= $row['turnover_value_ratio'] !== null ? number_format((float) $row['turnover_value_ratio'], 2) : '—' ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <?php require __DIR__ . '/../../components/footer.php'; ?>