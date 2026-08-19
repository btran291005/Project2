<?php
/**
 * File: backend/api/sales/create.php
 * Purpose: REST endpoint (POST) tạo giao dịch bán hàng tại quầy (New Sales
 *          Entry / POS). Chỉ Store Staff được gọi (Middleware::guardApi).
 *
 * Body (JSON):
 *   {
 *     "product_id":  int (required),
 *     "quantity":    int > 0 (required),
 *     "discount":    float 0..100 (optional),
 *     "warehouse_id": int (required) - nơi trừ tồn kho
 *   }
 *
 * Luồng:
 *   1. Guard role staff + đọc JSON body.
 *   2. Lấy sản phẩm (để lấy selling_price làm giá bán lẻ, unit_cost làm giá vốn).
 *   3. Tính giá bán sau chiết khấu: unit_price = selling_price * (1 - discount/100).
 *   4. Gọi Sales::createPosTransaction() - atomic: chèn giao dịch + chi tiết
 *      (kèm unit_price/unit_cost), trừ kho, ghi stock_movements.
 *   5. Trả JSON {success, sale:{...}, remaining_stock}.
 *
 * Related: FR-STF-02, BR-02, BR-03
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/app_config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../core/Logger.php';
require_once __DIR__ . '/../../core/Auth.php';
require_once __DIR__ . '/../../core/Middleware.php';
require_once __DIR__ . '/../../models/Sales.php';
require_once __DIR__ . '/../../models/Product.php';
require_once __DIR__ . '/../../models/Inventory.php';

// Chỉ Store Staff; chưa đăng nhập/sai role -> HTTP 401/403 JSON (Middleware::guardApi).
Middleware::guardApi([ROLE_STAFF]);

header('Content-Type: application/json; charset=utf-8');

$staffId = (int) Auth::id();

// --- Đọc & parse JSON body ---
$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON body.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$productId   = (int) ($data['product_id'] ?? 0);
$quantity    = (int) ($data['quantity'] ?? 0);
$discount    = (float) ($data['discount'] ?? 0);
$warehouseId = (int) ($data['warehouse_id'] ?? 0);

// --- Validate đầu vào ---
if ($productId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please select a product.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($warehouseId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please provide a warehouse.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($quantity <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Quantity must be greater than 0.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($discount < 0 || $discount > 100) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Discount must be between 0 and 100%.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Lấy sản phẩm (giá bán lẻ + giá vốn) ---
$productModel = new Product();
$product = $productModel->getById($productId);
if ($product === false) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Product not found.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$sellingPrice = (float) ($product['selling_price'] ?? 0);
$unitCost     = (float) ($product['unit_cost'] ?? 0);
$unitPrice    = round($sellingPrice * (1 - $discount / 100), 2);

// --- FEFO: nếu category yêu cầu, chọn batch gần hết hạn nhất ---
$batchId = null;
if (!empty($product['requires_fefo'])) {
    $inventoryModel = new Inventory();
    $nextBatch = $inventoryModel->getNextFefoBatch($productId);
    $batchId = $nextBatch['batch_id'] ?? null;
}

// --- Tạo giao dịch ---
$salesModel = new Sales();
$result = $salesModel->createPosTransaction($staffId, $warehouseId, [
    [
        'product_id'   => $productId,
        'quantity_sold'=> $quantity,
        'unit_price'   => $unitPrice,
        'unit_cost'    => $unitCost,
        'batch_id'     => $batchId,
    ],
]);

// Nếu FEFO và bán thành công, trừ luôn quantity_remaining của batch.
if ($result['success'] && $batchId !== null) {
    $inventoryModel = $inventoryModel ?? new Inventory();
    $inventoryModel->deductFromBatch($batchId, $quantity);
}

if (!$result['success']) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => $result['message'],
        'remaining_stock' => $result['remaining_stock'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Thành công ---
$sale = [
    'transaction_id' => $result['transaction_id'],
    'product_id'     => $productId,
    'sku_code'       => $product['sku_code'],
    'product_name'   => $product['product_name'],
    'quantity_sold'  => $quantity,
    'unit_price'     => $unitPrice,
    'discount'       => $discount,
    'unit_cost'      => $unitCost,
    'timestamp'      => date('Y-m-d H:i:s'),
];

echo json_encode([
    'success'         => true,
    'sale'            => $sale,
    'remaining_stock' => $result['remaining_stock'],
], JSON_UNESCAPED_UNICODE);
