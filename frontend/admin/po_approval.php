<?php
/**
 * File: frontend/admin/po_approval.php
 * Purpose: "Approval Queue" - danh sách PO đang chờ duyệt, xem chi tiết và
 * duyệt/từ chối. Layout theo mockup (bảng trái + panel chi tiết phải khi
 * chọn 1 dòng), điều chỉnh cho khớp dữ liệu thật:
 *   - Bỏ cột "Store" (schema không có khái niệm nhiều chi nhánh/store riêng).
 *   - "Approval Timeline" rút gọn còn 2 mốc thật có trong DB (Manager tạo ->
 *     Admin duyệt), không có bước "Store Manager Review" vì không tồn tại
 *     trong quy trình thật (BR-07: Manager tạo -> Admin duyệt, chỉ 2 bước).
 * Related: FR-ADM-06, BR-07, BR-20
 * Calls: AdminService::listPendingApprovals(), approvePurchaseOrder(),
 *        rejectPurchaseOrder()
 */

declare(strict_types=1);

require_once __DIR__ . '/../../backend/config/app_config.php';
require_once __DIR__ . '/../../backend/config/database.php';
require_once __DIR__ . '/../../backend/core/Logger.php';
require_once __DIR__ . '/../../backend/core/Auth.php';
require_once __DIR__ . '/../../backend/core/Middleware.php';
require_once __DIR__ . '/../../backend/services/AdminService.php';

Middleware::guard([ROLE_ADMIN]);

$adminService = new AdminService();
$actorId = Auth::id();

$flashMessage = '';
$flashIsError = false;

