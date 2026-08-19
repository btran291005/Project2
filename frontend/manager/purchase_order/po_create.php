<?php
/**
 * File: frontend/manager/purchase_order/po_create.php
 * Purpose: Tạo Purchase Order THỦ CÔNG - Manager tự chọn nhà cung cấp, tự
 * chọn sản phẩm và tự nhập số lượng, KHÔNG bắt buộc phải xuất phát từ danh
 * sách gợi ý (khác với reorder_suggestions.php - nơi các dòng đã được
 * ReorderService tính sẵn suggested_qty theo BR-05).
 * Related: FR-MGR-04, FR-MGR-05, BR-06, BR-07
 * Calls: ManagerService::listSuppliers(), getProductsBySupplier(),
 *        createPurchaseOrderDraft()
 *
 * LUỒNG TRANG:
 *   1. Bước 1 - Chọn nhà cung cấp: dropdown load qua GET (?supplier_id=...),
 *      không submit POST ở bước này (BR-07: mọi PO chỉ gắn 1 supplier duy
 *      nhất, nên phải chốt NCC trước khi hiện danh sách sản phẩm).
 *   2. Bước 2 - Chọn sản phẩm: sau khi có ?supplier_id, hiện TOÀN BỘ sản
 *      phẩm active thuộc đúng NCC đó (ManagerService::getProductsBySupplier()),
 *      Manager tick chọn dòng cần đặt và tự nhập số lượng (không có giá trị
 *      suggested_qty gợi ý sẵn - mặc định để trống, khác hẳn luồng
 *      reorder_suggestions.php).
 *   3. Submit -> createPurchaseOrderDraft() - dùng ĐÚNG method backend đã có
 *      sẵn cho reorder_suggestions.php (không cần thêm code Service mới cho
 *      bước tạo Draft, vì method đó vốn không phụ thuộc gì vào nguồn gốc
 *      suggested_qty).
 *
 * Style/layout đồng bộ frontend/admin/*.php và frontend/manager/reorder/reorder_suggestions.php.
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
// XỬ LÝ TẠO PO
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_po') {
    $supplierId = (int) ($_POST['supplier_id'] ?? 0);
    $selectedProductIds = $_POST['product_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];

    $lines = [];
    foreach ($selectedProductIds as $idx => $productId) {
        $productId = (int) $productId;
        $qty = (int) ($quantities[$idx] ?? 0);
        // Chỉ đưa vào $lines những dòng Manager THỰC SỰ nhập số lượng > 0 -
        // cho phép tick chọn nhiều sản phẩm nhưng bỏ trống số lượng của dòng
        // không muốn đặt, thay vì bắt buộc XÓA checkbox đó đi.
        if ($productId > 0 && $qty > 0) {
            // createPurchaseOrderDraft() yêu cầu 'suggested_qty' cho mỗi dòng
            // (dùng chung schema với luồng reorder_suggestions.php) - ở đây
            // KHÔNG có gợi ý AI/rule-based nào, nên suggested_qty = chính số
            // lượng Manager tự nhập (đúng bản chất: "được đề xuất bởi chính
            // Manager", không phải hệ thống).
            $lines[] = ['product_id' => $productId, 'suggested_qty' => $qty];
        }
    }

    if ($supplierId <= 0) {
        $result = ['success' => false, 'message' => 'Please select a supplier.'];
    } elseif (empty($lines)) {
        $result = ['success' => false, 'message' => 'Please select at least 1 product and enter a quantity greater than 0.'];
    } else {
        $result = $managerService->createPurchaseOrderDraft($supplierId, $actorId, $lines);
    }

    if ($result['success']) {
        // Tạo Draft thành công -> chuyển sang po_submit.php để Manager xem lại
        // /chỉnh sửa trước khi gửi Admin duyệt, đồng bộ với luồng reorder_suggestions.php.
        header('Location: po_submit.php?po_id=' . urlencode((string) $result['po_id']) . '&flash=' . urlencode($result['message']));
        exit;
    }

    header('Location: po_create.php?supplier_id=' . $supplierId . '&flash=' . urlencode($result['message']) . '&err=1');
    exit;
}

if (isset($_GET['flash'])) {
    $flashMessage = (string) $_GET['flash'];
    $flashIsError = ($_GET['err'] ?? '0') === '1';
}

// =========================================================================
// DỮ LIỆU HIỂN THỊ
// =========================================================================
$suppliers = $managerService->listSuppliers();

$selectedSupplierId = isset($_GET['supplier_id']) && $_GET['supplier_id'] !== '' ? (int) $_GET['supplier_id'] : null;
$supplierProducts = [];
$selectedSupplierName = null;

if ($selectedSupplierId !== null) {
    $supplierProducts = $managerService->getProductsBySupplier($selectedSupplierId);
    foreach ($suppliers as $s) {
        if ((int) $s['supplier_id'] === $selectedSupplierId) {
            $selectedSupplierName = $s['supplier_name'];
            break;
        }
    }
}

$pageTitle   = 'Create Manual PO';
$breadcrumbs = ['Manager', 'Purchase Order', 'Create New'];
$activeMenu  = 'po';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Manual PO - InventoryDSS</title>
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

                <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
                    <div>
                        <h2 class="page-heading mb-1">Create Manual PO</h2>
                        <p class="page-subheading mb-0">
                            Manually choose the supplier, products, and quantities - not dependent on the suggestion list.
                            Want to use Reorder Point suggestions instead? Go to <a href="../inventory/reorder_suggestions.php">Reorder Suggestions</a>.
                        </p>
                    </div>
                    <a href="po_submit.php" class="btn btn-outline-secondary btn-sm">&larr; Back to PO List</a>
                </div>

                <?php if ($flashMessage !== ''): ?>
                    <div class="alert <?= $flashIsError ? 'alert-danger' : 'alert-success' ?> py-2 px-3" style="font-size: .87rem;">
                        <?= htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>

                <!-- Step 1: Select supplier -->
                <div class="panel-card mb-3">
                    <div class="panel-card-header">
                        <h3 class="panel-card-title">Step 1 - Select Supplier</h3>
                    </div>
                    <form method="GET" class="d-flex flex-wrap gap-2 align-items-end">
                        <div style="min-width: 280px;">
                            <label class="form-label small text-muted mb-1">Supplier</label>
                            <select name="supplier_id" class="form-select" onchange="this.form.submit()">
                                <option value="">-- Select supplier --</option>
                                <?php foreach ($suppliers as $s): ?>
                                    <option value="<?= (int) $s['supplier_id'] ?>" <?= $selectedSupplierId === (int) $s['supplier_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($s['supplier_name'], ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <noscript><button type="submit" class="btn btn-outline-secondary btn-sm">Load product list</button></noscript>
                    </form>
                </div>

                <!-- Step 2: Select products + quantities -->
                <?php if ($selectedSupplierId === null): ?>
                    <div class="panel-card">
                        <div class="empty-state">Select a supplier in Step 1 to view the product list.</div>
                    </div>
                <?php elseif (empty($supplierProducts)): ?>
                    <div class="panel-card">
                        <div class="empty-state">Supplier <strong><?= htmlspecialchars($selectedSupplierName ?? '', ENT_QUOTES, 'UTF-8') ?></strong> does not have any active products assigned in the system yet.</div>
                    </div>
                <?php else: ?>
                    <div class="panel-card">
                        <form method="POST" onsubmit="return confirm('Create a draft purchase order for <?= htmlspecialchars(addslashes($selectedSupplierName ?? ''), ENT_QUOTES, 'UTF-8') ?> with the selected lines?');">
                            <input type="hidden" name="action" value="create_po">
                            <input type="hidden" name="supplier_id" value="<?= (int) $selectedSupplierId ?>">

                            <div class="panel-card-header">
                                <h3 class="panel-card-title">
                                    Step 2 - Select products from <?= htmlspecialchars($selectedSupplierName ?? '', ENT_QUOTES, 'UTF-8') ?>
                                </h3>
                                <button type="submit" class="btn btn-brand btn-sm">Create Draft PO</button>
                            </div>

                            <div class="table-responsive">
                                <table class="table data-table align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th style="width: 40px;">
                                                <input type="checkbox" class="form-check-input" id="selectAllProducts">
                                            </th>
                                            <th>Product</th>
                                            <th class="text-end">Current Stock</th>
                                            <th class="text-end">Unit Price</th>
                                            <th class="text-end" style="width: 160px;">Order Quantity</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($supplierProducts as $i => $product): ?>
                                            <tr>
                                                <td>
                                                    <input type="checkbox" class="form-check-input product-checkbox"
                                                           data-qty-input="qty-<?= $i ?>">
                                                    <input type="hidden" name="product_id[<?= $i ?>]" value="<?= (int) $product['product_id'] ?>">
                                                </td>
                                                <td>
                                                    <span class="fw-semibold"><?= htmlspecialchars($product['product_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                                    <div class="text-muted small"><?= htmlspecialchars($product['sku_code'], ENT_QUOTES, 'UTF-8') ?></div>
                                                </td>
                                                <td class="text-end text-muted"><?= number_format((int) $product['current_stock']) ?></td>
                                                <td class="text-end text-muted"><?= number_format((float) $product['unit_cost']) ?> đ</td>
                                                <td class="text-end">
                                                    <input type="number" min="0" class="form-control form-control-sm text-end"
                                                           id="qty-<?= $i ?>" name="quantity[<?= $i ?>]" value="0"
                                                           style="max-width: 120px; margin-left: auto;">
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>

            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Chọn tất cả - tick vào ô đầu bảng thì tick hết checkbox từng dòng.
        const selectAll = document.getElementById('selectAllProducts');
        if (selectAll) {
            selectAll.addEventListener('change', function () {
                document.querySelectorAll('.product-checkbox').forEach(function (cb) {
                    cb.checked = selectAll.checked;
                });
            });
        }

        // Tick checkbox 1 dòng -> nếu số lượng đang là 0, tự đặt mặc định 1 để
        // đỡ phải gõ tay (Manager vẫn có thể sửa lại số khác trước khi submit).
        // Bỏ tick -> KHÔNG tự xóa số lượng đã nhập, để Manager tick lại không
        // mất dữ liệu đã gõ.
        document.querySelectorAll('.product-checkbox').forEach(function (cb) {
            cb.addEventListener('change', function () {
                const qtyInput = document.getElementById(this.getAttribute('data-qty-input'));
                if (this.checked && qtyInput && parseInt(qtyInput.value, 10) === 0) {
                    qtyInput.value = 1;
                }
            });
        });
    </script>
    <?php require __DIR__ . '/../../components/footer.php'; ?>