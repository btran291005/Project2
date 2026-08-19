<?php
/**
 * File: frontend/admin/reorder_rules.php
 * Purpose: UI for configuring reorder rules per category (FR-ADM-04) or per
 * product override (FR-ADM-05 dependency) - min/max/safety-stock/reorder point.
 * Related: FR-ADM-04, FR-ADM-05, BR-16, FR-SYS-03
 * Calls: AdminService::listCategories(), listReorderRules(), listProducts(),
 *        configureReorderRule(), getAuditLogs()
 *
 * Đây là bản đầy đủ thay thế stub Phase 2-3.
 *
 * LƯU Ý DỮ LIỆU: reorder_rules THẬT chỉ áp dụng cho ĐÚNG 1 trong 2: 1 category
 * (category_id, product_id NULL - áp dụng cho mọi sản phẩm thuộc category đó
 * chưa có rule riêng) HOẶC 1 product cụ thể (product_id, category_id NULL -
 * override, có hiệu lực cao hơn rule category theo Product::getEffectiveReorderRule(),
 * BR-05). KHÔNG có khái niệm "1 rule chung cho cả category_type" trực tiếp
 * trong DB - category_type chỉ là 1 cột phân loại trên bảng categories, dùng
 * để GOM NHÓM các category cùng đặc tính (VD nhiều category FMCG khác nhau)
 * lại với nhau trên UI cho dễ nhìn. Do đó trang này group các thẻ theo
 * category_type, nhưng mỗi input vẫn lưu/đọc rule ở đúng cấp category_id thật
 * - không bịa thêm 1 target rule không có trong schema.
 *
 * Style/layout đồng bộ frontend/admin/dashboard.php và accounts.php (header/
 * sidebar/footer component + Bootstrap 5 + panel-card/data-table/stock-pill/
 * activity-list dùng chung).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../backend/config/app_config.php';
require_once __DIR__ . '/../../backend/config/database.php';
require_once __DIR__ . '/../../backend/core/Logger.php';
require_once __DIR__ . '/../../backend/core/Auth.php';
require_once __DIR__ . '/../../backend/core/Middleware.php';
require_once __DIR__ . '/../../backend/services/AdminService.php';

// BR-19 / NFR-03: chỉ Admin được vào trang này, chặn ở tầng server
Middleware::guard([ROLE_ADMIN]);

$adminService = new AdminService();
$actorId = Auth::id();

$flashMessage = '';
$flashIsError = false;

// =========================================================================
// XỬ LÝ POST: save_category_rule / save_product_rule
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    $ruleData = [
        'min_stock'     => $_POST['min_stock'] ?? '',
        'max_stock'     => $_POST['max_stock'] ?? '',
        'safety_stock'  => $_POST['safety_stock'] ?? '',
        'reorder_point' => $_POST['reorder_point'] ?? '',
    ];

    if ($action === 'save_category_rule') {
        $result = $adminService->configureReorderRule(
            ['category_id' => (int) ($_POST['category_id'] ?? 0)],
            $ruleData,
            $actorId
        );
        $flashMessage = $result['message'];
        $flashIsError = !$result['success'];
    } elseif ($action === 'save_product_rule') {
        $result = $adminService->configureReorderRule(
            ['product_id' => (int) ($_POST['product_id'] ?? 0)],
            $ruleData,
            $actorId
        );
        $flashMessage = $result['message'];
        $flashIsError = !$result['success'];
    }
}

// =========================================================================
// DỮ LIỆU HIỂN THỊ
// =========================================================================
$categories = $adminService->listCategories(); // [['category_id'=>1,'category_name'=>...,'category_type'=>'FMCG','requires_fefo'=>0], ...]

// reorder_rules hiện có, map theo category_id (chỉ những rule category_id IS NOT NULL)
$allRules = $adminService->listReorderRules();
$rulesByCategoryId = [];
foreach ($allRules as $rule) {
    if ($rule['category_id'] !== null) {
        $rulesByCategoryId[(int) $rule['category_id']] = $rule;
    }
}

// Group categories theo category_type - phản ánh đúng cột thật trên bảng
// categories, chỉ dùng để tổ chức layout thành nhóm thẻ, không tạo target rule mới.
$categoriesByType = [];
foreach ($categories as $cat) {
    $categoriesByType[$cat['category_type']][] = $cat;
}

// Nhãn dễ đọc hơn tên ENUM thô + thứ tự hiển thị ưu tiên
$typeLabels = [
    'FMCG'            => 'FMCG',
    'Fresh_Food'       => 'Fresh Food',
    'Imported_Korean'  => 'Imports (Korean)',
];
$typeOrder = ['FMCG', 'Fresh_Food', 'Imported_Korean'];
foreach (array_keys($categoriesByType) as $type) {
    if (!in_array($type, $typeOrder, true)) {
        $typeOrder[] = $type; // phòng hờ có type mới phát sinh sau này
    }
}

// Product-level override: danh sách rule đang gán riêng cho 1 product_id
$productRules = array_values(array_filter($allRules, fn($r) => $r['product_id'] !== null));

// Toàn bộ sản phẩm active - dùng cho dropdown "Add product override"
$allProducts = $adminService->listProducts(null, null, true);

// Panel bên phải: lịch sử thay đổi rule gần đây (audit log lọc theo reorder_rules,
// khớp target_table='reorder_rules' mà Product::upsertReorderRule() tự ghi)
$ruleHistory = $adminService->getAuditLogs(['action_type' => 'UPDATE_REORDER_RULE']);
$ruleHistory = array_slice($ruleHistory, 0, 8);

/** Badge trạng thái cấu hình: đã có rule riêng (đã lưu) hay chưa được cấu hình. */
function ruleStatusPillClass(bool $configured): string
{
    return $configured ? 'stock-pill-success' : 'stock-pill-muted';
}

