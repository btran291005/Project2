<?php
/**
 * File: frontend/components/sidebar.php
 * Purpose: Renders navigation menu based on $_SESSION['role'].
 *
 * CẬP NHẬT (flatten sidebar): Sidebar giờ CHỈ hiển thị các mục CHÍNH (main
 * items) trong sitemap - KHÔNG còn render danh sách mục con (submenu mở
 * rộng) ngay trong sidebar nữa. Với các nhóm trước đây có 'children' (VD
 * "Users & Roles", "Inventory", "Orders", "Reports"), mục chính giờ là 1
 * link phẳng duy nhất, trỏ tạm tới trang con đầu tiên còn tồn tại của nhóm
 * đó (xem 'href' của từng mục bên dưới). Khi trang landing/overview thật
 * của từng nhóm được triển khai (VD trang Inventory tổng có card/tab dẫn
 * qua Stock Count, Good Receipt, Inventory Adjustment...), chỉ cần đổi lại
 * 'href' của mục đó trỏ sang trang landing mới - phần "chỗ bấm vô để xem"
 * mục con sẽ nằm NGAY TRONG trang đó, không còn nằm trong sidebar.
 *
 * Warning: This controls menu VISIBILITY only. It does NOT enforce access —
 *          Middleware.php is the real access control. Never rely on a hidden
 *          menu item as a security measure.
 *
 * Yêu cầu: file gọi include component này phải include trước đó:
 *   app_config.php (BASE_URL, ROLE_ADMIN/MANAGER/STAFF), Auth.php (đã Auth::start()).
 *
 * Biến tùy chọn có thể set TRƯỚC KHI include file này để tô sáng mục đang active:
 *   $activeMenu = 'dashboard'; // khớp key của 1 mục trong $menuItems bên dưới.
 * Một số trang con cũ (từ thời còn submenu) set $activeMenu bằng key mục con
 * (VD 'accounts', 'demand_trend'...) - những key này được liệt kê trong
 * 'activeAlso' của mục cha tương ứng để mục cha vẫn được tô sáng đúng, không
 * cần sửa lại từng file trang.
 *
 * CẤU TRÚC DỮ LIỆU $menuItems (đã flatten - không còn 'children'):
 *   'key' => [
 *       'label'      => string,               // tên hiển thị
 *       'href'       => string,                // đường dẫn tương đối (chưa gồm BASE_URL)
 *       'icon'       => string,
 *       'activeAlso' => string[] (optional),    // các $activeMenu key cũ của mục con
 *                                                // (nếu có) để tô sáng đúng mục cha
 *   ]
 *
 * GHI CHÚ ĐỐI CHIẾU VỚI SITEMAP (để không route vào file/link không tồn tại):
 *   - Admin > Inventory (Overview, Count History): đã có 2 trang thật trong
 *     frontend/admin/inventory/ (inventory_overview.php, inventory_count_history.php)
 *     - mục 'inventory' bên dưới trỏ vào Overview, trang đó có tab nội bộ dẫn
 *     sang Count History.
 *   - Staff > Inventory > FEFO Picking: sitemap có yêu cầu, logic FEFO đã có
 *     ở backend (StaffService.php/Product.php/Inventory.php) nhưng CHƯA CÓ
 *     trang UI riêng trong frontend/staff/. Tạm ẩn khỏi menu, xem TODO.
 *   - Manager > Reports: sitemap gộp thành 1 mục "Reports", nhưng repo hiện
 *     có 3 trang phân tích riêng biệt (Demand Trend, Product Performance,
 *     Supplier Lead-time) - không có trang "Reports" tổng hợp nào khác. Mục
 *     "Reports" trong sidebar tạm trỏ vào Demand Trend (trang đầu tiên);
 *     khi có trang Reports tổng thật, đổi href của mục 'reports' để trỏ
 *     sang đó, còn 3 trang trên trở thành các "chỗ bấm vô để xem" bên trong.
 *   - Đường dẫn có khoảng trắng/dấu & (VD "reorder & forecast", "account &
 *     permission") - PHP require (nằm ở các file admin/manager/*.php, xử lý
 *     filesystem path, không phải URL) chạy đúng với chuỗi thường không cần
 *     encode gì. NHƯNG href render ra HTML LÀ URL - khoảng trắng và dấu '&'
 *     KHÔNG hợp lệ trong URL (dấu '&' đặc biệt bị hiểu là ký tự phân tách
 *     query string, cắt path ngay tại đó), nên mọi href bên dưới bắt buộc đi
 *     qua sidebarHref() để rawurlencode() từng đoạn - xem hàm ngay dưới đây.
 *     Bug thật đã từng xảy ra: href trỏ '.../account & permission/accounts.php'
 *     bị trình duyệt/server hiểu thành query string tại dấu '&', link 404.
 */

