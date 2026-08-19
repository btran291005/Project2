<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

class Supplier
{
    private PDO $conn;
    private string $table = 'suppliers';

    public function __construct()
    {
        $this->conn = Database::getConnection();
    }

    // CRUD

    public function getAll(): array
    {
        $stmt = $this->conn->query("SELECT * FROM {$this->table} ORDER BY supplier_name ASC");
        return $stmt->fetchAll();
    }

    public function getById(int $supplierId): array|false
    {
        $stmt = $this->conn->prepare("SELECT * FROM {$this->table} WHERE supplier_id = :id");
        $stmt->execute([':id' => $supplierId]);
        return $stmt->fetch();
    }

    public function create(array $data): string
    {
        $stmt = $this->conn->prepare(
            "INSERT INTO {$this->table} (supplier_name, contact_phone, avg_lead_time_days)
             VALUES (:supplier_name, :contact_phone, :avg_lead_time_days)"
        );
        $stmt->execute([
            ':supplier_name'      => $data['supplier_name'],
            ':contact_phone'      => $data['contact_phone'] ?? null,
            ':avg_lead_time_days' => $data['avg_lead_time_days'] ?? null,
        ]);

        return $this->conn->lastInsertId();
    }

    public function update(int $supplierId, array $data): bool
    {
        $allowed = ['supplier_name', 'contact_phone', 'avg_lead_time_days'];
        $fields = [];
        $params = [':id' => $supplierId];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = :{$field}";
                $params[":{$field}"] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $sql = "UPDATE {$this->table} SET " . implode(', ', $fields) . " WHERE supplier_id = :id";
        $stmt = $this->conn->prepare($sql);
        return $stmt->execute($params);
    }

    /* Xóa cứng. Nếu supplier còn bị tham chiếu bởi products/purchase_orders (FK), MySQL từ chối -> trả về false. */
    public function delete(int $supplierId): bool
    {
        try {
            $stmt = $this->conn->prepare("DELETE FROM {$this->table} WHERE supplier_id = :id");
            return $stmt->execute([':id' => $supplierId]);
        } catch (PDOException $e) {
            error_log('[Supplier::delete] FK constraint hoặc lỗi khác: ' . $e->getMessage());
            return false;
        }
    }

    // FR-MGR-11 / Vendor Management

    /* Thống kê hiệu suất 1 nhà cung cấp: lead-time tham khảo (cột tĩnh) + tỉ lệ đơn hàng có sai lệch (discrepancy) trong số PO đã 'Delivered'. */
    public function getPerformanceStats(int $supplierId): array|false
    {
        $sql = "SELECT
                    s.supplier_id,
                    s.supplier_name,
                    s.contact_phone,
                    s.avg_lead_time_days,
                    COUNT(DISTINCT po.po_id) AS total_delivered_orders,
                    COUNT(DISTINCT CASE WHEN pod.discrepancy_reason IS NOT NULL THEN po.po_id END) AS orders_with_discrepancy,
                    ROUND(
                        COUNT(DISTINCT CASE WHEN pod.discrepancy_reason IS NOT NULL THEN po.po_id END) /
                        NULLIF(COUNT(DISTINCT po.po_id), 0) * 100, 1
                    ) AS discrepancy_rate_percent
                FROM {$this->table} s
                LEFT JOIN purchase_orders po
                       ON po.supplier_id = s.supplier_id AND po.status = 'Delivered'
                LEFT JOIN purchase_order_details pod ON pod.po_id = po.po_id
                WHERE s.supplier_id = :id
                GROUP BY s.supplier_id";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([':id' => $supplierId]);
        return $stmt->fetch();
    }

    /* Vendor Management: gợi ý nhà cung cấp tin cậy nhất khi tạo PO mới - ưu tiên tỉ lệ sai lệch thấp nhất, sau đó lead-time (tham khảo) thấp nhất.
     * Chỉ xét các supplier đã có ít nhất 1 đơn 'Delivered' (đủ dữ liệu để đánh giá). */
    public function getMostReliable(int $limit = 5): array
    {
        $sql = "SELECT
                    s.supplier_id,
                    s.supplier_name,
                    s.avg_lead_time_days,
                    COUNT(DISTINCT po.po_id) AS total_delivered_orders,
                    ROUND(
                        COUNT(DISTINCT CASE WHEN pod.discrepancy_reason IS NOT NULL THEN po.po_id END) /
                        NULLIF(COUNT(DISTINCT po.po_id), 0) * 100, 1
                    ) AS discrepancy_rate_percent
                FROM {$this->table} s
                JOIN purchase_orders po ON po.supplier_id = s.supplier_id AND po.status = 'Delivered'
                LEFT JOIN purchase_order_details pod ON pod.po_id = po.po_id
                GROUP BY s.supplier_id
                HAVING total_delivered_orders > 0
                ORDER BY discrepancy_rate_percent ASC, s.avg_lead_time_days ASC
                LIMIT :limit";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}