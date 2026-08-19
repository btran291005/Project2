<?php
/**
 * File: frontend/manager/reorder & forecast/reorder_suggestions.php
 * Purpose: UI xem danh sách gợi ý đặt hàng (BR-05), Manager chọn dòng cần đặt
 * rồi tạo Purchase Order Draft. Gợi ý được GOM THEO NHÀ CUNG CẤP vì mỗi PO chỉ
 * gửi cho đúng 1 supplier (BR-07/Order::createDraft()).
 * Related: FR-MGR-02, FR-MGR-04, FR-MGR-05, BR-05, BR-06
 * Calls: ManagerService::getReorderSuggestions(), getProductSupplierMap(),
 *        createPurchaseOrderDraft()
 *
 * LUỒNG TRANG:
 *   1. Hiển thị toàn bộ gợi ý (từ ReorderService::suggestQuantity(), đã sắp
 *      theo sales_volume giảm dần - BR-13), gom theo supplier_name thành từng
 *      block riêng, mỗi block có checkbox "chọn tất cả".
 *   2. Manager tick chọn dòng muốn đặt trong 1 block (chỉ tạo được PO cho
 *      từng supplier 1 lần - submit form của đúng block đó).
 *   3. Submit -> createPurchaseOrderDraft() với suggested_qty làm approved_qty
 *      ban đầu (Manager sẽ chỉnh sửa số lượng cụ thể ở po_submit.php trước
 *      khi gửi Admin duyệt - đúng luồng BR-06).
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
// XỬ LÝ TẠO PO TỪ CÁC DÒNG ĐÃ CHỌN
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_po') {
    $supplierId = (int) ($_POST['supplier_id'] ?? 0);
    $selectedProductIds = $_POST['product_id'] ?? [];
    $suggestedQtys = $_POST['suggested_qty'] ?? [];

    $lines = [];
    foreach ($selectedProductIds as $idx => $productId) {
        $productId = (int) $productId;
        $qty = (int) ($suggestedQtys[$idx] ?? 0);
        if ($productId > 0 && $qty > 0) {
            $lines[] = ['product_id' => $productId, 'suggested_qty' => $qty];
        }
    }

    if (empty($lines)) {
        $result = ['success' => false, 'message' => 'Vui lòng chọn ít nhất 1 sản phẩm để đặt hàng.'];
    } else {
        $result = $managerService->createPurchaseOrderDraft($supplierId, $actorId, $lines);
    }

    if ($result['success']) {
        // Tạo Draft thành công -> chuyển thẳng sang po_submit.php để Manager
        // chỉnh số lượng cụ thể (BR-06) trước khi gửi Admin duyệt, thay vì
        // quay lại trang này - đúng mạch luồng nghiệp vụ tiếp theo.
        header('Location: ../purchase_order/po_submit.php?po_id=' . urlencode((string) $result['po_id']) . '&flash=' . urlencode($result['message']));
        exit;
    }

    header('Location: reorder_suggestions.php?flash=' . urlencode($result['message']) . '&err=1');
    exit;
}

if (isset($_GET['flash'])) {
    $flashMessage = (string) $_GET['flash'];
    $flashIsError = ($_GET['err'] ?? '0') === '1';
}

// =========================================================================
// DỮ LIỆU HIỂN THỊ
// =========================================================================
$suggestionResult = $managerService->getReorderSuggestions();
$supplierMap = $managerService->getProductSupplierMap();

// Gom gợi ý theo supplier_id - mỗi block là 1 PO tiềm năng.
$groupedBySupplier = [];
if ($suggestionResult['success']) {
    foreach ($suggestionResult['suggestions'] as $item) {
        $productId = (int) $item['product_id'];
        $supplierInfo = $supplierMap[$productId] ?? null;

        // Sản phẩm chưa gán supplier hợp lệ (dữ liệu thiếu) - vẫn hiển thị
        // nhưng gom vào nhóm "Chưa xác định" để Manager biết cần bổ sung dữ
        // liệu master thay vì bị ẩn đi âm thầm.
        $supplierId   = $supplierInfo['supplier_id'] ?? 0;
        $supplierName = $supplierInfo['supplier_name'] ?? 'Chưa xác định nhà cung cấp';

        $groupedBySupplier[$supplierId]['supplier_name'] = $supplierName;
        $groupedBySupplier[$supplierId]['items'][] = $item;
    }
}

$pageTitle   = 'Reorder Suggestions';
$breadcrumbs = ['Manager', 'Reorder', 'Suggestions'];
$activeMenu  = 'reorder';

/**
 * Phân loại mức độ khẩn cấp của 1 dòng gợi ý - dựa TRỰC TIẾP trên
 * current_stock/safety_stock/reorder_point thật (ReorderService::suggestQuantity()
 * đã lọc sẵn chỉ trả về sản phẩm current_stock <= reorder_point, nên ở đây chỉ
 * còn phân biệt 'Critical' (đã thủng safety stock - rủi ro hết hàng trước khi
 * lô mới về) và 'Low' (dưới reorder point nhưng vẫn còn trên safety stock).
 */
