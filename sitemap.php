<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

header('Content-Type: application/xml; charset=utf-8');

$db   = get_db();
$stmt = $db->query("
    SELECT uuid, uploaded_at
    FROM images
    WHERE status = 'approved'
    ORDER BY uploaded_at DESC
");
$images = $stmt->fetchAll();

echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">

    <!-- Static pages -->
    <url>
        <loc>https://burgerboard.net/</loc>
        <changefreq>hourly</changefreq>
        <priority>1.0</priority>
    </url>
    <url>
        <loc>https://burgerboard.net/upload.php</loc>
        <changefreq>monthly</changefreq>
        <priority>0.5</priority>
    </url>
    <url>
        <loc>https://burgerboard.net/about.php</loc>
        <changefreq>monthly</changefreq>
        <priority>0.6</priority>
    </url>

    <!-- Approved burger images -->
    <?php foreach ($images as $img): ?>
    <url>
        <loc>https://burgerboard.net/image.php?id=<?= h($img['uuid']) ?></loc>
        <lastmod><?= h(date('Y-m-d', strtotime($img['uploaded_at']))) ?></lastmod>
        <changefreq>never</changefreq>
        <priority>0.8</priority>
    </url>
    <?php endforeach; ?>

</urlset>

