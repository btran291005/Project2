<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Logger.php';

class Sales
{
    private PDO $conn;
    private string $table = 'sales_transactions';

    public function __construct()
    {
        $this->conn = Database::getConnection();
    }

    /* BR-02 + BR-03: Tạo giao dịch bán hàng và trừ kho ngay lập tức, atomic. */
    public function createTransaction(int $staffId, int $warehouseId, array $items): array
    {
        if (empty($items)) {
            return ['success' => false, 'transaction_id' => null, 'message' => 'Transaction has no products.'];
        }

        try {
            $this->conn->beginTransaction();

            // Khóa các dòng stock liên quan trước (SELECT ... FOR UPDATE) để 2 giao dịch bán cùng sản phẩm/cùng lúc không thể cùng đọc thấy đủ hàng rồi cùng trừ âm.
            $lockStmt = $this->conn->prepare(
                "SELECT quantity_on_hand FROM stock
                 WHERE product_id = :product_id AND warehouse_id = :warehouse_id
                 FOR UPDATE"
            );

            foreach ($items as $item) {
                $lockStmt->execute([
                    ':product_id'   => (int) $item['product_id'],
                    ':warehouse_id' => $warehouseId,
                ]);
                $row = $lockStmt->fetch();

                if (!$row) {
                    $this->conn->rollBack();
                    return [
                        'success' => false, 'transaction_id' => null,
                        'message' => "Product ID {$item['product_id']} has no stock at this warehouse.",
                    ];
                }

                if ((int) $row['quantity_on_hand'] < (int) $item['quantity_sold']) {
                    $this->conn->rollBack();
                    return [
                        'success' => false, 'transaction_id' => null,
                        'message' => "Product ID {$item['product_id']} has insufficient stock "
                                    . "(available {$row['quantity_on_hand']}, needed {$item['quantity_sold']}).",
                    ];
                }
            }

            // Đủ hàng cho toàn bộ giỏ hàng -> tạo giao dịch
            $stmt = $this->conn->prepare(
                "INSERT INTO {$this->table} (performed_by, transaction_time) VALUES (:performed_by, NOW())"
            );
            $stmt->execute([':performed_by' => $staffId]);
            $transactionId = $this->conn->lastInsertId();

            $detailStmt = $this->conn->prepare(
                "INSERT INTO sales_transaction_details (transaction_id, product_id, quantity_sold, batch_id)
                 VALUES (:transaction_id, :product_id, :quantity_sold, :batch_id)"
            );

            $updateStockStmt = $this->conn->prepare(
                "UPDATE stock SET quantity_on_hand = quantity_on_hand - :qty, last_updated = NOW()
                 WHERE product_id = :product_id AND warehouse_id = :warehouse_id"
            );

            $movementStmt = $this->conn->prepare(
                "INSERT INTO stock_movements
                    (product_id, movement_type, quantity_change, reason, reference_id, performed_by, created_at)
                 VALUES
                    (:product_id, 'sale', :quantity_change, NULL, :reference_id, :performed_by, NOW())"
            );

            foreach ($items as $item) {
                $productId = (int) $item['product_id'];
                $qty       = (int) $item['quantity_sold'];

                $detailStmt->execute([
                    ':transaction_id' => $transactionId,
                    ':product_id'     => $productId,
                    ':quantity_sold'  => $qty,
                    ':batch_id'       => $item['batch_id'] ?? null,
                ]);

                // CHECK constraint chk_qty_positive ở DB là lớp bảo vệ cuối cùng cho BR-03, nhưng ta đã khóa + kiểm tra trước nên về lý thuyết không thể âm ở đây.
                $updateStockStmt->execute([
                    ':qty'          => $qty,
                    ':product_id'   => $productId,
                    ':warehouse_id' => $warehouseId,
                ]);

                $movementStmt->execute([
                    ':product_id'       => $productId,
                    ':quantity_change'  => -$qty,
                    ':reference_id'     => $transactionId,
                    ':performed_by'     => $staffId,
                ]);
            }

            $this->conn->commit();

            return ['success' => true, 'transaction_id' => $transactionId, 'message' => 'Sales transaction completed successfully.'];
        } catch (PDOException $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            // CHECK constraint vi phạm (mã 23000 ở MySQL 8) cũng rơi vào đây -> vẫn là BR-03
            error_log('[Sales::createTransaction] ' . $e->getMessage());
            return ['success' => false, 'transaction_id' => null, 'message' => 'An error occurred, the transaction has been cancelled.'];
        }
    }

/**
     * BR-02 / BR-03 / New Sales Entry (POS): tạo giao dịch bán hàng KÈM giá bán
     * lẻ (unit_price) và giá vốn (unit_cost) tại thời điểm bán, atomic. Khác
     * createTransaction() (chỉ ghi số lượng, để unit_price/unit_cost = 0) - đây
     * là bản dùng cho màn "New Sales Entry" cần lưu đúng giá để tính tổng tiền.
     *
     * $items: [['product_id'=>int,'quantity_sold'=>int,'unit_price'=>float,
     *           'unit_cost'=>float,'batch_id'=>int|null], ...]
     *
     * @return array{
     *   success: bool,
     *   transaction_id: int|null,
     *   remaining_stock: int|null,
     *   message: string
     * }
     */
    public function createPosTransaction(int $staffId, int $warehouseId, array $items): array
    {
        if (empty($items)) {
            return ['success' => false, 'transaction_id' => null, 'remaining_stock' => null, 'message' => 'Transaction has no products.'];
        }

        try {
            $this->conn->beginTransaction();

            // Khóa các dòng stock liên quan để tránh 2 giao dịch đồng thời cùng
            // đọc thấy đủ hàng rồi cùng trừ âm (BR-03), giống createTransaction().
            $lockStmt = $this->conn->prepare(
                "SELECT quantity_on_hand FROM stock
                 WHERE product_id = :product_id AND warehouse_id = :warehouse_id
                 FOR UPDATE"
            );

            foreach ($items as $item) {
                $lockStmt->execute([
                    ':product_id'   => (int) $item['product_id'],
                    ':warehouse_id' => $warehouseId,
                ]);
                $row = $lockStmt->fetch();

                if (!$row) {
                    $this->conn->rollBack();
                    return [
                        'success' => false, 'transaction_id' => null, 'remaining_stock' => null,
                        'message' => "Product ID {$item['product_id']} has no stock at this warehouse.",
                    ];
                }

                if ((int) $row['quantity_on_hand'] < (int) $item['quantity_sold']) {
                    $this->conn->rollBack();
                    return [
                        'success' => false, 'transaction_id' => null, 'remaining_stock' => null,
                        'message' => "Product ID {$item['product_id']} has insufficient stock "
                                    . "(available {$row['quantity_on_hand']}, needed {$item['quantity_sold']}).",
                    ];
                }
            }

            // Đủ hàng -> tạo giao dịch
            $stmt = $this->conn->prepare(
                "INSERT INTO {$this->table} (performed_by, transaction_time) VALUES (:performed_by, NOW())"
            );
            $stmt->execute([':performed_by' => $staffId]);
            $transactionId = $this->conn->lastInsertId();

            $detailStmt = $this->conn->prepare(
                "INSERT INTO sales_transaction_details
                    (transaction_id, product_id, quantity_sold, unit_price, unit_cost, batch_id)
                 VALUES
                    (:transaction_id, :product_id, :quantity_sold, :unit_price, :unit_cost, :batch_id)"
            );

            $updateStockStmt = $this->conn->prepare(
                "UPDATE stock SET quantity_on_hand = quantity_on_hand - :qty, last_updated = NOW()
                 WHERE product_id = :product_id AND warehouse_id = :warehouse_id"
            );

            $movementStmt = $this->conn->prepare(
                "INSERT INTO stock_movements
                    (product_id, movement_type, quantity_change, reason, reference_id, performed_by, created_at)
                 VALUES
                    (:product_id, 'sale', :quantity_change, NULL, :reference_id, :performed_by, NOW())"
            );

            $remainingStock = null;

            foreach ($items as $item) {
                $productId = (int) $item['product_id'];
                $qty       = (int) $item['quantity_sold'];

                $detailStmt->execute([
                    ':transaction_id' => $transactionId,
                    ':product_id'     => $productId,
                    ':quantity_sold'  => $qty,
                    ':unit_price'     => (float) ($item['unit_price'] ?? 0),
                    ':unit_cost'      => (float) ($item['unit_cost'] ?? 0),
                    ':batch_id'       => $item['batch_id'] ?? null,
                ]);

                $updateStockStmt->execute([
                    ':qty'          => $qty,
                    ':product_id'   => $productId,
                    ':warehouse_id' => $warehouseId,
                ]);

                $movementStmt->execute([
                    ':product_id'      => $productId,
                    ':quantity_change' => -$qty,
                    ':reference_id'    => $transactionId,
                    ':performed_by'    => $staffId,
                ]);

                // Lấy tồn kho còn lại sau khi trừ (dùng cho payload realtime broadcast).
                $remainingStmt = $this->conn->prepare(
                    "SELECT quantity_on_hand FROM stock
                     WHERE product_id = :product_id AND warehouse_id = :warehouse_id"
                );
                $remainingStmt->execute([':product_id' => $productId, ':warehouse_id' => $warehouseId]);
                $remainingStock = (int) $remainingStmt->fetchColumn();
            }

            $this->conn->commit();

            return [
                'success'         => true,
                'transaction_id'  => (int) $transactionId,
                'remaining_stock' => $remainingStock,
                'message'         => 'Sales transaction completed successfully.',
            ];
        } catch (PDOException $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log('[Sales::createPosTransaction] ' . $e->getMessage());
            return ['success' => false, 'transaction_id' => null, 'remaining_stock' => null, 'message' => 'An error occurred, the transaction has been cancelled.'];
        }
    }