if (!defined('BASE_URL')) {
    // An toàn: nếu ai đó include thiếu config, không cho sidebar render sai đường dẫn
    require_once __DIR__ . '/../../backend/config/app_config.php';
}

$roleId = Auth::roleId();
$activeMenu = $activeMenu ?? '';

$menuItems = [];

if ($roleId === ROLE_ADMIN) {
    $menuItems = [
        'dashboard'   => ['label' => 'Dashboard / KPI', 'href' => '/admin/dashboard.php', 'icon' => 'grid'],
        'users_roles' => [
            'label' => 'Users & Roles',
            'href'  => '/admin/account & permission/accounts.php',
            'icon'  => 'users',
            'activeAlso' => ['accounts', 'permissions'],
        ],
        'rules'       => ['label' => 'Rules', 'href' => '/admin/reorder_rules.php', 'icon' => 'sliders'],
        'inventory'   => [
            'label' => 'Inventory',
            'href'  => '/admin/inventory/inventory_overview.php',
            'icon'  => 'box',
            'activeAlso' => ['inventory_overview', 'inventory_count_history'],
        ],
        'approvals'   => ['label' => 'Approvals', 'href' => '/admin/po_approval.php', 'icon' => 'check-square'],
        'audit_log'   => ['label' => 'Audit Log', 'href' => '/admin/audit_log.php', 'icon' => 'clock'],
        // File thật nằm trong subfolder: /admin/backup/backup_restore.php
        // (đã xác minh qua `find frontend/admin -iname backup_restore.php`).
        'system_backup' => ['label' => 'System Backup', 'href' => '/admin/backup/backup_restore.php', 'icon' => 'archive'],
    ];
} elseif ($roleId === ROLE_MANAGER) {
    $menuItems = [
        'dashboard' => ['label' => 'Dashboard', 'href' => '/manager/dashboard.php', 'icon' => 'grid'],
        'inventory' => [
            'label' => 'Inventory',
            // "Inventory Health" chưa có trang landing riêng - reorder_suggestions.php
            // (danh sách gợi ý đặt hàng theo Reorder Point/Safety Stock, BR-05) là
            // màn hình gần nghĩa nhất hiện có, trỏ vào đây trước theo yêu cầu.
            // Khi có trang Inventory Health landing thật (tab dẫn qua AI
            // Replenishment/Stock Incidents), đổi href sang đó.
            // LƯU Ý: forecast/demand_trend KHÔNG còn thuộc nhóm này nữa - đã tách
            // thành mục 'forecast' riêng bên dưới theo yêu cầu.
            'href'  => '/manager/inventory/reorder_suggestions.php',
            'icon'  => 'box',
            'activeAlso' => ['inventory_health', 'ai_replenishment', 'stock_incidents', 'reorder', 'shortage'],
        ],
        'forecast' => [
            // Mục riêng: gộp Demand Trend (lịch sử bán thực tế) + AI Forecast
            // (dự báo 7 ngày tới) trong 1 trang - forecast.php. Tách khỏi cả
            // Inventory lẫn Reports vì đây là công cụ tra cứu/dự báo theo TỪNG
            // sản phẩm, khác với Reports (KPI TỔNG toàn hệ thống).
            'label' => 'Demand & Forecast',
            'href'  => '/manager/forecast.php',
            'icon'  => 'trending-up',
            'activeAlso' => ['demand_trend'],
        ],
        'orders' => [
            'label' => 'Purchase Orders',
            'href'  => '/manager/purchase_order/po_create.php',
            'icon'  => 'file-text',
            'activeAlso' => ['purchase_orders', 'po_tracking', 'po', 'po_status'],
        ],
        'reports' => [
            'label' => 'Reports',
            // Trang tổng quan hiệu suất thật (Performance Analytics) - KPI tổng
            // toàn hệ thống (waste rate, doanh thu, category strength...), khác
            // với product_pfm.php (xếp hạng CHI TIẾT từng sản phẩm - vẫn còn
            // trong 'activeAlso' bên dưới để tô sáng đúng khi mở từ đó).
            'href'  => '/manager/reports/performance_analytics.php',
            'icon'  => 'bar-chart-2',
            'activeAlso' => ['performance_analytics', 'product_pfm', 'lead_time'],
        ],
    ];
} elseif ($roleId === ROLE_STAFF) {
$menuItems = [
        'dashboard' => ['label' => 'Dashboard', 'href' => '/staff/dashboard.php', 'icon' => 'grid'],
        'sales_entry' => ['label' => 'New Sales Entry', 'href' => '/staff/sales_entry.php', 'icon' => 'shopping-cart'],
        'inventory' => [
            'label' => 'Inventory',
            // Mặc định vào Goods Receipt trước - đúng thứ tự quy trình thật:
            // hàng về (Goods Receipt) -> kiểm kê định kỳ (Stock Count) ->
            // điều chỉnh khi phát hiện lệch (Adjustment). Cả 3 trang đều có
            // tab điều hướng riêng (.inv-tab-nav) để chuyển qua lại.
            'href'  => '/staff/inventory/goods_receipt.php',
            'icon'  => 'box',
            // TODO: sitemap yêu cầu "FEFO Picking" - logic FEFO đã có ở backend
            // (StaffService/Product/Inventory model) nhưng chưa có trang UI
            // riêng trong frontend/staff/. Khi có trang, thêm key vào đây.
            'activeAlso' => ['stock_count', 'good_receipt', 'inv_adjustment'],
        ],
        // Không có trong sitemap ảnh, nhưng khớp FR-STF-01/03/10/11/13 (Stock
        // view, Sales History, Feedback) - giữ lại vì đã có trang thật, không
        // xóa chức năng đã code chỉ vì sitemap rút gọn không vẽ chi tiết.
        'stock'      => ['label' => 'Stock', 'href' => '/staff/stock/stock_view.php', 'icon' => 'box'],
        'sales_hist' => ['label' => 'Sales History', 'href' => '/staff/sales_history.php', 'icon' => 'shopping-cart'],
        'feedback'   => ['label' => 'Customer Feedback', 'href' => '/staff/customer_feedback.php', 'icon' => 'message-square'],
    ];
}

