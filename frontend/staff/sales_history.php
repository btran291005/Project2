<?php
/**
 * File: frontend/staff/sales_history.php
 * Purpose: UI xem lịch sử bán hàng 7/30 ngày cho 1 sản phẩm được chọn -
 * biểu đồ + bảng số lượng bán theo từng ngày.
 *
 * Cùng kỹ thuật vẽ SVG line chart (PHP-side, không cần JS/API) đã dùng ở
 * frontend/manager/forecast.php (Phần 1: Demand Trend) -
 * NHƯNG bản Staff KHÔNG có phần AI Forecast (đó là công cụ của Manager,
 * ngoài phạm vi FR-STF-13 - Staff chỉ xem lịch sử, không dự báo/đặt hàng).
 *
 * Related: FR-STF-13
 * Calls: StaffService::getSalesHistory()
 */

declare(strict_types=1);

require_once __DIR__ . '/../../backend/config/app_config.php';
require_once __DIR__ . '/../../backend/config/database.php';
require_once __DIR__ . '/../../backend/core/Logger.php';
require_once __DIR__ . '/../../backend/core/Auth.php';
require_once __DIR__ . '/../../backend/core/Middleware.php';
require_once __DIR__ . '/../../backend/services/StaffService.php';
require_once __DIR__ . '/../../backend/models/Product.php';

Middleware::guard([ROLE_STAFF]);

$staffService = new StaffService();
$productModel = new Product();

$allProducts = $productModel->getAll(null, null, true);

$selectedProductId = isset($_GET['product_id']) && $_GET['product_id'] !== ''
    ? (int) $_GET['product_id']
    : (int) ($allProducts[0]['product_id'] ?? 0);

$selectedDays = isset($_GET['days']) && (int) $_GET['days'] === SALES_HISTORY_LONG_RANGE_DAYS
    ? SALES_HISTORY_LONG_RANGE_DAYS
    : SALES_HISTORY_SHORT_RANGE_DAYS;

$selectedProduct = null;
foreach ($allProducts as $p) {
    if ((int) $p['product_id'] === $selectedProductId) {
        $selectedProduct = $p;
        break;
    }
}

$rawHistory = $selectedProductId > 0 ? $staffService->getSalesHistory($selectedProductId, $selectedDays) : [];

// Điền đủ $selectedDays ngày liên tục (kể cả ngày không bán = 0) - cùng cách
// đã dùng ở forecast.php để biểu đồ không bị "gãy" ở những ngày không có dữ liệu.
$byDate = [];
foreach ($rawHistory as $row) {
    $byDate[$row['sale_date']] = (int) $row['total_quantity'];
}

$trend = [];
for ($i = $selectedDays - 1; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-{$i} day"));
    $trend[] = [
        'date'  => $date,
        'label' => $selectedDays === SALES_HISTORY_SHORT_RANGE_DAYS ? date('D', strtotime($date)) : date('d/m', strtotime($date)),
        'count' => $byDate[$date] ?? 0,
    ];
}

$totalSold = array_sum(array_column($trend, 'count'));
$avgPerDay = $selectedDays > 0 ? round($totalSold / $selectedDays, 1) : 0.0;
$peakDay   = !empty($trend) ? array_reduce($trend, fn($carry, $row) => ($carry === null || $row['count'] > $carry['count']) ? $row : $carry) : null;
$daysWithSales = count(array_filter($trend, fn($row) => $row['count'] > 0));

