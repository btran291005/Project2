<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Logger.php';

class Inventory
{
    private PDO $conn;
    private string $table = 'stock';

    public function __construct()
    {
        $this->conn = Database::getConnection();
    }

    // STOCK (tồn kho hiện tại)

    /* FR-STF-01: tồn kho hiện tại theo sản phẩm, gộp tất cả warehouse. Nếu $productId = null, trả về toàn bộ danh sách sản phẩm đang active. */
    public function getStockByProduct(?int $productId = null): array
    {
        $sql = "SELECT p.product_id, p.sku_code, p.product_name, p.category_id,
                       SUM(s.quantity_on_hand) AS total_quantity
                FROM products p
                JOIN {$this->table} s ON s.product_id = p.product_id
                WHERE p.is_active = 1";
        $params = [];

        if ($productId !== null) {
            $sql .= " AND p.product_id = :product_id";
            $params[':product_id'] = $productId;
        }

        $sql .= " GROUP BY p.product_id, p.sku_code, p.product_name, p.category_id
                  ORDER BY p.product_name ASC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /* Tồn kho chi tiết theo từng warehouse - dùng khi goods_receipt/adjustments cần chọn kho cụ thể. */
    public function getStockByProductAndWarehouse(int $productId, int $warehouseId): array|false
    {
        $stmt = $this->conn->prepare(
            "SELECT stock_id, product_id, warehouse_id, quantity_on_hand, last_updated
             FROM {$this->table}
             WHERE product_id = :product_id AND warehouse_id = :warehouse_id
             LIMIT 1"
        );
        $stmt->execute([':product_id' => $productId, ':warehouse_id' => $warehouseId]);
        return $stmt->fetch();
    }

    // NHẬP KHO (stock_in) - FR-STF-06, gọi từ Service sau khi Order::recordReceipt()

    /* FR-STF-06: cộng tồn kho khi hàng về (sau khi đã xác nhận số lượng thực nhận qua PurchaseOrderService/Order model). Atomic: cộng `stock` + ghi `stock_movements` trong cùng transaction. */
    public function stockIn(
        int $productId,
        int $warehouseId,
        int $quantity,
        int $performedBy,
        ?int $referenceId = null
    ): array {
        if ($quantity <= 0) {
            return ['success' => false, 'message' => 'Stock-in quantity must be greater than 0.'];
        }

        try {
            $this->conn->beginTransaction();

            $this->upsertStockRow($productId, $warehouseId, $quantity);

            $movement = $this->conn->prepare(
                "INSERT INTO stock_movements
                    (product_id, movement_type, quantity_change, reason, reference_id, performed_by, created_at)
                 VALUES
                    (:product_id, 'stock_in', :quantity_change, NULL, :reference_id, :performed_by, NOW())"
            );
            $movement->execute([
                ':product_id'      => $productId,
                ':quantity_change' => $quantity,
                ':reference_id'    => $referenceId,
                ':performed_by'    => $performedBy,
            ]);

            $this->conn->commit();
            return ['success' => true, 'message' => 'Stock updated (goods received).'];
        } catch (PDOException $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log('[Inventory::stockIn] ' . $e->getMessage());
            return ['success' => false, 'message' => 'An error occurred while recording the stock-in.'];
        }
    }

    // ĐIỀU CHỈNH TỒN KHO (adjustment) - FR-STF-08, BR-11

    /* FR-STF-08 / BR-11: điều chỉnh tồn kho (hỏng/hết hạn/thất thoát), bắt buộc có $reason. $quantityChange có thể âm (hủy hàng) hoặc dương (phát hiện thừa khi kiểm kê thủ công ngoài phiên stock_count chính thức).
     * BR-03: nếu $quantityChange âm và làm tồn kho < 0, từ chối giao dịch. */
    public function adjustStock(
        int $productId,
        int $warehouseId,
        int $quantityChange,
        string $reason,
        int $performedBy
    ): array {
        if (trim($reason) === '') {
            return ['success' => false, 'message' => 'Stock adjustment requires a reason (BR-11).'];
        }

        try {
            $this->conn->beginTransaction();

            // Khóa dòng stock trước khi kiểm tra để tránh 2 điều chỉnh đồng thời cùng đọc thấy đủ hàng rồi cùng trừ âm (giống Sales::createTransaction()).
            $lockStmt = $this->conn->prepare(
                "SELECT quantity_on_hand FROM {$this->table}
                 WHERE product_id = :product_id AND warehouse_id = :warehouse_id
                 FOR UPDATE"
            );
            $lockStmt->execute([':product_id' => $productId, ':warehouse_id' => $warehouseId]);
            $row = $lockStmt->fetch();

            $currentQty = $row ? (int) $row['quantity_on_hand'] : 0;
            $newQty = $currentQty + $quantityChange;

            if ($newQty < 0) {
                $this->conn->rollBack();
                return [
                    'success' => false,
                    'message' => "Cannot adjust: current stock ({$currentQty}) "
                                . "is not enough to subtract " . abs($quantityChange) . " (BR-03).",
                ];
            }

            $this->upsertStockRow($productId, $warehouseId, $quantityChange);

            $movement = $this->conn->prepare(
                "INSERT INTO stock_movements
                    (product_id, movement_type, quantity_change, reason, reference_id, performed_by, created_at)
                 VALUES
                    (:product_id, 'adjustment', :quantity_change, :reason, NULL, :performed_by, NOW())"
            );
            $movement->execute([
                ':product_id'      => $productId,
                ':quantity_change' => $quantityChange,
                ':reason'          => $reason,
                ':performed_by'    => $performedBy,
            ]);

            $this->conn->commit();
            return ['success' => true, 'message' => 'Stock adjustment recorded.'];
        } catch (PDOException $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log('[Inventory::adjustStock] ' . $e->getMessage());
            return ['success' => false, 'message' => 'An error occurred while adjusting stock.'];
        }
    }

    /* Cộng/trừ vào dòng `stock` hiện có, hoặc tạo mới dòng (chỉ hợp lệ khi cộng dương, VD: lần đầu nhập kho 1 sản phẩm tại 1 warehouse chưa từng có). Hàm private dùng chung cho stockIn() và adjustStock(). */
    private function upsertStockRow(int $productId, int $warehouseId, int $quantityChange): void
    {
        $existing = $this->getStockByProductAndWarehouse($productId, $warehouseId);

        if ($existing === false) {
            $this->conn->prepare(
                "INSERT INTO {$this->table} (product_id, warehouse_id, quantity_on_hand)
                 VALUES (:product_id, :warehouse_id, :qty)"
            )->execute([
                ':product_id'   => $productId,
                ':warehouse_id' => $warehouseId,
                ':qty'          => max(0, $quantityChange),
            ]);
            return;
        }

        $this->conn->prepare(
            "UPDATE {$this->table}
             SET quantity_on_hand = quantity_on_hand + :qty, last_updated = NOW()
             WHERE product_id = :product_id AND warehouse_id = :warehouse_id"
        )->execute([
            ':qty'          => $quantityChange,
            ':product_id'   => $productId,
            ':warehouse_id' => $warehouseId,
        ]);
    }

    /* FR-STF-12 (lịch sử điều chỉnh): trả về các dòng movement_type='adjustment' cho 1 sản phẩm. */
    public function getAdjustmentHistory(int $productId): array
    {
        $stmt = $this->conn->prepare(
            "SELECT m.movement_id, m.quantity_change, m.reason, m.created_at, a.full_name AS performed_by_name
             FROM stock_movements m
             JOIN accounts a ON a.account_id = m.performed_by
             WHERE m.product_id = :product_id AND m.movement_type = 'adjustment'
             ORDER BY m.created_at DESC"
        );
        $stmt->execute([':product_id' => $productId]);
        return $stmt->fetchAll();
    }

    /**
     * Giống getAdjustmentHistory() nhưng KHÔNG lọc theo 1 sản phẩm - dùng cho
     * bảng "Inventory Adjustment History" trên trang Staff (liệt kê điều
     * chỉnh của MỌI sản phẩm, có thể lọc theo SKU/lý do/ngày qua tham số).
     */
    public function getAllAdjustments(?int $productId = null, ?string $reason = null, ?string $date = null, int $limit = 50): array
    {
        $sql = "SELECT m.movement_id, m.product_id, p.product_name, p.sku_code,
                       m.quantity_change, m.reason, m.created_at, a.full_name AS performed_by_name
                FROM stock_movements m
                JOIN products p ON p.product_id = m.product_id
                JOIN accounts a ON a.account_id = m.performed_by
                WHERE m.movement_type = 'adjustment'";
        $params = [];

        if ($productId !== null) {
            $sql .= " AND m.product_id = :product_id";
            $params[':product_id'] = $productId;
        }
        if ($reason !== null && $reason !== '') {
            $sql .= " AND m.reason = :reason";
            $params[':reason'] = $reason;
        }
        if ($date !== null && $date !== '') {
            $sql .= " AND DATE(m.created_at) = :date";
            $params[':date'] = $date;
        }

        $sql .= " ORDER BY m.created_at DESC LIMIT " . (int) $limit;

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // STOCK BATCHES + FEFO (FR-STF-14)

    /* danh sách lô hàng còn tồn của 1 sản phẩm, SẮP XẾP THEO FEFO - lô có expiry_date gần nhất lên đầu. Lô không có expiry_date (NULL) xếp SAU CÙNG (sản phẩm không cần FEFO đáng lẽ không nên có batch, nhưng xử lý an toàn nếu dữ liệu thiếu sót). */
    public function getBatchesForFefo(int $productId): array
    {
        $stmt = $this->conn->prepare(
            "SELECT batch_id, product_id, received_date, expiry_date, quantity_remaining
             FROM stock_batches
             WHERE product_id = :product_id AND quantity_remaining > 0
             ORDER BY expiry_date IS NULL ASC, expiry_date ASC"
        );
        $stmt->execute([':product_id' => $productId]);
        return $stmt->fetchAll();
    }

    /* batch_id nên xuất TRƯỚC TIÊN theo FEFO. Trả về null nếu hết batch. Dùng trong Sales.php trước khi gọi createTransaction() để xác định batch_id cho từng item (Sales::createTransaction() CHẤP NHẬN batch_id, không tự chọn). */
    public function getNextFefoBatch(int $productId): array|null
    {
        $batches = $this->getBatchesForFefo($productId);
        return $batches[0] ?? null;
    }

    /* Trừ quantity_remaining của 1 batch cụ thể sau khi bán/xuất hàng theo FEFO. CHECK constraint chk_batch_qty ở DB là lớp bảo vệ cuối, nhưng kiểm tra ở đây để trả lỗi rõ ràng hơn thay vì PDOException thô. */
    public function deductFromBatch(int $batchId, int $quantity): array
    {
        $stmt = $this->conn->prepare(
            "SELECT quantity_remaining FROM stock_batches WHERE batch_id = :id FOR UPDATE"
        );
        $stmt->execute([':id' => $batchId]);
        $batch = $stmt->fetch();

        if (!$batch || (int) $batch['quantity_remaining'] < $quantity) {
            return ['success' => false, 'message' => 'Batch quantity is not enough to subtract.'];
        }

        $update = $this->conn->prepare(
            "UPDATE stock_batches SET quantity_remaining = quantity_remaining - :qty WHERE batch_id = :id"
        );
        $update->execute([':qty' => $quantity, ':id' => $batchId]);

        return ['success' => true, 'message' => 'Batch deducted according to FEFO.'];
    }

    /* Ghi nhận 1 lô hàng mới khi nhập kho - gọi từ Service nếu sản phẩm thuộc category requires_fefo. */
    public function createBatch(int $productId, string $receivedDate, ?string $expiryDate, int $quantity): string
    {
        $stmt = $this->conn->prepare(
            "INSERT INTO stock_batches (product_id, received_date, expiry_date, quantity_remaining)
             VALUES (:product_id, :received_date, :expiry_date, :quantity)"
        );
        $stmt->execute([
            ':product_id'    => $productId,
            ':received_date' => $receivedDate,
            ':expiry_date'   => $expiryDate,
            ':quantity'      => $quantity,
        ]);
        return $this->conn->lastInsertId();
    }

    /* Cảnh báo lô sắp hết hạn trong N giờ tới (dùng EXPIRY_ALERT_WINDOW_HOURS từ app_config.php làm giá trị mặc định ở Service). */
    public function getExpiringBatches(int $withinHours): array
    {
        $stmt = $this->conn->prepare(
            "SELECT b.batch_id, b.product_id, p.product_name, b.expiry_date, b.quantity_remaining
             FROM stock_batches b
             JOIN products p ON p.product_id = b.product_id
             WHERE b.quantity_remaining > 0
               AND b.expiry_date IS NOT NULL
               AND b.expiry_date <= DATE_ADD(NOW(), INTERVAL :hours HOUR)
             ORDER BY b.expiry_date ASC"
        );
        $stmt->execute([':hours' => $withinHours]);
        return $stmt->fetchAll();
    }
}