/** Mục có đang active không? Khớp key chính, hoặc khớp 1 trong các key cũ liệt kê ở 'activeAlso'. */
function sidebarIsActive(string $key, array $item, string $activeMenu): bool
{
    if ($activeMenu === $key) {
        return true;
    }
    return isset($item['activeAlso']) && in_array($activeMenu, $item['activeAlso'], true);
}

/**
 * Ghép BASE_URL + href thành URL hợp lệ - encode TỪNG ĐOẠN path bằng
 * rawurlencode() (khoảng trắng -> %20, dấu '&' -> %26...) nhưng GIỮ NGUYÊN
 * dấu '/' phân tách thư mục (rawurlencode() sẽ encode luôn cả '/' nếu áp
 * dụng cho cả chuỗi, làm hỏng path - nên phải tách theo '/', encode từng
 * đoạn, rồi nối lại bằng '/').
 *
 * Bắt buộc dùng hàm này cho MỌI href render ra <a> trong sidebar, vì 1 số
 * thư mục thật trong repo có khoảng trắng/dấu & trong tên (VD "account &
 * permission", "reorder & forecast") - nếu ghép thẳng chuỗi không qua hàm
 * này, dấu '&' cắt đứt URL tại đó (bị hiểu thành query string), href sẽ trỏ
 * sai và trả về 404.
 */
function sidebarHref(string $relativeHref): string
{
    $segments = explode('/', $relativeHref);
    $encodedSegments = array_map('rawurlencode', $segments);
    return BASE_URL . implode('/', $encodedSegments);
}
?>
<aside class="app-sidebar">
    <div class="sidebar-brand">
        <img src="<?= BASE_URL ?>/assets/img/gs25_luxury_logo.jpg" alt="Gs25IntelliStock" class="sidebar-logo" referrerpolicy="no-referrer">
        <div class="sidebar-brand-text">
            <span class="sidebar-brand-title">Gs25IntelliStock</span>
            <span class="sidebar-brand-subtitle">Smart Inventory System</span>
        </div>
    </div>

    <nav class="sidebar-nav">
        <?php foreach ($menuItems as $key => $item): ?>
            <a href="<?= sidebarHref($item['href']) ?>"
               class="sidebar-link<?= sidebarIsActive($key, $item, $activeMenu) ? ' active' : '' ?>"
               data-menu="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>">
                <span class="sidebar-link-icon" data-icon="<?= htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8') ?>"></span>
                <span class="sidebar-link-label"><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
</aside>