$activeMenu  = 'sales_hist';
$pageTitle   = 'Sales History';
$breadcrumbs = ['Staff', 'Sales History'];
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales History - InventoryDSS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/theme_variables.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/custom.css" rel="stylesheet">
</head>
<body>
    <div class="app-shell">
        <?php require __DIR__ . '/../components/sidebar.php'; ?>

        <div class="app-content">
            <?php require __DIR__ . '/../components/header.php'; ?>

            <main class="app-main">

                <div class="mb-4">
                    <h2 class="page-heading mb-1">Sales History</h2>
                    <p class="page-subheading mb-0">View 7/30-day sales history for each product (FR-STF-13).</p>
                </div>

                <!-- Chọn sản phẩm + khoảng ngày -->
                <div class="panel-card mb-3">
                    <form method="get" class="filter-bar p-1">
                        <div style="min-width: 260px;">
                            <label class="form-label">Product</label>
                            <select name="product_id" class="form-select form-select-sm" onchange="this.form.submit()">
                                <?php if (empty($allProducts)): ?>
                                    <option value="">No products available</option>
                                <?php endif; ?>
                                <?php foreach ($allProducts as $p): ?>
                                    <option value="<?= (int) $p['product_id'] ?>" <?= $selectedProductId === (int) $p['product_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($p['sku_code'] . ' - ' . $p['product_name'], ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="form-label">Time Range</label>
                            <div class="btn-group" role="group">
                                <a href="?product_id=<?= $selectedProductId ?>&days=7" class="btn btn-sm <?= $selectedDays === 7 ? 'btn-brand' : 'btn-outline-secondary' ?>">7 Days</a>
                                <a href="?product_id=<?= $selectedProductId ?>&days=30" class="btn btn-sm <?= $selectedDays === 30 ? 'btn-brand' : 'btn-outline-secondary' ?>">30 Days</a>
                            </div>
                        </div>
                    </form>
                </div>

                <?php if ($selectedProduct === null): ?>
                    <div class="panel-card">
                        <div class="empty-state">No product selected to view sales history.</div>
                    </div>
                <?php else: ?>

                    <!-- KPI cards -->
                    <div class="row g-3 mb-4">
                        <div class="col-6 col-xl-3">
                            <div class="kpi-card">
                                <span class="kpi-label">Total Sold (<?= $selectedDays ?> days)</span>
                                <span class="kpi-value"><?= number_format($totalSold) ?></span>
                            </div>
                        </div>
                        <div class="col-6 col-xl-3">
                            <div class="kpi-card">
                                <span class="kpi-label">Average / Day</span>
                                <span class="kpi-value"><?= number_format($avgPerDay, 1) ?></span>
                            </div>
                        </div>
                        <div class="col-6 col-xl-3">
                            <div class="kpi-card">
                                <span class="kpi-label">Peak Day</span>
                                <span class="kpi-value"><?= $peakDay ? number_format($peakDay['count']) : '—' ?></span>
                            </div>
                        </div>
                        <div class="col-6 col-xl-3">
                            <div class="kpi-card">
                                <span class="kpi-label">Days with Sales</span>
                                <span class="kpi-value"><?= $daysWithSales ?> / <?= $selectedDays ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="panel-card">
                        <div class="panel-card-header">
                            <h3 class="panel-card-title"><?= htmlspecialchars($selectedProduct['product_name'], ENT_QUOTES, 'UTF-8') ?></h3>
                            <span class="panel-card-note"><?= htmlspecialchars($selectedProduct['sku_code'], ENT_QUOTES, 'UTF-8') ?> &middot; <?= $selectedDays ?> days</span>
                        </div>

                        <?php if ($totalSold === 0): ?>
                            <div class="empty-state">No sales transactions in this time period.</div>
                        <?php else: ?>
                            <?php
                                $chartW = 900; $chartH = 240;
                                $padTop = 24; $padBottom = 12; $padLeft = 40; $padRight = 14;
                                $plotW = $chartW - $padLeft - $padRight;
                                $plotH = $chartH - $padTop - $padBottom;

                                $counts = array_column($trend, 'count');
                                $maxCount = max($counts) ?: 1;
                                $axisMax = (int) max(4, ceil($maxCount / 4) * 4);
                                $yTicks = [0, (int) round($axisMax * 0.25), (int) round($axisMax * 0.5), (int) round($axisMax * 0.75), $axisMax];

                                $n = count($trend);
                                $stepX = $n > 1 ? $plotW / ($n - 1) : 0;

                                $pts = [];
                                foreach ($trend as $i => $row) {
                                    $x = round($padLeft + ($i * $stepX), 1);
                                    $y = round($padTop + $plotH - (($row['count'] / $axisMax) * $plotH), 1);
                                    $pts[] = ['x' => $x, 'y' => $y, 'count' => $row['count'], 'label' => $row['label']];
                                }
                                $lineStr = implode(' ', array_map(fn($p) => $p['x'] . ',' . $p['y'], $pts));
                                $areaStr = $padLeft . ',' . ($padTop + $plotH) . ' ' . $lineStr . ' ' . ($padLeft + $plotW) . ',' . ($padTop + $plotH);
                                $labelStride = $selectedDays > 14 ? (int) ceil($n / 10) : 1;
                            ?>
                            <div class="activity-chart-wrap">
                                <svg class="activity-chart-svg" viewBox="0 0 <?= $chartW ?> <?= $chartH ?>" preserveAspectRatio="xMidYMid meet">
                                    <?php foreach ($yTicks as $tick): ?>
                                        <?php $tickY = round($padTop + $plotH - (($tick / $axisMax) * $plotH), 1); ?>
                                        <line x1="<?= $padLeft ?>" y1="<?= $tickY ?>" x2="<?= $padLeft + $plotW ?>" y2="<?= $tickY ?>" stroke="var(--surface-border)" stroke-width="1"></line>
                                        <text x="<?= $padLeft - 8 ?>" y="<?= $tickY + 3 ?>" text-anchor="end" class="activity-chart-axis-label"><?= $tick ?></text>
                                    <?php endforeach; ?>

                                    <polygon points="<?= htmlspecialchars($areaStr, ENT_QUOTES, 'UTF-8') ?>" fill="var(--brand-primary)" opacity="0.12"></polygon>
                                    <polyline points="<?= htmlspecialchars($lineStr, ENT_QUOTES, 'UTF-8') ?>" fill="none" stroke="var(--brand-primary)" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke"></polyline>

                                    <?php foreach ($pts as $p): ?>
                                        <circle cx="<?= $p['x'] ?>" cy="<?= $p['y'] ?>" r="3.5" fill="#fff" stroke="var(--brand-primary)" stroke-width="2"></circle>
                                        <?php if ($selectedDays === 7): ?>
                                            <text x="<?= $p['x'] ?>" y="<?= max(12, $p['y'] - 10) ?>" text-anchor="middle" class="activity-chart-point-label"><?= (int) $p['count'] ?></text>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </svg>
                                <div class="activity-chart-labels">
                                    <?php foreach ($trend as $i => $row): ?>
                                        <span><?= $i % $labelStride === 0 ? htmlspecialchars($row['label'], ENT_QUOTES, 'UTF-8') : '' ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div class="table-responsive mt-3">
                                <table class="table data-table align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th class="text-end">Quantity Sold</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (array_reverse($trend) as $row): ?>
                                            <tr>
                                                <td class="text-muted"><?= htmlspecialchars(date('d/m/Y (D)', strtotime($row['date'])), ENT_QUOTES, 'UTF-8') ?></td>
                                                <td class="text-end <?= $row['count'] > 0 ? 'fw-semibold' : 'text-muted' ?>"><?= number_format($row['count']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                <?php endif; ?>

            </main>
        </div>
    </div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        /* Realtime sync (New Sales Entry -> Sales History): lắng nghe sự kiện
           NEW_SALE từ BroadcastChannel (ưu tiên) hoặc localStorage + polling
           (fallback) rồi tự reload dữ liệu hiện tại - KHÔNG cần nhấn F5.
           Chỉ thêm listener, không sửa thiết kế/chức năng trang có sẵn. */
        (function () {
            const CHANNEL = 'gs25_inventory_sales';
            let debounce = null;

            function refresh() {
                if (debounce) return;
                debounce = setTimeout(function () {
                    debounce = null;
                    location.reload();
                }, 400);
            }

            try {
                if (window.BroadcastChannel) {
                    const bc = new BroadcastChannel(CHANNEL);
                    bc.onmessage = function (e) {
                        if (e.data && e.data.type === 'NEW_SALE') refresh();
                    };
                }
            } catch (e) {}

            // Fallback: sự kiện storage từ cùng origin
            try {
                window.addEventListener('storage', function (e) {
                    if (e.key === CHANNEL && e.newValue) {
                        try {
                            const d = JSON.parse(e.newValue);
                            if (d && d.type === 'NEW_SALE') refresh();
                        } catch (err) {}
                    }
                });
            } catch (e) {}

            // Fallback cuối: polling 5s (nếu BroadcastChannel & storage đều không dùng được)
            setInterval(refresh, 5000);
        })();
    </script>
    <?php require __DIR__ . '/../components/footer.php'; ?>
