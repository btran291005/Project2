<?php
/**
 * File: backend/services/StaffService.php
 * Purpose: Business logic for Store Staff actions — stock view, sales,
 *          goods receipt verification, inventory adjustments, stock counts,
 *          FEFO pick suggestions, customer feedback logging.
 * Related: FR-STF-01 through FR-STF-14
 *
 * PHẠM VI: Nhận hàng theo PO (FR-STF-05/07) đã có PurchaseOrderService riêng
 * (Phase trước) - file này KHÔNG lặp lại luồng đó, chỉ expose các hàm
 * xem/chuẩn bị dữ liệu cần cho Staff (VD: lấy PO đang chờ nhận) qua ủy quyền
 * (delegate) sang PurchaseOrderService, để Controller Staff chỉ cần gọi 1
 * điểm duy nhất (StaffService) thay vì phải biết cả PurchaseOrderService.
 *
 * QUY ƯỚC CHUNG: giống AdminService.php/ManagerService.php - Service không tự
 * check role (đã chặn ở Middleware trước khi vào Controller), input được ép
 * kiểu trước khi xuống Model, return value dạng array ['success'=>bool,...].
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Logger.php';
require_once __DIR__ . '/../models/Product.php';
require_once __DIR__ . '/../models/Inventory.php';
require_once __DIR__ . '/../models/Sales.php';
require_once __DIR__ . '/../models/StockCount.php';
require_once __DIR__ . '/PurchaseOrderService.php';

class StaffService
{
    private Product $productModel;
    private Inventory $inventoryModel;
    private Sales $salesModel;
    private StockCount $stockCountModel;
    private PurchaseOrderService $poService;

    public function __construct()
    {
        $this->productModel    = new Product();
        $this->inventoryModel  = new Inventory();
        $this->salesModel      = new Sales();
        $this->stockCountModel = new StockCount();
        $this->poService       = new PurchaseOrderService();
    }

    // =====================================================================
    // 1. XEM TỒN KHO (FR-STF-01, FR-STF-03, FR-STF-10)
    // =====================================================================

    /** FR-STF-01: tồn kho hiện tại của 1 sản phẩm (gộp mọi kho), hoặc toàn bộ nếu không truyền. */
    public function getStock(?int $productId = null): array
    {
        return $this->inventoryModel->getStockByProduct($productId);
    }

    /**
     * FR-STF-03 / BR-04 / BR-13: danh sách cảnh báo tồn thấp cho Staff xem,
     * cùng công thức và thứ tự ưu tiên với ManagerService::getLowStockAlerts()
     * (BR-13: sản phẩm bán chạy lên đầu) - Staff chỉ xem, không sửa reorder rule.
     */
    public function getLowStockAlerts(): array
    {
        $stockList = $this->inventoryModel->getStockByProduct();
        $alerts = [];

        foreach ($stockList as $stockRow) {
            $productId = (int) $stockRow['product_id'];
            $rule = $this->productModel->getEffectiveReorderRule($productId);

            if ($rule === false) {
                continue;
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
                    'sales_volume_7d'  => $salesVolume,
                ];
            }
        }

        usort($alerts, fn(array $a, array $b) => $b['sales_volume_7d'] <=> $a['sales_volume_7d']);

        return $alerts;
    }

    /** FR-STF-10: giống getLowStockAlerts() nhưng sắp theo mức độ nguy cấp (tồn kho/reorder_point thấp nhất trước). */
    public function getUrgentRestockList(): array
    {
        $alerts = $this->getLowStockAlerts();

        usort($alerts, function (array $a, array $b) {
            $ratioA = $a['reorder_point'] > 0 ? $a['current_quantity'] / $a['reorder_point'] : 0;
            $ratioB = $b['reorder_point'] > 0 ? $b['current_quantity'] / $b['reorder_point'] : 0;
            return $ratioA <=> $ratioB;
        });

        return $alerts;
    }

    /** Synonym for getUrgentRestockList() to support older page naming and low stock listing. */
    public function getLowStockList(): array
    {
        return $this->getUrgentRestockList();
    }

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
    // 2. BÁN HÀNG (FR-STF-02, BR-02, BR-03) — kèm FEFO nếu category yêu cầu (FR-STF-12)
    // =====================================================================

    /**
     * FR-STF-02 / BR-02 / BR-03: tạo giao dịch bán hàng, tự động trừ kho.
     * FR-STF-12: nếu category của sản phẩm yêu cầu FEFO (requires_fefo = 1)
     * và item KHÔNG tự chỉ định batch_id, tự động chọn batch theo FEFO
     * (Inventory::getNextFefoBatch()) trước khi gọi Sales::createTransaction().
     *
     * $items: [['product_id'=>int,'quantity_sold'=>int,'batch_id'=>int|null], ...]
     *
     * @return array{success: bool, transaction_id: string|null, message: string}
     */
    public function createSale(int $staffId, int $warehouseId, array $items): array
    {
        if (empty($items)) {
            return ['success' => false, 'transaction_id' => null, 'message' => 'Transaction has no products.'];
        }

        foreach ($items as &$item) {
            if (!empty($item['batch_id'])) {
                continue; // Đã chỉ định sẵn batch - không tự động chọn lại.
            }

            $product = $this->productModel->getById((int) $item['product_id']);
            if ($product !== false && !empty($product['requires_fefo'])) {
                $nextBatch = $this->inventoryModel->getNextFefoBatch((int) $item['product_id']);
                $item['batch_id'] = $nextBatch['batch_id'] ?? null;
            }
        }
        unset($item);

        $result = $this->salesModel->createTransaction($staffId, $warehouseId, $items);

        // FEFO: nếu bán thành công và item có batch_id, trừ luôn quantity_remaining của batch đó.
        if ($result['success']) {
            foreach ($items as $item) {
                if (!empty($item['batch_id'])) {
                    $this->inventoryModel->deductFromBatch((int) $item['batch_id'], (int) $item['quantity_sold']);
                }
            }
        }

        return $result;
    }

    // =====================================================================
    // 3. NHẬN HÀNG THEO PO (FR-STF-05, FR-STF-06, FR-STF-07, BR-08/09/10)
    // =====================================================================
    // Ủy quyền toàn bộ sang PurchaseOrderService (đã code ở Phase trước) - xem
    // docblock ở đầu file để biết lý do không viết lại logic tại đây.

    /** FR-STF-05: danh sách PO đã duyệt, đang chờ Staff nhận hàng. */
    public function listOrdersAwaitingReceipt(): array
    {
        return $this->poService->listOrdersAwaitingReceipt();
    }

    /** FR-STF-05: chi tiết 1 PO để Staff đối chiếu trước khi xác nhận (BR-08). */
    public function getOrderForReceiving(int $poId): array|false
    {
        return $this->poService->getOrderForReceiving($poId);
    }

    /** FR-STF-05/07: xác nhận nhận hàng 1 dòng sản phẩm. */
    public function receivePoLine(
        int $poDetailId,
        int $productId,
        int $receivedQty,
        int $warehouseId,
        int $staffId,
        ?string $discrepancyReason = null
    ): array {
        return $this->poService->receiveLine($poDetailId, $productId, $receivedQty, $warehouseId, $staffId, $discrepancyReason);
    }

    /** FR-STF-05/06/07: xác nhận nhận hàng toàn bộ đơn trong 1 lượt (NFR-05). */
    public function receiveFullOrder(int $poId, array $lines, int $warehouseId, int $staffId): array
    {
        return $this->poService->receiveFullOrder($poId, $lines, $warehouseId, $staffId);
    }

    // =====================================================================
    // 4. ĐIỀU CHỈNH TỒN KHO (FR-STF-08, BR-11, BR-12)
    // =====================================================================

    /**
     * FR-STF-08 / BR-11: điều chỉnh tồn kho (hư hỏng/hết hạn/thất thoát),
     * bắt buộc chọn lý do từ danh sách cố định (dropdown - BR-11). BR-12:
     * Inventory::adjustStock() đã tự ghi vào stock_movements (audit trail).
     */
    public function adjustStock(int $productId, int $warehouseId, int $quantityChange, string $reason, int $staffId): array
    {
        if (!in_array($reason, self::ADJUSTMENT_REASONS, true)) {
            return [
                'success' => false,
                'message' => 'Invalid adjustment reason - please choose from the defined list (BR-11).',
            ];
        }

        return $this->inventoryModel->adjustStock($productId, $warehouseId, $quantityChange, $reason, $staffId);
    }

    /** BR-11: danh sách lý do điều chỉnh hợp lệ, dùng để render dropdown trên UI. */
    public const ADJUSTMENT_REASONS = ['Damaged', 'Expired', 'Lost', 'Other'];

    public function listAdjustmentReasons(): array
    {
        return self::ADJUSTMENT_REASONS;
    }

    /** FR-STF-14: lịch sử điều chỉnh tồn kho của 1 sản phẩm. */
    public function getAdjustmentHistory(int $productId): array
    {
        return $this->inventoryModel->getAdjustmentHistory($productId);
    }

    /** Giống getAdjustmentHistory() nhưng cho TOÀN BỘ sản phẩm (bảng lịch sử trên trang Adjustments). */
    public function getAllAdjustmentHistory(?int $productId = null, ?string $reason = null, ?string $date = null): array
    {
        return $this->inventoryModel->getAllAdjustments($productId, $reason, $date);
    }

    // =====================================================================
    // 5. KIỂM KÊ ĐỊNH KỲ (FR-STF-04, FR-STF-09, BR-14)
    // =====================================================================

    /** FR-STF-09: bắt đầu 1 phiên kiểm kê mới. */
    public function startStockCountSession(int $staffId): array
    {
        $countId = $this->stockCountModel->createSession($staffId);
        return ['success' => true, 'count_id' => $countId, 'message' => 'Stock count session started.'];
    }

    /**
     * FR-STF-04 / BR-14: ghi nhận số lượng đếm thực tế cho 1 sản phẩm trong
     * phiên kiểm kê đang mở. system_qty và discrepancy được StockCount Model
     * tự tính (xem StockCount::addCountItem()).
     */
    public function recordCountItem(int $countId, int $productId, int $actualQty): array
    {
        if ($actualQty < 0) {
            return ['success' => false, 'message' => 'Counted quantity cannot be negative.'];
        }

        return $this->stockCountModel->addCountItem($countId, $productId, $actualQty);
    }

    /**
     * FR-STF-09 / BR-14: hoàn tất phiên kiểm kê - ghi nhận chênh lệch vào
     * stock_movements (KHÔNG tự sửa bảng stock - xem ghi chú SCHEMA GAP
     * trong StockCount.php, cần Admin/Manager đối chiếu thủ công theo kho).
     */
    public function finalizeStockCount(int $countId, int $staffId): array
    {
        return $this->stockCountModel->finalizeSession($countId, $staffId);
    }

    public function getStockCountDetail(int $countId): array|false
    {
        return $this->stockCountModel->getSessionById($countId);
    }

    // =====================================================================
    // 6. FEFO PICK SUGGESTION (FR-STF-12)
    // =====================================================================

    /** FR-STF-12: gợi ý lô hàng nên xuất/bán trước theo FEFO (expiry gần nhất lên đầu). */
    public function getFefoBatches(int $productId): array
    {
        return $this->inventoryModel->getBatchesForFefo($productId);
    }

    public function getNextFefoBatch(int $productId): array|null
    {
        return $this->inventoryModel->getNextFefoBatch($productId);
    }

    /** Cảnh báo lô sắp hết hạn trong N giờ tới - hỗ trợ Staff ưu tiên xử lý trước khi hư hỏng. */
    public function getExpiringBatches(int $withinHours = EXPIRY_ALERT_WINDOW_HOURS): array
    {
        return $this->inventoryModel->getExpiringBatches($withinHours);
    }

    // =====================================================================
    // 7. LỊCH SỬ BÁN HÀNG (FR-STF-13)
    // =====================================================================

    /** FR-STF-13: lịch sử bán hàng 7/30 ngày cho 1 sản phẩm - toggle qua $days. */
    public function getSalesHistory(int $productId, int $days = SALES_HISTORY_SHORT_RANGE_DAYS): array
    {
        return $this->salesModel->getSalesHistory($productId, $days);
    }

    // =====================================================================
    // 8. PHẢN HỒI KHÁCH HÀNG (FR-STF-11)
    // =====================================================================

    /** FR-STF-11: ghi nhận phản hồi/khiếu nại của khách hàng liên quan tới hết hàng. */
    public function logCustomerFeedback(?int $productId, int $staffId, string $feedbackText): array
    {
        if (trim($feedbackText) === '') {
            return ['success' => false, 'message' => 'Feedback content cannot be empty.'];
        }

        $feedbackId = $this->stockCountModel->addCustomerFeedback($productId, $staffId, trim($feedbackText));

        return ['success' => true, 'feedback_id' => $feedbackId, 'message' => 'Customer feedback recorded.'];
    }

    public function listCustomerFeedback(?int $productId = null): array
    {
        return $this->stockCountModel->getCustomerFeedback($productId);
    }

    // =====================================================================
    // 9. DASHBOARD TỔNG HỢP (cho frontend/staff/dashboard.php)
    // =====================================================================

    /**
     * Xu hướng bán hàng 7 ngày TOÀN STORE (đếm SỐ GIAO DỊCH mỗi ngày) + Top 5
     * sản phẩm bán chạy nhất trong 7 ngày đó. Khác Sales::getSalesHistory()
     * (chỉ tính CHO 1 sản phẩm) - đây là tổng hợp toàn store cho dashboard.
     * Cùng cách tính "SỐ LƯỢNG thay vì DOANH THU" như ManagerService (schema
     * chưa có cột giá bán lẻ, xem ghi chú tương tự ở AdminService/ManagerService).
     *
     * @return array{
     *   daily_transaction_count: array (7 phần tử ['date','label','count']),
     *   top_products: array (tối đa 5 phần tử ['product_id','sku_code','product_name','total_quantity_sold'])
     * }
     */
    public function getSalesOverview7d(): array
    {
        $pdo = Database::getConnection();
        $fromDate = date('Y-m-d 00:00:00', strtotime('-6 days'));
        $toDate   = date('Y-m-d 23:59:59');

        // Số giao dịch mỗi ngày (không phải số lượng sản phẩm) - khớp đúng cách
        // ManagerService::getDashboardOverview() đếm sales_trend_7d, để 2 dashboard
        // (Manager/Staff) đọc cùng 1 khái niệm "hoạt động bán hàng" nhất quán.
        $txStmt = $pdo->prepare(
            "SELECT DATE(transaction_time) AS sale_date, COUNT(*) AS tx_count
             FROM sales_transactions
             WHERE transaction_time BETWEEN :from_date AND :to_date
             GROUP BY DATE(transaction_time)"
        );
        $txStmt->execute([':from_date' => $fromDate, ':to_date' => $toDate]);
        $txByDate = [];
        foreach ($txStmt->fetchAll() as $row) {
            $txByDate[$row['sale_date']] = (int) $row['tx_count'];
        }

        $dailyTransactionCount = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-{$i} days"));
            $dailyTransactionCount[] = [
                'date'  => $day,
                'label' => date('D', strtotime($day)),
                'count' => $txByDate[$day] ?? 0,
            ];
        }

        // Top 5 sản phẩm bán chạy nhất (theo SỐ LƯỢNG bán) trong cùng 7 ngày.
        $topStmt = $pdo->prepare(
            "SELECT p.product_id, p.sku_code, p.product_name, SUM(std.quantity_sold) AS total_quantity_sold
             FROM sales_transaction_details std
             JOIN sales_transactions st ON st.transaction_id = std.transaction_id
             JOIN products p ON p.product_id = std.product_id
             WHERE st.transaction_time BETWEEN :from_date AND :to_date
             GROUP BY p.product_id, p.sku_code, p.product_name
             ORDER BY total_quantity_sold DESC
             LIMIT 5"
        );
        $topStmt->execute([':from_date' => $fromDate, ':to_date' => $toDate]);
        $topProducts = $topStmt->fetchAll();
        foreach ($topProducts as &$row) {
            $row['total_quantity_sold'] = (int) $row['total_quantity_sold'];
        }
        unset($row);

        return [
            'daily_transaction_count' => $dailyTransactionCount,
            'top_products'            => $topProducts,
        ];
    }

    /**
     * Thời điểm đăng nhập (LOGIN) gần nhất của 1 account - dùng làm "giờ bắt
     * đầu ca" trên dashboard, vì hệ thống KHÔNG có bảng ca làm việc (shifts)
     * thật. Đây là proxy hợp lý (session đăng nhập ~ đang trong ca làm), khác
     * hẳn việc bịa giờ ca cố định không có nguồn dữ liệu.
     */
    public function getLastLoginTime(int $accountId): ?string
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            "SELECT timestamp FROM audit_logs
             WHERE account_id = :account_id AND action_type = 'LOGIN'
             ORDER BY timestamp DESC
             LIMIT 1"
        );
        $stmt->execute([':account_id' => $accountId]);
        $row = $stmt->fetch();

        return $row !== false ? $row['timestamp'] : null;
    }
}