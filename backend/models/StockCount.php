<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

class StockCount
{
    private PDO $conn;
    private string $table = 'stock_counts';

    public function __construct()
    {
        $this->conn = Database::getConnection();
    }

    // stock_counts / stock_count_details

    /* FR-STF-09: bắt đầu 1 phiên kiểm kê mới. */
    public function createSession(int $staffId): string
    {
        $stmt = $this->conn->prepare(
            "INSERT INTO {$this->table} (performed_by, count_date) VALUES (:performed_by, NOW())"
        );
        $stmt->execute([':performed_by' => $staffId]);
        return $this->conn->lastInsertId();
    }

    /* FR-STF-04 / BR-14: ghi nhận số lượng đếm thực tế cho 1 sản phẩm.
     * system_qty được TỰ ĐỘNG lấy = tổng quantity_on_hand hiện tại của sản phẩm trên tất cả các kho (xem ghi chú SCHEMA GAP ở đầu file).
     * @return array{success: bool, detail_id?: string, system_qty?: int, actual_qty?: int, discrepancy?: int, message: string} */
    public function addCountItem(int $countId, int $productId, int $actualQty): array
    {
        $sysStmt = $this->conn->prepare(
            "SELECT COALESCE(SUM(quantity_on_hand), 0) AS system_qty
             FROM stock WHERE product_id = :product_id"
        );
        $sysStmt->execute([':product_id' => $productId]);
        $systemQty = (int) $sysStmt->fetch()['system_qty'];

        $stmt = $this->conn->prepare(
            "INSERT INTO stock_count_details (count_id, product_id, system_qty, actual_qty)
             VALUES (:count_id, :product_id, :system_qty, :actual_qty)"
        );
        $stmt->execute([
            ':count_id'   => $countId,
            ':product_id' => $productId,
            ':system_qty' => $systemQty,
            ':actual_qty' => $actualQty,
        ]);

        return [
            'success'     => true,
            'detail_id'   => $this->conn->lastInsertId(),
            'system_qty'  => $systemQty,
            'actual_qty'  => $actualQty,
            'discrepancy' => $actualQty - $systemQty, // giống hệt cách DB tự tính (GENERATED)
            'message'     => 'Count result recorded.',
        ];
    }

    /* FR-STF-09 / BR-14: hoàn tất phiên kiểm kê - với mỗi dòng có discrepancy != 0, ghi 1 dòng vào stock_movements (movement_type = 'count_correction') để lưu vết, KHÔNG tự sửa bảng stock (xem SCHEMA GAP ở đầu file). */
    public function finalizeSession(int $countId, int $staffId): array
    {
        try {
            $this->conn->beginTransaction();

            $itemStmt = $this->conn->prepare(
                "SELECT * FROM stock_count_details WHERE count_id = :count_id"
            );
            $itemStmt->execute([':count_id' => $countId]);
            $items = $itemStmt->fetchAll();

            $movementStmt = $this->conn->prepare(
                "INSERT INTO stock_movements
                    (product_id, movement_type, quantity_change, reason, reference_id, performed_by, created_at)
                 VALUES
                    (:product_id, 'count_correction', :quantity_change,
                     'Stock count discrepancy - manual reconciliation required per warehouse', :reference_id, :performed_by, NOW())"
            );

            $discrepancyCount = 0;
            foreach ($items as $item) {
                $discrepancy = (int) $item['discrepancy'];
                if ($discrepancy !== 0) {
                    $discrepancyCount++;
                    $movementStmt->execute([
                        ':product_id'      => $item['product_id'],
                        ':quantity_change' => $discrepancy,
                        ':reference_id'    => $countId,
                        ':performed_by'    => $staffId,
                    ]);
                }
            }

            $this->conn->commit();

            return [
                'success'            => true,
                'total_items'        => count($items),
                'discrepancy_items'  => $discrepancyCount,
                'message'            => 'Stock count session completed.',
            ];
        } catch (PDOException $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log('[StockCount::finalizeSession] ' . $e->getMessage());
            return ['success' => false, 'message' => 'An error occurred while finalizing the stock count.'];
        }
    }

