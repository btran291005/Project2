<?php
/**
 * File: frontend/manager/reorder/stockout_risk.php
 * Purpose: "Top 10 Stock-out Risk" - danh sách sản phẩm có nguy cơ hết hàng
 * cao nhất, xếp theo số giờ tồn kho còn lại (current_stock / avg_daily_sales).
 * Related: FR-MGR-12
 * Calls: ManagerService::getStockoutRisk()
 *
 * FR-MGR-12 acceptance criteria: "Updated within ≤ 60s of any stock change,
 * predicting items likely to hit zero within the next 24h based on recent
 * sales velocity" - risk_hours ≤ 24 là mốc "nguy cấp trong hôm nay", dùng để
 * tô màu badge đỏ khác với các dòng còn lại trong Top 10.
 *
 * Style/layout đồng bộ frontend/admin/*.php - chỉ dùng class có sẵn trong
 * custom.css/theme_variables.css, không viết <style> riêng trong file.
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
$risks = $managerService->getStockoutRisk();

/** Mốc "nguy cấp trong 24h" theo đúng acceptance criteria FR-MGR-12. */
const URGENT_RISK_HOURS_THRESHOLD = 24;

/** Định dạng risk_hours thành chuỗi dễ đọc (VD: "18.5h" hoặc "3.2 ngày" nếu quá dài). */
function formatRiskHours(float $hours): string
{
    if ($hours < 48) {
        return number_format($hours, 1) . ' hours';
    }
    return number_format($hours / 24, 1) . ' days';
}

$pageTitle   = 'Stock-out Risk';
$breadcrumbs = ['Manager', 'Reorder', 'Stock-out Risk'];
$activeMenu  = 'reorder';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock-out Risk - InventoryDSS</title>
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
                        <h2 class="page-heading mb-1">Top 10 Stock-out Risk</h2>
                        <p class="page-subheading mb-0">Products with the highest stockout risk, ranked by recent 7-day sales velocity (FR-MGR-12).</p>
                    </div>
                    <a href="reorder_suggestions.php" class="btn btn-brand btn-sm">View Reorder Suggestions &rarr;</a>
                </div>

                <div class="panel-card">
                    <div class="panel-card-header">
                        <h3 class="panel-card-title">Risk List</h3>
                        <span class="panel-card-note"><?= count($risks) ?>/10 products with sales velocity &gt; 0</span>
                    </div>

                    <?php if (empty($risks)): ?>
                        <div class="empty-state">No products are currently at risk of stockout (or there isn't enough recent 7-day sales data yet).</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table data-table align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Product</th>
                                        <th class="text-end">Current Stock</th>
                                        <th class="text-end">Avg Sold/Day (7d)</th>
                                        <th class="text-end">Time Remaining</th>
                                        <th class="text-end">Level</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($risks as $i => $risk): ?>
                                        <?php $isUrgent = (float) $risk['risk_hours'] <= URGENT_RISK_HOURS_THRESHOLD; ?>
                                        <tr>
                                            <td class="text-muted"><?= $i + 1 ?></td>
                                            <td>
                                                <span class="fw-semibold"><?= htmlspecialchars($risk['product_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                                <div class="text-muted small"><?= htmlspecialchars($risk['sku_code'], ENT_QUOTES, 'UTF-8') ?></div>
                                            </td>
                                            <td class="text-end"><?= number_format((int) $risk['current_stock']) ?></td>
                                            <td class="text-end"><?= number_format((float) $risk['avg_daily_sales_7d'], 2) ?></td>
                                            <td class="text-end fw-semibold"><?= htmlspecialchars(formatRiskHours((float) $risk['risk_hours']), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td class="text-end">
                                                <?php if ($isUrgent): ?>
                                                    <span class="stock-pill stock-pill-critical">Urgent (&le;24h)</span>
                                                <?php else: ?>
                                                    <span class="stock-pill stock-pill-warn">Needs Monitoring</span>
                                                <?php endif; ?>
                                            </td>
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