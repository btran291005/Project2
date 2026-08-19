<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../backend/config/app_config.php';
require_once __DIR__ . '/../../../backend/config/database.php';
require_once __DIR__ . '/../../../backend/core/Logger.php';
require_once __DIR__ . '/../../../backend/core/Auth.php';
require_once __DIR__ . '/../../../backend/core/Middleware.php';
require_once __DIR__ . '/../../../backend/services/ManagerService.php';

Middleware::guard([ROLE_MANAGER]);

if (empty($_SESSION['forecast_csrf_token'])) {
    $_SESSION['forecast_csrf_token'] = bin2hex(random_bytes(32));
}

try {
    $managerService = new ManagerService();
    $products = $managerService->getForecastProducts();
} catch (Exception $e) {
    error_log('[forecast.php] Error loading products: ' . $e->getMessage());
    $products = [];
}

$activeMenu = 'forecast';
$pageTitle = 'Demand Forecast';
$breadcrumbs = ['Manager', 'Demand Forecast'];
$forecastEndpoint = str_replace('/frontend', '', BASE_URL) . '/backend/api/forecast_request.php';
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Demand Forecast - InventoryDSS</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/theme_variables.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/custom.css">
    <style>
        .forecast-page { padding: 24px; max-width: 1400px; margin: 0 auto; }
        .forecast-intro { display: flex; justify-content: space-between; gap: 20px; align-items: flex-start; margin-bottom: 24px; }
        .forecast-intro h2 { margin: 0 0 8px; color: #172b4d; font-size: 26px; font-weight: 700; }
        .forecast-intro p { margin: 0; color: #5e6c84; max-width: 700px; line-height: 1.6; font-size: 14px; }

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

        .empty-state { padding: 48px 20px; text-align: center; color: #6b778c; background: #fff; border: 1px dashed #c1c7d0; border-radius: 14px; font-size: 14px; }

        @media (max-width: 900px) {
            .forecast-page { padding: 16px; }
            .forecast-intro { display: block; }
            .forecast-panel { grid-template-columns: 1fr; }
            .forecast-button { width: 100%; }
            .forecast-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="app-shell">
    <?php require __DIR__ . '/../../components/sidebar.php'; ?>

    <div class="app-content">
        <?php require __DIR__ . '/../../components/header.php'; ?>

        <main class="app-main">
            <section class="forecast-page">
                <div class="forecast-intro">
                    <div>
                        <h2 class="page-heading mb-1">Dự báo nhu cầu (Demand Forecast)</h2>
                        <p class="page-subheading mb-0">Dự báo nhu cầu 7 ngày tới cho từng sản phẩm bằng AI Forecast API. So sánh gợi ý từ API với quy tắc Reorder Point. Nếu API không khả dụng, hệ thống tự động dùng quy tắc dự phòng.</p>
                    </div>
                </div>

                <div class="forecast-panel">
                    <div class="forecast-field">
                        <label for="forecastProduct">Chọn sản phẩm</label>
                        <select id="forecastProduct">
                            <option value="">-- Chọn sản phẩm --</option>
                            <?php foreach ($products as $product): ?>
                                <option value="<?= (int) $product['product_id'] ?>" data-stock="<?= (int) $product['current_stock'] ?>">
                                    <?= htmlspecialchars($product['sku_code'] . ' — ' . $product['product_name'] . ' (' . $product['category_type'] . ')', ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="button" class="forecast-button" id="runForecast" <?= empty($products) ? 'disabled' : '' ?>>Tạo dự báo 7 ngày</button>
                </div>

                <div id="forecastStatus" class="forecast-status" role="status"></div>

                <div id="emptyForecast" class="empty-state">
                    <strong>Chọn sản phẩm</strong> từ dropdown bên trên rồi bấm nút <strong>"Tạo dự báo 7 ngày"</strong> để xem kết quả dự báo nhu cầu.
                </div>

                <div id="forecastResult" hidden>
                    <div class="forecast-grid">
                        <article class="forecast-card api">
                            <h3>📊 Gợi ý từ AI Forecast</h3>
                            <div class="forecast-number" id="apiSuggestion">—</div>
                            <div class="forecast-meta" id="apiMeta">Đang chờ dự báo</div>
                        </article>

                        <article class="forecast-card rule">
                            <h3>📋 Gợi ý Reorder Point</h3>
                            <div class="forecast-number" id="ruleSuggestion">—</div>
                            <div class="forecast-meta" id="ruleMeta">Dữ liệu sẵn sàng</div>
                        </article>

                        <article class="forecast-card stock">
                            <h3>📦 Tồn kho hiện tại</h3>
                            <div class="forecast-number" id="stockValue">—</div>
                            <div class="forecast-meta" id="stockMeta">Tồn kho hôm nay</div>
                        </article>
                    </div>

                    <article class="forecast-card forecast-chart-card" id="forecastChartCard">
                        <h3>📈 Dự báo nhu cầu theo ngày (7 ngày tới)</h3>
                        <p id="chartSubtitle">Khoảng trên/dưới thể hiện vùng biến động dự kiến dựa trên dữ liệu lịch sử</p>
                        <svg id="forecastChart" viewBox="0 0 760 280" aria-label="Biểu đồ dự báo nhu cầu 7 ngày"></svg>
                    </article>

                    <div class="forecast-table-wrap">
                        <table class="forecast-table">
                            <thead>
                                <tr>
                                    <th>Ngày</th>
                                    <th>Nhu cầu dự báo</th>
                                    <th>Cận dưới</th>
                                    <th>Cận trên</th>
                                </tr>
                            </thead>
                            <tbody id="forecastTable"></tbody>
                        </table>
                    </div>
                </div>
            </section>
        </main>
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

    // Draw axes
    svg.append(makeElement('line', {x1: left, y1: height - bottom, x2: width - right, y2: height - bottom, class: 'axis-line'}));
    svg.append(makeElement('line', {x1: left, y1: top, x2: left, y2: height - bottom, class: 'axis-line'}));

    // Draw area (upper/lower bounds)
    const upperPath = points.map((p, i) => `${xPos(i)},${yPos(Number(p.upper_bound) || 0)}`).join(' ');
    const lowerPath = points.slice().reverse().map((p, i) => `${xPos(points.length - 1 - i)},${yPos(Number(p.lower_bound) || 0)}`).join(' ');
    svg.append(makeElement('polygon', {points: upperPath + ' ' + lowerPath, class: 'forecast-area'}));

    // Draw forecast line
    const forecastPath = points.map((p, i) => `${xPos(i)},${yPos(Number(p.predicted_quantity) || 0)}`).join(' ');
    svg.append(makeElement('polyline', {points: forecastPath, class: 'forecast-line'}));

    // Draw Y-axis labels
    [0, Math.ceil(max / 2), max].forEach(v => {
        const label = makeElement('text', {x: '4', y: String(yPos(v) + 4), class: 'chart-label'});
        label.textContent = format(v);
        svg.append(label);
    });

    // Draw X-axis date labels
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

    setText('apiSuggestion', isApi ? format(data.suggested_qty) + ' đơn vị' : 'Không khả dụng');
    setText('apiMeta', isApi
        ? `Nhu cầu dự báo: ${format(data.forecasted_demand || 0)} · ${data.model_used || 'forecast_api'}`
        : 'Đã tự động chuyển sang quy tắc dự phòng'
    );

    setText('ruleSuggestion', format(rule.suggested_qty || data.suggested_qty || 0) + ' đơn vị');
    setText('ruleMeta', `Bán TB 7 ngày: ${format(rule.avg_daily_sales_7d || 0)} · Safety stock: ${format(rule.safety_stock || 0)}`);

    const currentStock = rule.current_stock ?? (product.selectedOptions[0]?.dataset.stock ?? 0);
    setText('stockValue', format(currentStock) + ' đơn vị');
    setText('stockMeta', `Reorder point: ${format(rule.reorder_point || 0)} · Max stock: ${format(rule.max_stock || 0)}`);

    // Render table
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
    const statusMsg = data.message || (isApi ? 'Đã tạo dự báo.' : 'Đã dùng quy tắc dự phòng.');
    setStatus(statusMsg, statusType);
}

button?.addEventListener('click', async () => {
    if (!product.value) {
        setStatus('Vui lòng chọn sản phẩm trước.', 'error');
        return;
    }

    button.disabled = true;
    button.textContent = 'Đang tạo dự báo…';
    setStatus('Đang lấy dữ liệu và tính dự báo…', 'ok');

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

        // Đọc response dạng text trước rồi mới parse JSON thủ công: nếu server
        // trả về HTML (trang lỗi PHP, 404, warning rò rỉ...) thay vì JSON,
        // ta báo lỗi rõ ràng cho người dùng thay vì để JSON.parse ném ra
        // thông báo khó hiểu kiểu `"...</...>"... is not valid JSON`.
        const rawText = await response.text();
        let data;
        try {
            data = JSON.parse(rawText);
        } catch (parseError) {
            console.error('[Forecast Error] Non-JSON response:', rawText.slice(0, 500));
            throw new Error(
                `Server trả về dữ liệu không hợp lệ (HTTP ${response.status}). `
                + 'Có thể do lỗi phía backend (PHP warning/exception) hoặc sai đường dẫn API. '
                + 'Vui lòng kiểm tra log server hoặc liên hệ quản trị viên.'
            );
        }

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${data.message || 'Lỗi kết nối'}`);
        }

        if (!data.success) {
            throw new Error(data.message || 'Dự báo không thành công.');
        }

        renderResults(data);
    } catch (error) {
        console.error('[Forecast Error]', error);
        resultBox.hidden = true;
        emptyBox.hidden = false;
        setStatus(error.message || 'Không thể tạo dự báo. Vui lòng thử lại.', 'error');
    } finally {
        button.disabled = false;
        button.textContent = 'Tạo dự báo 7 ngày';
    }
});
        </script>
    </div>
</div>
<?php require __DIR__ . '/../../components/footer.php'; ?>
