<?php
/**
 * File: frontend/staff/sales_entry.php
 * Purpose: Màn hình "New Sales Entry" (POS) cho Store Staff - ghi nhận 1 giao
 *          dịch bán tại quầy: chọn sản phẩm, số lượng, chiết khấu, phương thức
 *          thanh toán, ghi chú; xem trước tồn kho còn lại + tổng tiền theo thời
 *          gian thực; bấm "Complete Sale" để lưu giao dịch + trừ kho + broadcast
 *          sự kiện để Sales History (tab khác) tự cập nhật không cần F5.
 *
 * Related: FR-STF-02, BR-02, BR-03
 * Calls (frontend/AJAX): POST backend/api/sales/create.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../backend/config/app_config.php';
require_once __DIR__ . '/../../backend/config/database.php';
require_once __DIR__ . '/../../backend/core/Logger.php';
require_once __DIR__ . '/../../backend/core/Auth.php';
require_once __DIR__ . '/../../backend/core/Middleware.php';
require_once __DIR__ . '/../../backend/models/Product.php';
require_once __DIR__ . '/../../backend/models/Inventory.php';
require_once __DIR__ . '/../../backend/models/Warehouse.php';

Middleware::guard([ROLE_STAFF]);

$productModel    = new Product();
$inventoryModel  = new Inventory();
$warehouseModel  = new Warehouse();

$warehouses = $warehouseModel->getAll();
$defaultWarehouseId = !empty($warehouses) ? (int) $warehouses[0]['warehouse_id'] : 0;

// Danh sách sản phẩm active + tồn kho theo từng warehouse (để hiển thị badge
// stock + cho phép validate phía client).
$products = $productModel->getAll(null, null, true);

$productData = [];
foreach ($products as $p) {
    $pid = (int) $p['product_id'];
    $stockByWh = [];
    foreach ($warehouses as $wh) {
        $row = $inventoryModel->getStockByProductAndWarehouse($pid, (int) $wh['warehouse_id']);
        $stockByWh[(int) $wh['warehouse_id']] = $row ? (int) $row['quantity_on_hand'] : 0;
    }
    $productData[] = [
        'product_id'    => $pid,
        'sku_code'      => $p['sku_code'],
        'product_name'  => $p['product_name'],
        'category_name' => $p['category_name'],
        'unit'          => $p['unit'],
        'selling_price' => (float) ($p['selling_price'] ?? 0),
        'requires_fefo' => (int) ($p['requires_fefo'] ?? 0),
        'stock_by_wh'   => $stockByWh,
    ];
}

$activeMenu  = 'sales_entry';
$pageTitle   = 'New Sales Entry';
$breadcrumbs = ['Staff', 'New Sales Entry'];
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Sales Entry - InventoryDSS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/css/theme_variables.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/custom.css" rel="stylesheet">
    <style>
        /* ============================================================
           NEW SALES ENTRY - light theme (đồng bộ với toàn bộ app dùng
           biến --surface-*, --text-*, --color-* từ theme_variables.css)
           ============================================================ */
        .nse-body {
            font-family: var(--font-sans, 'Plus Jakarta Sans', 'Inter', system-ui, sans-serif);
            background: var(--surface-page-bg);
            color: var(--text-secondary);
            min-height: 100vh;
        }
        .nse-body .app-main { padding: 24px; }

        .nse-hero h1 {
            font-size: 1.7rem; font-weight: 800; color: var(--text-primary);
            letter-spacing: -.01em; margin: 0;
        }
        .nse-hero p { color: var(--text-muted); margin: 4px 0 0; font-size: .92rem; }
        .nse-live-badge {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 6px 14px; border-radius: 999px;
            background: var(--color-success-bg); border: 1px solid var(--color-success);
            color: var(--color-success); font-size: .78rem; font-weight: 600;
        }
        .nse-live-dot {
            width: 8px; height: 8px; border-radius: 50%;
            background: var(--color-success); box-shadow: 0 0 0 3px var(--color-success-bg);
            animation: nsePulse 1.6s infinite;
        }
        @keyframes nsePulse { 0%,100%{opacity:1} 50%{opacity:.35} }

        .glass-card {
            background: var(--surface-card-bg);
            border: 1px solid var(--surface-border);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-card);
            padding: 20px 22px;
        }
        .glass-card .card-title { color: var(--text-primary); font-weight: 700; font-size: 1rem; }
        .glass-card .card-sub { color: var(--text-muted); font-size: .8rem; }

        .nse-search { position: relative; }
        .nse-search input {
            width: 100%; padding: 12px 16px 12px 44px;
            border-radius: var(--radius-sm); border: 1px solid var(--surface-border);
            background: var(--surface-page-bg); color: var(--text-primary); font-size: .9rem;
            outline: none; transition: all .2s;
        }
        .nse-search input:focus {
            border-color: var(--brand-primary);
            box-shadow: 0 0 0 4px var(--brand-primary-light);
        }
        .nse-search svg {
            position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
            width: 18px; height: 18px; color: var(--text-muted); pointer-events: none;
        }
        .nse-dropdown {
            position: absolute; top: calc(100% + 8px); left: 0; right: 0;
            max-height: 300px; overflow-y: auto; z-index: 40;
            background: var(--surface-card-bg); border: 1px solid var(--surface-border);
            border-radius: var(--radius-sm); box-shadow: var(--shadow-card-hover);
            display: none;
        }
        .nse-dropdown.open { display: block; }
        .nse-dropdown-item {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 14px; cursor: pointer; transition: background .15s;
        }
        .nse-dropdown-item:hover { background: var(--brand-primary-light); }
        .nse-dropdown-item .nse-dd-name { color: var(--text-primary); font-weight: 600; font-size: .88rem; }
        .nse-dropdown-item .nse-dd-sku { color: var(--text-muted); font-size: .74rem; }
        .nse-dropdown-item .nse-dd-price { margin-left: auto; color: var(--brand-primary); font-weight: 700; font-size: .86rem; }

        .nse-product-card {
            display: flex; gap: 16px; align-items: center;
            padding: 16px; border-radius: var(--radius-sm);
            background: var(--brand-primary-light); border: 1px solid var(--surface-border);
        }
        .nse-product-img {
            width: 64px; height: 64px; border-radius: var(--radius-sm); flex-shrink: 0;
            background: var(--surface-page-bg);
            display: flex; align-items: center; justify-content: center;
            font-size: 1.6rem; color: var(--brand-primary); border: 1px solid var(--surface-border);
        }
        .nse-product-name { color: var(--text-primary); font-weight: 700; font-size: 1rem; }
        .nse-product-sku { color: var(--text-muted); font-size: .76rem; }
        .nse-product-meta { color: var(--text-muted); font-size: .78rem; }
        .nse-stock-badge {
            display: inline-flex; align-items: center; padding: 3px 10px;
            border-radius: 999px; font-size: .74rem; font-weight: 700;
        }
        .nse-stock-ok  { background: var(--color-success-bg); color: var(--color-success); border: 1px solid var(--color-success-bg); }
        .nse-stock-low { background: var(--color-warning-bg); color: var(--color-warning); border: 1px solid var(--color-warning-bg); }

        .nse-field-label { color: var(--text-muted); font-size: .76rem; text-transform: uppercase; letter-spacing: .05em; font-weight: 600; margin-bottom: 6px; }
        .nse-input, .nse-select, .nse-textarea {
            width: 100%; padding: 10px 14px; border-radius: var(--radius-sm);
            border: 1px solid var(--surface-border);
            background: var(--surface-page-bg); color: var(--text-primary); font-size: .9rem;
            outline: none; transition: all .2s;
        }
        .nse-input:focus, .nse-select:focus, .nse-textarea:focus {
            border-color: var(--brand-primary); box-shadow: 0 0 0 4px var(--brand-primary-light);
        }
        .nse-textarea { resize: vertical; min-height: 70px; }

        .nse-qty-wrap { display: flex; align-items: center; gap: 8px; }
        .nse-qty-btn {
            width: 40px; height: 40px; border-radius: var(--radius-sm); border: 1px solid var(--surface-border);
            background: var(--surface-card-bg); color: var(--text-primary); font-size: 1.2rem; font-weight: 700;
            display: flex; align-items: center; justify-content: center; cursor: pointer;
            transition: all .15s;
        }
        .nse-qty-btn:hover:not(:disabled) { background: var(--brand-primary-light); border-color: var(--brand-primary); }
        .nse-qty-btn:disabled { opacity: .35; cursor: not-allowed; }
        .nse-qty-input {
            width: 70px; text-align: center; padding: 8px; border-radius: var(--radius-sm);
            border: 1px solid var(--surface-border); background: var(--surface-page-bg);
            color: var(--text-primary); font-size: 1.05rem; font-weight: 700; outline: none;
        }

        .nse-pay-options { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
        .nse-pay-option {
            padding: 12px 10px; border-radius: var(--radius-sm); text-align: center; cursor: pointer;
            border: 1px solid var(--surface-border); background: var(--surface-card-bg);
            color: var(--text-muted); font-size: .82rem; font-weight: 600; transition: all .15s;
        }
        .nse-pay-option:hover { border-color: var(--brand-primary); color: var(--brand-primary); }
        .nse-pay-option.active {
            background: var(--brand-primary-light);
            border-color: var(--brand-primary); color: var(--brand-primary);
            box-shadow: 0 0 0 3px var(--brand-primary-light);
        }
        .nse-pay-option .nse-po-icon { display: block; font-size: 1.3rem; margin-bottom: 4px; }

        .nse-summary-row {
            display: flex; align-items: center; justify-content: space-between;
            padding: 10px 0; border-bottom: 1px solid var(--surface-border-soft);
        }
        .nse-summary-row:last-of-type { border-bottom: none; }
        .nse-summary-label { color: var(--text-muted); font-size: .84rem; }
        .nse-summary-value { color: var(--text-primary); font-weight: 700; font-size: .9rem; }
        .nse-summary-total {
            display: flex; align-items: center; justify-content: space-between;
            padding: 16px; margin-top: 6px; border-radius: var(--radius-sm);
            background: var(--brand-primary-light);
            border: 1px solid var(--brand-primary);
        }
        .nse-summary-total .nse-st-label { color: var(--text-muted); font-size: .8rem; }
        .nse-summary-total .nse-st-value { color: var(--brand-primary); font-size: 1.5rem; font-weight: 800; }

        .nse-submit {
            width: 100%; padding: 15px; margin-top: 18px; border: none; border-radius: var(--radius-sm);
            background: linear-gradient(135deg, var(--brand-primary), var(--brand-primary-dark));
            color: #fff; font-size: 1rem; font-weight: 700; letter-spacing: .01em;
            box-shadow: 0 6px 16px -8px rgba(30,58,95,.55);
            transition: all .2s; position: relative; overflow: hidden;
        }
        .nse-submit:hover:not(:disabled) { filter: brightness(1.06); transform: translateY(-1px); box-shadow: 0 10px 20px -8px rgba(30,58,95,.6); }
        .nse-submit:disabled { opacity: .6; cursor: not-allowed; }
        .nse-submit .spinner {
            display: none; width: 18px; height: 18px; border: 2px solid rgba(255,255,255,.4);
            border-top-color: #fff; border-radius: 50%; animation: nseSpin .7s linear infinite;
            vertical-align: middle; margin-right: 8px;
        }
        .nse-submit.loading .spinner { display: inline-block; }
        @keyframes nseSpin { to { transform: rotate(360deg); } }

        .nse-toast {
            position: fixed; top: 20px; right: 20px; z-index: 2000;
            min-width: 300px; max-width: 380px; padding: 14px 18px; border-radius: var(--radius-sm);
            display: flex; align-items: flex-start; gap: 12px;
            box-shadow: var(--shadow-card-hover); transform: translateX(120%);
            transition: transform .35s cubic-bezier(.2,.9,.3,1.2);
        }
        .nse-toast.show { transform: translateX(0); }
        .nse-toast-success { background: var(--color-success-bg); border: 1px solid var(--color-success); color: var(--color-success); }
        .nse-toast-error   { background: var(--color-danger-bg); border: 1px solid var(--color-danger); color: var(--color-danger); }
        .nse-toast-icon { font-size: 1.3rem; }
        .nse-toast-close { margin-left: auto; cursor: pointer; color: inherit; opacity: .7; font-size: 1rem; background: none; border: none; }

        .nse-stock-anim { transition: color .4s; }
        .nse-stock-flash { color: var(--color-success) !important; }

        @media (max-width: 767.98px) {
            .nse-pay-options { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body class="nse-body">
    <div class="app-shell">
        <?php require __DIR__ . '/../components/sidebar.php'; ?>

        <div class="app-content">
            <?php require __DIR__ . '/../components/header.php'; ?>

            <main class="app-main">
                <div class="nse-hero d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
                    <div>
                        <h1>New Sales Entry</h1>
                        <p>Record a customer purchase and update inventory instantly.</p>
                    </div>
                    <span class="nse-live-badge"><span class="nse-live-dot"></span> Live Sync</span>
                </div>

                <div class="row g-3">
                    <!-- Trái: form bán hàng -->
                    <div class="col-12 col-xl-7">
                        <div class="glass-card p-4 mb-3">
                            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
                                <div>
                                    <div class="card-title mb-0">Product</div>
                                    <div class="card-sub">Search &amp; select a product to sell</div>
                                </div>
                                <select id="nseWarehouse" class="nse-select" style="width:auto;">
                                    <?php foreach ($warehouses as $wh): ?>
                                        <option value="<?= (int) $wh['warehouse_id'] ?>" <?= $defaultWarehouseId === (int) $wh['warehouse_id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($wh['warehouse_name'], ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="nse-search mb-3">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
                                <input type="text" id="nseSearch" placeholder="Search by name or SKU..." autocomplete="off">
                                <div class="nse-dropdown" id="nseDropdown"></div>
                            </div>

                            <div id="nseProductCard" class="nse-product-card" style="display:none;">
                                <div class="nse-product-img" id="nseProductImg">📦</div>
                                <div class="flex-grow-1 min-w-0">
                                    <div class="nse-product-name" id="nseProductName">—</div>
                                    <div class="nse-product-sku" id="nseProductSku">—</div>
                                    <div class="nse-product-meta" id="nseProductMeta">—</div>
                                </div>
                                <div class="text-end">
                                    <span class="nse-stock-badge nse-stock-ok" id="nseStockBadge">— in stock</span>
                                    <div class="nse-product-meta mt-1" id="nseUnitPrice">—</div>
                                </div>
                            </div>
                        </div>

                        <div class="glass-card p-4 mb-3">
                            <div class="card-title mb-3">Sale Details</div>
                            <div class="row g-3">
                                <div class="col-12 col-md-6">
                                    <div class="nse-field-label">Quantity</div>
                                    <div class="nse-qty-wrap">
                                        <button type="button" class="nse-qty-btn" id="nseQtyMinus">−</button>
                                        <input type="number" id="nseQty" class="nse-qty-input" value="1" min="1">
                                        <button type="button" class="nse-qty-btn" id="nseQtyPlus">+</button>
                                    </div>
                                </div>
                                <div class="col-12 col-md-6">
                                    <div class="nse-field-label">Discount (%)</div>
                                    <input type="number" id="nseDiscount" class="nse-input" value="0" min="0" max="100" step="0.5">
                                </div>
                                <div class="col-12">
                                    <div class="nse-field-label">Payment Method</div>
                                    <div class="nse-pay-options" id="nsePayOptions">
                                        <div class="nse-pay-option active" data-pay="Cash"><span class="nse-po-icon">💵</span>Cash</div>
                                        <div class="nse-pay-option" data-pay="Credit Card"><span class="nse-po-icon">💳</span>Credit Card</div>
                                        <div class="nse-pay-option" data-pay="E-wallet"><span class="nse-po-icon">📱</span>E-wallet</div>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div class="nse-field-label">Notes (optional)</div>
                                    <textarea id="nseNotes" class="nse-textarea" placeholder="Any notes about this sale..."></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Phải: summary panel -->
                    <div class="col-12 col-xl-5">
                        <div class="glass-card p-4">
                            <div class="card-title mb-3">Order Summary</div>
                            <div class="nse-summary-row">
                                <span class="nse-summary-label">Current Stock</span>
                                <span class="nse-summary-value" id="nseSummaryCurrent">—</span>
                            </div>
                            <div class="nse-summary-row">
                                <span class="nse-summary-label">Quantity Sold</span>
                                <span class="nse-summary-value" id="nseSummaryQty">—</span>
                            </div>
                            <div class="nse-summary-row">
                                <span class="nse-summary-label">Remaining Stock After Sale</span>
                                <span class="nse-summary-value nse-stock-anim" id="nseSummaryRemaining">—</span>
                            </div>
                            <div class="nse-summary-row">
                                <span class="nse-summary-label">Unit Price</span>
                                <span class="nse-summary-value" id="nseSummaryUnit">—</span>
                            </div>
                            <div class="nse-summary-row">
                                <span class="nse-summary-label">Discount</span>
                                <span class="nse-summary-value" id="nseSummaryDiscount">—</span>
                            </div>
                            <div class="nse-summary-total">
                                <span class="nse-st-label">Estimated Total</span>
                                <span class="nse-st-value nse-stock-anim" id="nseSummaryTotal">—</span>
                            </div>
                            <button type="button" class="nse-submit" id="nseSubmit">
                                <span class="spinner"></span>
                                Complete Sale
                            </button>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Toast -->
    <div class="nse-toast" id="nseToast">
        <span class="nse-toast-icon" id="nseToastIcon">✅</span>
        <div id="nseToastMsg"></div>
        <button class="nse-toast-close" onclick="nseHideToast()">✕</button>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        /* ============================================================
           NEW SALES ENTRY - client logic
           ============================================================ */
        const PRODUCTS = <?= json_encode($productData, JSON_UNESCAPED_UNICODE) ?>;
        const WAREHOUSES = <?= json_encode(array_map(fn($w) => ['id'=>(int)$w['warehouse_id'], 'name'=>$w['warehouse_name']], $warehouses), JSON_UNESCAPED_UNICODE) ?>;

        let selectedProduct = null;
        let currentStock = 0;
        let payMethod = 'Cash';

        const $ = (id) => document.getElementById(id);
        const fmt = (v) => '₫' + Number(v).toLocaleString('en-US', {minimumFractionDigits:0, maximumFractionDigits:2});
        const nseToast = $('nseToast');
        let toastTimer = null;

        function nseShowToast(type, msg) {
            nseToast.className = 'nse-toast show nse-toast-' + type;
            $('nseToastIcon').textContent = type === 'success' ? '✅' : '⚠️';
            $('nseToastMsg').textContent = msg;
            clearTimeout(toastTimer);
            toastTimer = setTimeout(nseHideToast, 4000);
        }
        function nseHideToast() {
            nseToast.classList.remove('show');
        }

        function getWarehouseId() {
            return parseInt($('nseWarehouse').value, 10);
        }
        function stockFor(pid, wh) {
            const p = PRODUCTS.find(x => x.product_id === pid);
            return p && p.stock_by_wh[wh] != null ? p.stock_by_wh[wh] : 0;
        }

        /* --- Search dropdown --- */
        const searchInput = $('nseSearch');
        const dropdown = $('nseDropdown');

        function renderDropdown(items) {
            if (!items.length) {
                dropdown.innerHTML = '<div class="nse-dropdown-item"><span class="nse-dd-sku">No products found</span></div>';
                dropdown.classList.add('open');
                return;
            }
            dropdown.innerHTML = items.map(p => {
                const st = stockFor(p.product_id, getWarehouseId());
                return `<div class="nse-dropdown-item" data-id="${p.product_id}">
                            <div>
                                <div class="nse-dd-name">${escapeHtml(p.product_name)}</div>
                                <div class="nse-dd-sku">${escapeHtml(p.sku_code)} · ${escapeHtml(p.category_name)}</div>
                            </div>
<span class="nse-dd-price">${fmt(p.selling_price)}</span>
                            <span style="color:${st>0?'#166534':'#b91c1c'};font-size:.74rem;">${st} in stock</span>
                        </div>`;
            }).join('');
            dropdown.classList.add('open');
        }

        searchInput.addEventListener('input', () => {
            const q = searchInput.value.trim().toLowerCase();
            const wh = getWarehouseId();
            const items = !q ? PRODUCTS : PRODUCTS.filter(p =>
                p.product_name.toLowerCase().includes(q) || p.sku_code.toLowerCase().includes(q));
            renderDropdown(items);
        });

        dropdown.addEventListener('click', (e) => {
            const itemEl = e.target.closest('.nse-dropdown-item');
            if (!itemEl || !itemEl.dataset.id) return;
            selectProduct(parseInt(itemEl.dataset.id, 10));
            dropdown.classList.remove('open');
            searchInput.blur();
        });

        document.addEventListener('click', (e) => {
            if (!e.target.closest('.nse-search')) dropdown.classList.remove('open');
        });

        function selectProduct(pid) {
            selectedProduct = PRODUCTS.find(x => x.product_id === pid);
            if (!selectedProduct) return;
            currentStock = stockFor(pid, getWarehouseId());
            withProductCard(true);
            $('nseProductName').textContent = selectedProduct.product_name;
            $('nseProductSku').textContent = selectedProduct.sku_code + ' · ' + selectedProduct.category_name;
            $('nseProductImg').textContent = '📦';
            $('nseProductMeta').textContent = 'Unit: ' + selectedProduct.unit;
            $('nseUnitPrice').textContent = 'Price: ' + fmt(selectedProduct.selling_price);

            const badge = $('nseStockBadge');
            badge.className = 'nse-stock-badge ' + (currentStock > 0 ? 'nse-stock-ok' : 'nse-stock-low');
            badge.textContent = currentStock > 0 ? currentStock + ' in stock' : 'Out of stock';

            $('nseQty').value = 1;
            $('nseDiscount').value = 0;
            updateSummary();
        }

        function withProductCard(show) {
            $('nseProductCard').style.display = show ? 'flex' : 'none';
        }

        /* --- Quantity stepper --- */
        function clampQty(input) {
            let v = parseInt(input.value, 10);
            if (isNaN(v) || v < 1) v = 1;
            if (selectedProduct) {
                const max = Math.max(1, currentStock);
                if (v > max) v = max;
            }
            input.value = v;
            updateSummary();
        }
        $('nseQty').addEventListener('input', () => clampQty($('nseQty')));
        $('nseQtyPlus').addEventListener('click', () => {
            const q = $('nseQty');
            const max = selectedProduct ? Math.max(1, currentStock) : 9999;
            if (parseInt(q.value,10) < max) { q.value = parseInt(q.value,10)+1; updateSummary(); }
        });
        $('nseQtyMinus').addEventListener('click', () => {
            const q = $('nseQty');
            if (parseInt(q.value,10) > 1) { q.value = parseInt(q.value,10)-1; updateSummary(); }
        });
        $('nseDiscount').addEventListener('input', () => {
            let d = parseFloat($('nseDiscount').value);
            if (isNaN(d) || d < 0) d = 0;
            if (d > 100) d = 100;
            $('nseDiscount').value = d;
            updateSummary();
        });

        /* --- Payment method --- */
        $('nsePayOptions').addEventListener('click', (e) => {
            const opt = e.target.closest('.nse-pay-option');
            if (!opt) return;
            document.querySelectorAll('.nse-pay-option').forEach(o => o.classList.remove('active'));
            opt.classList.add('active');
            payMethod = opt.dataset.pay;
        });

        /* --- Warehouse change --- */
        $('nseWarehouse').addEventListener('change', () => {
            if (selectedProduct) selectProduct(selectedProduct.product_id);
            else updateSummary();
        });

        /* --- Live summary --- */
        function updateSummary() {
            if (!selectedProduct) {
                $('nseSummaryCurrent').textContent = '—';
                $('nseSummaryQty').textContent = '—';
                $('nseSummaryRemaining').textContent = '—';
                $('nseSummaryUnit').textContent = '—';
                $('nseSummaryDiscount').textContent = '—';
                $('nseSummaryTotal').textContent = '—';
                return;
            }
            const qty = parseInt($('nseQty').value, 10) || 0;
            const discount = parseFloat($('nseDiscount').value) || 0;
            const remaining = currentStock - qty;
            const unitPrice = selectedProduct.selling_price;
            const discountAmt = (unitPrice * discount / 100);
            const effectiveUnit = unitPrice - discountAmt;
            const total = effectiveUnit * qty;

            $('nseSummaryCurrent').textContent = currentStock;
            $('nseSummaryQty').textContent = qty;
            $('nseSummaryRemaining').textContent = Math.max(0, remaining);
            $('nseSummaryUnit').textContent = fmt(unitPrice);
            $('nseSummaryDiscount').textContent = discount > 0 ? '-' + fmt(discountAmt) + ' (' + discount + '%)' : '—';
            $('nseSummaryTotal').textContent = fmt(total);
        }

        /* --- Submit sale --- */
        const submitBtn = $('nseSubmit');
        submitBtn.addEventListener('click', async () => {
            if (!selectedProduct) {
                nseShowToast('error', 'Please select a product first.');
                return;
            }
            const qty = parseInt($('nseQty').value, 10) || 0;
            const discount = parseFloat($('nseDiscount').value) || 0;
            if (qty <= 0) { nseShowToast('error', 'Quantity must be greater than 0.'); return; }
            if (qty > currentStock) {
                nseShowToast('error', 'Insufficient stock. Only ' + currentStock + ' available.');
                return;
            }

            submitBtn.classList.add('loading');
            submitBtn.disabled = true;

            try {
                const res = await fetch('<?= BASE_URL ?>/../backend/api/sales/create.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        product_id: selectedProduct.product_id,
                        quantity: qty,
                        discount: discount,
                        warehouse_id: getWarehouseId()
                    })
                });
                const data = await res.json();

                if (!data.success) {
                    nseShowToast('error', data.message || 'Sale failed.');
                    animateStockFlash(false);
                    return;
                }

                // Thành công: cập nhật tồn kho local + animate + broadcast
                currentStock = data.remaining_stock;
                selectedProduct.stock_by_wh[getWarehouseId()] = currentStock;
                animateStockFlash(true);

                nseShowToast('success', 'Sale completed successfully. ' + data.sale.product_name + ' x' + data.sale.quantity_sold);

                // Broadcast realtime để Sales History (tab khác) tự cập nhật
                broadcastNewSale(data.sale);

                // Xoá form
                selectedProduct = null;
                withProductCard(false);
                $('nseSearch').value = '';
                updateSummary();
            } catch (err) {
                nseShowToast('error', 'Connection error. Please try again.');
            } finally {
                submitBtn.classList.remove('loading');
                submitBtn.disabled = false;
            }
        });

        /* --- Stock number animation --- */
        const remainingEl = $('nseSummaryRemaining');
        const totalEl = $('nseSummaryTotal');
        function animateStockFlash(success) {
            const el = success ? remainingEl : null;
            if (el) {
                el.classList.add('nse-stock-flash');
                setTimeout(() => el.classList.remove('nse-stock-flash'), 900);
            }
        }

        /* --- BroadcastChannel realtime sync (fallback: localStorage + polling) --- */
        const CHANNEL = 'gs25_inventory_sales';

        function broadcastNewSale(sale) {
            const payload = { type: 'NEW_SALE', product_id: sale.product_id, quantity: sale.quantity_sold, remaining_stock: null, timestamp: Date.now() };
            try {
                if (window.BroadcastChannel) {
                    const bc = new BroadcastChannel(CHANNEL);
                    bc.postMessage(payload);
                    bc.close();
                }
            } catch (e) {}
            try {
                localStorage.setItem(CHANNEL, JSON.stringify(payload)); // fallback storage event
            } catch (e) {}
        }

        /* --- Helper --- */
        function escapeHtml(str) {
            return String(str).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'<','>':'>','"':'"',"'":'&#39;'}[c]));
        }

        // Khởi tạo
        updateSummary();
    </script>
    <?php require __DIR__ . '/../components/footer.php'; ?>
</body>
</html>
