<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// ─── Pagination ───────────────────────────────────────────────────────────────
const PER_PAGE = 24;
$page   = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * PER_PAGE;

// ─── Fetch approved images ────────────────────────────────────────────────────
$db    = get_db();
$total = (int)$db->query("SELECT COUNT(*) FROM images WHERE status = 'approved'")->fetchColumn();
$pages = (int)ceil($total / PER_PAGE);
$page  = min($page, max(1, $pages)); // clamp page to valid range

$stmt = $db->prepare("
    SELECT uuid, filename, caption, uploaded_at
    FROM images
    WHERE status = 'approved'
    ORDER BY uploaded_at DESC
    LIMIT ? OFFSET ?
");
$stmt->execute([PER_PAGE, $offset]);
$images = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BurgerBoard — A Gallery of Glorious Burgers</title>
    <meta name="description" content="A community gallery of burger photos from around the world. Browse delicious burgers and share your own.">
    <meta name="keywords" content="burger, hamburger, food, gallery, community, photos">
    <meta name="robots" content="index, follow">

    <!-- Open Graph — looks good when shared on social media -->
    <meta property="og:title" content="BurgerBoard — A Gallery of Glorious Burgers">
    <meta property="og:description" content="A community gallery of burger photos from around the world.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://burgerboard.net">

    <link rel="canonical" href="https://burgerboard.net/">
    <link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <header class="site-header">
        <div class="header-inner">
            <a href="/" class="logo">🍔 BurgerBoard</a>
            <nav class="header-nav">
                <a href="/upload.php" class="btn-upload">Share a Burger</a>
            </nav>
        </div>
    </header>

    <main class="gallery-page">

        <?php if ($total === 0): ?>
            <div class="empty-state">
                <p>🍔</p>
                <h2>No burgers yet!</h2>
                <p>Be the first to share one.</p>
                <a href="/upload.php" class="btn-upload">Upload Now</a>
            </div>

        <?php else: ?>
            <div class="gallery-grid">
                <?php foreach ($images as $img): ?>
                    <figure class="gallery-item">
                        <a href="/image.php?id=<?= h($img['uuid']) ?>"
                           target="_blank"
                           rel="noopener"
                           class="gallery-link">
                            <img
                                src="/image.php?id=<?= h($img['uuid']) ?>"
                                alt="<?= $img['caption'] ? h($img['caption']) : 'A delicious burger' ?>"
                                loading="lazy"
                                decoding="async"
                            >
                        </a>
                        <?php if ($img['caption']): ?>
                            <figcaption><?= h($img['caption']) ?></figcaption>
                        <?php endif; ?>
                        <time class="upload-time"
                              datetime="<?= h($img['uploaded_at']) ?>"
                              title="<?= h($img['uploaded_at']) ?>">
                            <?= h(date('M j, Y', strtotime($img['uploaded_at']))) ?>
                        </time>
                    </figure>
                <?php endforeach; ?>
            </div>

            <?php if ($pages > 1): ?>
            <nav class="pagination" aria-label="Gallery pages">
                <?php if ($page > 1): ?>
                    <a href="?page=<?= $page - 1 ?>" class="page-btn" rel="prev">← Newer</a>
                <?php endif; ?>

                <span class="page-info">Page <?= $page ?> of <?= $pages ?></span>

                <?php if ($page < $pages): ?>
                    <a href="?page=<?= $page + 1 ?>" class="page-btn" rel="next">Older →</a>
                <?php endif; ?>
            </nav>
            <?php endif; ?>

        <?php endif; ?>
    </main>

<footer class="site-footer">
    <p>
        <a href="/upload.php">Share your burger</a> ·
        <a href="/about.php">About</a> ·
        <span><?= number_format($total) ?> burger<?= $total !== 1 ? 's' : '' ?> and counting</span>
    </p>
</footer>

    <script>
    // Lightweight lightbox — clicking an image opens it full size in an overlay
    document.querySelectorAll('.gallery-link').forEach(link => {
        link.addEventListener('click', e => {
            e.preventDefault();
            const src = link.href;
            const alt = link.querySelector('img').alt;

            const overlay = document.createElement('div');
            overlay.className = 'lightbox';
            overlay.innerHTML = `
                <div class="lightbox-backdrop"></div>
                <div class="lightbox-content">
                    <button class="lightbox-close" aria-label="Close">&times;</button>
                    <img src="${src}" alt="${alt}">
                </div>
            `;

            document.body.appendChild(overlay);
            document.body.style.overflow = 'hidden';

            const close = () => {
                overlay.remove();
                document.body.style.overflow = '';
            };

            overlay.querySelector('.lightbox-close').addEventListener('click', close);
            overlay.querySelector('.lightbox-backdrop').addEventListener('click', close);
            document.addEventListener('keydown', e => {
                if (e.key === 'Escape') close();
            }, { once: true });
        });
    });
    </script>
</body>
</html>
