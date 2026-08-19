<?php
/**
 * File: frontend/manager/inventory/forecast.php
 * Purpose: GỘP 2 trang cũ (forecast.php + demand_trend.php) làm 1, vì cùng
 * chủ đề "nhu cầu của 1 sản phẩm đang chọn" chỉ khác chiều thời gian:
 *   - Phần 1 (Demand Trend, FR-MGR-08): xu hướng bán THỰC TẾ 7/30 ngày qua,
 *     render ngay bằng SVG PHP-side khi load trang (không cần JS/API).
 *   - Phần 2 (AI Forecast): dự báo 7 ngày TỚI cho cùng sản phẩm đang chọn,
 *     gọi backend/api/forecast_request.php bằng JS khi bấm nút (có thể chậm/
 *     gọi ngoài nên giữ tách biệt khỏi phần render tức thời ở Phần 1).
 * Dùng CHUNG 1 dropdown chọn sản phẩm cho cả 2 phần để khỏi chọn 2 lần.
 * demand_trend.php cũ đã bị XÓA - toàn bộ nội dung nằm ở đây (Phần 1).
 *
 * Related: FR-MGR-02, FR-MGR-08, BR-05
 * Calls: ManagerService::getForecastProducts(), ManagerService::getDemandTrend(),
 *        backend/api/forecast_request.php (IntegrationService::getForecastForProduct())
 */

declare(strict_types=1);

require_once __DIR__ . '/../../backend/config/app_config.php';
require_once __DIR__ . '/../../backend/config/database.php';
require_once __DIR__ . '/../../backend/core/Logger.php';
require_once __DIR__ . '/../../backend/core/Auth.php';
require_once __DIR__ . '/../../backend/core/Middleware.php';
require_once __DIR__ . '/../../backend/services/ManagerService.php';

Middleware::guard([ROLE_MANAGER]);

if (empty($_SESSION['forecast_csrf_token'])) {
    $_SESSION['forecast_csrf_token'] = bin2hex(random_bytes(32));
}

$managerService = new ManagerService();

try {
    $products = $managerService->getForecastProducts();
} catch (Exception $e) {
    error_log('[forecast.php] Error loading products: ' . $e->getMessage());
    $products = [];
}

// Sản phẩm đang chọn: lấy từ query string (dùng chung cho cả Demand Trend
// và ô mặc định của AI Forecast dropdown), mặc định sản phẩm đầu tiên.
$selectedProductId = isset($_GET['product_id']) && $_GET['product_id'] !== ''
    ? (int) $_GET['product_id']
    : (int) ($products[0]['product_id'] ?? 0);

// Khoảng ngày cho Demand Trend: chỉ chấp nhận 7 hoặc 30 (Sales::getSalesHistory()).
$selectedDays = isset($_GET['days']) && (int) $_GET['days'] === SALES_HISTORY_LONG_RANGE_DAYS
    ? SALES_HISTORY_LONG_RANGE_DAYS
    : SALES_HISTORY_SHORT_RANGE_DAYS;

$selectedProduct = null;
foreach ($products as $p) {
    if ((int) $p['product_id'] === $selectedProductId) {
        $selectedProduct = $p;
        break;
    }
}

$rawHistory = $selectedProductId > 0 ? $managerService->getDemandTrend($selectedProductId, $selectedDays) : [];

// Điền đủ $selectedDays ngày liên tục (kể cả ngày không có giao dịch = 0).
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

