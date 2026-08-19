<?php
/**
 * File: backend/services/ManagerService.php
 * Purpose: Business logic for Manager actions — dashboard data aggregation,
 *          Top 10 stock-out risk calculation, reorder suggestions, PO submission,
 *          demand trend and product performance analytics.
 * Related: FR-MGR-01 through FR-MGR-11
 * Note: FR-MGR-12 formula (current_stock / avg_7day_sales) is implemented in
 *       method getStockoutRisk(). Keep this formula isolated and documented —
 *       it is the most likely requirement to change after user testing.
 *
 * PHẠM VI CỦA FILE NÀY:
 *   - Công thức gợi ý số lượng đặt hàng theo BR-05 (min/max/safety_stock/
 *     reorder_point) thuộc về ReorderService.php (đã code) - file này chỉ gọi
 *     qua ReorderService::suggestQuantity() ở getReorderSuggestions().
 *   - Gợi ý từ AI Demand Forecast API (FR-MGR-10) + fallback rule-based
 *     (BR-18) thuộc về IntegrationService.php (đã code) - file này chỉ gọi
 *     qua IntegrationService::getForecastForProduct() ở getForecastSuggestion(),
 *     KHÔNG tự lặp lại logic gọi ForecastAPI/xử lý fallback ở đây.
 *
 * QUY ƯỚC CHUNG: giống AdminService.php - Service không tự check role (đã
 * chặn ở Middleware trước khi vào Controller), input được ép kiểu trước khi
 * xuống Model, return value dạng array ['success'=>bool,'message'=>string,...].
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Logger.php';
require_once __DIR__ . '/../models/Product.php';
require_once __DIR__ . '/../models/Inventory.php';
require_once __DIR__ . '/../models/Order.php';
require_once __DIR__ . '/../models/Sales.php';
require_once __DIR__ . '/../models/Supplier.php';
require_once __DIR__ . '/../models/StockCount.php';

class ManagerService
{
    private Product $productModel;
    private Inventory $inventoryModel;
    private Order $orderModel;
    private Sales $salesModel;
    private Supplier $supplierModel;
    private StockCount $stockCountModel;

    public function __construct()
    {
        $this->productModel    = new Product();
        $this->inventoryModel  = new Inventory();
        $this->orderModel      = new Order();
        $this->salesModel      = new Sales();
        $this->supplierModel   = new Supplier();
        $this->stockCountModel = new StockCount();
    }

    // =====================================================================
    // 1. DASHBOARD (FR-MGR-01)
    // =====================================================================

    /**
     * FR-MGR-01: dữ liệu tổng hợp cho dashboard - tồn kho, doanh số gần đây,
     * cảnh báo tồn thấp. NFR-02: hàm này chỉ chạy các query đơn giản/đã có
     * index qua PK-FK, để đáp ứng yêu cầu load ≤ 3 giây với ~500-1000 SKU.
     *
     * ⚠️ "Revenue" (doanh thu BÁN HÀNG) trong User Story gốc vẫn KHÔNG tính
     * được - products.unit_cost là giá NHẬP (dùng để tính giá trị đơn đặt
     * hàng PO), KHÔNG phải giá BÁN LẺ. sales_transaction_details vẫn chưa có
     * cột giá bán -> dashboard trả về SỐ LƯỢNG bán, chưa có doanh thu bằng tiền.
     */
    public function getDashboardSummary(): array
    {
        return [
            'total_stock_by_product' => $this->inventoryModel->getStockByProduct(),
            'low_stock_alerts'       => $this->getLowStockAlerts(),
            'stockout_risk_top10'    => $this->getStockoutRisk(),
            'recent_sales'           => $this->salesModel->getAll(
                date('Y-m-d', strtotime('-7 days')),
                date('Y-m-d 23:59:59')
            ),
            'pending_po_count'       => count($this->orderModel->getPendingApproval()),
            'note_revenue'           => 'products.unit_cost is the purchase cost (used for PO value), '
                                      . 'there is no retail selling price yet so the dashboard shows quantities, not revenue.',
        ];
    }

    // =====================================================================
    // 2. LOW-STOCK ALERTS & REORDER POINT (FR-MGR-02, FR-MGR-03, BR-04, BR-13)
    // =====================================================================

    /**
     * FR-MGR-03 / BR-04: danh sách sản phẩm đã chạm hoặc dưới Reorder Point.
     * BR-13: ưu tiên sản phẩm bán chạy (sales_volume) lên đầu danh sách.
     * BR-05: rule hiệu lực lấy qua Product::getEffectiveReorderRule() (đã có
     * sẵn logic ưu tiên rule riêng theo product_id trước, fallback category).
     *
     * NFR-02: với ~500-1000 SKU, việc lặp N+1 query getEffectiveReorderRule()
     * cho từng sản phẩm có thể chậm khi N lớn - chấp nhận được ở quy mô cửa
     * hàng lẻ (GS25 thực tế thường vài trăm SKU/chi nhánh), nhưng nếu đo được
     * vượt ngưỡng 3s (NFR-02) cần tối ưu bằng 1 query JOIN duy nhất thay vì
     * lặp gọi Model - ghi chú lại để tối ưu sau nếu benchmark thực tế cần.
     */
    public function getLowStockAlerts(): array
    {
        $stockList = $this->inventoryModel->getStockByProduct();
        $alerts = [];

        foreach ($stockList as $stockRow) {
            $productId = (int) $stockRow['product_id'];
            $rule = $this->productModel->getEffectiveReorderRule($productId);

            if ($rule === false) {
                continue; // Sản phẩm chưa có reorder rule nào cấu hình -> bỏ qua, không cảnh báo mù.
            }

            $totalQty = (int) $stockRow['total_quantity'];
            if ($totalQty <= (int) $rule['reorder_point']) {
                $salesVolume = $this->getRecentSalesVolume($productId, STOCKOUT_RISK_SALES_WINDOW_DAYS);

                $alerts[] = [
                    'product_id'       => $productId,
                    'sku_code'         => $stockRow['sku_code'],
                    'product_name'     => $stockRow['product_name'],
                    'current_quantity' => $totalQty,
                    'reorder_point'    => (int) $rule['reorder_point'],
                    'safety_stock'     => (int) $rule['safety_stock'],
                    'sales_volume_7d'  => $salesVolume, // dùng để sort BR-13
                ];
            }
        }

        // BR-13: sắp xếp theo sales_volume giảm dần - sản phẩm bán chạy lên đầu.
        usort($alerts, fn(array $a, array $b) => $b['sales_volume_7d'] <=> $a['sales_volume_7d']);

        return $alerts;
    }

    /** Tổng số lượng đã bán trong N ngày gần nhất - dùng chung cho BR-13 và FR-MGR-12. */
    private function getRecentSalesVolume(int $productId, int $days): int
    {
        $history = $this->salesModel->getSalesHistory($productId, $days);
        $total = 0;
        foreach ($history as $row) {
            $total += (int) $row['total_quantity'];
        }
        return $total;
    }

    // =====================================================================
    // 3. TOP 10 STOCK-OUT RISK (FR-MGR-12) — công thức cốt lõi, tách riêng
    // =====================================================================

    /**
     * FR-MGR-12: Top 10 sản phẩm có nguy cơ hết hàng cao nhất trong 24h tới,
     * xếp hạng theo (current_stock ÷ avg_daily_sales_7d) - giá trị càng NHỎ
     * càng nguy cấp (hết hàng càng sớm).
     *
     * Quy ước xử lý biên (edge case) - GIỮ NGUYÊN CÔNG THỨC NÀY KHI SỬA SAU:
     *   - avg_daily_sales_7d = 0 (sản phẩm không bán được ngày nào trong 7 ngày
     *     qua): KHÔNG đưa vào danh sách rủi ro, vì (X ÷ 0) không xác định và về
     *     nghiệp vụ, sản phẩm không có sales velocity thì không "sắp hết hàng
     *     trong 24h" theo đúng nghĩa của yêu cầu (predicting items likely to
     *     hit zero within the next 24h BASED ON RECENT SALES VELOCITY).
     *   - current_stock = 0 VÀ avg_daily_sales_7d > 0: risk_hours = 0 (đã hết
     *     hàng ngay bây giờ) - vẫn đưa vào đầu danh sách, đây là ca nguy cấp nhất.
     *   - Sản phẩm is_active = 0 (ngừng kinh doanh): loại khỏi danh sách, vì
     *     getStockByProduct() đã tự lọc is_active=1 ở Inventory.php.
     *
     * @return array Danh sách tối đa 10 sản phẩm, sắp xếp risk tăng dần
     *               (nguy cấp nhất lên đầu), mỗi phần tử có 'risk_hours'.
     */
    public function getStockoutRisk(): array
    {
        $stockList = $this->inventoryModel->getStockByProduct();
        $risks = [];

        foreach ($stockList as $stockRow) {
            $productId = (int) $stockRow['product_id'];
            $currentStock = (int) $stockRow['total_quantity'];

            $totalSold7d = $this->getRecentSalesVolume($productId, STOCKOUT_RISK_SALES_WINDOW_DAYS);
            $avgDailySales = $totalSold7d / STOCKOUT_RISK_SALES_WINDOW_DAYS;

            if ($avgDailySales <= 0) {
                continue; // Không có sales velocity -> không xác định được thời điểm hết hàng.
            }

            $daysOfStockLeft = $currentStock / $avgDailySales;
            $hoursOfStockLeft = $daysOfStockLeft * 24;

            // Lấy thêm reorder_point (rule riêng theo product, fallback theo
            // category - BR-05) để hiển thị chung 1 bảng duy nhất với ngưỡng
            // đặt hàng, thay vì phải tách 2 bảng riêng (Top rủi ro / Cảnh báo
            // tồn thấp) như trước - tránh trùng lặp thông tin trên dashboard.
            $rule = $this->productModel->getEffectiveReorderRule($productId);

            $risks[] = [
                'product_id'         => $productId,
                'sku_code'           => $stockRow['sku_code'],
                'product_name'       => $stockRow['product_name'],
                'current_stock'      => $currentStock,
                'reorder_point'      => $rule ? (int) $rule['reorder_point'] : null,
                'avg_daily_sales_7d' => round($avgDailySales, 2),
                'risk_hours'         => round($hoursOfStockLeft, 1),
            ];
        }

        // Nguy cấp nhất (risk_hours nhỏ nhất) lên đầu.
        usort($risks, fn(array $a, array $b) => $a['risk_hours'] <=> $b['risk_hours']);

        return array_slice($risks, 0, 10);
    }

    // =====================================================================
    // 4. REPLENISHMENT / REORDER SUGGESTIONS (FR-MGR-02, FR-MGR-04, FR-MGR-05)
    // =====================================================================

    /**
     * FR-MGR-02: chuẩn bị danh sách gợi ý đặt hàng để Manager xem trước khi tạo PO.
     *
     * ⚠️⚠️ PLACEHOLDER - CHƯA HOẠT ĐỘNG ⚠️⚠️
     * Công thức tính suggested_qty theo BR-05 (dựa trên reorder_point,
     * safety_stock, xu hướng bán gần đây) thuộc phạm vi ReorderService.php,
     * hiện file đó vẫn TRỐNG (0 dòng - chưa được code ở phase nào). Hàm này
     * gọi ReorderService::suggestQuantity() như điểm nối sẵn cho phase sau -
     * NẾU GỌI HÀM NÀY TRƯỚC KHI ReorderService.php ĐƯỢC VIẾT, sẽ gây
     * "Fatal error: Uncaught Error: Class ReorderService not found".
     * KHÔNG xóa require_once bên dưới khi ReorderService.php đã có code -
     * chỉ cần đảm bảo đường dẫn đúng.
     *
     * @return array{success: bool, suggestions?: array, message: string}
     */
    public function getReorderSuggestions(): array
    {
        $reorderServicePath = __DIR__ . '/ReorderService.php';

        if (!file_exists($reorderServicePath) || filesize($reorderServicePath) === 0) {
            return [
                'success' => false,
                'message' => 'Order suggestion feature (BR-05) is not ready yet - ReorderService.php has not been coded (later phase).',
            ];
        }

        require_once $reorderServicePath;

        /** @phpstan-ignore-next-line class chỉ tồn tại sau khi ReorderService.php được code ở phase sau */
        $reorderService = new ReorderService();

        // Chữ ký hàm dự kiến - CẦN THỐNG NHẤT LẠI với nhóm khi code ReorderService thật:
        // ReorderService::suggestQuantity(): array trả về danh sách toàn bộ sản phẩm
        // dưới reorder_point kèm suggested_qty tính theo BR-05.
        return [
            'success'     => true,
            'suggestions' => $reorderService->suggestQuantity(),
            'message'     => 'Order suggestion calculated.',
        ];
    }

    /**
     * FR-MGR-10: gợi ý bổ sung từ Demand Forecast API, hiển thị TÁCH BIỆT với
     * gợi ý theo rule (BR-05) để Manager so sánh 2 nguồn.
     *
     * IntegrationService::getForecastForProduct() tự xử lý fallback BR-18
     * (API lỗi/timeout -> rule-based qua ReorderService) và tự ghi audit vào
     * demand_forecasts - Service này chỉ cần gọi thẳng, không lặp lại logic.
     */
    public function getForecastSuggestion(int $productId): array
    {
        require_once __DIR__ . '/IntegrationService.php';
        $integrationService = new IntegrationService();

        return $integrationService->getForecastForProduct($productId);
    }

    /** Danh sách sản phẩm dùng cho màn hình Forecast của Manager. */
    public function getForecastProducts(): array
    {
        $products = $this->productModel->getAll(null, null, true);
        $stocksByProduct = [];
        foreach ($this->inventoryModel->getStockByProduct() as $stock) {
            $stocksByProduct[(int) $stock['product_id']] = (int) $stock['total_quantity'];
        }

        return array_map(
            static fn(array $product): array => [
                'product_id' => (int) $product['product_id'],
                'sku_code' => $product['sku_code'],
                'product_name' => $product['product_name'],
                'category_type' => $product['category_type'],
                'current_stock' => $stocksByProduct[(int) $product['product_id']] ?? 0,
            ],
            $products
        );
    }

    /**
     * FR-MGR-02/04/05: map product_id -> supplier_id + supplier_name cho TOÀN
     * BỘ sản phẩm active - dùng ở UI reorder_suggestions.php để GOM các gợi ý
     * theo nhà cung cấp trước khi tạo PO, vì Order::createDraft() chỉ nhận 1
     * supplier_id duy nhất cho mỗi đơn (BR-07: 1 PO = 1 NCC).
     *
     * @return array<int, array{supplier_id: int, supplier_name: string}>
     */
    public function getProductSupplierMap(): array
    {
        return $this->productModel->getSupplierMapForActiveProducts();
    }

    /**
     * FR-MGR-04/05: danh sách sản phẩm active THUỘC 1 nhà cung cấp cụ thể,
     * kèm unit_cost + tồn kho hiện tại - dùng cho màn "Tạo PO thủ công"
     * (po_create.php), nơi Manager TỰ CHỌN sản phẩm/số lượng thay vì bắt buộc
     * đi từ danh sách gợi ý của ReorderService. Lọc theo supplier ngay từ
     * đầu vì BR-07 (1 PO = 1 NCC) - tránh Manager chọn nhầm sản phẩm không
     * thuộc NCC đã chọn ở bước trước.
     *
     * @return array<int, array{product_id:int, sku_code:string, product_name:string, unit_cost:float, current_stock:int}>
     */
    public function getProductsBySupplier(int $supplierId): array
    {
        $products = $this->productModel->getBySupplier($supplierId);

        $stocksByProduct = [];
        foreach ($this->inventoryModel->getStockByProduct() as $stock) {
            $stocksByProduct[(int) $stock['product_id']] = (int) $stock['total_quantity'];
        }

        return array_map(
            static fn(array $product): array => [
                'product_id'     => (int) $product['product_id'],
                'sku_code'       => $product['sku_code'],
                'product_name'   => $product['product_name'],
                'unit_cost'      => (float) $product['unit_cost'],
                'current_stock'  => $stocksByProduct[(int) $product['product_id']] ?? 0,
            ],
            $products
        );
    }

    /**
     * FR-MGR-04 / BR-06: Manager tạo PO mới với số lượng TỰ NHẬP (đã override
     * sẵn từ đầu, vì ReorderService gợi ý số lượng ban đầu chưa có ở phase này).
     * approved_qty = suggested_qty tại bước tạo Draft; Manager sửa lại qua
     * overridePoLineQuantity() bên dưới nếu cần đổi trước khi submit.
     *
     * $lines: [['product_id'=>int,'suggested_qty'=>int,'approved_qty'=>int?], ...]
     *
     * @return array{success: bool, po_id?: string, message: string}
     */
    public function createPurchaseOrderDraft(int $supplierId, int $createdBy, array $lines): array
    {
        if (empty($lines)) {
            return ['success' => false, 'message' => 'Purchase order must have at least 1 product line.'];
        }

        foreach ($lines as $line) {
            if (empty($line['product_id']) || !isset($line['suggested_qty']) || (int) $line['suggested_qty'] <= 0) {
                return ['success' => false, 'message' => 'Each product line must have a product_id and quantity > 0.'];
            }
        }

        return $this->orderModel->createDraft($supplierId, $createdBy, $lines);
    }

    /**
     * FR-MGR-04 / BR-06: Manager sửa số lượng 1 dòng PO trước khi submit -
     * chỉ được phép khi PO còn 'Draft' (Order::updateLineQuantity() đã tự
     * kiểm tra status và tự ghi Logger::log('OVERRIDE_PO_QTY')).
     */
    public function overridePoLineQuantity(int $poId, int $poDetailId, int $newQty, int $managerId): array
    {
        if ($newQty <= 0) {
            return ['success' => false, 'message' => 'Quantity must be greater than 0.'];
        }

        return $this->orderModel->updateLineQuantity($poId, $poDetailId, $newQty, $managerId);
    }

    /**
     * FR-MGR-05: Manager submit PO cho Admin duyệt - Draft -> Pending.
     * BR-07: PO KHÔNG được gửi tới nhà cung cấp ở bước này - chỉ đổi trạng
     * thái nội bộ, chờ AdminService::approvePurchaseOrder() ở Phase Admin.
     */
    public function submitPurchaseOrder(int $poId): array
    {
        return $this->orderModel->submitForApproval($poId);
    }

    /** FR-MGR-06: xem trạng thái các PO đã tạo (pending/approved/delivered), lọc theo Manager hiện tại. */
    public function listMyPurchaseOrders(int $managerId, ?string $status = null): array
    {
        return $this->orderModel->getAll($status, $managerId);
    }

    public function getPurchaseOrderDetail(int $poId): array|false
    {
        $po = $this->orderModel->getById($poId);
        if ($po === false) {
            return false;
        }

        $po['details'] = $this->orderModel->getDetails($poId);
        // FR-MGR-11: kèm sẵn hiệu suất nhà cung cấp (lead-time, tỉ lệ sai lệch)
        // để trang chi tiết PO hiển thị "Supplier Details" mà không cần query thêm ở view.
        $supplierPerf = $this->supplierModel->getPerformanceStats((int) $po['supplier_id']);
        $po['supplier_performance'] = $supplierPerf !== false ? $supplierPerf : null;
        return $po;
    }

    // =====================================================================
    // 5. SHORTAGE INCIDENTS (FR-MGR-07)
    // =====================================================================

    /** FR-MGR-07: ghi nhận 1 sự cố thiếu hàng, mặc định trạng thái 'Open'. */
    public function logShortageIncident(int $productId, int $managerId, ?string $resolutionAction = null): array
    {
        $incidentId = $this->stockCountModel->createShortageIncident($productId, $managerId, $resolutionAction);

        return ['success' => true, 'incident_id' => $incidentId, 'message' => 'Stock shortage incident recorded.'];
    }

    /** FR-MGR-07: cập nhật hướng xử lý và đóng 1 sự cố thiếu hàng - Open -> Resolved. */
    public function resolveShortageIncident(int $incidentId, string $resolutionAction): array
    {
        if (trim($resolutionAction) === '') {
            return ['success' => false, 'message' => 'Please enter a resolution action before closing the incident.'];
        }

        $ok = $this->stockCountModel->resolveShortageIncident($incidentId, $resolutionAction);

        return ['success' => $ok, 'message' => $ok ? 'Stock shortage incident closed.' : 'An error occurred.'];
    }

    public function listShortageIncidents(?string $status = null): array
    {
        return $this->stockCountModel->getShortageIncidents($status);
    }

    // =====================================================================
    // 6. ANALYTICS (FR-MGR-08, FR-MGR-09)
    // =====================================================================

    /**
     * FR-MGR-08: xu hướng nhu cầu 7/30 ngày cho 1 sản phẩm - tái sử dụng
     * Sales::getSalesHistory() đã có sẵn (chỉ chấp nhận đúng 7 hoặc 30 theo
     * SALES_HISTORY_SHORT_RANGE_DAYS/SALES_HISTORY_LONG_RANGE_DAYS).
     */
    public function getDemandTrend(int $productId, int $days = SALES_HISTORY_SHORT_RANGE_DAYS): array
    {
        return $this->salesModel->getSalesHistory($productId, $days);
    }

    /**
     * FR-MGR-09: Product Performance Analysis - xếp hạng sản phẩm theo SỐ
     * LƯỢNG bán và số lần điều chỉnh/thất thoát trong khoảng thời gian, để
     * Manager xác định best-seller (ưu tiên trưng bày) và slow-moving (cân
     * nhắc loại bỏ).
     *
     * ⚠️ "Revenue" và "inventory turnover rate" theo đúng nghĩa tài chính
     * (yêu cầu gốc trong User Story) KHÔNG tính được vì thiếu cột giá - báo
     * cáo dưới đây dùng SỐ LƯỢNG bán làm proxy cho "doanh thu" và tỉ lệ
     * (số lượng bán ÷ tồn kho hiện tại) làm proxy cho "turnover rate", đã ghi
     * rõ trong khóa 'note' của kết quả trả về để không gây hiểu nhầm là số liệu tài chính thật.
     */
    public function getProductPerformanceReport(string $fromDate, string $toDate): array
    {
        $pdo = Database::getConnection();

        $sql = "SELECT p.product_id, p.sku_code, p.product_name, p.unit_cost,
                       COALESCE(SUM(std.quantity_sold), 0) AS total_quantity_sold,
                       COALESCE(stock_total.total_stock, 0) AS current_stock
                FROM products p
                LEFT JOIN sales_transaction_details std ON std.product_id = p.product_id
                LEFT JOIN sales_transactions st
                       ON st.transaction_id = std.transaction_id
                      AND st.transaction_time BETWEEN :from_date AND :to_date
                LEFT JOIN (
                    SELECT product_id, SUM(quantity_on_hand) AS total_stock
                    FROM stock
                    GROUP BY product_id
                ) stock_total ON stock_total.product_id = p.product_id
                WHERE p.is_active = 1
                GROUP BY p.product_id, p.sku_code, p.product_name, p.unit_cost, stock_total.total_stock
                ORDER BY total_quantity_sold DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':from_date' => $fromDate, ':to_date' => $toDate]);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $sold      = (int) $row['total_quantity_sold'];
            $stock     = (int) $row['current_stock'];
            $unitCost  = (float) $row['unit_cost'];

            // Turnover theo SỐ LƯỢNG - giữ lại để tương thích ngược với UI/báo cáo cũ.
            $row['turnover_ratio_approx'] = $stock > 0 ? round($sold / $stock, 2) : null;

            // Turnover theo GIÁ TRỊ (COGS ÷ giá trị tồn kho) - nhất quán với công
            // thức đã dùng ở AdminService::calculateKpisForPeriod().
            $cogs = $sold * $unitCost;
            $stockValue = $stock * $unitCost;
            $row['cogs_value']            = $cogs;
            $row['stock_value']           = $stockValue;
            $row['turnover_value_ratio']  = $stockValue > 0 ? round($cogs / $stockValue, 2) : null;
        }
        unset($row);

        return [
            'from_date' => $fromDate,
            'to_date'   => $toDate,
            'products'  => $rows,
            'note'      => 'unit_cost is the PURCHASE cost (cost basis); there is no retail selling price yet, so sales '
                          . 'revenue still CANNOT be calculated. turnover_ratio_approx = quantity sold ÷ current stock; '
                          . 'turnover_value_ratio = COGS (quantity sold × unit_cost) ÷ current stock value.',
        ];
    }

    // =====================================================================
    // 7. VENDOR MANAGEMENT (FR-MGR-11)
    // =====================================================================

    /** FR-MGR-11: lead-time và tỉ lệ sai lệch giao hàng của 1 nhà cung cấp - hiển thị khi tạo PO. */
    public function getSupplierPerformance(int $supplierId): array|false
    {
        return $this->supplierModel->getPerformanceStats($supplierId);
    }

    public function listSuppliers(): array
    {
        return $this->supplierModel->getAll();
    }

    /**
     * Vendor Management: gợi ý nhà cung cấp tin cậy nhất (ít sai lệch nhất,
     * lead-time thấp nhất) khi Manager chuẩn bị tạo PO mới.
     */
    public function getMostReliableSuppliers(int $limit = 5): array
    {
        return $this->supplierModel->getMostReliable($limit);
    }

    // =====================================================================
    // 8. DASHBOARD TỔNG HỢP MỞ RỘNG (FR-MGR-01) - cho manager/dashboard.php
    // =====================================================================

    /**
     * Bản mở rộng của getDashboardSummary() - gộp thêm dữ liệu cho các panel
     * dashboard mới (xu hướng bán 7 ngày, phân bố trạng thái PO của CHÍNH
     * Manager này, sự cố thiếu hàng đang mở, gợi ý đặt hàng BR-05) mà KHÔNG
     * lặp lại query đã có: sales_trend_7d tính trực tiếp từ recent_sales của
     * getDashboardSummary(), đúng tinh thần NFR-02 (hạn chế query dư thừa).
     *
     * @return array Tất cả khóa của getDashboardSummary() + các khóa mới:
     *   'reorder_suggestions' (array{success:bool,suggestions?:array,message:string}),
     *   'sales_trend_7d' (7 phần tử ['date','label','count']),
     *   'po_status_distribution' (5 phần tử ['status','count'], đúng thứ tự Draft->Delivered),
     *   'open_shortages' (array), 'open_shortage_count' (int)
     */
    public function getDashboardOverview(int $managerId): array
    {
        $summary = $this->getDashboardSummary();

        // Sales trend 7 ngày: đếm SỐ GIAO DỊCH mỗi ngày từ recent_sales đã lấy sẵn
        // ở getDashboardSummary() - không query CSDL thêm lần nào.
        $salesByDate = [];
        foreach ($summary['recent_sales'] as $sale) {
            $day = substr((string) $sale['transaction_time'], 0, 10);
            $salesByDate[$day] = ($salesByDate[$day] ?? 0) + 1;
        }
        $salesTrend7d = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-{$i} days"));
            $salesTrend7d[] = [
                'date'  => $day,
                'label' => date('d/m', strtotime($day)),
                'count' => $salesByDate[$day] ?? 0,
            ];
        }

        // Phân bố trạng thái PO CHỈ của Manager hiện tại (FR-MGR-06) - không phải
        // toàn hệ thống (khác PO Workflow bên Admin, vốn tính trên TOÀN BỘ PO).
        $myOrders = $this->listMyPurchaseOrders($managerId);
        $statusCounts = ['Draft' => 0, 'Pending' => 0, 'Approved' => 0, 'Rejected' => 0, 'Delivered' => 0];
        foreach ($myOrders as $po) {
            $status = $po['status'];
            if (isset($statusCounts[$status])) {
                $statusCounts[$status]++;
            }
        }
        $poStatusDistribution = [];
        foreach ($statusCounts as $status => $count) {
            $poStatusDistribution[] = ['status' => $status, 'count' => $count];
        }

        // FR-MGR-07: sự cố thiếu hàng đang mở (chưa Resolved).
        $openShortages = $this->listShortageIncidents('Open');

        // PO của CHÍNH Manager này đang chờ Admin duyệt - khác 'pending_po_count'
        // ở getDashboardSummary() (đếm TOÀN HỆ THỐNG, dùng cho KPI); danh sách này
        // dùng cho Alerts panel nên chỉ cần đúng PO của Manager đang xem dashboard.
        $myPendingOrders = array_values(array_filter($myOrders, static fn(array $po): bool => $po['status'] === 'Pending'));

        $summary['reorder_suggestions']     = $this->getReorderSuggestions(); // BR-05, FR-MGR-02
        $summary['sales_trend_7d']          = $salesTrend7d;
        $summary['po_status_distribution']  = $poStatusDistribution;
        $summary['open_shortages']          = $openShortages;
        $summary['my_pending_orders']       = $myPendingOrders;
        $summary['open_shortage_count']     = count($openShortages);

        return $summary;
    }

    // =====================================================================
    // 9. PERFORMANCE ANALYTICS (frontend/manager/reports/performance_analytics.php)
    // =====================================================================

    /**
     * Dữ liệu tổng hợp cho trang Performance Analytics của Manager. Khác với
     * getProductPerformanceReport() (xếp hạng CHI TIẾT từng sản phẩm), hàm
     * này tính các KPI TỔNG toàn hệ thống trong 1 khoảng ngày cho trang tổng
     * quan (Avg Inventory Value, Waste Rate, Revenue, xu hướng, v.v).
     *
     * ⚠️ selling_price ĐÃ CÓ SẴN trong bảng products (khác với ghi chú cũ ở
     * getProductPerformanceReport() nói "chưa có giá bán lẻ" - ghi chú đó lỗi
     * thời so với schema hiện tại) - hàm này là nơi ĐẦU TIÊN dùng
     * selling_price để tính doanh thu bằng tiền thật (SUM(quantity_sold *
     * selling_price)), không còn phải dùng số lượng làm proxy nữa.
     *
     * KHÔNG bao gồm (không có backing data thật trong DB, xem trao đổi lúc
     * thiết kế): "AI Demand Accuracy %" (demand_forecasts không lưu
     * actual/predicted theo ngày để so sánh), "Sales vs Target" (không có
     * cột target ở đâu), "Forecast Variance" (không có lead-time-variance
     * theo từng đơn để đối chiếu quantity-error). Các card này được thay
     * bằng Top Suppliers/Waste theo category - đều tính từ dữ liệu thật.
     *
     * @return array{
     *   from_date:string, to_date:string,
     *   avg_inventory_value:float,
     *   waste:array{waste_qty:int, waste_value:float, stock_in_qty:int, waste_rate_percent:?float, by_category: array},
     *   revenue:array{total_revenue:float, total_quantity_sold:int, by_month: array},
     *   stockout_trend: array (sự cố theo ngày),
     *   category_strength: array (turnover value ratio trung bình theo category),
     *   top_suppliers: array,
     *   top_overstock_risks: array
     * }
     */
    public function getPerformanceAnalytics(string $fromDate, string $toDate): array
    {
        $pdo = Database::getConnection();

        // --- 1. Avg Inventory Value: giá trị tồn kho hiện tại theo giá nhập,
        // cùng công thức đã dùng ở AdminService/inventory_health.php. ---
        $stmt = $pdo->query(
            "SELECT COALESCE(SUM(s.quantity_on_hand * p.unit_cost), 0) AS total_value
             FROM stock s
             JOIN products p ON p.product_id = s.product_id
             WHERE p.is_active = 1"
        );
        $avgInventoryValue = (float) $stmt->fetch()['total_value'];

        // --- 2. Waste: dựa trên stock_movements loại 'adjustment' có lý do
        // thuộc nhóm hao hụt thật (Expired/Damaged/Lost/Defective) - KHÁC với
        // 'count_correction' (điều chỉnh do sai lệch kiểm kê, không hẳn là hao hụt). ---
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(-m.quantity_change), 0) AS waste_qty,
                    COALESCE(SUM(-m.quantity_change * p.unit_cost), 0) AS waste_value
             FROM stock_movements m
             JOIN products p ON p.product_id = m.product_id
             WHERE m.movement_type = 'adjustment'
               AND m.quantity_change < 0
               AND (m.reason LIKE '%Expired%' OR m.reason LIKE '%Damage%' OR m.reason LIKE '%Loss%' OR m.reason LIKE '%Defective%')
               AND m.created_at BETWEEN :from_date AND :to_date"
        );
        $stmt->execute([':from_date' => $fromDate, ':to_date' => $toDate]);
        $wasteRow = $stmt->fetch();
        $wasteQty   = (int) $wasteRow['waste_qty'];
        $wasteValue = (float) $wasteRow['waste_value'];

        // Mẫu số cho tỉ lệ hao hụt: tổng SL nhập kho (stock_in) cùng kỳ -
        // nếu không có nhập hàng nào thì không tính được tỉ lệ (tránh chia 0).
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(quantity_change), 0) AS stock_in_qty
             FROM stock_movements
             WHERE movement_type = 'stock_in' AND created_at BETWEEN :from_date AND :to_date"
        );
        $stmt->execute([':from_date' => $fromDate, ':to_date' => $toDate]);
        $stockInQty = (int) $stmt->fetch()['stock_in_qty'];
        $wasteRatePercent = $stockInQty > 0 ? round(($wasteQty / $stockInQty) * 100, 2) : null;

        // Waste theo category (thay cho "Forecast Variance" - không có data thật).
        $stmt = $pdo->prepare(
            "SELECT c.category_name,
                    COALESCE(SUM(-m.quantity_change), 0) AS waste_qty,
                    COALESCE(SUM(-m.quantity_change * p.unit_cost), 0) AS waste_value
             FROM stock_movements m
             JOIN products p ON p.product_id = m.product_id
             JOIN categories c ON c.category_id = p.category_id
             WHERE m.movement_type = 'adjustment'
               AND m.quantity_change < 0
               AND (m.reason LIKE '%Expired%' OR m.reason LIKE '%Damage%' OR m.reason LIKE '%Loss%' OR m.reason LIKE '%Defective%')
               AND m.created_at BETWEEN :from_date AND :to_date
             GROUP BY c.category_id, c.category_name
             ORDER BY waste_value DESC"
        );
        $stmt->execute([':from_date' => $fromDate, ':to_date' => $toDate]);
        $wasteByCategory = $stmt->fetchAll();

        // --- 3. Revenue: SUM(quantity_sold * selling_price) - LẦN ĐẦU dùng
        // selling_price để tính doanh thu bằng tiền thật trong toàn bộ codebase. ---
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(std.quantity_sold * p.selling_price), 0) AS total_revenue,
                    COALESCE(SUM(std.quantity_sold), 0) AS total_quantity_sold
             FROM sales_transaction_details std
             JOIN sales_transactions st ON st.transaction_id = std.transaction_id
             JOIN products p ON p.product_id = std.product_id
             WHERE st.transaction_time BETWEEN :from_date AND :to_date"
        );
        $stmt->execute([':from_date' => $fromDate, ':to_date' => $toDate]);
        $revenueRow = $stmt->fetch();

        // Doanh thu theo tháng (thay cho "Sales vs Target" - không có cột target).
        $stmt = $pdo->prepare(
            "SELECT DATE_FORMAT(st.transaction_time, '%Y-%m') AS month,
                    COALESCE(SUM(std.quantity_sold * p.selling_price), 0) AS revenue
             FROM sales_transaction_details std
             JOIN sales_transactions st ON st.transaction_id = std.transaction_id
             JOIN products p ON p.product_id = std.product_id
             WHERE st.transaction_time BETWEEN :from_date AND :to_date
             GROUP BY month
             ORDER BY month ASC"
        );
        $stmt->execute([':from_date' => $fromDate, ':to_date' => $toDate]);
        $revenueByMonth = $stmt->fetchAll();

        // --- 4. Stock-out trend: số sự cố (shortage_incidents) theo ngày. ---
        $stmt = $pdo->prepare(
            "SELECT DATE(created_at) AS incident_date, COUNT(*) AS incident_count
             FROM shortage_incidents
             WHERE created_at BETWEEN :from_date AND :to_date
             GROUP BY incident_date
             ORDER BY incident_date ASC"
        );
        $stmt->execute([':from_date' => $fromDate, ':to_date' => $toDate]);
        $stockoutTrend = $stmt->fetchAll();

        // --- 5. Category Strength: turnover theo giá trị trung bình mỗi category
        // (dùng lại công thức COGS/stock_value đã chuẩn hóa ở getProductPerformanceReport()). ---
        $stmt = $pdo->prepare(
            "SELECT c.category_name,
                    COALESCE(SUM(std.quantity_sold), 0) AS total_quantity_sold,
                    COALESCE(SUM(std.quantity_sold * p.unit_cost), 0) AS cogs_value,
                    COALESCE(SUM(stock_total.stock_value), 0) AS stock_value
             FROM categories c
             JOIN products p ON p.category_id = c.category_id AND p.is_active = 1
             LEFT JOIN sales_transaction_details std ON std.product_id = p.product_id
             LEFT JOIN sales_transactions st
                    ON st.transaction_id = std.transaction_id
                   AND st.transaction_time BETWEEN :from_date AND :to_date
             LEFT JOIN (
                 SELECT s.product_id, SUM(s.quantity_on_hand * p2.unit_cost) AS stock_value
                 FROM stock s
                 JOIN products p2 ON p2.product_id = s.product_id
                 GROUP BY s.product_id
             ) stock_total ON stock_total.product_id = p.product_id
             GROUP BY c.category_id, c.category_name"
        );
        $stmt->execute([':from_date' => $fromDate, ':to_date' => $toDate]);
        $categoryRows = $stmt->fetchAll();
        foreach ($categoryRows as &$catRow) {
            $stockValue = (float) $catRow['stock_value'];
            $cogsValue  = (float) $catRow['cogs_value'];
            $catRow['turnover_value_ratio'] = $stockValue > 0 ? round($cogsValue / $stockValue, 2) : 0.0;
        }
        unset($catRow);

        // --- 6. Top Suppliers tin cậy nhất (thay cho "AI Demand Accuracy" - không có data thật). ---
        $topSuppliers = $this->supplierModel->getMostReliable(5);

        // --- 7. Top Overstock Risks: tồn kho cao NHƯNG bán rất chậm/không bán
        // trong kỳ - ngược lại với "Top Priority Reorder" (đã có ở trang
        // Inventory Health) vốn liệt kê SP tồn THẤP. ---
        $stmt = $pdo->prepare(
            "SELECT p.product_id, p.sku_code, p.product_name, c.category_name,
                    COALESCE(stock_total.total_stock, 0) AS current_stock,
                    COALESCE(sold.total_quantity_sold, 0) AS quantity_sold
             FROM products p
             JOIN categories c ON c.category_id = p.category_id
             LEFT JOIN (
                 SELECT product_id, SUM(quantity_on_hand) AS total_stock
                 FROM stock
                 GROUP BY product_id
             ) stock_total ON stock_total.product_id = p.product_id
             LEFT JOIN (
                 SELECT std.product_id, SUM(std.quantity_sold) AS total_quantity_sold
                 FROM sales_transaction_details std
                 JOIN sales_transactions st ON st.transaction_id = std.transaction_id
                 WHERE st.transaction_time BETWEEN :from_date AND :to_date
                 GROUP BY std.product_id
             ) sold ON sold.product_id = p.product_id
             WHERE p.is_active = 1 AND COALESCE(stock_total.total_stock, 0) > 0
             ORDER BY (COALESCE(stock_total.total_stock, 0) - COALESCE(sold.total_quantity_sold, 0)) DESC
             LIMIT 10"
        );
        $stmt->execute([':from_date' => $fromDate, ':to_date' => $toDate]);
        $topOverstockRisks = $stmt->fetchAll();

        return [
            'from_date' => $fromDate,
            'to_date'   => $toDate,
            'avg_inventory_value' => $avgInventoryValue,
            'waste' => [
                'waste_qty'           => $wasteQty,
                'waste_value'         => $wasteValue,
                'stock_in_qty'        => $stockInQty,
                'waste_rate_percent'  => $wasteRatePercent,
                'by_category'         => $wasteByCategory,
            ],
            'revenue' => [
                'total_revenue'        => (float) $revenueRow['total_revenue'],
                'total_quantity_sold'  => (int) $revenueRow['total_quantity_sold'],
                'by_month'             => $revenueByMonth,
            ],
            'stockout_trend'      => $stockoutTrend,
            'category_strength'   => $categoryRows,
            'top_suppliers'       => $topSuppliers,
            'top_overstock_risks' => $topOverstockRisks,
            'note' => 'Revenue and waste value are calculated using the actual selling_price/unit_cost from the DB. '
                     . '"AI Demand Accuracy %", "Sales vs Target", "Forecast Variance" are not shown because '
                     . 'the system does not yet store daily actual-vs-predicted data or sales targets.',
        ];
    }
}