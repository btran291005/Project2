<?php
/**
 * File: frontend/manager/purchase_order/po-status.php
 * Purpose: Manager xem TỔNG TRẠNG THÁI của TẤT CẢ các PO do chính mình tạo
 * (Draft/Pending/Approved/Rejected/Delivered) - đối lập với po_submit.php
 * (chỉ để soạn/nộp các đơn còn Draft). Đây là trang tra cứu/theo dõi,
 * KHÔNG cho sửa số lượng hay nộp đơn ở đây (BR-20 - chỉ po_submit.php mới
 * có quyền sửa Draft).
 * Related: FR-MGR-06
 * Calls: ManagerService::listMyPurchaseOrders(), getPurchaseOrderDetail()
 *
 * Style/layout đồng bộ frontend/manager/purchase_order/po_submit.php.
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

/** Màu badge theo trạng thái PO - dùng chung công thức với po_submit.php. */
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

$statusOptions = ['Draft', 'Pending', 'Approved', 'Rejected', 'Delivered'];
$filterStatus  = $_GET['status'] ?? '';
$filterStatus  = in_array($filterStatus, $statusOptions, true) ? $filterStatus : null;

$orders = $managerService->listMyPurchaseOrders($actorId, $filterStatus);

// Nếu có ?view=<po_id> -> hiển thị chi tiết dòng sản phẩm của đơn đó (read-only).
$viewingPoId = isset($_GET['view']) ? (int) $_GET['view'] : null;
$viewingPo = $viewingPoId ? $managerService->getPurchaseOrderDetail($viewingPoId) : false;

// Ngăn Manager xem PO của người khác qua URL thủ công (?view=<po_id lạ>).
if ($viewingPo !== false && (int) $viewingPo['created_by'] !== $actorId) {
    $viewingPo = false;
}

$viewingPoTotal = 0;
if ($viewingPo !== false) {
    foreach ($viewingPo['details'] as $line) {
        $viewingPoTotal += (float) $line['line_cost'];
    }
}