$activeMenu = 'forecast';
$pageTitle = 'Demand Trend & Forecast';
$breadcrumbs = ['Manager', 'Demand Trend & Forecast'];
$forecastEndpoint = str_replace('/frontend', '', BASE_URL) . '/backend/api/forecast_request.php';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Demand Trend & Forecast - InventoryDSS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/theme_variables.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/custom.css">
    <style>
        .forecast-panel { background: #fff; border: 1px solid #e7ecf2; border-radius: 14px; box-shadow: 0 3px 10px rgba(9,30,66,.05); padding: 20px; display: grid; grid-template-columns: minmax(280px, 1fr) auto; gap: 16px; align-items: end; margin-bottom: 20px; }

        .forecast-field { display: flex; flex-direction: column; gap: 7px; }
        .forecast-field label { font-size: 13px; font-weight: 700; color: #344563; }
        .forecast-field select { border: 1px solid #c1c7d0; border-radius: 8px; padding: 11px 12px; color: #172b4d; background: #fff; font-size: 14px; font-family: inherit; cursor: pointer; transition: border-color 0.2s; }
        .forecast-field select:hover { border-color: #0052cc; }
        .forecast-field select:focus { outline: none; border-color: #0052cc; box-shadow: 0 0 0 3px rgba(0,82,204,0.1); }

        .forecast-button { border: 0; border-radius: 8px; padding: 11px 24px; font-weight: 700; color: #fff; background: #0052cc; cursor: pointer; min-height: 42px; font-size: 14px; transition: all 0.2s; white-space: nowrap; }
        .forecast-button:hover:not(:disabled) { background: #0747a6; }
        .forecast-button:active:not(:disabled) { transform: translateY(1px); }
        .forecast-button:disabled { opacity: 0.65; cursor: not-allowed; }

        .forecast-status { display: none; margin-bottom: 18px; padding: 12px 14px; border-radius: 9px; font-size: 14px; font-weight: 500; }
        .forecast-status.visible { display: block; animation: slideIn 0.3s ease-out; }
        .forecast-status.ok { color: #155724; background: #e3fcef; border: 1px solid #c6e9d8; }
        .forecast-status.fallback { color: #7a4b00; background: #fff7d6; border: 1px solid #ffe484; }
        .forecast-status.error { color: #ae2e24; background: #ffebe6; border: 1px solid #ffc7bf; }

        @keyframes slideIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }

        .forecast-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 16px; margin-bottom: 20px; }
        .forecast-card { background: #fff; border: 1px solid #e7ecf2; border-radius: 14px; box-shadow: 0 3px 10px rgba(9,30,66,.05); padding: 20px; min-height: 140px; display: flex; flex-direction: column; justify-content: space-between; }
        .forecast-card h3 { margin: 0 0 12px; font-size: 13px; font-weight: 700; color: #5e6c84; text-transform: uppercase; letter-spacing: 0.5px; }
        .forecast-number { color: #172b4d; font-size: 32px; line-height: 1.1; font-weight: 800; }
        .forecast-meta { font-size: 12px; color: #5e6c84; margin-top: 10px; line-height: 1.5; }

        .forecast-card.api { border-top: 4px solid #0052cc; }
        .forecast-card.api h3 { color: #0052cc; }
        .forecast-card.rule { border-top: 4px solid #00875a; }
        .forecast-card.rule h3 { color: #00875a; }
        .forecast-card.stock { border-top: 4px solid #6554c0; }
        .forecast-card.stock h3 { color: #6554c0; }

        .forecast-chart-card { background: #fff; border: 1px solid #e7ecf2; border-radius: 14px; box-shadow: 0 3px 10px rgba(9,30,66,.05); padding: 20px; margin-bottom: 20px; }
        .forecast-chart-card h3 { margin: 0 0 6px; color: #172b4d; font-size: 16px; font-weight: 700; }
        .forecast-chart-card p { margin: 0 0 14px; font-size: 13px; color: #5e6c84; }

        #forecastChart { display: block; width: 100%; height: 280px; overflow: visible; }
        .axis-line { stroke: #dfe1e6; stroke-width: 1; }
        .forecast-line { fill: none; stroke: #0052cc; stroke-width: 3; stroke-linejoin: round; stroke-linecap: round; }
        .forecast-area { fill: #deebff; opacity: 0.7; }
        .chart-label { fill: #6b778c; font-size: 11px; }

        .forecast-table-wrap { background: #fff; border: 1px solid #e7ecf2; border-radius: 14px; box-shadow: 0 3px 10px rgba(9,30,66,.05); overflow: auto; }
        .forecast-table { width: 100%; border-collapse: collapse; min-width: 640px; }
        .forecast-table th { padding: 14px 16px; text-align: left; border-bottom: 2px solid #ebecf0; color: #5e6c84; background: #f7f8fa; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; }
        .forecast-table td { padding: 12px 16px; text-align: left; border-bottom: 1px solid #ebecf0; color: #172b4d; font-size: 14px; }
        .forecast-table tbody tr:hover { background: #f7f8fa; }
        .forecast-table tbody tr:last-child td { border-bottom: none; }

        .section-divider { border: 0; border-top: 1px solid var(--surface-border); margin: 32px 0 24px; }
        .section-heading { font-size: 18px; font-weight: 700; color: #172b4d; margin: 0 0 4px; }
        .section-subheading { font-size: 13px; color: #5e6c84; margin: 0 0 16px; }

        @media (max-width: 900px) {
            .forecast-panel { grid-template-columns: 1fr; }
            .forecast-button { width: 100%; }
            .forecast-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="app-shell">
    <?php require __DIR__ . '/../components/sidebar.php'; ?>

    <div class="app-content">
        <?php require __DIR__ . '/../components/header.php'; ?>

        <main class="app-main">

            <div class="mb-4">
                <h2 class="page-heading mb-1">Demand Trend &amp; Forecast</h2>
                <p class="page-subheading mb-0">View the actual 7/30-day sales trend and generate a 7-day AI forecast, both for the same selected product.</p>
            </div>

            <!-- ================= CHỌN SẢN PHẨM + KHOẢNG NGÀY (Demand Trend) ================= -->
            <div class="panel-card mb-3">
                <form method="get" class="filter-bar p-1">
                    <div style="min-width: 260px;">
                        <label class="form-label">Product</label>
                        <select name="product_id" id="forecastProduct" class="form-select form-select-sm" onchange="this.form.submit()">
                            <?php if (empty($products)): ?>
                                <option value="">No products available</option>
                            <?php endif; ?>
                            <?php foreach ($products as $p): ?>
                                <option value="<?= (int) $p['product_id'] ?>"
                                        data-stock="<?= (int) $p['current_stock'] ?>"
                                        <?= $selectedProductId === (int) $p['product_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($p['sku_code'] . ' - ' . $p['product_name'], ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Time Range (trend)</label>
                        <div class="btn-group" role="group">
                            <a href="?product_id=<?= $selectedProductId ?>&days=7"
                               class="btn btn-sm <?= $selectedDays === 7 ? 'btn-brand' : 'btn-outline-secondary' ?>">7 days</a>
                            <a href="?product_id=<?= $selectedProductId ?>&days=30"
                               class="btn btn-sm <?= $selectedDays === 30 ? 'btn-brand' : 'btn-outline-secondary' ?>">30 days</a>
                        </div>
                    </div>
                </form>
            </div>

            <?php if ($selectedProduct === null): ?>
                <div class="panel-card">
                    <div class="empty-state">No products available to view demand trend.</div>
                </div>
            <?php else: ?>

                <!-- ================= PHẦN 1: DEMAND TREND (lịch sử thực tế) ================= -->
                <h3 class="section-heading">📊 Actual Sales Trend</h3>
                <p class="section-subheading">Actual sales data recorded from the POS system (FR-MGR-08).</p>

                <div class="row g-3 mb-4">
                    <div class="col-6 col-xl-4">
                        <div class="kpi-card">
                            <span class="kpi-label">Total Sold (<?= $selectedDays ?> days)</span>
                            <span class="kpi-value"><?= number_format($totalSold) ?></span>
                        </div>
                    </div>
                    <div class="col-6 col-xl-4">
                        <div class="kpi-card">
                            <span class="kpi-label">Average / Day</span>
                            <span class="kpi-value"><?= number_format($avgPerDay, 1) ?></span>
                        </div>
                    </div>
                    <div class="col-6 col-xl-4">
                        <div class="kpi-card">
                            <span class="kpi-label">Peak Sales Day</span>
                            <span class="kpi-value"><?= $peakDay ? number_format($peakDay['count']) : '—' ?></span>
                        </div>
                    </div>
                </div>

                <div class="panel-card mb-4">
                    <div class="panel-card-header">
                        <h3 class="panel-card-title"><?= htmlspecialchars($selectedProduct['product_name'], ENT_QUOTES, 'UTF-8') ?></h3>
                        <span class="panel-card-note"><?= htmlspecialchars($selectedProduct['sku_code'], ENT_QUOTES, 'UTF-8') ?> &middot; last <?= $selectedDays ?> days</span>
                    </div>

                    <?php if ($totalSold === 0): ?>
                        <div class="empty-state">No sales transactions in this time range.</div>
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
                    <?php endif; ?>
                </div>

                <hr class="section-divider">

                <!-- ================= PHẦN 2: AI FORECAST (dự báo 7 ngày tới) ================= -->
                <h3 class="section-heading">🔮 AI Forecast for the Next 7 Days</h3>
                <p class="section-subheading">Calls the AI Forecast API for the product selected above. If the API is unavailable, the system automatically falls back to Reorder Point rules.</p>

                <div class="forecast-panel">
                    <div class="forecast-field">
                        <label>Selected Product</label>
                        <div style="padding: 11px 12px; border: 1px solid #dfe1e6; border-radius: 8px; background: #f7f8fa; font-size: 14px; color: #172b4d;">
                            <?= htmlspecialchars($selectedProduct['sku_code'] . ' — ' . $selectedProduct['product_name'], ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    </div>
                    <button type="button" class="forecast-button" id="runForecast">Generate 7-Day Forecast</button>
                </div>

                <div id="forecastStatus" class="forecast-status" role="status"></div>

                <div id="emptyForecast" class="empty-state">
                    Click <strong>"Generate 7-Day Forecast"</strong> to see the demand forecast for the selected product.
                </div>

                <div id="forecastResult" hidden>
                    <div class="forecast-grid">
                        <article class="forecast-card api">
                            <h3>📊 AI Forecast Suggestion</h3>
                            <div class="forecast-number" id="apiSuggestion">—</div>
                            <div class="forecast-meta" id="apiMeta">Awaiting forecast</div>
                        </article>

                        <article class="forecast-card rule">
                            <h3>📋 Reorder Point Suggestion</h3>
                            <div class="forecast-number" id="ruleSuggestion">—</div>
                            <div class="forecast-meta" id="ruleMeta">Data ready</div>
                        </article>

                        <article class="forecast-card stock">
                            <h3>📦 Current Stock</h3>
                            <div class="forecast-number" id="stockValue">—</div>
                            <div class="forecast-meta" id="stockMeta">Stock as of today</div>
                        </article>
                    </div>

                    <article class="forecast-card forecast-chart-card" id="forecastChartCard">
                        <h3>📈 Daily Demand Forecast (Next 7 Days)</h3>
                        <p id="chartSubtitle">The upper/lower band shows the expected variation range based on historical data</p>
                        <svg id="forecastChart" viewBox="0 0 760 280" aria-label="7-day demand forecast chart"></svg>
                    </article>

                    <div class="forecast-table-wrap">
                        <table class="forecast-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Forecasted Demand</th>
                                    <th>Lower Bound</th>
                                    <th>Upper Bound</th>
                                </tr>
                            </thead>
                            <tbody id="forecastTable"></tbody>
                        </table>
                    </div>
                </div>

            <?php endif; ?>

        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const endpoint = <?= json_encode($forecastEndpoint, JSON_UNESCAPED_SLASHES) ?>;
const csrfToken = <?= json_encode($_SESSION['forecast_csrf_token']) ?>;
const button = document.getElementById('runForecast');
const product = document.getElementById('forecastProduct');
const statusBox = document.getElementById('forecastStatus');
const resultBox = document.getElementById('forecastResult');
const emptyBox = document.getElementById('emptyForecast');

const format = value => new Intl.NumberFormat('vi-VN', {maximumFractionDigits: 1}).format(value);

function setStatus(message, type) {
    statusBox.textContent = message;
    statusBox.className = 'forecast-status visible ' + type;
}

function setText(id, value) {
    document.getElementById(id).textContent = value;
}

function renderChart(points) {
    const svg = document.getElementById('forecastChart');
    svg.replaceChildren();

    if (!points || points.length === 0) {
        document.getElementById('forecastChartCard').hidden = true;
        return;
    }

    document.getElementById('forecastChartCard').hidden = false;

    const width = 760, height = 260, left = 45, right = 18, top = 18, bottom = 34;
    const max = Math.max(1, ...points.map(p => Number(p.upper_bound) || 0));
    const xPos = i => left + i * (width - left - right) / Math.max(points.length - 1, 1);
    const yPos = v => top + (max - v) * (height - top - bottom) / max;
    const ns = 'http://www.w3.org/2000/svg';

    const makeElement = (tag, attrs) => {
        const el = document.createElementNS(ns, tag);
        Object.entries(attrs).forEach(([k, v]) => el.setAttribute(k, v));
        return el;
    };

    svg.append(makeElement('line', {x1: left, y1: height - bottom, x2: width - right, y2: height - bottom, class: 'axis-line'}));
    svg.append(makeElement('line', {x1: left, y1: top, x2: left, y2: height - bottom, class: 'axis-line'}));

    const upperPath = points.map((p, i) => `${xPos(i)},${yPos(Number(p.upper_bound) || 0)}`).join(' ');
    const lowerPath = points.slice().reverse().map((p, i) => `${xPos(points.length - 1 - i)},${yPos(Number(p.lower_bound) || 0)}`).join(' ');
    svg.append(makeElement('polygon', {points: upperPath + ' ' + lowerPath, class: 'forecast-area'}));

    const forecastPath = points.map((p, i) => `${xPos(i)},${yPos(Number(p.predicted_quantity) || 0)}`).join(' ');
    svg.append(makeElement('polyline', {points: forecastPath, class: 'forecast-line'}));

    [0, Math.ceil(max / 2), max].forEach(v => {
        const label = makeElement('text', {x: '4', y: String(yPos(v) + 4), class: 'chart-label'});
        label.textContent = format(v);
        svg.append(label);
    });

    points.forEach((p, i) => {
        const label = makeElement('text', {x: String(xPos(i)), y: String(height - 10), 'text-anchor': 'middle', class: 'chart-label'});
        label.textContent = p.forecast_date.slice(5).replace('-', '/');
        svg.append(label);
    });
}

function renderResults(data) {
    const rule = data.rule_based_suggestion || {};
    const isApi = data.source === 'ai_forecast';
    const points = data.forecast || [];

    setText('apiSuggestion', isApi ? format(data.suggested_qty) + ' units' : 'Unavailable');
    setText('apiMeta', isApi
        ? `Forecasted demand: ${format(data.forecasted_demand || 0)} · ${data.model_used || 'forecast_api'}`
        : 'Automatically switched to fallback rule'
    );

    setText('ruleSuggestion', format(rule.suggested_qty || data.suggested_qty || 0) + ' units');
    setText('ruleMeta', `Avg sales 7 days: ${format(rule.avg_daily_sales_7d || 0)} · Safety stock: ${format(rule.safety_stock || 0)}`);

    const currentStock = rule.current_stock ?? (product.selectedOptions[0]?.dataset.stock ?? 0);
    setText('stockValue', format(currentStock) + ' units');
    setText('stockMeta', `Reorder point: ${format(rule.reorder_point || 0)} · Max stock: ${format(rule.max_stock || 0)}`);

    const tbody = document.getElementById('forecastTable');
    tbody.replaceChildren();
    points.forEach(p => {
        const row = document.createElement('tr');
        const cells = [p.forecast_date, p.predicted_quantity, p.lower_bound, p.upper_bound];
        cells.forEach(v => {
            const cell = document.createElement('td');
            cell.textContent = typeof v === 'number' ? format(v) : v;
            row.append(cell);
        });
        tbody.append(row);
    });

    renderChart(points);
    emptyBox.hidden = true;
    resultBox.hidden = false;

    const statusType = isApi ? 'ok' : 'fallback';
    const statusMsg = data.message || (isApi ? 'Forecast generated.' : 'Used fallback rule.');
    setStatus(statusMsg, statusType);
}

button?.addEventListener('click', async () => {
    if (!product.value) {
        setStatus('Please select a product first.', 'error');
        return;
    }

    button.disabled = true;
    button.textContent = 'Generating forecast…';
    setStatus('Fetching data and computing forecast…', 'ok');

    try {
        const payload = {
            product_id: Number(product.value),
            csrf_token: csrfToken
        };

        const response = await fetch(endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify(payload)
        });

        const rawText = await response.text();
        let data;
        try {
            data = JSON.parse(rawText);
        } catch (parseError) {
            console.error('[Forecast Error] Non-JSON response:', rawText.slice(0, 500));
            throw new Error(
                `Server returned invalid data (HTTP ${response.status}). `
                + 'This may be due to a backend error (PHP warning/exception) or an incorrect API path. '
                + 'Please check the server log or contact the administrator.'
            );
        }

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${data.message || 'Connection error'}`);
        }

        if (!data.success) {
            throw new Error(data.message || 'Forecast failed.');
        }

        renderResults(data);
    } catch (error) {
        console.error('[Forecast Error]', error);
        resultBox.hidden = true;
        emptyBox.hidden = false;
        setStatus(error.message || 'Could not generate forecast. Please try again.', 'error');
    } finally {
        button.disabled = false;
        button.textContent = 'Generate 7-Day Forecast';
    }
});
</script>
<?php require __DIR__ . '/../components/footer.php'; ?>