// =========================================================================
// XỬ LÝ FORM SUBMIT (PRG pattern)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $poId   = (int) ($_POST['po_id'] ?? 0);

    if ($action === 'approve') {
        $result = $adminService->approvePurchaseOrder($poId, $actorId);
        header('Location: po_approval.php?flash=' . urlencode($result['message']) . '&err=' . ($result['success'] ? '0' : '1'));
        exit;
    }

    if ($action === 'reject') {
        $reason = (string) ($_POST['reason'] ?? '');
        $result = $adminService->rejectPurchaseOrder($poId, $reason, $actorId);
        header('Location: po_approval.php?flash=' . urlencode($result['message']) . '&err=' . ($result['success'] ? '0' : '1'));
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
$pendingOrders = $adminService->listPendingApprovals();

// Panel chi tiết mở sẵn cho 1 đơn nếu có ?po_id= khớp danh sách đang chờ
// duyệt (giống mockup: click 1 dòng -> panel phải hiện chi tiết đơn đó).
$selectedPoId = isset($_GET['po_id']) ? (int) $_GET['po_id'] : null;
$selectedPo = null;
if ($selectedPoId !== null) {
    foreach ($pendingOrders as $po) {
        if ((int) $po['po_id'] === $selectedPoId) {
            $selectedPo = $po;
            break;
        }
    }
}
// Mặc định: chưa chọn gì thì hiện chi tiết đơn ĐẦU TIÊN trong danh sách
// (khớp mockup - panel phải luôn có nội dung, không để trống khi có đơn chờ).
if ($selectedPo === null && !empty($pendingOrders)) {
    $selectedPo = $pendingOrders[0];
    $selectedPoId = (int) $selectedPo['po_id'];
}

$pageTitle   = 'Approval Queue';
$breadcrumbs = ['Admin', 'Approvals'];
$activeMenu  = 'approvals';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approval Queue - InventoryDSS</title>
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
                    <h2 class="page-heading mb-1">Approval Queue</h2>
                    <p class="page-subheading mb-0">Purchase orders awaiting approval - Admin reviews details then Approves or Rejects (BR-07).</p>
                </div>

                <?php if ($flashMessage !== ''): ?>
                    <div class="alert <?= $flashIsError ? 'alert-danger' : 'alert-success' ?> py-2 px-3" style="font-size: .87rem;">
                        <?= htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>

                <?php if (empty($pendingOrders)): ?>
                    <div class="panel-card">
                        <div class="empty-state">No purchase orders are currently pending approval.</div>
                    </div>
                <?php else: ?>
                    <div class="row g-3">

                        <!-- ================= CỘT TRÁI: DANH SÁCH ================= -->
                        <div class="col-12 col-xl-7">
                            <div class="panel-card">
                                <div class="panel-card-header">
                                    <h3 class="panel-card-title">Pending Approval</h3>
                                    <span class="panel-card-note"><?= count($pendingOrders) ?> orders</span>
                                </div>

                                <div class="table-responsive">
                                    <table class="table data-table align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>PO Number</th>
                                                <th>Supplier</th>
                                                <th>Created By</th>
                                                <th>Submitted Date</th>
                                                <th class="text-end">Amount</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($pendingOrders as $po): ?>
                                                <?php $isActive = (int) $po['po_id'] === $selectedPoId; ?>
                                                <tr style="<?= $isActive ? 'background: var(--surface-border-soft);' : '' ?> cursor: pointer;"
                                                    onclick="window.location.href='po_approval.php?po_id=<?= (int) $po['po_id'] ?>'">
                                                    <td class="fw-semibold" style="color: var(--brand-primary);">#PO-<?= (int) $po['po_id'] ?></td>
                                                    <td><?= htmlspecialchars($po['supplier_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td class="text-muted"><?= htmlspecialchars($po['created_by_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td class="text-muted small"><?= htmlspecialchars(date('d/m/Y H:i', strtotime($po['created_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td class="text-end fw-semibold"><?= number_format((float) $po['total_amount']) ?> đ</td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- ================= CỘT PHẢI: CHI TIẾT PO ================= -->
                        <div class="col-12 col-xl-5">
                            <?php if ($selectedPo === null): ?>
                                <div class="panel-card">
                                    <div class="empty-state">Select an order from the list on the left to view details.</div>
                                </div>
                            <?php else: ?>
                                <?php
                                    $totalAmount = 0;
                                    foreach ($selectedPo['details'] as $line) {
                                        $totalAmount += (float) $line['line_cost'];
                                    }
                                ?>
                                <div class="panel-card">
                                    <div class="d-flex justify-content-between align-items-start mb-3">
                                        <h3 class="panel-card-title mb-0">PO Details</h3>
                                    </div>

                                    <div class="d-flex flex-column gap-1 mb-3" style="font-size: .87rem;">
                                        <div class="d-flex justify-content-between">
                                            <span class="text-muted">PO Number</span>
                                            <span class="fw-semibold">#PO-<?= (int) $selectedPo['po_id'] ?></span>
                                        </div>
                                        <div class="d-flex justify-content-between">
                                            <span class="text-muted">Supplier</span>
                                            <span class="fw-semibold"><?= htmlspecialchars($selectedPo['supplier_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                    </div>

                                    <div class="text-uppercase text-muted small fw-semibold mb-2" style="letter-spacing: .04em;">Items Summary</div>
                                    <div class="d-flex flex-column gap-2 mb-3">
                                        <?php foreach ($selectedPo['details'] as $line): ?>
                                            <div class="d-flex justify-content-between align-items-start py-2" style="border-bottom: 1px solid var(--surface-border-soft);">
                                                <div>
                                                    <div class="fw-semibold"><?= htmlspecialchars($line['product_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                                    <div class="text-muted small">Unit price: <?= number_format((float) $line['unit_cost']) ?> đ &middot; Qty: <?= number_format((int) $line['approved_qty']) ?></div>
                                                </div>
                                                <div class="fw-semibold text-end"><?= number_format((float) $line['line_cost']) ?> đ</div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>

                                    <div class="d-flex justify-content-between align-items-center mb-3 pt-2" style="border-top: 1px solid var(--surface-border);">
                                        <span class="fw-semibold">Total Amount</span>
                                        <span class="fw-bold fs-5" style="color: var(--brand-primary);"><?= number_format($totalAmount) ?> đ</span>
                                    </div>

                                    <div class="text-uppercase text-muted small fw-semibold mb-2" style="letter-spacing: .04em;">Approval Timeline</div>
                                    <div class="d-flex flex-column gap-2 mb-3" style="font-size: .84rem;">
                                        <div class="d-flex gap-2 align-items-start">
                                            <span style="width:8px; height:8px; border-radius:999px; margin-top:5px; background: var(--color-success); flex-shrink:0;"></span>
                                            <div>
                                                <div class="fw-semibold">Manager Submitted</div>
                                                <div class="text-muted"><?= htmlspecialchars(date('d/m/Y H:i', strtotime($selectedPo['created_at'])), ENT_QUOTES, 'UTF-8') ?> &middot; <?= htmlspecialchars($selectedPo['created_by_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                            </div>
                                        </div>
                                        <div class="d-flex gap-2 align-items-start">
                                            <span style="width:8px; height:8px; border-radius:999px; margin-top:5px; background: var(--surface-border); flex-shrink:0;"></span>
                                            <div>
                                                <div class="fw-semibold text-muted">Admin Approval</div>
                                                <div class="text-muted">Pending your action</div>
                                            </div>
                                        </div>
                                    </div>

                                    <form method="POST" id="rejectReasonForm">
                                        <input type="hidden" name="po_id" value="<?= (int) $selectedPo['po_id'] ?>">
                                        <label class="text-uppercase text-muted small fw-semibold mb-1" style="letter-spacing: .04em;">Reason for Rejection (optional)</label>
                                        <textarea name="reason" class="form-control mb-3" rows="2" placeholder="Provide context if rejecting this order..."></textarea>

                                        <div class="d-flex gap-2">
                                            <button type="submit" name="action" value="reject" class="btn btn-danger btn-sm flex-fill"
                                                    onclick="return confirm('Reject PO #PO-<?= (int) $selectedPo['po_id'] ?>?');">
                                                &times; Reject
                                            </button>
                                            <button type="submit" name="action" value="approve" class="btn btn-success btn-sm flex-fill"
                                                    onclick="return confirm('Approve PO #PO-<?= (int) $selectedPo['po_id'] ?>? The order will be considered sent to the supplier (BR-07).');">
                                                &check; Approve Order
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>

                    </div>
                <?php endif; ?>

            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <?php require __DIR__ . '/../components/footer.php'; ?>