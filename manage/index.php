<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config.php';

// ─── Constants ────────────────────────────────────────────────────────────────
const ADMIN_SESSION_TIMEOUT = 1800; // 30 minutes
const MANAGE_PER_PAGE       = 20;

// ─── Helpers ──────────────────────────────────────────────────────────────────
function is_logged_in(): bool {
    if (empty($_SESSION['admin_id'])) return false;

    // Idle timeout check
    if (!empty($_SESSION['last_active']) &&
        (time() - $_SESSION['last_active']) > ADMIN_SESSION_TIMEOUT) {
        session_unset();
        session_destroy();
        return false;
    }

    $_SESSION['last_active'] = time();
    return true;
}

function require_login(): void {
    if (!is_logged_in()) {
        redirect('/manage/?action=login');
    }
}

// ─── Routing ──────────────────────────────────────────────────────────────────
$action = $_GET['action'] ?? 'queue';

// Public actions — no login required
$public_actions = ['login', 'login_post'];
if (!in_array($action, $public_actions)) {
    require_login();
}

$db = get_db();

// ─────────────────────────────────────────────────────────────────────────────
// POST HANDLERS
// ─────────────────────────────────────────────────────────────────────────────

// ── Login POST ────────────────────────────────────────────────────────────────
if ($action === 'login_post' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        redirect('/manage/?action=login&error=invalid');
    }

    $ip_hash = hash_ip(get_visitor_ip());

    if (is_rate_limited('login_attempts', $ip_hash, LOGIN_LIMIT, LOGIN_WINDOW)) {
        redirect('/manage/?action=login&error=locked');
    }

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $db->prepare("SELECT id, password_hash FROM admin_users WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    $admin = $stmt->fetch();

    if (!$admin || !password_verify($password, $admin['password_hash'])) {
        record_attempt('login_attempts', $ip_hash);
        redirect('/manage/?action=login&error=invalid');
    }

    // Successful login
    session_regenerate_id(true);
    $_SESSION['admin_id']    = $admin['id'];
    $_SESSION['last_active'] = time();

    // Update last login timestamp
    $db->prepare("UPDATE admin_users SET last_login = NOW() WHERE id = ?")
       ->execute([$admin['id']]);

    redirect('/manage/');
}

// ── Approve single image ──────────────────────────────────────────────────────
if ($action === 'approve' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) redirect('/manage/?error=csrf');
    $uuid = $_POST['uuid'] ?? '';
    if ($uuid) {
        $db->prepare("
            UPDATE images SET status = 'approved', reviewed_at = NOW()
            WHERE uuid = ? AND status = 'pending'
        ")->execute([$uuid]);
    }
    redirect('/manage/?success=approved');
}

// ── Reject single image ───────────────────────────────────────────────────────
if ($action === 'reject' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) redirect('/manage/?error=csrf');
    $uuid = $_POST['uuid'] ?? '';
    if ($uuid) {
        $db->prepare("
            UPDATE images SET status = 'rejected', reviewed_at = NOW()
            WHERE uuid = ? AND status = 'pending'
        ")->execute([$uuid]);
    }
    redirect('/manage/?success=rejected');
}

// ── Logout ────────────────────────────────────────────────────────────────────
if ($action === 'logout') {
    session_unset();
    session_destroy();
    redirect('/manage/?action=login');
}

// ── Delete image (hard delete from disk + DB) ─────────────────────────────────
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) redirect('/manage/?error=csrf');
    $uuid = $_POST['uuid'] ?? '';
    if ($uuid && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid)) {
        $stmt = $db->prepare("SELECT filename FROM images WHERE uuid = ? LIMIT 1");
        $stmt->execute([$uuid]);
        $row = $stmt->fetch();
        if ($row) {
            $file = UPLOAD_DIR . basename($row['filename']);
            if (file_exists($file)) unlink($file);
            $db->prepare("DELETE FROM images WHERE uuid = ?")->execute([$uuid]);
        }
    }
    redirect('/manage/?success=deleted');
}

