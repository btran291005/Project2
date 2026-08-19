<?php
/**
 * File: frontend/manager/inventory/inventory_health.php
 * Purpose: Trang landing "Inventory" của Manager - tổng quan sức khỏe tồn kho
 * (tổng SP, số SP dưới Reorder Point, số SP thủng Safety Stock, tổng giá trị
 * tồn kho theo giá nhập) + 2 "chỗ bấm vô để xem": AI Replenishment (gợi ý đặt
 * hàng) và Stock Incidents (sự cố thiếu hàng). Cùng kiểu với cách Admin làm
 * inventory_overview.php (landing + tab dẫn qua Count History) - chỉ đổi
 * href của mục 'inventory' bên sidebar Manager để trỏ vào đây, KHÔNG thêm
 * submenu thật vào sidebar (giữ nguyên sidebar phẳng hiện tại).
 *
 * Related: FR-MGR-02 (reorder), FR-MGR-07 (shortage incidents), BR-04/BR-05
 * Calls: ReorderService::suggestQuantity() (qua ManagerService::getReorderSuggestions()),
 *        ManagerService::listShortageIncidents(), Inventory::getStockByProduct(),
 *        Product::getAll()
 *
 * LƯU Ý DỮ LIỆU: không có cột "confidence %"/nguồn AI-vs-Rule cho CẢ danh
 * sách (chỉ có per-product qua IntegrationService::getForecastForProduct(),
 * tốn 1 lời gọi API/sản phẩm) - trang tổng quan này CHỈ dùng số liệu tính
 * được rẻ (rule-based list + tồn kho), không bịa số liệu AI hàng loạt.
 *
 * Style/layout đồng bộ frontend/admin/inventory/inventory_overview.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../backend/config/app_config.php';
require_once __DIR__ . '/../../../backend/config/database.php';
require_once __DIR__ . '/../../../backend/core/Logger.php';
require_once __DIR__ . '/../../../backend/core/Auth.php';
require_once __DIR__ . '/../../../backend/core/Middleware.php';
require_once __DIR__ . '/../../../backend/services/ManagerService.php';
require_once __DIR__ . '/../../../backend/models/Product.php';
require_once __DIR__ . '/../../../backend/models/Inventory.php';

Middleware::guard([ROLE_MANAGER]);

$managerService = new ManagerService();
$productModel   = new Product();
$inventoryModel = new Inventory();

// =========================================================================
// DỮ LIỆU: tổng hợp KPI sức khỏe tồn kho từ dữ liệu thật (không bịa)
// =========================================================================
$suggestionResult = $managerService->getReorderSuggestions();
$reorderSuggestions = $suggestionResult['success'] ? $suggestionResult['suggestions'] : [];

$openIncidents = $managerService->listShortageIncidents('Open');

$activeProducts = $productModel->getAll(null, null, true);
$totalActiveProducts = count($activeProducts);

// Giá trị tồn kho hiện tại (theo giá nhập unit_cost) - dùng cùng cách tính
// line_cost đã áp dụng ở Order::getDetails() (approved_qty * unit_cost),
// ở đây thay approved_qty bằng current_stock thật.
$unitCostByProduct = [];
foreach ($activeProducts as $p) {
    $unitCostByProduct[(int) $p['product_id']] = (float) $p['unit_cost'];
}
$stockRows = $inventoryModel->getStockByProduct();
$totalStockValue = 0.0;
foreach ($stockRows as $row) {
    $pid = (int) $row['product_id'];
    $totalStockValue += ((float) $row['total_quantity']) * ($unitCostByProduct[$pid] ?? 0.0);
}

// Critical = đã thủng safety stock (rủi ro hết hàng cao nhất), Low = dưới
// reorder point nhưng vẫn còn trên safety stock - đúng ngưỡng đã dùng ở
// reorder_suggestions.php (reorderUrgency()).
$criticalCount = 0;
foreach ($reorderSuggestions as $item) {
    if ((int) $item['current_stock'] <= (int) $item['safety_stock']) {
        $criticalCount++;
    }
}
$lowStockCount = count($reorderSuggestions);

$pageTitle   = 'Inventory Health';
$breadcrumbs = ['Manager', 'Inventory', 'Health'];
$activeMenu  = 'inventory_health';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Health - InventoryDSS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/theme_variables.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/custom.css" rel="stylesheet">
    <style>
        /* Tab điều hướng nội bộ trang Inventory - tái dùng token màu/border
           chung, không thêm class mới vào custom.css chỉ vì 1 trang. */
        .inv-tab-nav {
            display: flex;
            gap: 6px;
            border-bottom: 1px solid var(--surface-border);
            margin-bottom: 20px;
        }
        .inv-tab-link {
            padding: 10px 16px;
            font-size: .87rem;
            font-weight: 600;
            color: var(--text-muted);
            text-decoration: none;
            border-bottom: 2px solid transparent;
            margin-bottom: -1px;
        }
        .inv-tab-link:hover { color: var(--brand-primary); }
        .inv-tab-link.active {
            color: var(--brand-primary);
            border-bottom-color: var(--brand-primary);
        }
        .inv-quick-card {
            display: block;
            height: 100%;
            padding: 20px 22px;
            background: var(--surface-card-bg);
            border: 1px solid var(--surface-border);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-card);
            text-decoration: none;
            color: inherit;
            transition: box-shadow .15s, transform .1s;
        }
        .inv-quick-card:hover {
            color: inherit;
            box-shadow: 0 8px 20px -8px rgba(30, 58, 95, .3);
            transform: translateY(-2px);
        }
        .inv-quick-card-title {
            font-size: 1rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 6px;
        }
        .inv-quick-card-desc {
            font-size: .84rem;
            color: var(--text-muted);
            line-height: 1.5;
        }
        .inv-quick-card-arrow {
            font-size: .82rem;
            font-weight: 600;
            color: var(--brand-primary);
            margin-top: 12px;
            display: inline-block;
        }
    </style>
