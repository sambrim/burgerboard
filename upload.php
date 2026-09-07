<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/config.php';

// ─── CSRF token init (needed for the form) ────────────────────────────────────
$csrf = csrf_token();

// ─── Allowed MIME types ───────────────────────────────────────────────────────
const ALLOWED_MIME = [
    'image/jpeg'    => 'jpg',
    'image/png'     => 'png',
    'image/webp'    => 'webp',
    'image/heic'    => 'heic',
    'image/heif'    => 'heif',
    'image/gif'     => 'gif',
    'image/bmp'     => 'bmp',
    'image/tiff'    => 'tiff',
];

// ─── MIME types that require ImageMagick (GD can't handle them) ───────────────
const IMAGICK_MIME = [
    'image/heic',
    'image/heif',
    'image/bmp',
    'image/tiff',
];

// ─── Result state ─────────────────────────────────────────────────────────────
$success = false;
$error   = '';

// ─── Handle POST ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF check
    if (!csrf_verify()) {
        $error = 'Invalid request. Please try again.';
    }

    // Honeypot check — bots fill this, humans leave it empty
    elseif (!empty($_POST['website'])) {
        // Silently succeed to not tip off bots
        $success = true;
    }

    else {
        $ip      = get_visitor_ip();
        $ip_hash = hash_ip($ip);

        // Rate limit check
        if (is_rate_limited('upload_attempts', $ip_hash, UPLOAD_LIMIT, UPLOAD_WINDOW)) {
            $error = 'Too many uploads. Please wait a while before trying again.';
        }

        // File present?
        elseif (empty($_FILES['burger_image']) || $_FILES['burger_image']['error'] === UPLOAD_ERR_NO_FILE) {
            $error = 'Please select an image to upload.';
        }

        // Upload error?
        elseif ($_FILES['burger_image']['error'] !== UPLOAD_ERR_OK) {
            $error = 'Upload failed. Please try again.';
        }

        // File size check
        elseif ($_FILES['burger_image']['size'] > MAX_FILE_SIZE) {
            $error = 'Image is too large. Maximum size is 8MB.';
        }

        else {
            $tmp_path = $_FILES['burger_image']['tmp_name'];

            // ── Server-side MIME validation via finfo ─────────────────────────
            $finfo     = new finfo(FILEINFO_MIME_TYPE);
            $mime_type = $finfo->file($tmp_path);

            if (!array_key_exists($mime_type, ALLOWED_MIME)) {
                $error = 'Invalid file type. Please upload a JPEG, PNG, WebP, HEIC, GIF, BMP or TIFF image.';
            }

            else {
                // ── Generate UUID filename — always save as JPEG ───────────────
                $uuid      = generate_uuid();
                $filename  = $uuid . '.jpg';
                $dest_path = UPLOAD_DIR . $filename;
                $saved     = false;

                if (in_array($mime_type, IMAGICK_MIME)) {
                    // ── ImageMagick path — handles HEIC, HEIF, BMP, TIFF ──────
                    try {
                        $imagick = new Imagick($tmp_path);
                        $imagick->setImageFormat('jpeg');
                        $imagick->setImageCompressionQuality(85);
                        $imagick->autoOrient(); // auto-rotate based on EXIF before stripping
                        $imagick->stripImage(); // strip all EXIF/metadata
                        $imagick->writeImage($dest_path);
                        $imagick->destroy();
                        $saved = true;
                    } catch (ImagickException $e) {
                        $error = 'Could not process image. Please try a different file.';
                    }                    
                    } else {
                    // ── GD path — handles JPEG, PNG, WebP, GIF ───────────────
                    $source = match($mime_type) {
                        'image/jpeg' => imagecreatefromjpeg($tmp_path),
                        'image/png'  => imagecreatefrompng($tmp_path),
                        'image/webp' => imagecreatefromwebp($tmp_path),
                        'image/gif'  => imagecreatefromgif($tmp_path),
                    };

                    if ($source === false) {
                        $error = 'Could not process image. Please try a different file.';
                    } else {
                        // ── Auto-rotate based on EXIF orientation (JPEG only) ─
                        if ($mime_type === 'image/jpeg') {
                            $exif = @exif_read_data($tmp_path);
                            $orientation = $exif['Orientation'] ?? 1;

                            $source = match($orientation) {
                                3 => imagerotate($source, 180, 0),  // upside down
                                6 => imagerotate($source, 270, 0),  // rotated 90 CW
                                8 => imagerotate($source, 90, 0),   // rotated 90 CCW
                                default => $source                   // normal, no rotation needed
                            };
                        }

                        // Handle PNG/GIF transparency — fill with white background
                        $width  = imagesx($source);
                        $height = imagesy($source);
                        $bg     = imagecreatetruecolor($width, $height);
                        $white  = imagecolorallocate($bg, 255, 255, 255);
                        imagefill($bg, 0, 0, $white);
                        imagecopy($bg, $source, 0, 0, 0, 0, $width, $height);
                        imagedestroy($source);

                        $saved = imagejpeg($bg, $dest_path, 85);
                        imagedestroy($bg);

                        if (!$saved) {
                            $error = 'Failed to save image. Please try again.';
                        }
                    }
                }

                if ($saved) {
                    // ── Sanitize optional caption ─────────────────────────────
                    $caption = isset($_POST['caption'])
                        ? trim(mb_substr(strip_tags($_POST['caption']), 0, 300))
                        : null;
                    if ($caption === '') $caption = null;

                    // ── Record attempt + insert DB row ────────────────────────
                    record_attempt('upload_attempts', $ip_hash);

                    $db = get_db();
                    $db->prepare("
                        INSERT INTO images
                            (uuid, filename, caption, status, ip_hash, uploaded_at)
                        VALUES
                            (?, ?, ?, 'pending', ?, NOW())
                    ")->execute([$uuid, $filename, $caption, $ip_hash]);

                    // ── Send SMS notification to admin ────────────────────────
                    send_sms('A new burger post has been submitted and is awaiting your approval.');

                    $success = true;
                    // Regenerate CSRF token after successful submission
                    unset($_SESSION['csrf_token']);
                    $csrf = csrf_token();
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Your Burger — BurgerBoard</title>
    <link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="canonical" href="https://burgerboard.net/upload.php">
</head>
<body>
    <header class="site-header">
        <div class="header-inner">
            <a href="/" class="logo">🍔 BurgerBoard</a>
        </div>
    </header>

    <main class="upload-page">
        <h1>Share Your Burger</h1>
        <p class="subtitle">All uploads are reviewed before appearing in the gallery.</p>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <strong>Burger submitted!</strong> It will appear in the gallery once approved. Thanks for sharing!
            </div>
        <?php elseif ($error !== ''): ?>
            <div class="alert alert-error">
                <?= h($error) ?>
            </div>
        <?php endif; ?>

        <?php if (!$success): ?>
        <form class="upload-form" action="/upload.php" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">

            <!-- Honeypot — hidden from real users via CSS -->
            <div class="honeypot" aria-hidden="true">
                <label for="website">Leave this empty</label>
                <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
            </div>

            <div class="form-group">
                <label for="burger_image">Burger Photo <span class="required">*</span></label>
                <input type="file" id="burger_image" name="burger_image"
                       accept="image/jpeg,image/png,image/webp,image/heic,image/heif,image/gif,image/bmp,image/tiff" required>
                <small>JPEG, PNG, WebP, HEIC, GIF, BMP or TIFF · Max 8MB</small>
            </div>

            <div class="form-group">
                <label for="caption">Caption <span class="optional">(optional)</span></label>
                <input type="text" id="caption" name="caption"
                       maxlength="300" placeholder="What's in this burger?">
            </div>

            <button type="submit" class="btn-submit">Submit Burger 🍔</button>
        </form>
        <?php endif; ?>
    </main>

<footer class="site-footer">
        <p>
            <a href="/">Gallery</a> ·
            <a href="/about.php">About</a>
        </p>
    </footer>
</body>
</html>
