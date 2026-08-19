<?php
/**
 * File: frontend/manager/purchase_order/po_submit.php
 * Purpose: UI xem chi tiết PO Draft, sửa approved_qty từng dòng (BR-06), rồi
 * submit cho Admin duyệt (Draft -> Pending).
 * Related: FR-MGR-03, FR-MGR-04, FR-MGR-05, BR-06, BR-07, BR-20
 * Calls: ManagerService::getPurchaseOrderDetail(), overridePoLineQuantity(),
 *        submitPurchaseOrder(), listMyPurchaseOrders()
 *
 * LUỒNG TRANG:
 *   - Không có ?po_id -> danh sách toàn bộ PO Draft của Manager hiện tại,
 *     chọn 1 đơn để vào sửa/submit (đơn đến từ reorder_suggestions.php cũng
 *     redirect thẳng vào đây kèm po_id).
 *   - Có ?po_id -> chi tiết đơn, Manager sửa approved_qty từng dòng (BR-06:
 *     mỗi lần sửa tự lưu ngay, không cần đợi submit cả đơn), rồi bấm "Gửi
 *     Admin duyệt" để chuyển Draft -> Pending (BR-07: sau bước này đơn coi
 *     như đã khóa, không sửa được nữa - BR-20).
 *
 * Style/layout đồng bộ frontend/admin/*.php.
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
$actorId = Auth::id();

$flashMessage = '';
$flashIsError = false;

// =========================================================================
// XỬ LÝ FORM SUBMIT
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $poId   = (int) ($_POST['po_id'] ?? 0);

    if ($action === 'update_line') {
        $poDetailId = (int) ($_POST['po_detail_id'] ?? 0);
        $newQty     = (int) ($_POST['new_qty'] ?? 0);
        $result = $managerService->overridePoLineQuantity($poId, $poDetailId, $newQty, $actorId);
        header('Location: po_submit.php?po_id=' . $poId . '&flash=' . urlencode($result['message']) . '&err=' . ($result['success'] ? '0' : '1'));
        exit;
    }

    if ($action === 'submit_po') {
        $result = $managerService->submitPurchaseOrder($poId);
        if ($result['success']) {
            // BR-20: sau khi submit, đơn bị khóa - quay về danh sách thay vì ở
            // lại trang chi tiết (trang chi tiết sẽ tự ẩn form sửa nếu status
            // != Draft, nhưng quay về danh sách rõ ràng hơn cho UX).
            header('Location: po_submit.php?flash=' . urlencode($result['message']));
            exit;
        }
        header('Location: po_submit.php?po_id=' . $poId . '&flash=' . urlencode($result['message']) . '&err=1');
        exit;
    }
}

if (isset($_GET['flash'])) {
    $flashMessage = (string) $_GET['flash'];
    $flashIsError = ($_GET['err'] ?? '0') === '1';
}

// =========================================================================
// DỮ LIỆU HIỂN THỊ
// =========================================================================
$selectedPoId = isset($_GET['po_id']) ? (int) $_GET['po_id'] : null;
$selectedPo = null;

if ($selectedPoId !== null) {
    $selectedPo = $managerService->getPurchaseOrderDetail($selectedPoId);
    if ($selectedPo === false) {
        $flashMessage = 'Purchase order not found.';
        $flashIsError = true;
        $selectedPoId = null;
    }
}

// Trang này CHỈ để soạn/nộp PO - chỉ hiện các đơn còn 'Draft' (cần Manager xử
// lý tiếp: sửa số lượng rồi nộp Admin duyệt). Xem tổng trạng thái TẤT CẢ đơn
// (Pending/Approved/Rejected/Delivered...) -> po-status.php riêng.
$myOrders = $selectedPoId === null ? $managerService->listMyPurchaseOrders($actorId, 'Draft') : [];

/** Màu badge theo trạng thái PO - khớp inline style đã dùng ở admin/po_approval.php. */
function poStatusBadgeStyle(string $status): string
{
    return match ($status) {
        'Draft'     => 'background: var(--color-info-bg); color: var(--color-info);',
        'Pending'   => 'background: var(--color-warning-bg); color: var(--color-warning);',
        'Approved'  => 'background: var(--color-success-bg); color: var(--color-success);',
        'Rejected'  => 'background: var(--color-danger-bg); color: var(--color-danger);',
        'Delivered' => 'background: var(--color-success-bg); color: var(--color-success);',
        default     => 'background: var(--surface-alt); color: var(--text-muted);',
    };
}

