# 🍔 BurgerBoard

A community-driven gallery for sharing burger photos from around the world. No accounts, no tracking, no nonsense — just burgers.

## Overview

BurgerBoard is a privacy-first photo gallery where burger enthusiasts can anonymously upload and share photos of their favorite burgers. Every submission is reviewed by a human before appearing publicly to maintain gallery quality.

**Live Site:** https://burgerboard.net

## Features

### 🔒 Privacy First
- **Fully anonymous uploads** — No accounts, emails, or names required
- **No IP logging** — Visitor IPs are never stored; only hashed tokens for rate limiting
- **EXIF stripping** — All image metadata (GPS, device info, timestamps) automatically removed
- **No tracking** — Zero analytics, no third-party cookies, no advertising
- **Cloudflare protected** — Your real IP never reaches our origin server
- **HTTPS encrypted** — All connections secured end-to-end

### 🖼️ Gallery Features
- **Responsive design** — Works seamlessly on desktop and mobile
- **Lazy loading** — Efficient image loading with `loading="lazy"`
- **Lightbox viewer** — Click images to view full-size in an overlay
- **Pagination** — 24 burgers per page with navigation controls
- **Captions** — Optional user-provided descriptions (max 300 characters)
- **Timestamps** — Upload dates displayed in human-readable format

### 📝 Submission Features
- **Multiple formats** — JPEG, PNG, WebP, HEIC, HEIF, GIF, BMP, TIFF
- **Large files** — Up to 8MB per upload
- **Auto-conversion** — All images converted to JPEG for consistency
- **Auto-rotation** — Photos automatically rotated based on EXIF orientation
- **Spam protection** — Rate limiting (5 uploads per IP per 30 minutes)
- **Honeypot field** — Bot protection via hidden form field
- **CSRF protection** — Token-based request verification
- **Admin notifications** — SMS alerts via 46elks when new submissions arrive

### 🔍 SEO Optimized
- **XML sitemap** — Dynamic sitemap for search engines
- **Open Graph tags** — Rich previews on social media
- **Canonical URLs** — Proper URL structuring
- **Meta tags** — Descriptive titles and descriptions
- **robots.txt** — Search engine directives

## Installation

### Requirements
- PHP 8.0+ with PDO MySQL support
- MySQL 5.7+ or MariaDB
- GD library (for JPEG, PNG, WebP, GIF processing)
- ImageMagick PHP extension (for HEIC, HEIF, BMP, TIFF support)
- curl (for SMS notifications)
- Cloudflare account (recommended for privacy)

### Setup Steps

1. **Clone or download the repository:**
   ```bash
   git clone <repository-url>
   cd burgerboard
   ```

2. **Create the database:**
   ```sql
   CREATE DATABASE burgerboard;
   ```

3. **Create required tables:**
   ```sql
   USE burgerboard;

   CREATE TABLE images (
       id INT AUTO_INCREMENT PRIMARY KEY,
       uuid VARCHAR(36) UNIQUE NOT NULL,
       filename VARCHAR(255) NOT NULL,
       caption VARCHAR(300),
       status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
       ip_hash VARCHAR(64) NOT NULL,
       uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
       reviewed_at TIMESTAMP NULL,
       INDEX (status),
       INDEX (uploaded_at)
   );

   CREATE TABLE upload_attempts (
       id INT AUTO_INCREMENT PRIMARY KEY,
       ip_hash VARCHAR(64) NOT NULL,
       attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
       INDEX (ip_hash, attempted_at)
   );

   CREATE TABLE login_attempts (
       id INT AUTO_INCREMENT PRIMARY KEY,
       ip_hash VARCHAR(64) NOT NULL,
       attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
       INDEX (ip_hash, attempted_at)
   );
   ```

4. **Configure the application:**
   - Copy `config.php` and update with your database credentials
   - Set `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`
   - Generate a random `IP_SALT` string (keep secret, never change after launch)
   - Create the upload directory: `mkdir -p /var/uploads/burgerboard/` and set permissions: `chmod 755 /var/uploads/burgerboard/`

