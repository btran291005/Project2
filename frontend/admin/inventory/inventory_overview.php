<?php
/**
 * File: frontend/admin/inventory/inventory_overview.php
 * Purpose: UI tổng quan tồn kho cho Admin - danh sách sản phẩm kèm tồn kho
 * hiện tại, kho đang giữ hàng, và ngưỡng Safety/Reorder có hiệu lực. Đây là
 * nền tảng "xem trước khi sửa" cho việc quản lý master data Product (FR-ADM-01),
 * KHÔNG phải trang CRUD Product đầy đủ (tạo/sửa/xoá sản phẩm vẫn thuộc phạm vi
 * 1 trang admin/products.php riêng - hiện repo chưa có trang đó; trang này chỉ
 * thêm nút "Deactivate/Activate" nhanh vì AdminService đã có sẵn 2 action đó).
 * Related: FR-ADM-01, FR-ADM-05, BR-01, BR-04, BR-16
 * Calls: AdminService::getInventoryStockOverview(), getInventoryOverviewSummary(),
 *        listCategories(), deactivateProduct(), activateProduct()
 *
 * LƯU Ý DỮ LIỆU (đối chiếu prototype tham khảo GS25 IntelliStock):
 * - Prototype có panel "Master Data Quality" với Completeness %, "Missing
 *   Supplier", "Missing Category", "Duplicate SKUs". Schema thật: products.category_id
 *   và products.supplier_id đều NOT NULL, sku_code UNIQUE - 3 lỗi dữ liệu đó
 *   KHÔNG THỂ xảy ra ở tầng DB, nên panel này được thay bằng "Inventory Snapshot"
 *   dùng số liệu THẬT (tổng SKU, NCC, kho, sản phẩm ngừng KD, sản phẩm chạm
 *   reorder point) - xem AdminService::getInventoryOverviewSummary().
 * - Prototype có badge trạng thái "Inactive/Low Stock/New" trên mỗi dòng. Bảng
 *   products chỉ có is_active - "New" và "Low Stock" ở đây được SUY RA từ dữ
 *   liệu thật (Low Stock: current_stock <= reorder_point hiệu lực; không có cột
 *   created_at trên products nên KHÔNG hiển thị badge "New" giả).
 * - "Recent Master Data Changes" của prototype lấy từ audit_logs (đã có
 *   AdminService::getAuditLogs()) - tái sử dụng, lọc theo các action_type liên
 *   quan đến Product/Supplier/Warehouse.
 * - Cột "Suppliers" trong bảng KHÔNG được thêm dù prototype có tab đó, vì trang
 *   quản lý supplier/warehouse thật đã tồn tại riêng - phạm vi task này CHỈ có
 *   2 file Inventory Overview + Count History.
 *
 * Style/layout đồng bộ frontend/admin/dashboard.php và accounts.php (header/
 * sidebar/footer component + Bootstrap 5 + kpi-card/panel-card/data-table/
 * stock-pill dùng chung).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../backend/config/app_config.php';
require_once __DIR__ . '/../../../backend/config/database.php';
require_once __DIR__ . '/../../../backend/core/Logger.php';
require_once __DIR__ . '/../../../backend/core/Auth.php';
require_once __DIR__ . '/../../../backend/core/Middleware.php';
require_once __DIR__ . '/../../../backend/services/AdminService.php';

// BR-19 / NFR-03: chỉ Admin được vào trang này, chặn ở tầng server
Middleware::guard([ROLE_ADMIN]);

$adminService = new AdminService();
$actorId = Auth::id();

$flashMessage = '';
$flashIsError = false;

// =========================================================================
// XỬ LÝ POST: deactivate_product / activate_product (quick action từ bảng)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $productId = (int) ($_POST['product_id'] ?? 0);

    if ($action === 'deactivate_product') {
        $result = $adminService->deactivateProduct($productId, $actorId);
        $flashMessage = $result['message'];
        $flashIsError = !$result['success'];
    } elseif ($action === 'activate_product') {
        $result = $adminService->activateProduct($productId, $actorId);
        $flashMessage = $result['message'];
        $flashIsError = !$result['success'];
    }
}

// =========================================================================
// DỮ LIỆU HIỂN THỊ
// =========================================================================
$categories = $adminService->listCategories();

$filterCategoryId = isset($_GET['category_id']) && $_GET['category_id'] !== '' ? (int) $_GET['category_id'] : null;
$filterStatus     = $_GET['status'] ?? '';
$filterStatus     = in_array($filterStatus, ['active', 'inactive', 'low_stock'], true) ? $filterStatus : null;
$keyword          = trim((string) ($_GET['q'] ?? ''));

$includeInactive = $filterStatus === 'inactive' || $filterStatus === null;
$products = $adminService->getInventoryStockOverview($includeInactive, $filterCategoryId, $keyword !== '' ? $keyword : null);

if ($filterStatus === 'active') {
    $products = array_values(array_filter($products, fn($p) => (int) $p['is_active'] === 1));
} elseif ($filterStatus === 'inactive') {
    $products = array_values(array_filter($products, fn($p) => (int) $p['is_active'] === 0));
} elseif ($filterStatus === 'low_stock') {
    $products = array_values(array_filter($products, fn($p) => (int) $p['is_active'] === 1 && (int) $p['current_stock'] <= (int) $p['reorder_point']));
}

$summary = $adminService->getInventoryOverviewSummary();

// Recent Master Data Changes - tái sử dụng audit log thật, lọc theo hành động
// liên quan tới Product/Supplier/Warehouse (FR-ADM-07), thay cho panel giả
// định "Recent Master Data Changes" của mockup.
$masterDataActions = [
    'CREATE_PRODUCT', 'UPDATE_PRODUCT', 'DEACTIVATE_PRODUCT', 'ACTIVATE_PRODUCT',
    'CREATE_SUPPLIER', 'UPDATE_SUPPLIER', 'DELETE_SUPPLIER',
    'CREATE_WAREHOUSE', 'UPDATE_WAREHOUSE', 'DELETE_WAREHOUSE',
];
$recentChanges = array_values(array_filter(
    $adminService->getAuditLogs(),
    fn($log) => in_array($log['action_type'], $masterDataActions, true)
));
$recentChanges = array_slice($recentChanges, 0, 5);

/** Badge trạng thái tồn kho theo dữ liệu thật (is_active + so với reorder point). */
function inventoryStatusInfo(array $product): array
{
    if ((int) $product['is_active'] === 0) {
        return ['label' => 'Inactive', 'pill' => 'stock-pill-muted', 'bar' => 'bg-secondary', 'ratio' => 0];
    }

    $stock  = (int) $product['current_stock'];
    $point  = (int) $product['reorder_point'];
    $safety = (int) $product['safety_stock'];

    if ($point <= 0) {
        return ['label' => 'No rule set', 'pill' => 'stock-pill-muted', 'bar' => 'bg-secondary', 'ratio' => 0];
    }
    if ($stock <= $safety) {
        return ['label' => 'Low Stock', 'pill' => 'stock-pill-critical', 'bar' => 'bg-danger', 'ratio' => min(100, (int) round($stock / max($point, 1) * 100))];
    }
    if ($stock <= $point) {
        return ['label' => 'Reorder Soon', 'pill' => 'stock-pill-warn', 'bar' => 'bg-warning', 'ratio' => min(100, (int) round($stock / max($point, 1) * 100))];
    }
    return ['label' => 'Optimal', 'pill' => 'stock-pill-success', 'bar' => 'bg-success', 'ratio' => 100];
}