    public function getById(int $transactionId): array|false
    {
        $stmt = $this->conn->prepare("SELECT * FROM {$this->table} WHERE transaction_id = :id");
        $stmt->execute([':id' => $transactionId]);
        $transaction = $stmt->fetch();

        if ($transaction) {
            $itemStmt = $this->conn->prepare(
                "SELECT std.*, p.product_name, p.sku_code
                 FROM sales_transaction_details std
                 JOIN products p ON p.product_id = std.product_id
                 WHERE std.transaction_id = :id"
            );
            $itemStmt->execute([':id' => $transactionId]);
            $transaction['items'] = $itemStmt->fetchAll();
        }

        return $transaction;
    }

    public function getAll(?string $fromDate = null, ?string $toDate = null): array
    {
        $sql = "SELECT * FROM {$this->table} WHERE 1=1";
        $params = [];

        if ($fromDate !== null) {
            $sql .= " AND transaction_time >= :from_date";
            $params[':from_date'] = $fromDate;
        }
        if ($toDate !== null) {
            $sql .= " AND transaction_time <= :to_date";
            $params[':to_date'] = $toDate;
        }

        $sql .= " ORDER BY transaction_time DESC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /* FR-STF-13: lịch sử bán hàng 7/30 ngày cho 1 sản phẩm (theo số lượng bán/ngày).
     * Dùng SALES_HISTORY_SHORT_RANGE_DAYS / SALES_HISTORY_LONG_RANGE_DAYS (định nghĩa ở backend/config/app_config.php) làm giá trị mặc định hợp lệ. */
    public function getSalesHistory(int $productId, int $days = SALES_HISTORY_SHORT_RANGE_DAYS): array
    {
        $allowedRanges = [SALES_HISTORY_SHORT_RANGE_DAYS, SALES_HISTORY_LONG_RANGE_DAYS];
        $days = in_array($days, $allowedRanges, true) ? $days : SALES_HISTORY_SHORT_RANGE_DAYS;

        $sql = "SELECT DATE(st.transaction_time) AS sale_date,
                       SUM(std.quantity_sold) AS total_quantity
                FROM sales_transaction_details std
                JOIN {$this->table} st ON st.transaction_id = std.transaction_id
                WHERE std.product_id = :product_id
                  AND st.transaction_time >= (NOW() - INTERVAL {$days} DAY)
                GROUP BY DATE(st.transaction_time)
                ORDER BY sale_date ASC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([':product_id' => $productId]);
        return $stmt->fetchAll();
    }

    /**
     * Lịch sử bán theo ngày liên tục cho Forecast API. Khác với getSalesHistory(),
     * ngày không có giao dịch vẫn được trả về với quantity_sold = 0 để tránh làm
     * sai chuỗi thời gian của model.
     *
     * @return array<int, array{sale_date: string, quantity_sold: int}>
     */
    public function getDailyForecastHistory(int $productId, int $days = FORECAST_HISTORY_DAYS): array
    {
        $days = max(14, min($days, 180));
        $startDate = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));

        $stmt = $this->conn->prepare(
            "SELECT DATE(st.transaction_time) AS sale_date, SUM(std.quantity_sold) AS total_quantity
             FROM sales_transaction_details std
             JOIN {$this->table} st ON st.transaction_id = std.transaction_id
             WHERE std.product_id = :product_id
               AND st.transaction_time >= :start_date
               AND st.transaction_time < DATE_ADD(CURDATE(), INTERVAL 1 DAY)
             GROUP BY DATE(st.transaction_time)"
        );
        $stmt->execute([
            ':product_id' => $productId,
            ':start_date' => $startDate,
        ]);

        $quantitiesByDate = [];
        foreach ($stmt->fetchAll() as $row) {
            $quantitiesByDate[(string) $row['sale_date']] = (int) $row['total_quantity'];
        }

        $history = [];
        $date = new DateTimeImmutable($startDate);
        for ($i = 0; $i < $days; $i++) {
            $dateKey = $date->format('Y-m-d');
            $history[] = [
                'sale_date' => $dateKey,
                'quantity_sold' => $quantitiesByDate[$dateKey] ?? 0,
            ];
            $date = $date->modify('+1 day');
        }

        return $history;
    }
}