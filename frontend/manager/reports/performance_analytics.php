<?php
/**
 * File: frontend/manager/reports/performance_analytics.php
 * Purpose: Trang "Performance Analytics" của Manager (mục Reports) - tổng
 * quan hiệu suất tồn kho/bán hàng trong 1 khoảng ngày. Dựng theo bố cục
 * tham khảo (Avg Inventory Value, Waste Rate, Stock-out Trends, Category
 * Strength, Top Overstock Risks...) nhưng CHỈ dùng số liệu có backing data
 * thật trong DB - xem ghi chú "KHÔNG bao gồm" ở docblock của
 * ManagerService::getPerformanceAnalytics().
 *
 * 3 card không có data thật trong bản tham khảo đã được thay:
 *   - "AI Demand Accuracy %"   -> Top Suppliers (tin cậy nhất, đã có data thật)
 *   - "Sales vs Target"       -> Doanh thu theo tháng (không có cột target)
 *   - "Forecast Variance"     -> Waste theo category
 *
 * Related: FR-MGR-09 (mở rộng ở mức tổng hợp toàn hệ thống thay vì per-product)
 * Calls: ManagerService::getPerformanceAnalytics()
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

// Mặc định: 30 ngày gần nhất (giống product_pfm.php), cho phép đổi qua GET.
$fromDate = $_GET['from_date'] ?? date('Y-m-d', strtotime('-30 days'));
$toDate   = $_GET['to_date'] ?? date('Y-m-d');

$analytics = $managerService->getPerformanceAnalytics($fromDate, $toDate . ' 23:59:59');

$activeMenu  = 'performance_analytics';
$pageTitle   = 'Performance Analytics';
$breadcrumbs = ['Manager', 'Reports', 'Performance Analytics'];

// ---- Chuẩn bị dữ liệu vẽ SVG (PHP-side, không cần JS) ----

// Stock-out trend: điền đủ ngày liên tục trong khoảng đã chọn (kể cả ngày = 0 sự cố).
$stockoutByDate = [];
foreach ($analytics['stockout_trend'] as $row) {
    $stockoutByDate[$row['incident_date']] = (int) $row['incident_count'];
}
$daySpan = max(1, (int) ((strtotime($toDate) - strtotime($fromDate)) / 86400));
$daySpan = min($daySpan, 60); // an toàn: không vẽ quá 60 điểm dù khoảng ngày dài hơn
$stockoutSeries = [];
for ($i = $daySpan; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime($toDate . " -{$i} day"));
    $stockoutSeries[] = ['date' => $date, 'count' => $stockoutByDate[$date] ?? 0];
}
$stockoutCounts  = array_column($stockoutSeries, 'count');
$stockoutMax     = max($stockoutCounts) ?: 1;

// Category Strength radar: tối đa 6 trục cho dễ đọc.
$radarCategories = array_slice($analytics['category_strength'], 0, 6);
$radarMax = 0.0;
foreach ($radarCategories as $c) {
    $radarMax = max($radarMax, (float) $c['turnover_value_ratio']);
}
$radarMax = $radarMax > 0 ? $radarMax : 1.0;

$pageSubtitle = 'From ' . date('d/m/Y', strtotime($fromDate)) . ' to ' . date('d/m/Y', strtotime($toDate));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Performance Analytics - InventoryDSS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/theme_variables.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/custom.css" rel="stylesheet">
    <style>
        .pa-bar-row { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
        .pa-bar-row:last-child { margin-bottom: 0; }
        .pa-bar-label { width: 130px; flex-shrink: 0; font-size: 13px; color: var(--text-muted); text-align: right; }
        .pa-bar-track { flex: 1; height: 14px; background: #eef1f5; border-radius: 7px; overflow: hidden; }
        .pa-bar-fill { height: 100%; background: var(--brand-primary); border-radius: 7px; }
        .pa-bar-value { width: 90px; flex-shrink: 0; font-size: 13px; font-weight: 600; color: var(--text-primary); }

        .pa-note { font-size: 12.5px; color: var(--text-muted); background: #f7f8fa; border: 1px dashed #c1c7d0; border-radius: 10px; padding: 12px 14px; margin-bottom: 20px; }
    </style>
</head>
<body>
    <div class="app-shell">
        <?php require __DIR__ . '/../../components/sidebar.php'; ?>

        <div class="app-content">
            <?php require __DIR__ . '/../../components/header.php'; ?>

            <main class="app-main">

                <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
                    <div>
                        <h2 class="page-heading mb-1">Performance Analytics</h2>
                        <p class="page-subheading mb-0"><?= htmlspecialchars($pageSubtitle, ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="product_pfm.php" class="btn btn-outline-secondary btn-sm">Detailed Product Ranking &rarr;</a>
                        <a href="supplier_leadtime.php" class="btn btn-outline-secondary btn-sm">Supplier Lead-time &rarr;</a>
                    </div>
                </div>

                <!-- Filter khoảng ngày -->
                <div class="panel-card mb-3">
                    <form method="get" class="filter-bar p-1">
                        <div>
                            <label class="form-label">From date</label>
                            <input type="date" name="from_date" class="form-control form-control-sm" value="<?= htmlspecialchars($fromDate, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div>
                            <label class="form-label">To date</label>
                            <input type="date" name="to_date" class="form-control form-control-sm" value="<?= htmlspecialchars($toDate, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div>
                            <button type="submit" class="btn btn-brand btn-sm">Apply</button>
                        </div>
                    </form>
                </div>

                <div class="pa-note">
                    Revenue and waste values are calculated using actual retail / cost prices from the system.
                    "AI Demand Accuracy", "Sales vs Target", and "Forecast Variance" are not shown because
                    the system does not currently store daily forecast-vs-actual data or sales targets -
                    these are replaced by Top Suppliers and Waste by Category, both backed by real data.
                </div>

                <!-- KPI cards hàng đầu -->
                <div class="row g-3 mb-4">
                    <div class="col-6 col-xl-3">
                        <div class="kpi-card">
                            <span class="kpi-label">Inventory Value</span>
                            <span class="kpi-value">&#8363;<?= number_format($analytics['avg_inventory_value'], 0) ?></span>
                        </div>
                    </div>
                    <div class="col-6 col-xl-3">
                        <div class="kpi-card <?= ($analytics['waste']['waste_rate_percent'] ?? 0) > 2 ? 'kpi-card-warn' : '' ?>">
                            <span class="kpi-label">Waste Rate</span>
                            <span class="kpi-value">
                                <?= $analytics['waste']['waste_rate_percent'] !== null ? number_format($analytics['waste']['waste_rate_percent'], 2) . '%' : 'N/A' ?>
                            </span>
                        </div>
                    </div>
                    <div class="col-6 col-xl-3">
                        <div class="kpi-card">
                            <span class="kpi-label">Revenue (This Period)</span>
                            <span class="kpi-value">&#8363;<?= number_format($analytics['revenue']['total_revenue'], 0) ?></span>
                        </div>
                    </div>
                    <div class="col-6 col-xl-3">
                        <div class="kpi-card">
                            <span class="kpi-label">Waste Value (This Period)</span>
                            <span class="kpi-value">&#8363;<?= number_format($analytics['waste']['waste_value'], 0) ?></span>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <!-- Stock-out Rate Trends -->
                    <div class="col-12 col-xl-8">
                        <div class="panel-card h-100">
                            <div class="panel-card-header">
                                <h3 class="panel-card-title">Shortage Incident Trend</h3>
                                <span class="panel-card-note">Number of incidents logged per day (shortage_incidents)</span>
                            </div>

                            <?php if (array_sum($stockoutCounts) === 0): ?>
                                <div class="empty-state">No shortage incidents in this time range.</div>
                            <?php else: ?>
                                <?php
                                    $chartW = 900; $chartH = 220;
                                    $padTop = 20; $padBottom = 12; $padLeft = 34; $padRight = 14;
                                    $plotW = $chartW - $padLeft - $padRight;
                                    $plotH = $chartH - $padTop - $padBottom;
                                    $axisMax = (int) max(2, ceil($stockoutMax / 2) * 2);
                                    $n = count($stockoutSeries);
                                    $stepX = $n > 1 ? $plotW / ($n - 1) : 0;
                                    $pts = [];
                                    foreach ($stockoutSeries as $i => $row) {
                                        $x = round($padLeft + ($i * $stepX), 1);
                                        $y = round($padTop + $plotH - (($row['count'] / $axisMax) * $plotH), 1);
                                        $pts[] = ['x' => $x, 'y' => $y];
                                    }
                                    $lineStr = implode(' ', array_map(fn($p) => $p['x'] . ',' . $p['y'], $pts));
                                    $areaStr = $padLeft . ',' . ($padTop + $plotH) . ' ' . $lineStr . ' ' . ($padLeft + $plotW) . ',' . ($padTop + $plotH);
                                    $labelStride = (int) max(1, ceil($n / 8));
                                ?>
                                <div class="activity-chart-wrap">
                                    <svg class="activity-chart-svg" viewBox="0 0 <?= $chartW ?> <?= $chartH ?>" preserveAspectRatio="xMidYMid meet">
                                        <polygon points="<?= htmlspecialchars($areaStr, ENT_QUOTES, 'UTF-8') ?>" fill="var(--brand-primary)" opacity="0.12"></polygon>
                                        <polyline points="<?= htmlspecialchars($lineStr, ENT_QUOTES, 'UTF-8') ?>" fill="none" stroke="var(--brand-primary)" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke"></polyline>
                                        <?php foreach ($pts as $p): ?>
                                            <circle cx="<?= $p['x'] ?>" cy="<?= $p['y'] ?>" r="3" fill="#fff" stroke="var(--brand-primary)" stroke-width="2"></circle>
                                        <?php endforeach; ?>
                                    </svg>
                                    <div class="activity-chart-labels">
                                        <?php foreach ($stockoutSeries as $i => $row): ?>
                                            <span><?= $i % $labelStride === 0 ? date('d/m', strtotime($row['date'])) : '' ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Top Suppliers (thay AI Demand Accuracy) -->
                    <div class="col-12 col-xl-4">
                        <div class="panel-card h-100">
                            <div class="panel-card-header">
                                <h3 class="panel-card-title">Most Reliable Suppliers</h3>
                            </div>
                            <?php if (empty($analytics['top_suppliers'])): ?>
                                <div class="empty-state">No "Delivered" orders yet to evaluate.</div>
                            <?php else: ?>
                                <?php foreach ($analytics['top_suppliers'] as $s): ?>
                                    <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">
                                        <div>
                                            <div class="fw-semibold small"><?= htmlspecialchars($s['supplier_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                            <div class="text-muted" style="font-size: 12px;">Avg lead-time: <?= number_format((float) $s['avg_lead_time_days'], 1) ?> days</div>
                                        </div>
                                        <span class="stock-pill <?= (float) $s['discrepancy_rate_percent'] > 10 ? 'stock-pill-warn' : '' ?>">
                                            <?= number_format((float) $s['discrepancy_rate_percent'], 1) ?>% discrepancy
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <!-- Category Strength (radar) -->
                    <div class="col-12 col-xl-4">
                        <div class="panel-card h-100">
                            <div class="panel-card-header">
                                <h3 class="panel-card-title">Category Strength</h3>
                                <span class="panel-card-note">Inventory turnover by value (COGS/Stock value)</span>
                            </div>

                            <?php if (empty($radarCategories)): ?>
                                <div class="empty-state">No data.</div>
                            <?php else: ?>
                                <?php
                                    $cx = 150; $cy = 145; $r = 105;
                                    $count = count($radarCategories);
                                    $angleStep = 2 * M_PI / $count;
                                    $axisPts = [];
                                    $valuePts = [];
                                    foreach ($radarCategories as $i => $cat) {
                                        $angle = -M_PI / 2 + $i * $angleStep;
                                        $axisPts[] = ['x' => $cx + $r * cos($angle), 'y' => $cy + $r * sin($angle), 'label' => $cat['category_name']];
                                        $ratio = min(1, ((float) $cat['turnover_value_ratio']) / $radarMax);
                                        $valuePts[] = ['x' => $cx + $r * $ratio * cos($angle), 'y' => $cy + $r * $ratio * sin($angle)];
                                    }
                                    $valuePolygon = implode(' ', array_map(fn($p) => round($p['x'],1) . ',' . round($p['y'],1), $valuePts));
                                ?>
                                <svg viewBox="0 0 300 300" style="width:100%; max-width: 320px; display:block; margin: 0 auto;">
                                    <?php foreach ([0.33, 0.66, 1.0] as $ring): ?>
                                        <?php
                                            $ringPts = [];
                                            for ($i = 0; $i < $count; $i++) {
                                                $angle = -M_PI / 2 + $i * $angleStep;
                                                $ringPts[] = round($cx + $r * $ring * cos($angle), 1) . ',' . round($cy + $r * $ring * sin($angle), 1);
                                            }
                                        ?>
                                        <polygon points="<?= implode(' ', $ringPts) ?>" fill="none" stroke="var(--surface-border)" stroke-width="1"></polygon>
                                    <?php endforeach; ?>

                                    <polygon points="<?= htmlspecialchars($valuePolygon, ENT_QUOTES, 'UTF-8') ?>" fill="var(--brand-primary)" fill-opacity="0.25" stroke="var(--brand-primary)" stroke-width="2"></polygon>

                                    <?php foreach ($axisPts as $i => $p): ?>
                                        <text x="<?= round($p['x'], 1) ?>" y="<?= round($p['y'], 1) ?>" text-anchor="middle" font-size="11" fill="var(--text-muted)"><?= htmlspecialchars($p['label'], ENT_QUOTES, 'UTF-8') ?></text>
                                    <?php endforeach; ?>
                                </svg>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Doanh thu theo tháng (thay Sales vs Target) -->
                    <div class="col-12 col-xl-4">
                        <div class="panel-card h-100">
                            <div class="panel-card-header">
                                <h3 class="panel-card-title">Revenue by Month</h3>
                            </div>
                            <?php if (empty($analytics['revenue']['by_month'])): ?>
                                <div class="empty-state">No sales data.</div>
                            <?php else: ?>
                                <?php
                                    $maxRevenue = max(array_column($analytics['revenue']['by_month'], 'revenue')) ?: 1;
                                ?>
                                <?php foreach ($analytics['revenue']['by_month'] as $row): ?>
                                    <div class="pa-bar-row">
                                        <span class="pa-bar-label"><?= htmlspecialchars($row['month'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <div class="pa-bar-track">
                                            <div class="pa-bar-fill" style="width: <?= round(((float)$row['revenue'] / $maxRevenue) * 100, 1) ?>%;"></div>
                                        </div>
                                        <span class="pa-bar-value">&#8363;<?= number_format((float) $row['revenue'], 0) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Waste theo category (thay Forecast Variance) -->
                    <div class="col-12 col-xl-4">
                        <div class="panel-card h-100">
                            <div class="panel-card-header">
                                <h3 class="panel-card-title">Waste by Category</h3>
                            </div>
                            <?php if (empty($analytics['waste']['by_category'])): ?>
                                <div class="empty-state">No waste recorded in this time range.</div>
                            <?php else: ?>
                                <?php
                                    $maxWaste = max(array_column($analytics['waste']['by_category'], 'waste_value')) ?: 1;
                                ?>
                                <?php foreach ($analytics['waste']['by_category'] as $row): ?>
                                    <div class="pa-bar-row">
                                        <span class="pa-bar-label"><?= htmlspecialchars($row['category_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <div class="pa-bar-track">
                                            <div class="pa-bar-fill" style="width: <?= round(((float)$row['waste_value'] / $maxWaste) * 100, 1) ?>%; background: var(--color-danger, #de350b);"></div>
                                        </div>
                                        <span class="pa-bar-value">&#8363;<?= number_format((float) $row['waste_value'], 0) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Top Overstock Risks -->
                <div class="panel-card">
                    <div class="panel-card-header">
                        <h3 class="panel-card-title">Top Overstock Risks</h3>
                        <span class="panel-card-note">High stock but slow sales in the selected period</span>
                    </div>

                    <?php if (empty($analytics['top_overstock_risks'])): ?>
                        <div class="empty-state">No data.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table data-table align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th>Category</th>
                                        <th class="text-end">Current Stock</th>
                                        <th class="text-end">Qty Sold (This Period)</th>
                                        <th class="text-end">Difference</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($analytics['top_overstock_risks'] as $row): ?>
                                        <?php $diff = (int) $row['current_stock'] - (int) $row['quantity_sold']; ?>
                                        <tr>
                                            <td>
                                                <span class="fw-semibold"><?= htmlspecialchars($row['product_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                                <div class="text-muted small"><?= htmlspecialchars($row['sku_code'], ENT_QUOTES, 'UTF-8') ?></div>
                                            </td>
                                            <td class="text-muted"><?= htmlspecialchars($row['category_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td class="text-end"><?= number_format((int) $row['current_stock']) ?></td>
                                            <td class="text-end text-muted"><?= number_format((int) $row['quantity_sold']) ?></td>
                                            <td class="text-end">
                                                <span class="stock-pill <?= $diff > 50 ? 'stock-pill-warn' : '' ?>"><?= number_format($diff) ?></span>
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