function reorderUrgency(array $item): array
{
    if ((int) $item['current_stock'] <= (int) $item['safety_stock']) {
        return ['label' => 'Critical', 'class' => 'stock-pill-critical'];
    }
    return ['label' => 'Low', 'class' => 'stock-pill-warn'];
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reorder Suggestions - InventoryDSS</title>
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
                        <h2 class="page-heading mb-1">Reorder Suggestions</h2>
                        <p class="page-subheading mb-0">
                            Hệ thống đã phân tích tồn kho/doanh số và tạo gợi ý đặt hàng theo Reorder Point &amp; Safety Stock (BR-05).
                            Chọn các dòng cần đặt bên dưới để tạo Purchase Order Draft.
                        </p>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="../purchase_order/po_create.php" class="btn btn-brand btn-sm">+ Tạo PO thủ công</a>
                        <a href="../inventory/stockout_risk.php" class="btn btn-outline-secondary btn-sm">Xem Stock-out Risk</a>
                    </div>
                </div>

                <?php if ($flashMessage !== ''): ?>
                    <div class="alert <?= $flashIsError ? 'alert-danger' : 'alert-success' ?> py-2 px-3" style="font-size: .87rem;">
                        <?= htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>

                <?php if (!$suggestionResult['success']): ?>
                    <div class="panel-card">
                        <div class="empty-state"><?= htmlspecialchars($suggestionResult['message'], ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                <?php elseif (empty($groupedBySupplier)): ?>
                    <div class="panel-card">
                        <div class="empty-state">Hiện không có sản phẩm nào chạm/dưới Reorder Point - chưa cần đặt hàng thêm.</div>
                    </div>
                <?php else: ?>

                    <div class="d-flex flex-column gap-3">
                        <?php foreach ($groupedBySupplier as $supplierId => $group): ?>
                            <?php $formId = 'poForm' . $supplierId; ?>
                            <div class="panel-card">
                                <form method="POST" id="<?= $formId ?>" onsubmit="return confirm('Tạo đơn đặt hàng nháp (Draft) cho <?= htmlspecialchars(addslashes($group['supplier_name']), ENT_QUOTES, 'UTF-8') ?> với các dòng đã chọn?');">
                                    <input type="hidden" name="action" value="create_po">
                                    <input type="hidden" name="supplier_id" value="<?= (int) $supplierId ?>">

                                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                                        <div>
                                            <h3 class="panel-card-title mb-0">
                                                <?= htmlspecialchars($group['supplier_name'], ENT_QUOTES, 'UTF-8') ?>
                                            </h3>
                                            <span class="panel-card-note"><?= count($group['items']) ?> sản phẩm cần đặt</span>
                                        </div>
                                        <button type="submit" class="btn btn-brand btn-sm" <?= $supplierId === 0 ? 'disabled title="Cần cập nhật nhà cung cấp cho sản phẩm trước"' : '' ?>>
                                            Tạo PO Draft cho các dòng đã chọn
                                        </button>
                                    </div>

                                    <div class="table-responsive">
                                        <table class="table data-table align-middle mb-0">
                                            <thead>
                                                <tr>
                                                    <th style="width: 40px;">
                                                        <input type="checkbox" class="form-check-input select-all-checkbox" data-form-id="<?= $formId ?>">
                                                    </th>
                                                    <th>Sản phẩm</th>
                                                    <th>Mức độ</th>
                                                    <th class="text-end">Tồn kho</th>
                                                    <th class="text-end">Reorder Point</th>
                                                    <th class="text-end">Bán TB/ngày (7d)</th>
                                                    <th class="text-end">SL gợi ý</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($group['items'] as $item): ?>
                                                    <?php $urgency = reorderUrgency($item); ?>
                                                    <tr>
                                                        <td>
                                                            <input type="checkbox" class="form-check-input line-checkbox" name="product_id[]" value="<?= (int) $item['product_id'] ?>" form="<?= $formId ?>">
                                                            <input type="hidden" name="suggested_qty[]" value="<?= (int) $item['suggested_qty'] ?>" form="<?= $formId ?>">
                                                        </td>
                                                        <td>
                                                            <span class="fw-semibold"><?= htmlspecialchars($item['product_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                                            <div class="text-muted small"><?= htmlspecialchars($item['sku_code'], ENT_QUOTES, 'UTF-8') ?></div>
                                                        </td>
                                                        <td><span class="stock-pill <?= $urgency['class'] ?>"><?= $urgency['label'] ?></span></td>
                                                        <td class="text-end"><?= number_format((int) $item['current_stock']) ?></td>
                                                        <td class="text-end text-muted"><?= number_format((int) $item['reorder_point']) ?></td>
                                                        <td class="text-end text-muted"><?= number_format((float) $item['avg_daily_sales_7d'], 2) ?></td>
                                                        <td class="text-end fw-semibold"><?= number_format((int) $item['suggested_qty']) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>

                <?php endif; ?>

            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Checkbox "chọn tất cả" cho từng block supplier - chỉ tác động các
        // dòng checkbox thuộc CHÍNH form đó (dùng thuộc tính form="..." nên
        // input nằm ngoài <form> vẫn submit đúng, nhưng cần lọc bằng form id).
        document.querySelectorAll('.select-all-checkbox').forEach(function (selectAll) {
            selectAll.addEventListener('change', function () {
                const formId = this.getAttribute('data-form-id');
                document.querySelectorAll('input.line-checkbox[form="' + formId + '"]').forEach(function (cb) {
                    cb.checked = selectAll.checked;
                });
            });
        });
    </script>
    <?php require __DIR__ . '/../../components/footer.php'; ?>