5. **Configure SMS notifications (optional):**
   - Sign up at [46elks.com](https://46elks.com)
   - Set `SMS_ENABLED`, `SMS_USERNAME`, `SMS_PASSWORD`, `SMS_FROM`, `SMS_TO` in `config.php`
   - Disable SMS by setting `SMS_ENABLED = false` if not needed

6. **Serve with a web server:**
   - Apache with `mod_php`
   - Nginx with PHP-FPM
   - Make sure `mod_rewrite` is enabled (for clean URLs if configured)

7. **Set up Cloudflare (recommended):**
   - Point your domain to Cloudflare
   - Enable Full (Strict) SSL/TLS encryption
   - The app trusts the `CF-Connecting-IP` header for rate limiting

## File Structure

```
burgerboard/
├── index.php           # Gallery homepage with pagination
├── upload.php          # Submission form and file handling
├── image.php           # Image serving with access control
├── about.php           # About page and privacy policy
├── sitemap.php         # XML sitemap for search engines
├── robots.txt          # Search engine directives
├── config.php          # Configuration and helper functions
├── /assets/            # CSS, JavaScript, images
└── /manage/            # Admin dashboard (not included)
```

## Configuration

### Rate Limiting
Edit `config.php` to adjust:
```php
define('UPLOAD_LIMIT', 5);      // max uploads per IP
define('UPLOAD_WINDOW', 30);    // within 30 minutes
```

### File Size
```php
define('MAX_FILE_SIZE', 8 * 1024 * 1024);  // 8MB
```

### Upload Directory
```php
define('UPLOAD_DIR', '/var/uploads/burgerboard/');
```

Ensure this directory exists and is writable by the web server:
```bash
mkdir -p /var/uploads/burgerboard/
chmod 755 /var/uploads/burgerboard/
```

## API Endpoints

### Gallery
- `GET /` — Homepage with all approved burgers (paginated)
- `GET /?page=2` — View burgers by page number

### Upload
- `GET /upload.php` — Upload form
- `POST /upload.php` — Submit a burger image

### Images
- `GET /image.php?id=<uuid>` — View a specific burger image

### Info Pages
- `GET /about.php` — About page and privacy policy
- `GET /sitemap.php` — XML sitemap
- `GET /robots.txt` — Search engine directives

## Security Features

- **CSRF tokens** — All forms protected with unique session tokens
- **Rate limiting** — IP-based upload throttling via hashed IPs
- **File validation** — Server-side MIME type verification with `finfo`
- **UUID filenames** — Images stored with cryptographically random names
- **SQL injection prevention** — All database queries use prepared statements
- **XSS protection** — Output HTML-escaped with the `h()` helper
- **Spam prevention** — Honeypot form field for bot detection
- **EXIF stripping** — All metadata removed before storage
- **Image format enforcement** — All images re-encoded to JPEG

## Privacy Policy

As stated in `/about.php`, BurgerBoard:
- Collects no personal information
- Stores no IP addresses (only hashed tokens)
- Sends no tracking data to third parties
- Does not use cookies for tracking
- Automatically removes EXIF data from all images
- Encrypts all traffic via HTTPS
- Reviews submissions manually before publication
- Does not retain rejected photos

## Development

### Adding Features
1. All new endpoints should start with `declare(strict_types=1);`
2. Use prepared statements for all database queries
3. Escape all user-controlled output with `h()`
4. Include CSRF protection on all forms
5. Add rate limiting where appropriate

### Testing
- Test image uploads with various formats (JPEG, PNG, WebP, HEIC, GIF, BMP, TIFF)
- Verify EXIF data is stripped from uploaded images
- Test rate limiting by exceeding upload limits
- Verify CSRF protection blocks unauthorized submissions
- Check that rejected images are not stored permanently

## Troubleshooting

### Images not uploading
- Check file permissions on `/var/uploads/burgerboard/`
- Verify GD and ImageMagick are installed: `php -i | grep -i imagick`
- Check PHP `upload_max_filesize` and `post_max_size` settings

### SMS notifications not working
- Verify 46elks credentials are correct
- Ensure `SMS_ENABLED` is set to `true`
- Test curl connectivity: `curl -u username:password https://api.46elks.com/a1/sms`

### Rate limiting too strict
- Adjust `UPLOAD_LIMIT` and `UPLOAD_WINDOW` in `config.php`
- Check that `IP_SALT` is set and consistent

### Images appearing rotated
- Verify ImageMagick is installed for formats requiring it
- Check that EXIF auto-orientation is working: test with a rotated HEIC image

## License

This project is open source. Please specify your license here.

## Support

Have questions or found a bug? Reach out to **hello@burgerboard.net**

---

Made with 🍔 for burger lovers everywhere.