/** Format datetime DB thành "HH:MM DD/MM" ngắn gọn, đồng bộ dashboard.php. */
function formatOverviewDateTime(?string $raw): string
{
    if ($raw === null || $raw === '') {
        return '—';
    }
    $ts = strtotime($raw);
    return $ts === false ? $raw : date('H:i d/m', $ts);
}

/** Map action_type sang mô tả ngắn cho panel "Recent Master Data Changes". */
function formatMasterDataAction(string $actionType): string
{
    $map = [
        'CREATE_PRODUCT'     => 'created a new product record',
        'UPDATE_PRODUCT'     => 'updated a product record',
        'DEACTIVATE_PRODUCT' => 'deactivated a product',
        'ACTIVATE_PRODUCT'   => 'reactivated a product',
        'CREATE_SUPPLIER'    => 'created a supplier',
        'UPDATE_SUPPLIER'    => 'updated a supplier',
        'DELETE_SUPPLIER'    => 'deleted a supplier',
        'CREATE_WAREHOUSE'   => 'created a warehouse',
        'UPDATE_WAREHOUSE'   => 'updated a warehouse',
        'DELETE_WAREHOUSE'   => 'deleted a warehouse',
    ];
    return $map[$actionType] ?? strtolower(str_replace('_', ' ', $actionType));
}

