<?php
/**
 * File: frontend/components/footer.php
 * Purpose: Shared page footer, closing HTML tags, shared JS includes.
 *
 * Dùng ở CUỐI mỗi trang, sau khi đã include header.php + sidebar.php và render
 * xong nội dung chính (thường bọc trong <main class="app-main">...</main>
 * trước khi include file này).
 */
?>
    <footer class="app-footer">
        <span>&copy; <?= date('Y') ?> GS25 IntelliStock - InventoryDSS</span>
        <span class="app-footer-sep">&middot;</span>
        <span>InventoryDSS_Group3_INS328201</span>
    </footer>

    <script src="<?= BASE_URL ?>/assets/js/common.js"></script>
</body>
</html>