$pageTitle   = 'PO Status';
$breadcrumbs = ['Manager', 'PO Status'];
$activeMenu  = 'po_status';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PO Status - InventoryDSS</title>
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

                <div class="mb-4">
                    <h2 class="page-heading mb-1">PO Status</h2>
                    <p class="page-subheading mb-0">
                        Aggregate status of all purchase orders you have created (FR-MGR-06).
                        Need to edit quantities or submit a Draft? Go to <a href="po_submit.php">Purchase Order (PO)</a>.
                    </p>
                </div>

                <form method="get" class="d-flex align-items-center gap-2 mb-3">
                    <label class="form-label small mb-0 text-muted">Filter by status</label>
                    <select name="status" class="form-select form-select-sm" style="width:auto;" onchange="this.form.submit()">
                        <option value="">All</option>
                        <?php foreach ($statusOptions as $status): ?>
                            <option value="<?= $status ?>" <?= $filterStatus === $status ? 'selected' : '' ?>><?= $status ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>

                <div class="row g-3">
                    <div class="col-lg-7">
                        <div class="panel-card">
                            <div class="panel-card-header">
                                <h3 class="panel-card-title">My Orders</h3>
                                <span class="panel-card-note"><?= count($orders) ?> orders</span>
                            </div>

                            <?php if (empty($orders)): ?>
                                <div class="empty-state">No orders match the current filter.</div>
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
                                            <?php foreach ($orders as $po): ?>
                                                <tr class="<?= $viewingPoId === (int) $po['po_id'] ? 'table-active' : '' ?>">
                                                    <td class="fw-semibold">#<?= (int) $po['po_id'] ?></td>
                                                    <td><?= htmlspecialchars($po['supplier_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td><span class="stock-pill" style="<?= poStatusBadgeStyle($po['status']) ?>"><?= htmlspecialchars($po['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                                    <td class="text-end"><?= number_format((float) $po['total_amount']) ?> đ</td>
                                                    <td class="text-muted small"><?= htmlspecialchars(date('d/m/Y', strtotime($po['created_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td class="text-end">
                                                        <a href="?view=<?= (int) $po['po_id'] ?><?= $filterStatus ? '&status=' . urlencode($filterStatus) : '' ?>" class="btn btn-outline-secondary btn-sm">View</a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="col-lg-5">
                        <?php if ($viewingPo === false): ?>
                            <div class="panel-card h-100 d-flex align-items-center justify-content-center">
                                <div class="empty-state mb-0">Select an order from the list on the left to view details.</div>
                            </div>
                        <?php else: ?>
                            <div class="panel-card">
                                <div class="panel-card-header">
                                    <h3 class="panel-card-title">
                                        PO #<?= (int) $viewingPo['po_id'] ?>
                                        <span class="stock-pill ms-2" style="<?= poStatusBadgeStyle($viewingPo['status']) ?>"><?= htmlspecialchars($viewingPo['status'], ENT_QUOTES, 'UTF-8') ?></span>
                                    </h3>
                                </div>

                                <div class="d-flex justify-content-between py-1" style="font-size:.87rem;">
                                    <span class="text-muted">Supplier</span>
                                    <span class="fw-semibold"><?= htmlspecialchars($viewingPo['supplier_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                                <div class="d-flex justify-content-between py-1" style="font-size:.87rem;">
                                    <span class="text-muted">Created Date</span>
                                    <span class="fw-semibold"><?= htmlspecialchars(date('d/m/Y H:i', strtotime($viewingPo['created_at'])), ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                                <?php if (!empty($viewingPo['approved_at'])): ?>
                                    <div class="d-flex justify-content-between py-1" style="font-size:.87rem;">
                                        <span class="text-muted"><?= $viewingPo['status'] === 'Rejected' ? 'Rejected Date' : 'Approved Date' ?></span>
                                        <span class="fw-semibold">
                                            <?= htmlspecialchars(date('d/m/Y H:i', strtotime($viewingPo['approved_at'])), ENT_QUOTES, 'UTF-8') ?>
                                            (<?= htmlspecialchars($viewingPo['approved_by_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?>)
                                        </span>
                                    </div>
                                <?php endif; ?>
                                <div class="d-flex justify-content-between py-1 mb-3" style="font-size:.87rem;">
                                    <span class="text-muted">Total Amount</span>
                                    <span class="fw-bold"><?= number_format($viewingPoTotal) ?> đ</span>
                                </div>

                                <div class="table-responsive">
                                    <table class="table data-table align-middle mb-0" style="font-size:.82rem;">
                                        <thead>
                                            <tr>
                                                <th>Product</th>
                                                <th class="text-end">Suggested Qty</th>
                                                <th class="text-end">Ordered Qty</th>
                                                <th class="text-end">Received</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($viewingPo['details'] as $line): ?>
                                                <tr>
                                                    <td>
                                                        <span class="fw-semibold"><?= htmlspecialchars($line['product_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                                        <div class="text-muted small"><?= htmlspecialchars($line['sku_code'], ENT_QUOTES, 'UTF-8') ?></div>
                                                    </td>
                                                    <td class="text-end text-muted"><?= number_format((int) $line['suggested_qty']) ?></td>
                                                    <td class="text-end fw-semibold"><?= number_format((int) $line['approved_qty']) ?></td>
                                                    <td class="text-end"><?= $line['received_qty'] !== null ? number_format((int) $line['received_qty']) : '—' ?></td>
                                                </tr>
                                                <?php if (!empty($line['discrepancy_reason'])): ?>
                                                    <tr>
                                                        <td colspan="4" class="small" style="color: var(--color-warning); background: var(--color-warning-bg);">
                                                            Discrepancy: <?= htmlspecialchars($line['discrepancy_reason'], ENT_QUOTES, 'UTF-8') ?>
                                                        </td>
                                                    </tr>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <?php if ($viewingPo['status'] === 'Rejected'): ?>
                                    <div class="alert alert-danger py-2 px-3 mt-3 mb-0" style="font-size: .82rem;">
                                        This order was rejected by Admin. Create a new draft order at <a href="../inventory/reorder_suggestions.php">Reorder Suggestions</a> if you still need to place an order (BR-20).
                                    </div>
                                <?php elseif ($viewingPo['status'] === 'Pending'): ?>
                                    <div class="alert alert-warning py-2 px-3 mt-3 mb-0" style="font-size: .82rem;">
                                        Awaiting Admin approval - quantities cannot be edited at this stage (BR-20).
                                    </div>
                                <?php elseif ($viewingPo['status'] === 'Draft'): ?>
                                    <div class="alert alert-info py-2 px-3 mt-3 mb-0" style="font-size: .82rem;">
                                        This order is still a draft - go to <a href="po_submit.php?po_id=<?= (int) $viewingPo['po_id'] ?>">Purchase Order (PO)</a> to edit quantities or submit for Admin approval.
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <?php require __DIR__ . '/../../components/footer.php'; ?>