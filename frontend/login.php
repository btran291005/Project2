<?php

declare(strict_types=1);

require_once __DIR__ . '/../backend/config/app_config.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../backend/core/Logger.php';
require_once __DIR__ . '/../backend/core/Auth.php';

Auth::start();

// Đã đăng nhập rồi thì không cần xem lại form login -> đẩy thẳng về dashboard
if (Auth::check()) {
    header('Location: index.php');
    exit;
}

// 3 tab role hiển thị trên UI
$roleTabs = ROLE_NAMES;

$errorMessage = '';
$selectedRole = ROLE_ADMIN; // mặc định tab đầu tiên khi mới vào trang

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username     = $_POST['username'] ?? '';
    $password     = $_POST['password'] ?? '';
    $postedRoleId = (int) ($_POST['role_id'] ?? 0);

    if (array_key_exists($postedRoleId, $roleTabs)) {
        $selectedRole = $postedRoleId;
    }

    $result = Auth::login($username, $password, $postedRoleId > 0 ? $postedRoleId : null);

    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
              || (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false)
              || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

    if ($result['success']) {
        $roleId = Auth::roleId();
        $dest = BASE_URL . '/index.php';
        if ($roleId === ROLE_ADMIN) {
            $dest = BASE_URL . '/admin/dashboard.php';
        } elseif ($roleId === ROLE_MANAGER) {
            $dest = BASE_URL . '/manager/dashboard.php';
        } elseif ($roleId === ROLE_STAFF) {
            $dest = BASE_URL . '/staff/dashboard.php';
        }

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => true, 'redirect' => $dest, 'role_id' => $roleId]);
            exit;
        }

        header('Location: ' . $dest);
        exit;
    }

    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => $result['message']]);
        exit;
    }

    $errorMessage = $result['message'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In - Gs25IntelliStock</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/login.css?v=20260803" rel="stylesheet">
</head>
<body>
    <div class="container-fluid px-4 px-xl-5 py-4 py-lg-5 position-relative" style="z-index: 1;">
        <div class="row g-4 g-lg-5 mx-auto align-items-stretch" style="max-width: 1400px;">

            <!-- Cột trái: logo + hero panel -->
            <div class="col-12 col-lg-7 d-flex flex-column">
                <div class="d-flex align-items-center gap-3 mb-4">
                    <img src="<?= BASE_URL ?>/assets/img/gs25_luxury_logo.jpg" alt="Gs25IntelliStock" class="rounded-3 shadow-sm border" style="width: 48px; height: 48px; object-fit: cover;" referrerpolicy="no-referrer">
                    <div>
                        <div class="fs-4 fw-bold text-dark lh-sm" style="color: var(--brand-primary) !important;">Gs25IntelliStock</div>
                        <div class="text-uppercase text-muted fw-bold small" style="font-size: .78rem; letter-spacing: .06em;">Smart Inventory System</div>
                    </div>
                </div>

                <div class="hero-panel flex-grow-1 rounded-4">
                    <img src="<?= BASE_URL ?>/assets/img/gs25_store_hero.png" alt="GS25 Convenience Store" class="hero-panel-photo" referrerpolicy="no-referrer">
                    <div class="hero-panel-overlay"></div>
                </div>
            </div>

            <!-- Cột phải: form -->
            <div class="col-12 col-lg-5 d-flex flex-column">
                <h1 class="fw-bold display-6 mb-2">Enterprise Sign In</h1>
                <p class="text-muted mb-4">Select a role and sign in to the InventoryDSS system.</p>

                <div class="role-tabs d-grid gap-2 p-1 rounded-3 mb-4" style="grid-template-columns: repeat(3, 1fr);" role="tablist" aria-label="Select login role">
                    <?php foreach ($roleTabs as $roleIdOption => $roleLabel): ?>
                        <button type="button"
                                class="role-tab btn btn-sm fw-bold border-0 rounded-2 py-2<?= $selectedRole === $roleIdOption ? ' active' : '' ?>"
                                data-role-id="<?= (int) $roleIdOption ?>"
                                role="tab"
                                aria-selected="<?= $selectedRole === $roleIdOption ? 'true' : 'false' ?>">
                            <?= htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8') ?>
                        </button>
                    <?php endforeach; ?>
                </div>

                <div class="login-card bg-white rounded-4 p-4 border flex-grow-1 d-flex flex-column justify-content-center">
                    <div id="alertContainer">
                        <?php if ($errorMessage !== ''): ?>
                            <div class="alert alert-danger py-2 small mb-3"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                    </div>

                    <form id="loginForm" method="POST" action="login.php" autocomplete="off">
                        <input type="hidden" name="role_id" id="role_id" value="<?= (int) $selectedRole ?>">

                        <div class="mb-3">
                            <label for="username" class="form-label text-uppercase small fw-bold text-secondary" style="letter-spacing: .04em; font-size: .72rem;">Username</label>
                            <div class="field-wrap">
                                <svg class="field-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" stroke="currentColor" stroke-width="1.8">
                                    <rect x="3" y="5" width="18" height="14" rx="2"/>
                                    <path d="M3 7l9 6 9-6"/>
                                </svg>
                                <input type="text" id="username" name="username" class="form-control rounded-3" required autofocus autocomplete="username"
                                       placeholder="name@gs25.com"
                                       value="<?= htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="password" class="form-label text-uppercase small fw-bold text-secondary" style="letter-spacing: .04em; font-size: .72rem;">Password</label>
                            <div class="field-wrap">
                                <svg class="field-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" stroke="currentColor" stroke-width="1.8">
                                    <rect x="4" y="10" width="16" height="10" rx="2"/>
                                    <path d="M8 10V7a4 4 0 018 0v3"/>
                                </svg>
                                <input type="password" id="password" name="password" class="form-control rounded-3" required autocomplete="current-password"
                                       placeholder="••••••••">
                                <button type="button" class="toggle-pw" id="togglePasswordBtn" aria-label="Show or hide password">
                                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" stroke="currentColor" stroke-width="1.8">
                                        <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7z"/>
                                        <circle cx="12" cy="12" r="3"/>
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <div class="form-check d-flex align-items-center gap-2 mb-4">
                            <input type="checkbox" id="remember" name="remember" class="form-check-input mt-0" style="cursor: pointer;">
                            <label for="remember" class="form-check-label small text-secondary" style="cursor: pointer;">Keep me signed in</label>
                        </div>

                        <button type="submit" id="submitBtn" class="btn btn-brand w-100 fw-bold py-2 rounded-3 d-flex align-items-center justify-content-center gap-2">
                            <span id="submitBtnText">Sign In</span>
                            <svg id="submitBtnIcon" width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" stroke="currentColor" stroke-width="2">
                                <path d="M5 12h14M13 6l6 6-6 6"/>
                            </svg>
                        </button>
                    </form>

                    <div class="mt-4 pt-3 border-top border-dashed">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <div class="text-uppercase text-muted fw-bold small" style="letter-spacing: .06em; font-size: .68rem;">Tài khoản mẫu (Click để điền nhanh)</div>
                            <span class="badge bg-light text-primary border" style="font-size: .65rem;">1-Click Fill</span>
                        </div>
                        <table class="table table-sm demo-table mb-0 align-middle">
                            <thead>
                                <tr class="text-muted small">
                                    <th class="fw-semibold">Role</th>
                                    <th class="fw-semibold">Username</th>
                                    <th class="fw-semibold">Password</th>
                                    <th class="text-end fw-semibold">Action</th>
                                </tr>
                            </thead>
                            <tbody class="small">
                                <tr style="cursor: pointer;" class="demo-account-row" data-role="1" data-user="admin" data-pw="Admin@123">
                                    <td class="fw-bold role-admin">Admin</td>
                                    <td><code>admin</code></td>
                                    <td><code>Admin@123</code></td>
                                    <td class="text-end"><button type="button" class="btn btn-xs btn-outline-primary py-0 px-2 rounded-2 btn-fill-demo">Click</button></td>
                                </tr>
                                <tr style="cursor: pointer;" class="demo-account-row" data-role="2" data-user="manager1" data-pw="Manager@123">
                                    <td class="fw-bold role-manager">Manager</td>
                                    <td><code>manager1</code></td>
                                    <td><code>Manager@123</code></td>
                                    <td class="text-end"><button type="button" class="btn btn-xs btn-outline-primary py-0 px-2 rounded-2 btn-fill-demo">Click</button></td>
                                </tr>
                                <tr style="cursor: pointer;" class="demo-account-row" data-role="3" data-user="staff1" data-pw="Staff@123">
                                    <td class="fw-bold role-staff">Store Staff</td>
                                    <td><code>staff1</code></td>
                                    <td><code>Staff@123</code></td>
                                    <td class="text-end"><button type="button" class="btn btn-xs btn-outline-primary py-0 px-2 rounded-2 btn-fill-demo">Click</button></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="text-center small text-muted mt-3 pt-3 border-top border-dashed">
                        Need access? Contact the system administrator.
                    </div>
                </div>
            </div>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        (function () {
            var btn = document.getElementById('togglePasswordBtn');
            var input = document.getElementById('password');
            if (btn && input) {
                btn.addEventListener('click', function () {
                    input.type = input.type === 'password' ? 'text' : 'password';
                });
            }

            var tabs = document.querySelectorAll('.role-tab');
            var roleInput = document.getElementById('role_id');
            var userInput = document.getElementById('username');
            var passInput = document.getElementById('password');

            function setRole(roleId) {
                if (roleInput) roleInput.value = roleId;
                tabs.forEach(function (t) {
                    var match = t.getAttribute('data-role-id') === String(roleId);
                    t.classList.toggle('active', match);
                    t.setAttribute('aria-selected', match ? 'true' : 'false');
                });
            }

            if (tabs.length) {
                tabs.forEach(function (tab) {
                    tab.addEventListener('click', function () {
                        setRole(tab.getAttribute('data-role-id'));
                    });
                });
            }

            // Auto switch role when typing standard usernames
            if (userInput) {
                userInput.addEventListener('input', function () {
                    var val = userInput.value.trim().toLowerCase();
                    if (val.startsWith('admin')) {
                        setRole(1);
                    } else if (val.startsWith('manager')) {
                        setRole(2);
                    } else if (val.startsWith('staff')) {
                        setRole(3);
                    }
                });
            }

            // Click demo account row to fill
            document.querySelectorAll('.demo-account-row').forEach(function (row) {
                row.addEventListener('click', function () {
                    var role = row.getAttribute('data-role');
                    var user = row.getAttribute('data-user');
                    var pw = row.getAttribute('data-pw');
                    if (userInput) userInput.value = user;
                    if (passInput) passInput.value = pw;
                    setRole(role);
                    if (userInput) userInput.focus();
                });
            });

            // Async form submit with visual state and robust redirect
            var form = document.getElementById('loginForm');
            var submitBtn = document.getElementById('submitBtn');
            var submitBtnText = document.getElementById('submitBtnText');
            var alertContainer = document.getElementById('alertContainer');

            if (form && submitBtn) {
                form.addEventListener('submit', function (e) {
                    e.preventDefault();

                    var usernameVal = (userInput ? userInput.value : '').trim();
                    var passwordVal = (passInput ? passInput.value : '');
                    var roleVal = roleInput ? roleInput.value : '1';

                    if (!usernameVal || !passwordVal) {
                        if (alertContainer) {
                            alertContainer.innerHTML = '<div class="alert alert-danger py-2 small mb-3">Vui lòng nhập đầy đủ Username và Mật khẩu.</div>';
                        }
                        return;
                    }

                    // Loading UI
                    submitBtn.disabled = true;
                    if (submitBtnText) submitBtnText.textContent = 'Signing in...';
                    if (alertContainer) alertContainer.innerHTML = '';

                    var formData = new FormData(form);

                    fetch('login.php', {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json'
                        },
                        credentials: 'same-origin'
                    })
                    .then(function (response) {
                        return response.json().catch(function () {
                            // If response is HTML or redirect, fallback to standard redirect
                            return { success: response.ok, redirect: 'index.php' };
                        });
                    })
                    .then(function (data) {
                        if (data && data.success) {
                            if (submitBtnText) submitBtnText.textContent = 'Redirecting...';
                            var target = data.redirect || 'index.php';
                            window.location.href = target;
                        } else {
                            submitBtn.disabled = false;
                            if (submitBtnText) submitBtnText.textContent = 'Sign In';
                            var msg = (data && data.message) ? data.message : 'Đăng nhập không thành công. Vui lòng kiểm tra lại thông tin.';
                            if (alertContainer) {
                                alertContainer.innerHTML = '<div class="alert alert-danger py-2 small mb-3">' + msg + '</div>';
                            }
                        }
                    })
                    .catch(function (err) {
                        console.warn('Fetch failed, submitting via standard POST:', err);
                        form.submit();
                    });
                });
            }
        })();
    </script>
</body>
</html>