/**
 * Workflow stepper của 1 PO theo đúng state machine thật (Order::EDITABLE_STATUSES,
 * submitForApproval/approve/reject/markDelivered) - KHÔNG bịa thêm bước không tồn tại
 * trong schema (vd. "AI Suggestion"/"Review" tách rời - ở đây gộp vào 'Draft' vì đó là
 * lúc Manager sửa approved_qty qua updateLineQuantity()).
 *
 * @return array<int, array{key:string,label:string}>
 */
function poWorkflowSteps(): array
{
    return [
        ['key' => 'Draft',     'label' => 'Draft & Review'],
        ['key' => 'Pending',   'label' => 'Pending Approval'],
        ['key' => 'Approved',  'label' => 'Approved'],
        ['key' => 'Delivered', 'label' => 'Delivered'],
    ];
}

/** Vẽ donut chart SVG từ danh sách segment - dùng chung công thức với admin/dashboard.php::renderDonutChart(). */
function renderPoDonutChart(array $segments): array
{
    $palette = ['#1e3a5f', '#c2410c', '#3b82f6', '#166534', '#92400e', '#9333ea'];

    $radius = 70;
    $circumference = 2 * M_PI * $radius;
    $cx = 90;
    $cy = 90;
    $strokeWidth = 26;

    $segmentsWithColor = [];
    $offset = 0;
    $circles = '';

    foreach ($segments as $i => $seg) {
        $color = $palette[$i % count($palette)];
        $pct = (float) $seg['percentage'];
        $dash = ($pct / 100) * $circumference;
        $gap = $circumference - $dash;

        $circles .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $radius . '" '
                  . 'fill="none" stroke="' . $color . '" stroke-width="' . $strokeWidth . '" '
                  . 'stroke-dasharray="' . round($dash, 2) . ' ' . round($gap, 2) . '" '
                  . 'stroke-dashoffset="' . round(-$offset, 2) . '" '
                  . 'transform="rotate(-90 ' . $cx . ' ' . $cy . ')" />';

        $offset += $dash;
        $segmentsWithColor[] = array_merge($seg, ['color' => $color]);
    }

    return ['svg' => '<svg viewBox="0 0 180 180">' . $circles . '</svg>', 'segments_with_color' => $segmentsWithColor];
}

// Dữ liệu bổ sung cho phần header giống mockup (Supplier Details / Estimated
// Total / Item Breakdown) - chỉ tính khi đang xem chi tiết 1 PO.
$supplierPerf = null;
$poDonut = null;
$poTotalAmount = 0;

if ($selectedPo !== null) {
    $supplierPerf = $managerService->getSupplierPerformance((int) $selectedPo['supplier_id']);

    // Item Breakdown: gom line_cost theo category_name THẬT của sản phẩm
    // (bảng categories/products đã có sẵn category_id) - không hardcode
    // "Beverage/Snacks/Other" như mockup gốc vì category thực tế của hệ
    // thống này khác (FMCG/Fresh_Food/Imported_Korean).
    $categoryTotals = [];
    foreach ($selectedPo['details'] as $line) {
        $cat = $line['category_name'] ?? 'Other';
        $categoryTotals[$cat] = ($categoryTotals[$cat] ?? 0) + (float) $line['line_cost'];
        $poTotalAmount += (float) $line['line_cost'];
    }
    arsort($categoryTotals);

    $segments = [];
    foreach ($categoryTotals as $catName => $amount) {
        $segments[] = [
            'category_name' => $catName,
            'amount'        => $amount,
            'percentage'    => $poTotalAmount > 0 ? round($amount / $poTotalAmount * 100, 1) : 0,
        ];
    }
    $poDonut = renderPoDonutChart($segments);
}

