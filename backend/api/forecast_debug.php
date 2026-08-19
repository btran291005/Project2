<?php
declare(strict_types=1);

require_once __DIR__ . '/config/app_config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/services/ManagerService.php';

header('Content-Type: application/json; charset=utf-8');

/**
 * Debug endpoint to test forecast functionality
 * Usage: GET /backend/api/forecast_debug.php?product_id=1
 */

$productId = filter_var($_GET['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if ($productId === false) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid product_id parameter'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $service = new ManagerService();
    
    // Get product info
    $product = new \Product();
    $productInfo = $product->getById($productId);
    
    // Get sales history
    $sales = new \Sales();
    $salesHistory = $sales->getDailyForecastHistory($productId);
    
    // Get forecast
    $forecast = $service->getForecastSuggestion($productId);
    
    echo json_encode([
        'product_id' => $productId,
        'product_info' => $productInfo ? [
            'product_id' => $productInfo['product_id'],
            'sku_code' => $productInfo['sku_code'],
            'product_name' => $productInfo['product_name'],
            'category_type' => $productInfo['category_type'],
        ] : null,
        'sales_history_count' => count($salesHistory),
        'sales_history_sample' => array_slice($salesHistory, -3), // Last 3 days
        'forecast_result' => $forecast,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ], JSON_UNESCAPED_UNICODE);
}
