<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// ─── Get and validate the requested UUID ──────────────────────────────────────
$uuid = $_GET['id'] ?? '';

// UUID v4 format validation — reject anything that doesn't match
if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid)) {
    http_response_code(400);
    exit('Invalid request.');
}

// ─── Look up the image in the database ────────────────────────────────────────
$db   = get_db();
$stmt = $db->prepare("
    SELECT filename, status
    FROM images
    WHERE uuid = ?
    LIMIT 1
");
$stmt->execute([$uuid]);
$image = $stmt->fetch();

// ─── Only serve approved images publicly, but also pending for admin session ──
session_start();
$is_admin = !empty($_SESSION['admin_id']);

if (!$image || ($image['status'] !== 'approved' && !$is_admin)) {
    http_response_code(404);
    exit('Image not found.');
}

// ─── Build the file path ──────────────────────────────────────────────────────
$file_path = UPLOAD_DIR . basename($image['filename']);

if (!file_exists($file_path) || !is_readable($file_path)) {
    http_response_code(404);
    exit('Image not found.');
}

// ─── Verify the file is actually a JPEG (everything is converted to JPEG) ─────
$finfo     = new finfo(FILEINFO_MIME_TYPE);
$mime_type = $finfo->file($file_path);

if ($mime_type !== 'image/jpeg') {
    http_response_code(415);
    exit('Unsupported media type.');
}

// ─── Send the image ───────────────────────────────────────────────────────────
$file_size = filesize($file_path);

header('Content-Type: image/jpeg');
header('Content-Length: ' . $file_size);
header('Cache-Control: public, max-age=31536000, immutable'); // 1 year — UUID never changes
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline');

// ─── Stream the file in chunks to keep memory usage low ───────────────────────
$handle = fopen($file_path, 'rb');
if ($handle === false) {
    http_response_code(500);
    exit('Could not read image.');
}

while (!feof($handle)) {
    echo fread($handle, 8192);
    flush();
}
fclose($handle);
exit;