// ─────────────────────────────────────────────────────────────────────────────
// DATA FOR VIEWS
// ─────────────────────────────────────────────────────────────────────────────
$pending_count  = (int)$db->query("SELECT COUNT(*) FROM images WHERE status = 'pending'")->fetchColumn();
$approved_count = (int)$db->query("SELECT COUNT(*) FROM images WHERE status = 'approved'")->fetchColumn();
$rejected_count = (int)$db->query("SELECT COUNT(*) FROM images WHERE status = 'rejected'")->fetchColumn();

// Queue pagination
$queue_page   = max(1, (int)($_GET['page'] ?? 1));
$queue_offset = ($queue_page - 1) * MANAGE_PER_PAGE;
$queue_pages  = (int)ceil($pending_count / MANAGE_PER_PAGE);

$pending_images = [];
if (is_logged_in()) {
    $stmt = $db->prepare("
        SELECT uuid, filename, caption, uploaded_at
        FROM images
        WHERE status = 'pending'
        ORDER BY uploaded_at ASC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([MANAGE_PER_PAGE, $queue_offset]);
    $pending_images = $stmt->fetchAll();
}

// Recent approved/rejected for the log tab
$filter     = $_GET['filter'] ?? 'approved';
$filter     = in_array($filter, ['approved', 'rejected']) ? $filter : 'approved';
$log_page   = max(1, (int)($_GET['logpage'] ?? 1));
$log_offset = ($log_page - 1) * MANAGE_PER_PAGE;
$log_total  = (int)$db->query("SELECT COUNT(*) FROM images WHERE status = '{$filter}'")->fetchColumn();
$log_pages  = (int)ceil($log_total / MANAGE_PER_PAGE);

$log_images = [];
if (is_logged_in()) {
    $stmt = $db->prepare("
        SELECT uuid, filename, caption, uploaded_at, reviewed_at, status
        FROM images
        WHERE status = ?
        ORDER BY reviewed_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$filter, MANAGE_PER_PAGE, $log_offset]);
    $log_images = $stmt->fetchAll();
}

$csrf        = csrf_token();
$error_msg   = $_GET['error']   ?? '';
$success_msg = $_GET['success'] ?? '';
$view        = $_GET['view']    ?? 'queue';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BurgerBoard — Admin</title>
    <link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body class="admin-body">

<?php if (!is_logged_in()): ?>
<!-- ─── LOGIN VIEW ──────────────────────────────────────────────────────────── -->
<main class="login-page">
    <div class="login-box">
        <h1>🍔 BurgerBoard</h1>
        <h2>Admin Login</h2>

        <?php if ($error_msg === 'invalid'): ?>
            <div class="alert alert-error">Invalid username or password.</div>
        <?php elseif ($error_msg === 'locked'): ?>
            <div class="alert alert-error">Too many attempts. Please wait <?= LOGIN_WINDOW ?> minutes.</div>
        <?php endif; ?>

        <form action="/manage/?action=login_post" method="POST">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">

            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username"
                       autocomplete="username" required autofocus>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password"
                       autocomplete="current-password" required>
            </div>

            <button type="submit" class="btn-submit">Login</button>
        </form>
    </div>
</main>

<?php else: ?>
<!-- ─── ADMIN PANEL ─────────────────────────────────────────────────────────── -->
<header class="admin-header">
    <div class="admin-header-inner">
        <a href="/manage/" class="logo">🍔 BurgerBoard Admin</a>
        <nav class="admin-nav">
            <a href="/manage/?view=queue" class="<?= $view === 'queue' ? 'active' : '' ?>">
                Queue <?php if ($pending_count > 0): ?>
                    <span class="badge"><?= $pending_count ?></span>
                <?php endif; ?>
            </a>
            <a href="/manage/?view=log" class="<?= $view === 'log' ? 'active' : '' ?>">Log</a>
            <a href="/" target="_blank" rel="noopener">View Site</a>
            <a href="/manage/?action=logout" class="btn-logout">Logout</a>
        </nav>
    </div>
</header>