$pageTitle   = 'Inventory Overview';
$breadcrumbs = ['Admin', 'Inventory', 'Overview'];
$activeMenu  = 'inventory_overview';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Overview - InventoryDSS</title>
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

                <!-- Page intro + tabs (Overview / Count History) -->
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
                    <div>
                        <h2 class="page-heading mb-1">Inventory Overview</h2>
                        <p class="page-subheading mb-0">Store-wide stock levels across products and warehouses (FR-ADM-01, BR-04).</p>
                    </div>
                </div>

                <ul class="nav nav-tabs mb-4" style="border-bottom: 2px solid var(--surface-border);">
                    <li class="nav-item">
                        <a class="nav-link active fw-semibold" href="inventory_overview.php">Overview</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="inventory_count_history.php">Count History</a>
                    </li>
                </ul>

                <?php if ($flashMessage !== ''): ?>
                    <div class="alert <?= $flashIsError ? 'alert-danger' : 'alert-success' ?> py-2 px-3 mb-3" style="font-size: .87rem;">
                        <?= htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>

                <!-- Snapshot cards - số liệu thật, thay cho "Products/Suppliers/Warehouses/Inactive/Low Stock" của mockup -->
                <div class="row g-3 mb-4">
                    <div class="col-6 col-md-4 col-xl">
                        <div class="kpi-card">
                            <div class="kpi-card-top"><span class="kpi-label">Products</span></div>
                            <span class="kpi-value"><?= number_format($summary['total_products']) ?></span>
                        </div>
                    </div>
                    <div class="col-6 col-md-4 col-xl">
                        <div class="kpi-card">
                            <div class="kpi-card-top"><span class="kpi-label">Suppliers</span></div>
                            <span class="kpi-value"><?= number_format($summary['total_suppliers']) ?></span>
                        </div>
                    </div>
                    <div class="col-6 col-md-4 col-xl">
                        <div class="kpi-card">
                            <div class="kpi-card-top"><span class="kpi-label">Warehouses</span></div>
                            <span class="kpi-value"><?= number_format($summary['total_warehouses']) ?></span>
                        </div>
                    </div>
                    <div class="col-6 col-md-4 col-xl">
                        <div class="kpi-card <?= $summary['inactive_count'] > 0 ? 'kpi-card-warn' : '' ?>">
                            <div class="kpi-card-top"><span class="kpi-label">Inactive</span></div>
                            <span class="kpi-value" style="color: var(--color-danger);"><?= number_format($summary['inactive_count']) ?></span>
                        </div>
                    </div>
                    <div class="col-6 col-md-4 col-xl">
                        <div class="kpi-card <?= $summary['low_stock_count'] > 0 ? 'kpi-card-warn' : '' ?>">
                            <div class="kpi-card-top"><span class="kpi-label">Low Stock</span></div>
                            <span class="kpi-value" style="color: var(--color-warn, #b8860b);"><?= number_format($summary['low_stock_count']) ?></span>
                        </div>
                    </div>
                </div>

                <div class="row g-4">
                    <div class="col-12 col-xl-8">

                        <!-- Filter + search -->
                        <form method="get" class="panel-card mb-3">
                            <div class="row g-2 align-items-center">
                                <div class="col-12 col-md-5">
                                    <input type="text" name="q" class="form-control" placeholder="Search SKU or Name..." value="<?= htmlspecialchars($keyword, ENT_QUOTES, 'UTF-8') ?>">
                                </div>
                                <div class="col-6 col-md-3">
                                    <select name="category_id" class="form-select" onchange="this.form.submit()">
                                        <option value="">All categories</option>
                                        <?php foreach ($categories as $cat): ?>
                                            <option value="<?= (int) $cat['category_id'] ?>" <?= $filterCategoryId === (int) $cat['category_id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['category_name'], ENT_QUOTES, 'UTF-8') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-6 col-md-2">
                                    <select name="status" class="form-select" onchange="this.form.submit()">
                                        <option value="">All status</option>
                                        <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Active</option>
                                        <option value="low_stock" <?= $filterStatus === 'low_stock' ? 'selected' : '' ?>>Low Stock</option>
                                        <option value="inactive" <?= $filterStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                    </select>
                                </div>
                                <div class="col-12 col-md-2 d-flex gap-2">
                                    <button type="submit" class="btn btn-brand flex-fill">Search</button>
                                    <a href="inventory_overview.php" class="btn btn-outline-secondary" title="Clear filters">&#8635;</a>
                                </div>
                            </div>
                        </form>

                        <!-- Bảng tồn kho -->
                        <div class="panel-card mb-4">
                            <div class="panel-card-header">
                                <h3 class="panel-card-title">Products</h3>
                                <span class="panel-card-note"><?= count($products) ?> product<?= count($products) === 1 ? '' : 's' ?></span>
                            </div>

                            <?php if (empty($products)): ?>
                                <div class="empty-state">No products match the current filters.</div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table data-table align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>SKU / Product Name</th>
                                                <th>Category</th>
                                                <th>Inventory Status</th>
                                                <th>Safety / Reorder</th>
                                                <th>Price (Sell / Cost)</th>
                                                <th class="text-end">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($products as $p): ?>
                                                <?php $status = inventoryStatusInfo($p); ?>
                                                <tr>
                                                    <td>
                                                        <span class="fw-semibold text-nowrap"><?= htmlspecialchars($p['sku_code'], ENT_QUOTES, 'UTF-8') ?></span>
                                                        <div><?= htmlspecialchars($p['product_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                                    </td>
                                                    <td><span class="stock-pill stock-pill-muted"><?= htmlspecialchars($p['category_name'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                                    <td style="min-width: 160px;">
                                                        <div class="progress mb-1" style="height: 6px;">
                                                            <div class="progress-bar <?= $status['bar'] ?>" style="width: <?= $status['ratio'] ?>%;"></div>
                                                        </div>
                                                        <span class="stock-pill <?= $status['pill'] ?>"><?= $status['label'] ?></span>
                                                        <div class="text-muted small mt-1">
                                                            <?= number_format((int) $p['current_stock']) ?> on hand
                                                            <?php if ($p['primary_warehouse_name']): ?>
                                                                &middot; WH: <?= htmlspecialchars($p['primary_warehouse_name'], ENT_QUOTES, 'UTF-8') ?><?= (int) $p['warehouse_count'] > 1 ? ' +' . ((int) $p['warehouse_count'] - 1) : '' ?>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                    <td class="text-nowrap">S: <?= number_format((int) $p['safety_stock']) ?> | R: <?= number_format((int) $p['reorder_point']) ?></td>
                                                    <td class="text-nowrap">
                                                        &#8363;<?= number_format((float) $p['selling_price'], 0) ?>
                                                        <div class="text-muted small">Cost: &#8363;<?= number_format((float) $p['unit_cost'], 0) ?></div>
                                                    </td>
                                                    <td class="text-end">
                                                        <form method="POST" onsubmit="return confirm('<?= (int) $p['is_active'] === 1 ? 'Deactivate' : 'Reactivate' ?> this product?');">
                                                            <input type="hidden" name="action" value="<?= (int) $p['is_active'] === 1 ? 'deactivate_product' : 'activate_product' ?>">
                                                            <input type="hidden" name="product_id" value="<?= (int) $p['product_id'] ?>">
                                                            <button type="submit" class="btn btn-sm <?= (int) $p['is_active'] === 1 ? 'btn-outline-danger' : 'btn-outline-success' ?>">
                                                                <?= (int) $p['is_active'] === 1 ? 'Deactivate' : 'Activate' ?>
                                                            </button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="col-12 col-xl-4">
                        <!-- Recent Master Data Changes - từ audit_logs thật (FR-ADM-07) -->
                        <div class="panel-card">
                            <div class="panel-card-header">
                                <h3 class="panel-card-title">Recent Master Data Changes</h3>
                            </div>
                            <?php if (empty($recentChanges)): ?>
                                <div class="empty-state">No recent changes.</div>
                            <?php else: ?>
                                <ul class="list-unstyled d-flex flex-column gap-3 mb-0">
                                    <?php foreach ($recentChanges as $log): ?>
                                        <li>
                                            <div class="d-flex justify-content-between">
                                                <span class="fw-semibold"><?= htmlspecialchars($log['account_name'] ?? 'System', ENT_QUOTES, 'UTF-8') ?></span>
                                                <span class="text-muted small"><?= formatOverviewDateTime($log['timestamp'] ?? null) ?></span>
                                            </div>
                                            <div class="small text-muted"><?= htmlspecialchars(formatMasterDataAction($log['action_type']), ENT_QUOTES, 'UTF-8') ?></div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                            <div class="mt-3">
                                <a href="<?= BASE_URL ?>/admin/audit_log.php" class="panel-card-link">View full audit log &rarr;</a>
                            </div>
                        </div>
                    </div>
                </div>

            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <?php require __DIR__ . '/../../components/footer.php'; ?>