</head>
<body>
    <div class="app-shell">
        <?php require __DIR__ . '/../../components/sidebar.php'; ?>

        <div class="app-content">
            <?php require __DIR__ . '/../../components/header.php'; ?>

            <main class="app-main">

                <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
                    <div>
                        <h2 class="page-heading mb-1">Inventory Health</h2>
                        <p class="page-subheading mb-0">Overview of inventory health, reorder suggestions, and open shortage incidents.</p>
                    </div>
                </div>

                <!-- Internal tab navigation -->
                <nav class="inv-tab-nav">
                    <a href="inventory_health.php" class="inv-tab-link active">Inventory Health</a>
                    <a href="ai_replenishment.php" class="inv-tab-link">AI Replenishment</a>
                    <a href="stock_incidents.php" class="inv-tab-link">Stock Incidents</a>
                </nav>

                <!-- KPI cards -->
                <div class="row g-3 mb-4">
                    <div class="col-6 col-xl-3">
                        <div class="kpi-card">
                            <div class="kpi-card-top"><span class="kpi-label">Active Products</span></div>
                            <span class="kpi-value"><?= number_format($totalActiveProducts) ?></span>
                        </div>
                    </div>
                    <div class="col-6 col-xl-3">
                        <div class="kpi-card <?= $lowStockCount > 0 ? 'kpi-card-warn' : '' ?>">
                            <div class="kpi-card-top"><span class="kpi-label">Below Reorder Point</span></div>
                            <span class="kpi-value" style="color: var(--color-warning);"><?= number_format($lowStockCount) ?></span>
                        </div>
                    </div>
                    <div class="col-6 col-xl-3">
                        <div class="kpi-card <?= $criticalCount > 0 ? 'kpi-card-warn' : '' ?>">
                            <div class="kpi-card-top"><span class="kpi-label">Critical (Below Safety Stock)</span></div>
                            <span class="kpi-value" style="color: var(--color-danger);"><?= number_format($criticalCount) ?></span>
                        </div>
                    </div>
                    <div class="col-6 col-xl-3">
                        <div class="kpi-card">
                            <div class="kpi-card-top"><span class="kpi-label">Stock Value (Cost)</span></div>
                            <span class="kpi-value">&#8363;<?= number_format($totalStockValue, 0) ?></span>
                        </div>
                    </div>
                </div>

                <!-- Quick access cards -->
                <div class="row g-3 mb-4">
                    <div class="col-12 col-md-6">
                        <a href="ai_replenishment.php" class="inv-quick-card">
                            <div class="inv-quick-card-title">AI Replenishment</div>
                            <p class="inv-quick-card-desc mb-0">
                                View reorder suggestions based on Reorder Point &amp; Safety Stock (BR-05), compared with the AI Forecast for each product.
                                <?= $lowStockCount > 0 ? "There are currently <strong>{$lowStockCount}</strong> products that need attention." : 'No products currently need reordering.' ?>
                            </p>
                            <span class="inv-quick-card-arrow">View suggestions &rarr;</span>
                        </a>
                    </div>
                    <div class="col-12 col-md-6">
                        <a href="stock_incidents.php" class="inv-quick-card">
                            <div class="inv-quick-card-title">Stock Incidents</div>
                            <p class="inv-quick-card-desc mb-0">
                                Log and track unexpected shortage incidents (FR-MGR-07).
                                <?= count($openIncidents) > 0 ? "There are currently <strong>" . count($openIncidents) . "</strong> open incidents." : 'No incidents currently open.' ?>
                            </p>
                            <span class="inv-quick-card-arrow">View incidents &rarr;</span>
                        </a>
                    </div>
                </div>

                <!-- Condensed list: top items needing the most attention -->
                <div class="panel-card">
                    <div class="panel-card-header">
                        <h3 class="panel-card-title">Top Priority Reorder Items</h3>
                        <a href="ai_replenishment.php" class="panel-card-link">View all</a>
                    </div>

                    <?php if (empty($reorderSuggestions)): ?>
                        <div class="empty-state">No products are at or below the Reorder Point.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table data-table align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th class="text-end">Current Stock</th>
                                        <th class="text-end">Reorder Point</th>
                                        <th class="text-end">Suggested Qty</th>
                                        <th>Urgency</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($reorderSuggestions, 0, 5) as $item): ?>
                                        <?php $isCritical = (int) $item['current_stock'] <= (int) $item['safety_stock']; ?>
                                        <tr>
                                            <td>
                                                <span class="fw-semibold"><?= htmlspecialchars($item['product_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                                <div class="text-muted small"><?= htmlspecialchars($item['sku_code'], ENT_QUOTES, 'UTF-8') ?></div>
                                            </td>
                                            <td class="text-end"><?= number_format((int) $item['current_stock']) ?></td>
                                            <td class="text-end text-muted"><?= number_format((int) $item['reorder_point']) ?></td>
                                            <td class="text-end fw-semibold"><?= number_format((int) $item['suggested_qty']) ?></td>
                                            <td><span class="stock-pill <?= $isCritical ? 'stock-pill-critical' : 'stock-pill-warn' ?>"><?= $isCritical ? 'Critical' : 'Low' ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <?php require __DIR__ . '/../../components/footer.php'; ?>