<main class="admin-main">

    <!-- Stats bar -->
    <div class="stats-bar">
        <div class="stat">
            <span class="stat-number"><?= number_format($pending_count) ?></span>
            <span class="stat-label">Pending</span>
        </div>
        <div class="stat">
            <span class="stat-number"><?= number_format($approved_count) ?></span>
            <span class="stat-label">Approved</span>
        </div>
        <div class="stat">
            <span class="stat-number"><?= number_format($rejected_count) ?></span>
            <span class="stat-label">Rejected</span>
        </div>
    </div>

    <!-- Flash messages -->
    <?php if ($success_msg === 'approved'): ?>
        <div class="alert alert-success">Image approved.</div>
    <?php elseif ($success_msg === 'rejected'): ?>
        <div class="alert alert-error">Image rejected.</div>
    <?php elseif ($success_msg === 'deleted'): ?>
        <div class="alert alert-error">Image deleted.</div>
    <?php elseif ($error_msg === 'csrf'): ?>
        <div class="alert alert-error">Security error. Please try again.</div>
    <?php endif; ?>

    <?php if ($view === 'queue'): ?>
    <!-- ── QUEUE VIEW ─────────────────────────────────────────────────────── -->
    <?php if (count($pending_images) === 0): ?>
        <div class="empty-state">
            <p>✅</p>
            <h2>Queue is clear!</h2>
            <p>No burgers waiting for review.</p>
        </div>
    <?php else: ?>

        <div class="admin-grid">
            <?php foreach ($pending_images as $img): ?>
            <div class="admin-card">
                <div class="admin-card-image">
                    <img src="/image.php?id=<?= h($img['uuid']) ?>"
                         alt="<?= $img['caption'] ? h($img['caption']) : 'Pending burger' ?>"
                         loading="lazy">
                </div>

                <div class="admin-card-meta">
                    <?php if ($img['caption']): ?>
                        <p class="admin-caption"><?= h($img['caption']) ?></p>
                    <?php else: ?>
                        <p class="admin-caption no-caption">No caption</p>
                    <?php endif; ?>
                    <time><?= h(date('M j, Y H:i', strtotime($img['uploaded_at']))) ?></time>
                </div>

                <div class="admin-card-rotate">
                    <button class="btn-rotate"
                            data-uuid="<?= h($img['uuid']) ?>"
                            data-direction="ccw"
                            title="Rotate counter-clockwise">↺</button>
                    <button class="btn-rotate"
                            data-uuid="<?= h($img['uuid']) ?>"
                            data-direction="cw"
                            title="Rotate clockwise">↻</button>
                </div>

                <div class="admin-card-actions">
                    <!-- Approve -->
                    <form action="/manage/?action=approve" method="POST"
                          style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                        <input type="hidden" name="uuid" value="<?= h($img['uuid']) ?>">
                        <button type="submit" class="btn-approve">✓ Approve</button>
                    </form>

                    <!-- Reject -->
                    <form action="/manage/?action=reject" method="POST"
                          style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                        <input type="hidden" name="uuid" value="<?= h($img['uuid']) ?>">
                        <button type="submit" class="btn-reject">✗ Reject</button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Queue pagination -->
        <?php if ($queue_pages > 1): ?>
        <nav class="pagination">
            <?php if ($queue_page > 1): ?>
                <a href="?view=queue&page=<?= $queue_page - 1 ?>" class="page-btn">← Prev</a>
            <?php endif; ?>
            <span class="page-info">Page <?= $queue_page ?> of <?= $queue_pages ?></span>
            <?php if ($queue_page < $queue_pages): ?>
                <a href="?view=queue&page=<?= $queue_page + 1 ?>" class="page-btn">Next →</a>
            <?php endif; ?>
        </nav>
        <?php endif; ?>

    <?php endif; ?>

    <?php else: ?>
    <!-- ── LOG VIEW ──────────────────────────────────────────────────────── -->
    <div class="log-filter">
        <a href="?view=log&filter=approved"
           class="<?= $filter === 'approved' ? 'active' : '' ?>">Approved</a>
        <a href="?view=log&filter=rejected"
           class="<?= $filter === 'rejected' ? 'active' : '' ?>">Rejected</a>
    </div>

    <?php if (count($log_images) === 0): ?>
        <div class="empty-state">
            <p>📭</p>
            <h2>Nothing here yet.</h2>
        </div>
    <?php else: ?>
        <div class="admin-grid">
            <?php foreach ($log_images as $img): ?>
            <div class="admin-card <?= $img['status'] === 'rejected' ? 'card-rejected' : '' ?>">
                <div class="admin-card-image">
                    <?php if ($img['status'] === 'approved'): ?>
                        <img src="/image.php?id=<?= h($img['uuid']) ?>"
                             alt="<?= $img['caption'] ? h($img['caption']) : 'Burger' ?>"
                             loading="lazy">
                    <?php else: ?>
                        <div class="rejected-placeholder">✗</div>
                    <?php endif; ?>
                </div>

                <div class="admin-card-meta">
                    <?php if ($img['caption']): ?>
                        <p class="admin-caption"><?= h($img['caption']) ?></p>
                    <?php else: ?>
                        <p class="admin-caption no-caption">No caption</p>
                    <?php endif; ?>
                    <time>Uploaded: <?= h(date('M j, Y H:i', strtotime($img['uploaded_at']))) ?></time>
                    <?php if ($img['reviewed_at']): ?>
                        <time>Reviewed: <?= h(date('M j, Y H:i', strtotime($img['reviewed_at']))) ?></time>
                    <?php endif; ?>
                </div>

                <div class="admin-card-actions">
                    <!-- Hard delete -->
                    <form action="/manage/?action=delete" method="POST"
                          onsubmit="return confirm('Permanently delete this image?')">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                        <input type="hidden" name="uuid" value="<?= h($img['uuid']) ?>">
                        <button type="submit" class="btn-delete">🗑 Delete</button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Log pagination -->
        <?php if ($log_pages > 1): ?>
        <nav class="pagination">
            <?php if ($log_page > 1): ?>
                <a href="?view=log&filter=<?= $filter ?>&logpage=<?= $log_page - 1 ?>"
                   class="page-btn">← Prev</a>
            <?php endif; ?>
            <span class="page-info">Page <?= $log_page ?> of <?= $log_pages ?></span>
            <?php if ($log_page < $log_pages): ?>
                <a href="?view=log&filter=<?= $filter ?>&logpage=<?= $log_page + 1 ?>"
                   class="page-btn">Next →</a>
            <?php endif; ?>
        </nav>
        <?php endif; ?>
    <?php endif; ?>
    <?php endif; ?>