    public function getSessionById(int $countId): array|false
    {
        $stmt = $this->conn->prepare("SELECT * FROM {$this->table} WHERE count_id = :id");
        $stmt->execute([':id' => $countId]);
        $session = $stmt->fetch();

        if ($session) {
            $itemStmt = $this->conn->prepare(
                "SELECT scd.*, p.product_name, p.sku_code
                 FROM stock_count_details scd
                 JOIN products p ON p.product_id = scd.product_id
                 WHERE scd.count_id = :id"
            );
            $itemStmt->execute([':id' => $countId]);
            $session['items'] = $itemStmt->fetchAll();
        }

        return $session;
    }

    /* FR-ADM-09: lịch sử kiểm kê toàn hệ thống + số dòng lệch, cho Admin theo dõi bất thường. */
    public function getHistory(): array
    {
        $sql = "SELECT sc.*, a.full_name AS performed_by_name,
                       COUNT(scd.count_detail_id) AS total_items,
                       SUM(CASE WHEN scd.discrepancy != 0 THEN 1 ELSE 0 END) AS discrepancy_items
                FROM {$this->table} sc
                JOIN accounts a ON a.account_id = sc.performed_by
                LEFT JOIN stock_count_details scd ON scd.count_id = sc.count_id
                GROUP BY sc.count_id
                ORDER BY sc.count_date DESC";

        $stmt = $this->conn->query($sql);
        return $stmt->fetchAll();
    }

    // customer_feedback (FR-STF-11)

    public function addCustomerFeedback(?int $productId, int $loggedBy, string $feedbackText): string
    {
        $stmt = $this->conn->prepare(
            "INSERT INTO customer_feedback (product_id, logged_by, feedback_text, created_at)
             VALUES (:product_id, :logged_by, :feedback_text, NOW())"
        );
        $stmt->execute([
            ':product_id'    => $productId,
            ':logged_by'     => $loggedBy,
            ':feedback_text' => $feedbackText,
        ]);

        return $this->conn->lastInsertId();
    }

    public function getCustomerFeedback(?int $productId = null): array
    {
        $sql = "SELECT cf.*, p.product_name, a.full_name AS logged_by_name
                FROM customer_feedback cf
                LEFT JOIN products p ON p.product_id = cf.product_id
                JOIN accounts a ON a.account_id = cf.logged_by
                WHERE 1=1";
        $params = [];

        if ($productId !== null) {
            $sql .= " AND cf.product_id = :product_id";
            $params[':product_id'] = $productId;
        }

        $sql .= " ORDER BY cf.created_at DESC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // shortage_incidents (User Story: log stock-shortage incidents)

    public function createShortageIncident(int $productId, int $handledBy, ?string $resolutionAction = null): string
    {
        $stmt = $this->conn->prepare(
            "INSERT INTO shortage_incidents (product_id, handled_by, resolution_action, status, created_at)
             VALUES (:product_id, :handled_by, :resolution_action, 'Open', NOW())"
        );
        $stmt->execute([
            ':product_id'        => $productId,
            ':handled_by'        => $handledBy,
            ':resolution_action' => $resolutionAction,
        ]);

        return $this->conn->lastInsertId();
    }

    public function resolveShortageIncident(int $incidentId, string $resolutionAction): bool
    {
        $stmt = $this->conn->prepare(
            "UPDATE shortage_incidents
             SET status = 'Resolved', resolution_action = :resolution_action
             WHERE incident_id = :id"
        );
        return $stmt->execute([
            ':resolution_action' => $resolutionAction,
            ':id'                => $incidentId,
        ]);
    }

    public function getShortageIncidents(?string $status = null): array
    {
        $sql = "SELECT si.*, p.product_name, p.sku_code, a.full_name AS handled_by_name
                FROM shortage_incidents si
                JOIN products p ON p.product_id = si.product_id
                JOIN accounts a ON a.account_id = si.handled_by
                WHERE 1=1";
        $params = [];

        if ($status !== null) {
            $sql .= " AND si.status = :status";
            $params[':status'] = $status;
        }

        $sql .= " ORDER BY si.created_at DESC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}