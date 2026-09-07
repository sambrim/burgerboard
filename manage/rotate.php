<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

// Must be logged in
if (empty($_SESSION['admin_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Must be POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// CSRF check
if (!csrf_verify()) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid request']);
    exit;
}

// Validate UUID
$uuid = $_POST['uuid'] ?? '';
if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid image']);
    exit;
}

// Validate direction
$direction = $_POST['direction'] ?? '';
if (!in_array($direction, ['cw', 'ccw'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid direction']);
    exit;
}

$db = get_db();

// Get image — must be pending
$stmt = $db->prepare("SELECT filename FROM images WHERE uuid = ? AND status = 'pending' LIMIT 1");
$stmt->execute([$uuid]);
$image = $stmt->fetch();

if (!$image) {
    http_response_code(404);
    echo json_encode(['error' => 'Image not found']);
    exit;
}

$file_path = UPLOAD_DIR . basename($image['filename']);

if (!file_exists($file_path) || !is_readable($file_path)) {
    http_response_code(404);
    echo json_encode(['error' => 'File not found']);
    exit;
}

// Load image
$source = imagecreatefromjpeg($file_path);
if ($source === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not load image']);
    exit;
}

// Rotate — GD rotates counter-clockwise so we invert for cw
$degrees = $direction === 'cw' ? 270 : 90;
$rotated = imagerotate($source, $degrees, 0);
imagedestroy($source);

if ($rotated === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Rotation failed']);
    exit;
}

// Save back to same file
if (!imagejpeg($rotated, $file_path, 85)) {
    imagedestroy($rotated);
    http_response_code(500);
    echo json_encode(['error' => 'Could not save image']);
    exit;
}

imagedestroy($rotated);

echo json_encode(['success' => true]);
exit;