</main>

<script>
document.querySelectorAll('.btn-rotate').forEach(btn => {
    btn.addEventListener('click', async () => {
        const uuid      = btn.dataset.uuid;
        const direction = btn.dataset.direction;
        const card      = btn.closest('.admin-card');
        const img       = card.querySelector('img');

        // Disable both rotate buttons while processing
        card.querySelectorAll('.btn-rotate').forEach(b => b.disabled = true);
        btn.textContent = '⏳';

        try {
            const formData = new FormData();
            formData.append('uuid',       uuid);
            formData.append('direction',  direction);
            formData.append('csrf_token', '<?= h($csrf) ?>');

            const response = await fetch('/manage/rotate.php', {
                method: 'POST',
                body:   formData
            });

            const data = await response.json();

            if (data.success) {
                // Bust the image cache and reload it
                img.src = '/image.php?id=' + uuid + '&t=' + Date.now();
            } else {
                alert('Rotation failed: ' + (data.error ?? 'unknown error'));
            }
        } catch (err) {
            alert('Request failed. Please try again.');
        } finally {
            // Re-enable buttons and restore icons
            card.querySelectorAll('.btn-rotate').forEach(b => b.disabled = false);
            card.querySelector('[data-direction="ccw"]').textContent = '↺';
            card.querySelector('[data-direction="cw"]').textContent  = '↻';
        }
    });
});
</script>

<?php endif; ?>
</body>
</html>
