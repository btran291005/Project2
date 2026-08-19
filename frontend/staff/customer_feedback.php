<?php
/**
 * File: frontend/staff/customer_feedback.php
 * Purpose: Ghi nhận phản hồi/khiếu nại khách hàng liên quan tới hết hàng,
 * và xem lịch sử phản hồi đã ghi.
 * Related: FR-STF-11
 * Calls: StaffService::logCustomerFeedback(), listCustomerFeedback()
 */

declare(strict_types=1);

require_once __DIR__ . '/../../backend/config/app_config.php';
require_once __DIR__ . '/../../backend/config/database.php';
require_once __DIR__ . '/../../backend/core/Logger.php';
require_once __DIR__ . '/../../backend/core/Auth.php';
require_once __DIR__ . '/../../backend/core/Middleware.php';
require_once __DIR__ . '/../../backend/services/StaffService.php';
require_once __DIR__ . '/../../backend/models/Product.php';

Middleware::guard([ROLE_STAFF]);

$staffService = new StaffService();
$productModel = new Product();
$actorId = Auth::id();

$flashMessage = '';
$flashIsError = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $productId = !empty($_POST['product_id']) ? (int) $_POST['product_id'] : null;
    $feedbackText = (string) ($_POST['feedback_text'] ?? '');

    $result = $staffService->logCustomerFeedback($productId, $actorId, $feedbackText);
    header('Location: customer_feedback.php?flash=' . urlencode($result['message']) . '&err=' . ($result['success'] ? '0' : '1'));
    exit;
}

if (isset($_GET['flash'])) {
    $flashMessage = (string) $_GET['flash'];
    $flashIsError = ($_GET['err'] ?? '0') === '1';
}

$allProducts = $productModel->getAll(null, null, true);
$feedbackList = $staffService->listCustomerFeedback();

$pageTitle   = 'Customer Feedback';
$breadcrumbs = ['Staff', 'Customer Feedback'];
$activeMenu  = 'feedback';
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Feedback - InventoryDSS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/theme_variables.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/custom.css" rel="stylesheet">
</head>
<body>
    <div class="app-shell">
        <?php require __DIR__ . '/../components/sidebar.php'; ?>

        <div class="app-content">
            <?php require __DIR__ . '/../components/header.php'; ?>

            <main class="app-main">

                <div class="mb-4">
                    <h2 class="page-heading mb-1">Customer Feedback</h2>
                    <p class="page-subheading mb-0">Record customer feedback/complaints related to out-of-stock items (FR-STF-11).</p>
                </div>

                <?php if ($flashMessage !== ''): ?>
                    <div class="alert <?= $flashIsError ? 'alert-danger' : 'alert-success' ?> py-2 px-3" style="font-size: .87rem;">
                        <?= htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>

                <div class="row g-3">
                    <div class="col-12 col-xl-4">
                        <div class="panel-card">
                            <div class="panel-card-header">
                                <h3 class="panel-card-title">Record New Feedback</h3>
                            </div>
                            <form method="POST" class="d-flex flex-column gap-3">
                                <div>
                                    <label class="form-label small">Related Product (optional)</label>
                                    <select name="product_id" class="form-select">
                                        <option value="">-- No specific product --</option>
                                        <?php foreach ($allProducts as $p): ?>
                                            <option value="<?= (int) $p['product_id'] ?>"><?= htmlspecialchars($p['product_name'] . ' (' . $p['sku_code'] . ')', ENT_QUOTES, 'UTF-8') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="form-label small">Feedback Content</label>
                                    <textarea name="feedback_text" class="form-control" rows="4" required placeholder="E.g.: Customer complained about unsweetened fresh milk being out of stock..."></textarea>
                                </div>
                                <button type="submit" class="btn btn-brand btn-sm">Record Feedback</button>
                            </form>
                        </div>
                    </div>

                    <div class="col-12 col-xl-8">
                        <div class="panel-card">
                            <div class="panel-card-header">
                                <h3 class="panel-card-title">Feedback History</h3>
                                <span class="panel-card-note"><?= count($feedbackList) ?> feedback records</span>
                            </div>

                            <?php if (empty($feedbackList)): ?>
                                <div class="empty-state">No feedback has been recorded yet.</div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table data-table align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>Time</th>
                                                <th>Product</th>
                                                <th>Content</th>
                                                <th>Recorded By</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($feedbackList as $fb): ?>
                                                <tr>
                                                    <td class="text-muted small"><?= htmlspecialchars(date('d/m/Y H:i', strtotime($fb['created_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td><?= $fb['product_name'] !== null ? htmlspecialchars($fb['product_name'], ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>' ?></td>
                                                    <td><?= htmlspecialchars($fb['feedback_text'], ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td class="text-muted"><?= htmlspecialchars($fb['logged_by_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <?php require __DIR__ . '/../components/footer.php'; ?>