$pageTitle   = 'Purchase Orders';
$breadcrumbs = ['Manager', 'Purchase Order'];
$activeMenu  = 'po';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Orders - InventoryDSS</title>
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

                <?php if ($flashMessage !== ''): ?>
                    <div class="alert <?= $flashIsError ? 'alert-danger' : 'alert-success' ?> py-2 px-3" style="font-size: .87rem;">
                        <?= htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>

                <?php if ($selectedPo === null): ?>

                    <!-- ================= DANH SÁCH PO CẦN XỬ LÝ (DRAFT) ================= -->
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
                        <div>
                            <h2 class="page-heading mb-1">Purchase Orders</h2>
                            <p class="page-subheading mb-0">
                                Draft orders to process - edit quantities (BR-06) then submit for Admin approval.
                                View the overall status of all orders at <a href="po-status.php">PO Status</a>.
                            </p>
                        </div>
                        <a href="po_create.php" class="btn btn-brand btn-sm">+ Create Manual PO</a>
                    </div>

                    <div class="panel-card">
                        <div class="panel-card-header">
                            <h3 class="panel-card-title">My Draft Orders</h3>
                            <span class="panel-card-note"><?= count($myOrders) ?> orders</span>
                        </div>

                        <?php if (empty($myOrders)): ?>
                            <div class="empty-state">No draft orders to process. Go to <a href="../inventory/reorder_suggestions.php">Reorder Suggestions</a> to create an order from suggestions, <a href="po_create.php">create a manual PO</a>, or view <a href="po-status.php">PO Status</a> for submitted orders.</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table data-table align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>PO Number</th>
                                            <th>Supplier</th>
                                            <th>Status</th>
                                            <th class="text-end">Total Amount</th>
                                            <th>Created Date</th>
                                            <th class="text-end">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($myOrders as $po): ?>
                                            <tr>
                                                <td class="fw-semibold">#<?= (int) $po['po_id'] ?></td>
                                                <td><?= htmlspecialchars($po['supplier_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                                <td><span class="stock-pill" style="<?= poStatusBadgeStyle($po['status']) ?>"><?= htmlspecialchars($po['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                                <td class="text-end"><?= number_format((float) $po['total_amount']) ?> đ</td>
                                                <td class="text-muted small"><?= htmlspecialchars(date('d/m/Y', strtotime($po['created_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                                                <td class="text-end">
                                                    <a href="po_submit.php?po_id=<?= (int) $po['po_id'] ?>" class="btn btn-outline-secondary btn-sm">View Details</a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                <?php else: ?>

                    <!-- ================= CHI TIẾT PO ================= -->
                    <?php $isDraft = $selectedPo['status'] === 'Draft'; ?>

                    <a href="po_submit.php" class="btn btn-outline-secondary btn-sm mb-3">&larr; Back to List</a>

                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
                        <div>
                            <h2 class="page-heading mb-1">
                                PO #<?= (int) $selectedPo['po_id'] ?>
                                <span class="stock-pill ms-2" style="<?= poStatusBadgeStyle($selectedPo['status']) ?>"><?= htmlspecialchars($selectedPo['status'], ENT_QUOTES, 'UTF-8') ?></span>
                            </h2>
                            <p class="page-subheading mb-0">
                                Supplier: <strong><?= htmlspecialchars($selectedPo['supplier_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                &middot; Created by <?= htmlspecialchars($selectedPo['created_by_name'], ENT_QUOTES, 'UTF-8') ?>
                                &middot; <?= htmlspecialchars(date('d/m/Y H:i', strtotime($selectedPo['created_at'])), ENT_QUOTES, 'UTF-8') ?>
                            </p>
                        </div>

                        <?php if ($isDraft): ?>
                            <form method="POST" onsubmit="return confirm('Submit PO #<?= (int) $selectedPo['po_id'] ?> for Admin approval? Once submitted, the order will be locked and cannot be edited (BR-20).');">
                                <input type="hidden" name="action" value="submit_po">
                                <input type="hidden" name="po_id" value="<?= (int) $selectedPo['po_id'] ?>">
                                <button type="submit" class="btn btn-brand btn-sm">Submit for Admin Approval</button>
                            </form>
                        <?php endif; ?>
                    </div>

                    <?php if (!$isDraft): ?>
                        <div class="alert alert-info py-2 px-3 mb-3" style="font-size: .82rem;">
                            This order is in <strong><?= htmlspecialchars($selectedPo['status'], ENT_QUOTES, 'UTF-8') ?></strong> status - quantities cannot be edited (BR-20: only Draft orders can be edited).
                        </div>
                    <?php endif; ?>

                    <!-- ===== Workflow stepper: Draft -> Pending -> Approved -> Delivered ===== -->
                    <div class="panel-card mb-4">
                        <div class="po-detail-steps">
                            <?php
                            $steps = poWorkflowSteps();
                            $currentIdx = array_search($selectedPo['status'], array_column($steps, 'key'), true);
                            $currentIdx = $currentIdx === false ? -1 : $currentIdx;
                            // Rejected: hiển thị dừng ngay sau Pending, không tô các bước sau như đã hoàn tất.
                            $isRejected = $selectedPo['status'] === 'Rejected';
                            ?>
                            <?php foreach ($steps as $i => $step): ?>
                                <?php
                                $state = 'upcoming';
                                if (!$isRejected && $i < $currentIdx) { $state = 'done'; }
                                elseif (!$isRejected && $i === $currentIdx) { $state = 'active'; }
                                elseif ($isRejected && $step['key'] === 'Draft') { $state = 'done'; }
                                elseif ($isRejected && $step['key'] === 'Pending') { $state = 'rejected'; }
                                ?>
                                <div class="po-detail-step po-detail-step-<?= $state ?>">
                                    <span class="po-detail-step-dot"></span>
                                    <span class="po-detail-step-label"><?= htmlspecialchars($step['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                                <?php if ($i < count($steps) - 1): ?><span class="po-detail-step-line"></span><?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- ===== Supplier Details / Estimated Total / Item Breakdown ===== -->
                    <div class="row g-3 mb-4">
                        <div class="col-lg-4">
                            <div class="panel-card h-100">
                                <div class="panel-card-header">
                                    <h3 class="panel-card-title">Supplier Details</h3>
                                </div>
                                <div class="fw-semibold mb-1"><?= htmlspecialchars($selectedPo['supplier_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="text-muted small mb-3">Supplier ID: <?= (int) $selectedPo['supplier_id'] ?></div>

                                <?php if ($supplierPerf !== false && $supplierPerf !== null): ?>
                                    <div class="d-flex justify-content-between py-1" style="font-size:.87rem;">
                                        <span class="text-muted">Avg. Lead Time</span>
                                        <span class="fw-semibold"><?= number_format((float) $supplierPerf['avg_lead_time_days'], 1) ?> days</span>
                                    </div>
                                    <div class="d-flex justify-content-between py-1" style="font-size:.87rem;">
                                        <span class="text-muted">Orders Delivered</span>
                                        <span class="fw-semibold"><?= number_format((int) $supplierPerf['total_delivered_orders']) ?></span>
                                    </div>
                                    <div class="d-flex justify-content-between py-1" style="font-size:.87rem;">
                                        <span class="text-muted">Discrepancy Rate</span>
                                        <span class="fw-semibold"><?= $supplierPerf['discrepancy_rate_percent'] !== null ? number_format((float) $supplierPerf['discrepancy_rate_percent'], 1) . '%' : 'No data yet' ?></span>
                                    </div>
                                <?php else: ?>
                                    <div class="text-muted small">No 'Delivered' orders yet to evaluate supplier performance.</div>
                                <?php endif; ?>

                                <?php $supplierPhone = $supplierPerf['contact_phone'] ?? null; ?>
                                <div class="mt-3 pt-3" style="border-top:1px solid var(--surface-border-soft);">
                                    <?php if (!empty($supplierPhone)): ?>
                                        <a href="tel:<?= htmlspecialchars($supplierPhone, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary btn-sm w-100">Contact Supplier: <?= htmlspecialchars($supplierPhone, ENT_QUOTES, 'UTF-8') ?></a>
                                    <?php else: ?>
                                        <span class="text-muted small">No contact phone number available.</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-4">
                            <div class="panel-card h-100">
                                <div class="panel-card-header">
                                    <h3 class="panel-card-title">Estimated PO Total</h3>
                                </div>
                                <div class="fw-bold" style="font-size:1.9rem; color: var(--brand-primary);"><?= number_format($poTotalAmount) ?> đ</div>
                                <p class="text-muted small mb-0 mt-2">Total purchase value (approved_qty &times; unit_cost) of <?= count($selectedPo['details']) ?> product lines in this order.</p>
                            </div>
                        </div>

                        <div class="col-lg-4">
                            <div class="panel-card h-100">
                                <div class="panel-card-header">
                                    <h3 class="panel-card-title">Item Breakdown</h3>
                                </div>
                                <?php if (!empty($poDonut['segments_with_color'])): ?>
                                    <div class="product-mix-wrap">
                                        <div class="product-mix-donut">
                                            <?= $poDonut['svg'] ?>
                                            <div class="product-mix-center">
                                                <span class="product-mix-center-value"><?= count($selectedPo['details']) ?></span>
                                                <span class="product-mix-center-label">SKUs</span>
                                            </div>
                                        </div>
                                        <div class="product-mix-legend">
                                            <?php foreach ($poDonut['segments_with_color'] as $seg): ?>
                                                <div class="product-mix-legend-item">
                                                    <span class="product-mix-legend-left">
                                                        <span class="product-mix-dot" style="background: <?= htmlspecialchars($seg['color'], ENT_QUOTES, 'UTF-8') ?>;"></span>
                                                        <?= htmlspecialchars($seg['category_name'], ENT_QUOTES, 'UTF-8') ?>
                                                    </span>
                                                    <span class="product-mix-legend-pct"><?= number_format($seg['percentage'], 1) ?>%</span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="empty-state">No product data yet.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="panel-card">
                        <div class="panel-card-header">
                            <h3 class="panel-card-title">Product Details</h3>
                        </div>

                        <div class="table-responsive">
                            <table class="table data-table align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th class="text-end">Suggested Qty</th>
                                        <th class="text-end">Ordered Qty (BR-06)</th>
                                        <th class="text-end">Unit Price</th>
                                        <th class="text-end">Line Total</th>
                                        <?php if ($isDraft): ?><th class="text-end">Actions</th><?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $totalAmount = 0; ?>
                                    <?php foreach ($selectedPo['details'] as $line): ?>
                                        <?php $totalAmount += (float) $line['line_cost']; ?>
                                        <tr>
                                            <td>
                                                <span class="fw-semibold"><?= htmlspecialchars($line['product_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                                <div class="text-muted small"><?= htmlspecialchars($line['sku_code'], ENT_QUOTES, 'UTF-8') ?></div>
                                            </td>
                                            <td class="text-end text-muted"><?= number_format((int) $line['suggested_qty']) ?></td>
                                            <td class="text-end fw-semibold"><?= number_format((int) $line['approved_qty']) ?></td>
                                            <td class="text-end"><?= number_format((float) $line['unit_cost']) ?> đ</td>
                                            <td class="text-end"><?= number_format((float) $line['line_cost']) ?> đ</td>
                                            <?php if ($isDraft): ?>
                                                <td class="text-end">
                                                    <button type="button" class="btn btn-outline-secondary btn-sm"
                                                            data-bs-toggle="modal" data-bs-target="#editLineModal"
                                                            data-po-detail-id="<?= (int) $line['po_detail_id'] ?>"
                                                            data-product-name="<?= htmlspecialchars($line['product_name'], ENT_QUOTES, 'UTF-8') ?>"
                                                            data-current-qty="<?= (int) $line['approved_qty'] ?>">
                                                        Edit Qty
                                                    </button>
                                                </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="4" class="text-end fw-semibold">Order Total</td>
                                        <td class="text-end fw-bold"><?= number_format($totalAmount) ?> đ</td>
                                        <?php if ($isDraft): ?><td></td><?php endif; ?>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                <?php endif; ?>

            </main>
        </div>
    </div>

    <?php if ($selectedPo !== null && $selectedPo['status'] === 'Draft'): ?>
    <!-- Modal: Sửa số lượng 1 dòng (BR-06) -->
    <div class="modal fade" id="editLineModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <form method="POST" class="modal-content">
                <input type="hidden" name="action" value="update_line">
                <input type="hidden" name="po_id" value="<?= (int) $selectedPo['po_id'] ?>">
                <input type="hidden" name="po_detail_id" id="editPoDetailId" value="">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Quantity - <span id="editProductName"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label small">New Order Quantity</label>
                    <input type="number" name="new_qty" id="editNewQty" class="form-control" min="1" required>
                    <div class="form-text">Changes are recorded in the Audit Log (BR-06).</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-brand btn-sm">Save Quantity</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const editLineModal = document.getElementById('editLineModal');
        if (editLineModal) {
            editLineModal.addEventListener('show.bs.modal', function (event) {
                const btn = event.relatedTarget;
                document.getElementById('editPoDetailId').value = btn.getAttribute('data-po-detail-id');
                document.getElementById('editProductName').textContent = btn.getAttribute('data-product-name');
                document.getElementById('editNewQty').value = btn.getAttribute('data-current-qty');
            });
        }
    </script>
    <?php require __DIR__ . '/../../components/footer.php'; ?>