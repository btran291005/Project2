<?php
/**
 * File: frontend/staff/inventory/stock_count.php
 * Purpose: UI kiểm kê định kỳ (Periodic Stock Count) - Staff nhập số lượng
 * đếm thực tế (Shelf Quantity) cho từng SKU, hệ thống tự tính chênh lệch so
 * với số lượng sổ sách (System/POS Quantity) và ghi vào stock_movements khi
 * hoàn tất phiên (FR-STF-04, FR-STF-09, BR-14).
 *
 * LUỒNG (form POST truyền thống, không cần JS/API riêng - khác bản gợi ý
 * "inline edit" của ảnh mẫu, nhưng vẫn giữ đúng UX 1 bảng nhập liệu + 1 nút
 * "Submit Count"):
 *   1) Vào trang lần đầu -> tự động startStockCountSession() một lần, lưu
 *      count_id vào $_SESSION['staff_count_id'] (giữ xuyên suốt cho tới khi
 *      Finalize hoặc chọn "Bắt đầu phiên mới").
 *   2) Nhập actual_qty cho nhiều SKU trong 1 bảng -> submit 1 lần -> mỗi
 *      dòng có giá trị được gọi recordCountItem() (StockCount::addCountItem()
 *      tự tính system_qty ngay lúc ghi, không phải lúc hiển thị - nên bảng
 *      "đã đếm" luôn khớp số liệu tại THỜI ĐIỂM đếm, không bị lệch nếu có
 *      giao dịch bán xảy ra sau đó).
 *   3) Nút "Hoàn tất phiên kiểm kê" -> finalizeStockCount() -> ghi
 *      count_correction vào stock_movements cho từng dòng lệch, đóng phiên.
 *
 * Related: FR-STF-04, FR-STF-09, BR-14
 * Calls: StaffService::startStockCountSession(), recordCountItem(),
 *        finalizeStockCount(), getStockCountDetail()
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../backend/config/app_config.php';
require_once __DIR__ . '/../../../backend/config/database.php';
require_once __DIR__ . '/../../../backend/core/Logger.php';
require_once __DIR__ . '/../../../backend/core/Auth.php';
require_once __DIR__ . '/../../../backend/core/Middleware.php';
require_once __DIR__ . '/../../../backend/services/StaffService.php';
require_once __DIR__ . '/../../../backend/models/Product.php';

Middleware::guard([ROLE_STAFF]);

$staffService = new StaffService();
$productModel = new Product();
$staffId = (int) Auth::id();

$errorMessage = null;
$successMessage = null;

// --- Bắt đầu phiên mới theo yêu cầu (nút "Bắt đầu phiên mới") ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'start_new_session') {
    unset($_SESSION['staff_count_id']);
}

// --- Đảm bảo luôn có 1 phiên đang mở để nhập liệu ---
if (empty($_SESSION['staff_count_id'])) {
    $sessionResult = $staffService->startStockCountSession($staffId);
    if ($sessionResult['success']) {
        $_SESSION['staff_count_id'] = (int) $sessionResult['count_id'];
    } else {
        $errorMessage = 'Cannot start a new stock count session.';
    }
}
$countId = (int) ($_SESSION['staff_count_id'] ?? 0);

// --- Ghi nhận các dòng đã nhập trong bảng (FR-STF-04) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_counts' && $countId > 0) {
    $actualQtys = $_POST['actual_qty'] ?? [];
    $recordedCount = 0;

    foreach ($actualQtys as $productId => $qtyRaw) {
        if ($qtyRaw === '' || $qtyRaw === null) {
            continue; // Bỏ qua dòng chưa nhập - không ép phải đếm hết 1 lượt.
        }
        $result = $staffService->recordCountItem($countId, (int) $productId, (int) $qtyRaw);
        if ($result['success']) {
            $recordedCount++;
        }
    }

    $successMessage = $recordedCount > 0
        ? "Recorded {$recordedCount} products in the stock count session."
        : 'No data was entered.';
}

// --- Hoàn tất phiên (FR-STF-09) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'finalize' && $countId > 0) {
    $finalizeResult = $staffService->finalizeStockCount($countId, $staffId);
    if ($finalizeResult['success']) {
        unset($_SESSION['staff_count_id']);
        $successMessage = "Stock count session #{$countId} finalized - {$finalizeResult['discrepancy_items']}/{$finalizeResult['total_items']} products had discrepancies.";
        $countId = 0;
    } else {
        $errorMessage = $finalizeResult['message'];
    }
}

// --- Dữ liệu hiển thị ---
$allProducts = $productModel->getAll(null, null, true);

$sessionDetail = $countId > 0 ? $staffService->getStockCountDetail($countId) : false;
$countedItems = $sessionDetail !== false ? ($sessionDetail['items'] ?? []) : [];
$countedByProductId = [];
foreach ($countedItems as $item) {
    $countedByProductId[(int) $item['product_id']] = $item;
}

$totalCounted = count($countedItems);
$discrepancyItems = array_filter($countedItems, fn($i) => (int) $i['discrepancy'] !== 0);
$discrepancyCount = count($discrepancyItems);
$discrepancyValue = 0.0;
foreach ($discrepancyItems as $item) {
    $product = $productModel->getById((int) $item['product_id']);
    $unitCost = $product !== false ? (float) $product['unit_cost'] : 0.0;
    $discrepancyValue += ((int) $item['discrepancy']) * $unitCost;
}
$discrepancyPercent = $totalCounted > 0 ? round(($discrepancyCount / $totalCounted) * 100, 2) : 0.0;

$activeMenu  = 'stock_count';
$pageTitle   = 'Periodic Stock Count';
$breadcrumbs = ['Staff', 'Inventory', 'Stock Count'];
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Count - InventoryDSS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/theme_variables.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/custom.css" rel="stylesheet">
    <style>
        .sc-input { width: 110px; text-align: right; }
        .sc-variance-pos { color: var(--color-success, #00875a); font-weight: 600; }
        .sc-variance-neg { color: var(--color-danger, #de350b); font-weight: 600; }
        .sc-variance-zero { color: var(--text-muted); font-weight: 600; }
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
                        <h2 class="page-heading mb-1">Periodic Stock Count</h2>
                        <p class="page-subheading mb-0">Reconcile physical shelf stock with system records. Current session: #<?= $countId ?: '—' ?></p>
                    </div>
                    <form method="post" onsubmit="return confirm('Start a new stock count session? Current session will remain active if not finalized.');">
                        <input type="hidden" name="action" value="start_new_session">
                        <button type="submit" class="btn btn-outline-secondary btn-sm">Start New Session</button>
                    </form>
                </div>

                <nav class="inv-tab-nav">
                    <a href="goods_receipt.php" class="inv-tab-link">Goods Receipt</a>
                    <a href="stock_count.php" class="inv-tab-link active">Stock Count</a>
                    <a href="adjustments.php" class="inv-tab-link">Adjustment</a>
                </nav>

                <?php if ($errorMessage): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <?php if ($successMessage): ?>
                    <div class="alert alert-success"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>

                <?php if ($countId > 0): ?>
                <form method="post">
                    <input type="hidden" name="action" value="submit_counts">

                    <div class="panel-card mb-4">
                        <div class="panel-card-header">
                            <h3 class="panel-card-title">Enter Physical Count (Shelf Qty)</h3>
                            <span class="panel-card-note"><?= count($allProducts) ?> active products</span>
                        </div>

                        <div class="table-responsive">
                            <table class="table data-table align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>SKU</th>
                                        <th>Product</th>
                                        <th class="text-end">Physical Count (Shelf Qty)</th>
                                        <th class="text-end">System Qty (recorded)</th>
                                        <th>Variance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($allProducts as $product): ?>
                                        <?php
                                            $pid = (int) $product['product_id'];
                                            $counted = $countedByProductId[$pid] ?? null;
                                        ?>
                                        <tr>
                                            <td class="text-muted"><?= htmlspecialchars($product['sku_code'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td class="fw-semibold"><?= htmlspecialchars($product['product_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td class="text-end">
                                                <input type="number" min="0" step="1" name="actual_qty[<?= $pid ?>]"
                                                       class="form-control form-control-sm sc-input ms-auto"
                                                       value="<?= $counted ? (int) $counted['actual_qty'] : '' ?>"
                                                       placeholder="—">
                                            </td>
                                            <td class="text-end text-muted"><?= $counted ? number_format((int) $counted['system_qty']) : '—' ?></td>
                                            <td>
                                                <?php if ($counted === null): ?>
                                                    <span class="text-muted small">Not counted</span>
                                                <?php else: ?>
                                                    <?php $d = (int) $counted['discrepancy']; ?>
                                                    <span class="<?= $d > 0 ? 'sc-variance-pos' : ($d < 0 ? 'sc-variance-neg' : 'sc-variance-zero') ?>">
                                                        <?= $d > 0 ? '+' : '' ?><?= number_format($d) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mb-4">
                        <button type="submit" class="btn btn-brand">Save Counts</button>
                    </div>
                </form>

                <form method="post" onsubmit="return confirm('Finalize stock count session? After finalization, discrepancies will be recorded in history and this session cannot be modified.');" class="mb-4">
                    <input type="hidden" name="action" value="finalize">
                    <button type="submit" class="btn btn-success" <?= $totalCounted === 0 ? 'disabled' : '' ?>>Finalize Stock Count</button>
                </form>

                <!-- KPI cards -->
                <div class="row g-3">
                    <div class="col-6 col-xl-3">
                        <div class="kpi-card">
                            <span class="kpi-label">Counted</span>
                            <span class="kpi-value"><?= number_format($totalCounted) ?> / <?= number_format(count($allProducts)) ?></span>
                        </div>
                    </div>
                    <div class="col-6 col-xl-3">
                        <div class="kpi-card <?= $discrepancyCount > 0 ? 'kpi-card-warn' : '' ?>">
                            <span class="kpi-label">Variance Rate</span>
                            <span class="kpi-value"><?= number_format($discrepancyPercent, 2) ?>%</span>
                        </div>
                    </div>
                    <div class="col-6 col-xl-3">
                        <div class="kpi-card <?= $discrepancyValue < 0 ? 'kpi-card-warn' : '' ?>">
                            <span class="kpi-label">Variance Value</span>
                            <span class="kpi-value">&#8363;<?= number_format($discrepancyValue, 0) ?></span>
                        </div>
                    </div>
                </div>

                <?php else: ?>
                    <div class="panel-card">
                        <div class="empty-state">No active stock count session. Click "Start New Session" to continue.</div>
                    </div>
                <?php endif; ?>

            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <?php require __DIR__ . '/../../components/footer.php'; ?>