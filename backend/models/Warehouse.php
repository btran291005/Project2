<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

class Warehouse
{
    private PDO $conn;
    private string $table = 'warehouses';

    public function __construct()
    {
        $this->conn = Database::getConnection();
    }

    public function getAll(): array
    {
        $stmt = $this->conn->query("SELECT * FROM {$this->table} ORDER BY warehouse_name ASC");
        return $stmt->fetchAll();
    }

    public function getById(int $warehouseId): array|false
    {
        $stmt = $this->conn->prepare("SELECT * FROM {$this->table} WHERE warehouse_id = :id");
        $stmt->execute([':id' => $warehouseId]);
        return $stmt->fetch();
    }

    public function create(array $data): string
    {
        $stmt = $this->conn->prepare(
            "INSERT INTO {$this->table} (warehouse_name, location) VALUES (:warehouse_name, :location)"
        );
        $stmt->execute([
            ':warehouse_name' => $data['warehouse_name'],
            ':location'       => $data['location'] ?? null,
        ]);

        return $this->conn->lastInsertId();
    }

    public function update(int $warehouseId, array $data): bool
    {
        $allowed = ['warehouse_name', 'location'];
        $fields = [];
        $params = [':id' => $warehouseId];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = :{$field}";
                $params[":{$field}"] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $sql = "UPDATE {$this->table} SET " . implode(', ', $fields) . " WHERE warehouse_id = :id";
        $stmt = $this->conn->prepare($sql);
        return $stmt->execute($params);
    }

    /* Xóa cứng. Nếu warehouse còn dữ liệu trong stock (FK), MySQL từ chối (error code 23000) -> trả về false thay vì lộ lỗi SQL thô. */
    public function delete(int $warehouseId): bool
    {
        try {
            $stmt = $this->conn->prepare("DELETE FROM {$this->table} WHERE warehouse_id = :id");
            return $stmt->execute([':id' => $warehouseId]);
        } catch (PDOException $e) {
            error_log('[Warehouse::delete] FK constraint hoặc lỗi khác: ' . $e->getMessage());
            return false;
        }
    }
}