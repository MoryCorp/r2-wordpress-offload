=== Numos R2 Media Offload ===
Contributors: numos
Tags: cloudflare, r2, media, offload, storage, cdn
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Offload WordPress media to Cloudflare R2 with zero external dependencies.

== Description ==

A lightweight, self-contained WordPress plugin that offloads media files to
Cloudflare R2 storage and rewrites URLs to serve them from your R2 bucket.

No AWS SDK, no Composer, no bloat. Pure PHP + cURL with native AWS4-HMAC-SHA256
request signing.

**Features:**

* Zero external dependencies (no SDK, no Composer)
* Automatic upload of new media to R2
* Batch migration of existing media with parallel uploads (curl_multi)
* Intelligent URL rewriting: attachment URLs, srcset, post content, ACF fields,
  Elementor data attributes, and full HTML output buffer
* Per-site prefix for multi-site or multi-tenant buckets
* Integrity verification (MD5/ETag) after each upload
* Retry logic with exponential backoff
* Local file cleanup after R2 verification (optional)
* Restore from R2 to local filesystem
* Database reconciliation (sync-from-r2)
* Orphan file detection (R2 and database)
* WP-CLI commands for all operations
* Admin dashboard with real-time progress, logs, and storage savings
* Media Library badges (list + grid view)
* CORS auto-configuration
* Secure logging (.log.php with PHP exit guard)

**Supported content:**

* Images: jpg, png, gif, webp, avif, bmp, tiff, heic
* Video: mp4, webm, mov, avi, mkv, wmv
* Audio: mp3, wav, m4a, flac, aac, wma
* Documents: pdf, doc/docx, xls/xlsx, ppt/pptx, csv, txt, zip

**Blocked for security:** svg, html, js, php, exe, sh, bat

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`
2. Add the following constants to your `wp-config.php`:

```php
define('NUMOS_R2_ACCOUNT_ID', 'your-cloudflare-account-id');
define('NUMOS_R2_ACCESS_KEY', 'your-r2-access-key');
define('NUMOS_R2_SECRET_KEY', 'your-r2-secret-key');
define('NUMOS_R2_BUCKET',     'your-bucket-name');
define('NUMOS_R2_PUBLIC_URL',  'https://your-cdn-domain.com');
```

3. Activate the plugin
4. Go to Media > R2 Offload to test connection and start migration

**Optional constants:**

* `NUMOS_R2_PREFIX` - Override the auto-detected site prefix (default: domain
  with dots replaced by dashes, e.g. `example-com`)

== Configuration ==

**Getting R2 Credentials:**

1. Log in to your Cloudflare dashboard
2. Go to R2 > Overview > Manage R2 API Tokens
3. Create a token with "Admin Read & Write" permissions
4. Note the Access Key ID and Secret Access Key

**Setting up a Public URL:**

Option A - R2 Custom Domain:
1. In your R2 bucket settings, add a custom domain
2. Use that domain as `NUMOS_R2_PUBLIC_URL`

Option B - Cloudflare Worker or CDN:
1. Create a Worker that proxies to your R2 bucket
2. Use the Worker URL as `NUMOS_R2_PUBLIC_URL`

== WP-CLI Commands ==

    wp numos-r2 status                  # Show sync status and stats
    wp numos-r2 migrate                 # Migrate all media to R2
    wp numos-r2 migrate --dry-run       # Preview what would be migrated
    wp numos-r2 verify                  # Verify all synced files on R2
    wp numos-r2 verify --fix            # Re-upload missing/broken files
    wp numos-r2 delete-local --yes      # Delete verified local copies
    wp numos-r2 restore --yes           # Restore all files from R2
    wp numos-r2 sync-from-r2            # Reconcile DB with R2 contents
    wp numos-r2 delete-r2 --yes         # Delete all files from R2

== Frequently Asked Questions ==

= Will this delete my local files? =

Not by default. Local file deletion is opt-in via the "Remove Local Files"
setting or the `wp numos-r2 delete-local` command. Each file is verified on R2
(HEAD + size check) before local deletion.

= What happens if R2 is down? =

The plugin checks R2 availability before rewriting URLs. If R2 is unreachable,
URLs fall back to local paths. Health status is cached (60s up, 30s down).

= Does it work with page builders? =

Yes. The output buffer catches all URLs in the final HTML, including Elementor
data-settings attributes, inline CSS, and dynamically loaded content.

= Does it work with ACF? =

Yes. Image, gallery, file, and URL fields are all supported via dedicated
ACF filters.

= Can I use one bucket for multiple sites? =

Yes. Each site gets its own prefix (e.g., `example-com/2024/01/photo.jpg`).
Override with `NUMOS_R2_PREFIX` if needed.

= How does integrity verification work? =

After each upload, the plugin compares the local file's MD5 hash with the R2
ETag. If they don't match, the upload is retried. Multipart uploads (ETag
format `md5-N`) skip the MD5 check as they use a different checksum format.

== Changelog ==

= 1.0.0 =
* Initial public release
* Parallel uploads with curl_multi (configurable concurrency)
* Full URL rewriting: attachment URLs, srcset, content, ACF, output buffer
* JSON-escaped URL rewriting for page builder data attributes
* Per-site R2 prefix (auto-detected from domain)
* WP-CLI commands: migrate, status, verify, delete-local, restore, sync-from-r2, delete-r2
* Admin dashboard: tabbed UI, real-time migration progress, storage savings, logs
* Media Library integration: list view column + grid view badges
* Local file cleanup with per-file R2 verification
* Restore from R2 to local filesystem
* Database orphan detection and cleanup
* R2 bucket orphan detection and cleanup
* CORS auto-configuration
* Secure logging with PHP exit guard
* Retry logic with exponential backoff
* Batch processing with configurable batch size

== Upgrade Notice ==

= 1.0.0 =
Initial release.
