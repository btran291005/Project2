<?php
/**
 * File: frontend/staff/inventory/goods_receipt.php
 * Purpose: UI nhận hàng theo Purchase Order đã duyệt (Approved) - đối chiếu
 * số lượng thực nhận với số lượng đã duyệt (approved_qty), bắt buộc ghi lý
 * do khi có sai lệch (BR-10), sau đó cộng thẳng vào tồn kho (BR-09).
 *
 * LUỒNG (2 bước, khác 3-bước "Selection/Verification/Confirmation" của ảnh
 * mẫu nhưng cùng bản chất nghiệp vụ - gộp bước 1+3 lại cho gọn vì đã có sẵn
 * receiveFullOrder() xử lý toàn bộ dòng trong 1 lượt, không cần bước xác
 * nhận riêng):
 *   1) ?po_id chưa có -> liệt kê PO đang chờ nhận (status = Approved), Staff
 *      bấm vào 1 PO để mở bước Verification.
 *   2) ?po_id=X -> bảng đối chiếu Expected/Received cho từng dòng + ô lý do
 *      sai lệch (chỉ bắt buộc khi Received != Expected, JS chỉ để UX rõ ràng,
 *      validate THẬT nằm ở Order::recordReceipt() phía server - BR-10) ->
 *      "Xác nhận & Hoàn tất" gọi receiveFullOrder() 1 lần cho toàn bộ dòng.
 *
 * Related: FR-STF-05, FR-STF-06, FR-STF-07, BR-08, BR-09, BR-10
 * Calls: StaffService::listOrdersAwaitingReceipt(), getOrderForReceiving(),
 *        receiveFullOrder()
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../backend/config/app_config.php';
require_once __DIR__ . '/../../../backend/config/database.php';
require_once __DIR__ . '/../../../backend/core/Logger.php';
require_once __DIR__ . '/../../../backend/core/Auth.php';
require_once __DIR__ . '/../../../backend/core/Middleware.php';
require_once __DIR__ . '/../../../backend/services/StaffService.php';
require_once __DIR__ . '/../../../backend/models/Warehouse.php';

Middleware::guard([ROLE_STAFF]);

$staffService  = new StaffService();
$warehouseModel = new Warehouse();
$staffId = (int) Auth::id();

$errorMessage = null;
$successMessage = null;

// --- Xác nhận nhận hàng toàn bộ đơn (bước 2 -> submit) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'receive_order') {
    $poId = (int) ($_POST['po_id'] ?? 0);
    $warehouseId = (int) ($_POST['warehouse_id'] ?? 0);
    $poDetailIds = $_POST['po_detail_id'] ?? [];
    $productIds  = $_POST['product_id'] ?? [];
    $receivedQtys = $_POST['received_qty'] ?? [];
    $discrepancyReasons = $_POST['discrepancy_reason'] ?? [];

    if ($warehouseId <= 0) {
        $errorMessage = 'Please select a receiving warehouse.';
    } else {
        $lines = [];
        foreach ($poDetailIds as $i => $poDetailId) {
            $lines[] = [
                'po_detail_id'        => (int) $poDetailId,
                'product_id'          => (int) ($productIds[$i] ?? 0),
                'received_qty'        => (int) ($receivedQtys[$i] ?? 0),
                'discrepancy_reason'  => trim((string) ($discrepancyReasons[$i] ?? '')) !== '' ? trim((string) $discrepancyReasons[$i]) : null,
            ];
        }

        $result = $staffService->receiveFullOrder($poId, $lines, $warehouseId, $staffId);

        if ($result['success']) {
            $successMessage = "Order #{$poId} receipt confirmed successfully.";
        } else {
            $failedLines = array_filter($result['results'] ?? [], fn($r) => !$r['success']);
            $errorDetails = array_map(fn($r) => $r['message'] ?? 'Unknown error', $failedLines);
            $errorMessage = ($result['message'] ?? 'Cannot complete receipt.')
                . (!empty($errorDetails) ? ' — ' . implode('; ', array_unique($errorDetails)) : '');
        }
    }
}

// --- Xác định đang ở bước nào ---
$selectedPoId = isset($_GET['po_id']) ? (int) $_GET['po_id'] : 0;
$selectedPo = null;

if ($selectedPoId > 0) {
    $order = $staffService->getOrderForReceiving($selectedPoId);
    if ($order !== false) {
        $selectedPo = $order;
    } else {
        $errorMessage = 'Purchase order not found.';
        $selectedPoId = 0;
    }
}

$pendingOrders = $staffService->listOrdersAwaitingReceipt();
$warehouses = $warehouseModel->getAll();

$activeMenu  = 'good_receipt';
$pageTitle   = 'Goods Receipt';
$breadcrumbs = ['Staff', 'Inventory', 'Goods Receipt'];
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Goods Receipt - InventoryDSS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/theme_variables.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/custom.css" rel="stylesheet">
    <style>
        .gr-input { width: 100px; text-align: right; }
        .gr-reason-input { min-width: 220px; }
        .gr-variance-pos { color: var(--color-info, #0052cc); font-weight: 600; }
        .gr-variance-neg { color: var(--color-danger, #de350b); font-weight: 600; }
        .gr-variance-zero { color: var(--color-success, #00875a); font-weight: 600; }
    </style>
</head>
<body>
    <div class="app-shell">
        <?php require __DIR__ . '/../../components/sidebar.php'; ?>

        <div class="app-content">
            <?php require __DIR__ . '/../../components/header.php'; ?>

            <main class="app-main">

                <div class="mb-3">
                    <h2 class="page-heading mb-1">Quick Goods Receipt</h2>
                    <p class="page-subheading mb-0">Receive and verify incoming shipments from suppliers.</p>
                </div>

                <nav class="inv-tab-nav">
                    <a href="goods_receipt.php" class="inv-tab-link active">Goods Receipt</a>
                    <a href="stock_count.php" class="inv-tab-link">Stock Count</a>
                    <a href="adjustments.php" class="inv-tab-link">Adjustment</a>
                </nav>

                <?php if ($errorMessage): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <?php if ($successMessage): ?>
                    <div class="alert alert-success"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>

                <?php if ($selectedPo === null): ?>
                    <!-- ============ BƯỚC 1: CHỌN ĐƠN HÀNG ĐANG CHỜ NHẬN ============ -->
                    <div class="panel-card">
                        <div class="panel-card-header">
                            <h3 class="panel-card-title">Orders Awaiting Receipt</h3>
                            <span class="badge-count"><?= count($pendingOrders) ?></span>
                        </div>

                        <?php if (empty($pendingOrders)): ?>
                            <div class="empty-state">No orders awaiting receipt (status "Approved").</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table data-table align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>PO</th>
                                            <th>Supplier</th>
                                            <th>Approved Date</th>
                                            <th class="text-end">Total Value</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pendingOrders as $po): ?>
                                            <tr>
                                                <td class="fw-semibold">#<?= (int) $po['po_id'] ?></td>
                                                <td><?= htmlspecialchars($po['supplier_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                                                <td class="text-muted"><?= htmlspecialchars((string) ($po['approved_at'] ?? $po['created_at'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                                                <td class="text-end">&#8363;<?= number_format((float) ($po['total_amount'] ?? 0), 0) ?></td>
                                                <td class="text-end">
                                                    <a href="?po_id=<?= (int) $po['po_id'] ?>" class="btn btn-brand btn-sm">Receive</a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                <?php else: ?>
                    <!-- ============ BƯỚC 2: ĐỐI CHIẾU & XÁC NHẬN ============ -->
                    <?php
                        $lines = $selectedPo['details'] ?? [];
                        $totalExpectedValue = array_sum(array_column($lines, 'line_cost'));
                    ?>

                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                        <div>
                            <span class="text-muted small">PO: #<?= (int) $selectedPo['po_id'] ?></span>
                            <span class="text-muted small ms-2">Supplier: <?= htmlspecialchars($selectedPo['supplier_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <a href="goods_receipt.php" class="btn btn-outline-secondary btn-sm">&larr; Choose another order</a>
                    </div>

                    <?php if (empty($selectedPo['can_receive'])): ?>
                        <div class="alert alert-warning">
                            This order is in "<?= htmlspecialchars($selectedPo['status'], ENT_QUOTES, 'UTF-8') ?>" status - only "Approved" orders can be received.
                        </div>
                    <?php else: ?>
                        <form method="post">
                            <input type="hidden" name="action" value="receive_order">
                            <input type="hidden" name="po_id" value="<?= (int) $selectedPo['po_id'] ?>">

                            <div class="panel-card mb-3">
                                <div class="panel-card-header">
                                    <h3 class="panel-card-title">Verify Received Quantities</h3>
                                    <div style="min-width: 220px;">
                                        <label class="form-label small mb-1">Receiving Warehouse</label>
                                        <select name="warehouse_id" class="form-select form-select-sm" required>
                                            <option value="">-- Select warehouse --</option>
                                            <?php foreach ($warehouses as $wh): ?>
                                                <option value="<?= (int) $wh['warehouse_id'] ?>"><?= htmlspecialchars($wh['warehouse_name'], ENT_QUOTES, 'UTF-8') ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="table-responsive">
                                    <table class="table data-table align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>SKU</th>
                                                <th>Product</th>
                                                <th class="text-end">Expected</th>
                                                <th class="text-end">Received</th>
                                                <th>Discrepancy Reason (required if different)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($lines as $i => $line): ?>
                                                <tr>
                                                    <td class="text-muted"><?= htmlspecialchars($line['sku_code'], ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td class="fw-semibold"><?= htmlspecialchars($line['product_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td class="text-end"><?= number_format((int) $line['approved_qty']) ?></td>
                                                    <td class="text-end">
                                                        <input type="hidden" name="po_detail_id[<?= $i ?>]" value="<?= (int) $line['po_detail_id'] ?>">
                                                        <input type="hidden" name="product_id[<?= $i ?>]" value="<?= (int) $line['product_id'] ?>">
                                                        <input type="number" min="0" step="1" name="received_qty[<?= $i ?>]"
                                                               class="form-control form-control-sm gr-input ms-auto"
                                                               value="<?= (int) $line['approved_qty'] ?>"
                                                               data-expected="<?= (int) $line['approved_qty'] ?>"
                                                               onchange="window.grToggleReason(this)">
                                                    </td>
                                                    <td>
                                                        <input type="text" name="discrepancy_reason[<?= $i ?>]"
                                                               class="form-control form-control-sm gr-reason-input"
                                                               placeholder="E.g.: damaged box, short shipment...">
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-success">Confirm & Complete Receipt</button>
                        </form>
                    <?php endif; ?>

                    <div class="row g-3 mt-1">
                        <div class="col-6 col-xl-3">
                            <div class="kpi-card">
                                <span class="kpi-label">Product Lines</span>
                                <span class="kpi-value"><?= count($lines) ?></span>
                            </div>
                        </div>
                        <div class="col-6 col-xl-3">
                            <div class="kpi-card">
                                <span class="kpi-label">Order Value (approved)</span>
                                <span class="kpi-value">&#8363;<?= number_format($totalExpectedValue, 0) ?></span>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Chỉ hỗ trợ UX (viền đỏ nhắc nhập lý do) - validate THẬT nằm ở server (BR-10).
        window.grToggleReason = function (input) {
            const expected = Number(input.dataset.expected);
            const received = Number(input.value);
            const reasonInput = input.closest('tr').querySelector('input[name^="discrepancy_reason"]');
            if (received !== expected) {
                reasonInput.classList.add('is-invalid');
                input.classList.add('gr-variance-neg');
            } else {
                reasonInput.classList.remove('is-invalid');
                input.classList.remove('gr-variance-neg');
            }
        };
    </script>
    <?php require __DIR__ . '/../../components/footer.php'; ?>