/** Format datetime DB sang "HH:MM d/m/Y" ngắn gọn cho panel lịch sử. */
function formatRuleHistoryTime(?string $raw): string
{
    if ($raw === null || $raw === '') {
        return '—';
    }
    $ts = strtotime($raw);
    return $ts === false ? $raw : date('H:i d/m/Y', $ts);
}

$pageTitle   = 'Rules';
$breadcrumbs = ['Admin', 'Rules'];
$activeMenu  = 'rules';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rules - InventoryDSS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/theme_variables.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/custom.css" rel="stylesheet">
    <style>
        /* Thẻ rule theo category - tái dùng token màu/border/shadow chung, không
           tạo class mới trong custom.css để tránh phình file chỉ vì 1 trang. */
        .rule-card {
            height: 100%;
            border: 1px solid var(--surface-border);
            border-radius: var(--radius-md);
            padding: 16px;
            background: var(--surface-card-bg);
        }
    </style>
</head>
<body>
    <div class="app-shell">
        <?php require __DIR__ . '/../components/sidebar.php'; ?>

        <div class="app-content">
            <?php require __DIR__ . '/../components/header.php'; ?>

            <main class="app-main">

                <!-- Page intro -->
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
                    <div>
                        <h2 class="page-heading mb-1">Global Inventory Parameters</h2>
                        <p class="page-subheading mb-0">Configure reorder rules by category, or override for a specific product (FR-ADM-04, FR-ADM-05, BR-16).</p>
                    </div>
                </div>

                <?php if ($flashMessage !== ''): ?>
                    <div class="alert <?= $flashIsError ? 'alert-danger' : 'alert-success' ?> py-2 px-3 mb-3" style="font-size: .87rem;">
                        <?= htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>

                <div class="row g-3">
                    <!-- ===================== LEFT: rule cards ===================== -->
                    <div class="col-12 col-xl-8">

                        <?php foreach ($typeOrder as $type): ?>
                            <?php if (empty($categoriesByType[$type])) continue; ?>

                            <div class="panel-card mb-3">
                                <div class="panel-card-header">
                                    <h3 class="panel-card-title"><?= htmlspecialchars($typeLabels[$type] ?? $type, ENT_QUOTES, 'UTF-8') ?></h3>
                                    <span class="panel-card-note"><?= count($categoriesByType[$type]) ?> categor<?= count($categoriesByType[$type]) === 1 ? 'y' : 'ies' ?></span>
                                </div>

                                <div class="row g-3">
                                    <?php foreach ($categoriesByType[$type] as $cat): ?>
                                        <?php
                                            $catId = (int) $cat['category_id'];
                                            $rule = $rulesByCategoryId[$catId] ?? null;
                                            $configured = $rule !== null;
                                        ?>
                                        <div class="col-12 col-md-6">
                                            <form method="POST" class="rule-card">
                                                <input type="hidden" name="action" value="save_category_rule">
                                                <input type="hidden" name="category_id" value="<?= $catId ?>">

                                                <div class="d-flex justify-content-between align-items-start mb-2">
                                                    <div>
                                                        <div class="fw-semibold"><?= htmlspecialchars($cat['category_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                                        <?php if ((int) $cat['requires_fefo'] === 1): ?>
                                                            <span class="text-muted small">Perishable &middot; FEFO required</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <span class="stock-pill <?= ruleStatusPillClass($configured) ?>"><?= $configured ? 'Configured' : 'Not set' ?></span>
                                                </div>

                                                <div class="row g-2">
                                                    <div class="col-6">
                                                        <label class="form-label small fw-semibold mb-1">Min Stock</label>
                                                        <input type="number" name="min_stock" class="form-control form-control-sm" min="0" required
                                                               value="<?= $rule ? (int) $rule['min_stock'] : '' ?>">
                                                    </div>
                                                    <div class="col-6">
                                                        <label class="form-label small fw-semibold mb-1">Max Stock</label>
                                                        <input type="number" name="max_stock" class="form-control form-control-sm" min="0" required
                                                               value="<?= $rule ? (int) $rule['max_stock'] : '' ?>">
                                                    </div>
                                                    <div class="col-6">
                                                        <label class="form-label small fw-semibold mb-1">Safety Stock</label>
                                                        <input type="number" name="safety_stock" class="form-control form-control-sm" min="0" required
                                                               value="<?= $rule ? (int) $rule['safety_stock'] : '' ?>">
                                                    </div>
                                                    <div class="col-6">
                                                        <label class="form-label small fw-semibold mb-1">Reorder Point</label>
                                                        <input type="number" name="reorder_point" class="form-control form-control-sm" min="0" required
                                                               value="<?= $rule ? (int) $rule['reorder_point'] : '' ?>">
                                                    </div>
                                                </div>

                                                <div class="d-flex justify-content-between align-items-center mt-2">
                                                    <span class="text-muted" style="font-size: .74rem;">
                                                        <?= $rule ? 'Last updated: ' . htmlspecialchars(date('d/m/Y', strtotime($rule['updated_at'])), ENT_QUOTES, 'UTF-8') : 'No rule saved yet' ?>
                                                    </span>
                                                    <button type="submit" class="btn btn-brand btn-sm">Save</button>
                                                </div>
                                            </form>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <!-- ===================== Product-level overrides ===================== -->
                        <div class="panel-card">
                            <div class="panel-card-header">
                                <h3 class="panel-card-title">Product Overrides</h3>
                                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#addOverrideModal">
                                    + Add override
                                </button>
                            </div>
                            <p class="text-muted small mb-3">A product-level rule takes priority over its category's rule (BR-05).</p>

                            <?php if (empty($productRules)): ?>
                                <div class="empty-state">No product-level overrides configured yet.</div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table data-table align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>Product</th>
                                                <th>Min</th>
                                                <th>Max</th>
                                                <th>Safety</th>
                                                <th>Reorder Pt.</th>
                                                <th>Last updated</th>
                                                <th class="text-end">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($productRules as $pr): ?>
                                                <tr>
                                                    <td>
                                                        <span class="fw-semibold"><?= htmlspecialchars($pr['product_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                                        <div class="text-muted small"><?= htmlspecialchars($pr['sku_code'], ENT_QUOTES, 'UTF-8') ?></div>
                                                    </td>
                                                    <td><?= (int) $pr['min_stock'] ?></td>
                                                    <td><?= (int) $pr['max_stock'] ?></td>
                                                    <td><?= (int) $pr['safety_stock'] ?></td>
                                                    <td><?= (int) $pr['reorder_point'] ?></td>
                                                    <td class="text-muted small"><?= htmlspecialchars(date('d/m/Y', strtotime($pr['updated_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td class="text-end">
                                                        <button type="button" class="btn btn-outline-secondary btn-sm"
                                                                data-bs-toggle="modal" data-bs-target="#addOverrideModal"
                                                                data-product-id="<?= (int) $pr['product_id'] ?>"
                                                                data-min-stock="<?= (int) $pr['min_stock'] ?>"
                                                                data-max-stock="<?= (int) $pr['max_stock'] ?>"
                                                                data-safety-stock="<?= (int) $pr['safety_stock'] ?>"
                                                                data-reorder-point="<?= (int) $pr['reorder_point'] ?>">
                                                            Edit
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>

                    </div>

                    <!-- ===================== RIGHT: recent rule changes ===================== -->
                    <div class="col-12 col-xl-4">
                        <div class="panel-card">
                            <div class="panel-card-header">
                                <h3 class="panel-card-title">Recent Rule Changes</h3>
                                <a href="<?= BASE_URL ?>/admin/audit_log.php" class="panel-card-link">Full audit log</a>
                            </div>

                            <?php if (empty($ruleHistory)): ?>
                                <div class="empty-state">No reorder rule has been changed yet.</div>
                            <?php else: ?>
                                <ul class="list-unstyled activity-list mb-0">
                                    <?php foreach ($ruleHistory as $log): ?>
                                        <li class="activity-item">
                                            <div class="activity-item-main">
                                                <span class="fw-semibold"><?= htmlspecialchars($log['account_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                                <span class="text-muted">updated a reorder rule</span>
                                                <?php if ($log['target_id'] !== null): ?>
                                                    <span class="text-muted">(rule #<?= (int) $log['target_id'] ?>)</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="activity-item-meta">
                                                <span class="activity-item-time"><?= formatRuleHistoryTime($log['timestamp']) ?></span>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            </main>
        </div>
    </div>

    <!-- ===================== MODAL: Add/Edit product override ===================== -->
    <div class="modal fade" id="addOverrideModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <form method="POST" class="modal-content" id="overrideForm">
                <input type="hidden" name="action" value="save_product_rule">
                <div class="modal-header">
                    <h5 class="modal-title">Product Override</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body d-flex flex-column gap-3">
                    <div>
                        <label class="form-label small fw-semibold">Product</label>
                        <select name="product_id" id="ovProductId" class="form-select" required>
                            <?php foreach ($allProducts as $p): ?>
                                <option value="<?= (int) $p['product_id'] ?>">
                                    <?= htmlspecialchars($p['product_name'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($p['sku_code'], ENT_QUOTES, 'UTF-8') ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Min Stock</label>
                            <input type="number" name="min_stock" id="ovMinStock" class="form-control" min="0" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Max Stock</label>
                            <input type="number" name="max_stock" id="ovMaxStock" class="form-control" min="0" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Safety Stock</label>
                            <input type="number" name="safety_stock" id="ovSafetyStock" class="form-control" min="0" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Reorder Point</label>
                            <input type="number" name="reorder_point" id="ovReorderPoint" class="form-control" min="0" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-brand">Save override</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Đổ dữ liệu từ nút "Edit" của 1 dòng override vào modal dùng chung
        // (Add + Edit chung 1 modal, giống pattern data-* của accounts.php).
        // Khi bấm "+ Add override" (không có data-product-id) thì reset form trắng.
        document.getElementById('addOverrideModal').addEventListener('show.bs.modal', function (event) {
            const btn = event.relatedTarget;
            const form = document.getElementById('overrideForm');
            if (!btn || !btn.hasAttribute('data-product-id')) {
                form.reset();
                return;
            }
            document.getElementById('ovProductId').value = btn.getAttribute('data-product-id');
            document.getElementById('ovMinStock').value = btn.getAttribute('data-min-stock');
            document.getElementById('ovMaxStock').value = btn.getAttribute('data-max-stock');
            document.getElementById('ovSafetyStock').value = btn.getAttribute('data-safety-stock');
            document.getElementById('ovReorderPoint').value = btn.getAttribute('data-reorder-point');
        });
    </script>
    <?php require __DIR__ . '/../components/footer.php'; ?>