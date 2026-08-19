<?php
/**
 * File: backend/services/ReorderService.php
 * Purpose: Công thức gợi ý số lượng đặt hàng (BR-05) - tính suggested_qty cho
 *          từng sản phẩm đã chạm/dưới Reorder Point, dựa trên Reorder Point,
 *          Safety Stock, Max Stock và xu hướng bán gần đây (sales trend).
 * Related: BR-04, BR-05, BR-13, FR-MGR-02, FR-MGR-04
 * Note: Đây là ĐIỂM NỐI mà ManagerService::getReorderSuggestions() đã chờ sẵn
 *       (xem ManagerService.php mục 4) - CHỮ KÝ HÀM PHẢI GIỮ ĐÚNG:
 *       suggestQuantity(): array, KHÔNG đổi tên/tham số nếu không cập nhật lại
 *       ManagerService cùng lúc.
 *
 * PHẠM VI CỦA FILE NÀY:
 *   - CHỈ tính toán con số gợi ý (min/max/safety_stock/reorder_point + sales
 *     trend) - KHÔNG tự tạo Purchase Order (đó là Order::createDraft(), gọi từ
 *     ManagerService::createPurchaseOrderDraft()), KHÔNG gọi AI Forecast API
 *     (đó là IntegrationService, vẫn còn là stub trống - BR-18 fallback không
 *     áp dụng ở đây vì file này CHÍNH LÀ nhánh fallback rule-based).
 *   - Không tự ghi Logger - đây là hàm tính toán/đọc dữ liệu (read-only), không
 *     có hành động ghi nào cần audit.
 *
 * QUY ƯỚC CHUNG: giống AdminService.php/ManagerService.php - Service không tự
 * check role (đã chặn ở Middleware trước khi vào Controller), input được ép
 * kiểu trước khi xuống Model, return value dạng array ['success'=>bool,...].
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Product.php';
require_once __DIR__ . '/../models/Inventory.php';
require_once __DIR__ . '/../models/Sales.php';

class ReorderService
{
    private Product $productModel;
    private Inventory $inventoryModel;
    private Sales $salesModel;

    public function __construct()
    {
        $this->productModel   = new Product();
        $this->inventoryModel = new Inventory();
        $this->salesModel     = new Sales();
    }

    // =====================================================================
    // BR-05: CÔNG THỨC GỢI Ý SỐ LƯỢNG ĐẶT HÀNG
    // =====================================================================
    // ⚠️ GIỮ NGUYÊN CÔNG THỨC NÀY KHI SỬA SAU (giống ghi chú ở FR-MGR-12):
    //
    //   avg_daily_sales_7d = tổng số lượng bán 7 ngày gần nhất ÷ 7
    //   projected_demand   = avg_daily_sales_7d × avg_lead_time_days (nếu có
    //                        thông tin nhà cung cấp), mặc định lead-time = 7
    //                        ngày nếu supplier chưa cấu hình avg_lead_time_days
    //                        (giả định an toàn, tránh đặt thiếu hàng).
    //   target_stock       = max(reorder_point + projected_demand, safety_stock)
    //                        - đảm bảo sau khi hàng về, tồn kho đủ vượt qua
    //                        safety_stock trong suốt thời gian chờ lô kế tiếp.
    //   suggested_qty      = target_stock − current_stock, làm tròn lên
    //                        (ceil, không đặt thiếu vì làm tròn xuống), tối
    //                        thiểu là 0 (không đặt số âm) và không vượt quá
    //                        khoảng trống còn lại tới max_stock (nếu có
    //                        max_stock > 0, tránh tồn kho vượt sức chứa).
    //
    // Đây là công thức "rule-based" đúng nghĩa BR-18: khi AI Forecast API lỗi,
    // hệ thống PHẢI fallback về đúng công thức này.

    /**
     * BR-05 / FR-MGR-02: tính danh sách gợi ý đặt hàng cho TOÀN BỘ sản phẩm
     * đang active và đã chạm/dưới Reorder Point. Đây là hàm mà
     * ManagerService::getReorderSuggestions() gọi tới.
     *
     * BR-13: kết quả trả về đã sắp xếp theo sales_volume_7d giảm dần (sản
     * phẩm bán chạy/cần gấp lên đầu), khớp quy ước đã dùng ở
     * ManagerService::getLowStockAlerts().
     *
     * @return array Danh sách sản phẩm cần đặt hàng, mỗi phần tử gồm:
     *   product_id, sku_code, product_name, current_stock, reorder_point,
     *   safety_stock, max_stock, avg_daily_sales_7d, suggested_qty
     */
    public function suggestQuantity(): array
    {
        $stockList   = $this->inventoryModel->getStockByProduct();
        $suggestions = [];

        foreach ($stockList as $stockRow) {
            $productId = (int) $stockRow['product_id'];
            $rule = $this->productModel->getEffectiveReorderRule($productId);

            if ($rule === false) {
                continue; // Chưa cấu hình reorder rule -> không thể tính gợi ý, bỏ qua (giống getLowStockAlerts()).
            }

            $currentStock = (int) $stockRow['total_quantity'];
            $reorderPoint = (int) $rule['reorder_point'];

            if ($currentStock > $reorderPoint) {
                continue; // BR-04: chỉ gợi ý cho sản phẩm đã chạm/dưới Reorder Point.
            }

            $suggestion = $this->calculateSuggestionForProduct($productId, $currentStock, $rule);

            $suggestions[] = [
                'product_id'         => $productId,
                'sku_code'           => $stockRow['sku_code'],
                'product_name'       => $stockRow['product_name'],
                'current_stock'      => $currentStock,
                'reorder_point'      => $reorderPoint,
                'safety_stock'       => (int) $rule['safety_stock'],
                'max_stock'          => (int) $rule['max_stock'],
                'avg_daily_sales_7d' => $suggestion['avg_daily_sales_7d'],
                'suggested_qty'      => $suggestion['suggested_qty'],
            ];
        }

        // BR-13: ưu tiên sản phẩm bán chạy (sales_volume) lên đầu danh sách.
        usort($suggestions, fn(array $a, array $b) => $b['avg_daily_sales_7d'] <=> $a['avg_daily_sales_7d']);

        return $suggestions;
    }

    /**
     * BR-05 / FR-MGR-02: tính gợi ý cho ĐÚNG 1 sản phẩm - dùng khi Manager
     * xem chi tiết 1 dòng trước khi thêm vào PO, hoặc khi cần tính lại sau
     * khi override thủ công. Không lọc theo Reorder Point (khác suggestQuantity()
     * ở trên) - trả về gợi ý ngay cả khi tồn kho hiện tại vẫn còn cao, để
     * Manager có thể chủ động thêm sản phẩm vào PO nếu muốn.
     *
     * @return array{success: bool, suggestion?: array, message: string}
     */
    public function suggestQuantityForProduct(int $productId): array
    {
        $rule = $this->productModel->getEffectiveReorderRule($productId);
        if ($rule === false) {
            return [
                'success' => false,
                'message' => 'Product has no reorder rule configured yet (Admin needs to set it up first - BR-16).',
            ];
        }

        $stockRows = $this->inventoryModel->getStockByProduct($productId);
        $currentStock = !empty($stockRows) ? (int) $stockRows[0]['total_quantity'] : 0;

        $suggestion = $this->calculateSuggestionForProduct($productId, $currentStock, $rule);

        return [
            'success' => true,
            'suggestion' => [
                'product_id'         => $productId,
                'current_stock'      => $currentStock,
                'reorder_point'      => (int) $rule['reorder_point'],
                'safety_stock'       => (int) $rule['safety_stock'],
                'max_stock'          => (int) $rule['max_stock'],
                'avg_daily_sales_7d' => $suggestion['avg_daily_sales_7d'],
                'suggested_qty'      => $suggestion['suggested_qty'],
            ],
            'message' => 'Order suggestion calculated.',
        ];
    }

    /**
     * Công thức cốt lõi BR-05 - tách riêng private để dùng chung cho cả
     * suggestQuantity() (toàn danh sách) và suggestQuantityForProduct() (1 sản
     * phẩm), tránh lặp code và đảm bảo 2 hàm public luôn cho cùng kết quả với
     * cùng input.
     *
     * @param array $rule Kết quả từ Product::getEffectiveReorderRule()
     *                    (['min_stock','max_stock','safety_stock','reorder_point',...])
     * @return array{avg_daily_sales_7d: float, suggested_qty: int}
     */
    private function calculateSuggestionForProduct(int $productId, int $currentStock, array $rule): array
    {
        $history = $this->salesModel->getSalesHistory($productId, STOCKOUT_RISK_SALES_WINDOW_DAYS);

        $totalSold7d = 0;
        foreach ($history as $row) {
            $totalSold7d += (int) $row['total_quantity'];
        }
        $avgDailySales = $totalSold7d / STOCKOUT_RISK_SALES_WINDOW_DAYS;

        // Lead-time: mặc định 7 ngày nếu sản phẩm/nhà cung cấp chưa có dữ liệu
        // avg_lead_time_days - giả định an toàn (không đặt thiếu hàng) khi
        // thiếu thông tin, thay vì mặc định 0 (sẽ khiến projected_demand = 0).
        $leadTimeDays = $this->getLeadTimeDaysForProduct($productId) ?? STOCKOUT_RISK_SALES_WINDOW_DAYS;

        $reorderPoint = (int) $rule['reorder_point'];
        $safetyStock  = (int) $rule['safety_stock'];
        $maxStock     = (int) $rule['max_stock'];

        $projectedDemand = $avgDailySales * $leadTimeDays;
        $targetStock     = max($reorderPoint + $projectedDemand, $safetyStock);

        $rawSuggestedQty = $targetStock - $currentStock;
        $suggestedQty    = (int) ceil(max(0, $rawSuggestedQty));

        // Không vượt quá khoảng trống còn lại tới max_stock (nếu max_stock đã
        // cấu hình > 0) - tránh gợi ý đặt hàng vượt sức chứa kho.
        if ($maxStock > 0) {
            $roomLeft = max(0, $maxStock - $currentStock);
            $suggestedQty = min($suggestedQty, $roomLeft);
        }

        return [
            'avg_daily_sales_7d' => round($avgDailySales, 2),
            'suggested_qty'      => $suggestedQty,
        ];
    }

    /**
     * Lấy avg_lead_time_days của nhà cung cấp đang gán cho 1 sản phẩm.
     * Trả về null nếu sản phẩm/supplier không tồn tại hoặc chưa cấu hình
     * lead-time - caller (calculateSuggestionForProduct) tự áp dụng giá trị
     * mặc định an toàn khi gặp null.
     */
    private function getLeadTimeDaysForProduct(int $productId): ?int
    {
        $product = $this->productModel->getById($productId);
        if ($product === false || empty($product['supplier_id'])) {
            return null;
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT avg_lead_time_days FROM suppliers WHERE supplier_id = :id");
        $stmt->execute([':id' => $product['supplier_id']]);
        $supplier = $stmt->fetch();

        if ($supplier === false || $supplier['avg_lead_time_days'] === null) {
            return null;
        }

        return (int) $supplier['avg_lead_time_days'];
    }
}