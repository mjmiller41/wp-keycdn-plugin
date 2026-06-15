# WP KeyCDN Media Offload

A WordPress plugin that offloads media to a KeyCDN Push Zone via FTPS with async background processing, soft-delete quarantine, manifest-based integrity verification, and WooCommerce compatibility.

---

## Table of Contents

1. [Prerequisites](#1-prerequisites)
2. [Install Action Scheduler](#2-install-action-scheduler)
3. [Install the Plugin](#3-install-the-plugin)
4. [Configure Credentials](#4-configure-credentials)
5. [Activate and Configure](#5-activate-and-configure)
6. [Test — FTP Connection](#6-test--ftp-connection)
7. [Test — Single Upload](#7-test--single-upload)
8. [Test — Deletion](#8-test--deletion)
9. [Test — Bulk Offload](#9-test--bulk-offload)
10. [Enable Local File Removal](#10-enable-local-file-removal-optional)
11. [Verify the Reconciliation Job](#11-verify-the-reconciliation-job)
12. [WP-CLI Reference](#12-wp-cli-reference)
13. [Common Problems & Fixes](#13-common-problems--fixes)

---

## 1. Prerequisites

### 1.1 Verify PHP extensions

SSH into your web server and run:

```bash
php -m | grep -E "^ftp$|^openssl$|^intl$"
```

All three must appear:

```
ftp
intl
openssl
```

If any are missing, install them. On Ubuntu/Debian:

```bash
sudo apt install php8.x-ftp php8.x-intl php8.x-openssl
sudo systemctl restart php8.x-fpm   # or apache2, depending on your stack
```

On a Docker-based local environment, rebuild the PHP image with:

```dockerfile
RUN docker-php-ext-install ftp
RUN apt-get install -y libicu-dev && docker-php-ext-install intl
```

Confirm FTP has SSL support (required for FTPS):

```bash
php -r "var_dump(function_exists('ftp_ssl_connect'));"
# Must output: bool(true)
```

### 1.2 WordPress requirements

- WordPress 6.0 or higher
- PHP 7.4 or higher
- **Action Scheduler** must be active before this plugin runs (see Part 2)

### 1.3 KeyCDN account setup

**A. Create a Push Zone:**

1. Log in to [app.keycdn.com](https://app.keycdn.com)
2. Go to **Zones → Add Zone**
3. Set **Zone Type** to **Push**
4. Give it a name (e.g., `mysite-media`)
5. Note the **Zone URL** shown after creation — it will look like `https://mysite-media-xyz.kxcdn.com`

**B. Create a subuser for FTP access:**

1. Go to **Account → Subusers → Add Subuser**
2. Set a username (e.g., `mysite-ftp`) and a strong password
3. Assign it access to your Push Zone only
4. Note the username and password — these become your FTP credentials

**C. Verify you can connect manually:**

```bash
ftp -p ftp.keycdn.com
# Enter the subuser name and password when prompted
# Type: ls
# You should see your zone name as a directory
```

---

## 2. Install Action Scheduler

The plugin depends on Action Scheduler for background processing. You have two options:

**Option A — Install as a standalone plugin (recommended for non-WooCommerce sites):**

1. Download from: `https://wordpress.org/plugins/action-scheduler/`
2. Upload to `wp-content/plugins/action-scheduler/`
3. Activate it in **Plugins → Installed Plugins**

**Option B — WooCommerce (already bundles Action Scheduler):**

If WooCommerce is already active on your site, Action Scheduler is already present — skip this step.

Verify Action Scheduler is active:

```bash
wp eval "echo function_exists('as_enqueue_async_action') ? 'OK' : 'MISSING';"
# Must output: OK
```

---

## 3. Install the Plugin

**Option A — From the cloned repo (production/staging):**

```bash
cd /path/to/wordpress/wp-content/plugins/
git clone https://github.com/mjmiller41/wp-keycdn-plugin.git wp-keycdn-plugin
```

**Option B — Zip and upload via admin UI:**

```bash
cd /path/to/wp-keycdn-plugin
zip -r wp-keycdn-plugin.zip . -x "*.git*"
```

Then in WordPress: **Plugins → Add New → Upload Plugin** → choose the zip file.

---

## 4. Configure Credentials

Credentials are entered in the plugin settings screen after activation (see Part 5, Step 3). The plugin stores the FTP password encrypted in the database using AES-256-CTR.

> **Recommended:** Define a stable encryption key in `wp-config.php` so the stored password survives WordPress salt rotations:
>
> ```php
> // Must never change after first activation
> define( 'KEYCDN_ENCRYPTION_KEY',  'a-long-random-string-change-this' );
> define( 'KEYCDN_ENCRYPTION_SALT', 'another-long-random-string-change-this' );
> ```
>
> Generate the random strings with:
>
> ```bash
> php -r "echo bin2hex(random_bytes(32)) . PHP_EOL; echo bin2hex(random_bytes(32)) . PHP_EOL;"
> ```
>
> Without these constants the plugin falls back to WordPress's built-in salts — rotating those salts will permanently break decryption of your stored password.

**Power users:** If you prefer to keep credentials entirely out of the database, you can define them as constants in `wp-config.php` instead and they will take precedence over anything entered in the UI:
```php
define( 'KEYCDN_ZONE_URL',  'https://mysite-media-xyz.kxcdn.com' );
define( 'KEYCDN_FTP_HOST',  'ftp.keycdn.com' );
define( 'KEYCDN_FTP_USER',  'mysite-ftp' );
define( 'KEYCDN_FTP_PASS',  'your-subuser-password' );
```

---

## 5. Activate and Configure

### Step 1 — Activate the plugin

**Via WP Admin:**

Go to **Plugins → Installed Plugins**, find **WP KeyCDN Media Offload**, click **Activate**.

**Via WP-CLI:**

```bash
wp plugin activate wp-keycdn-plugin
```

Activation creates:

- The `{prefix}cdn_offload_log` database table
- The `wp-content/uploads/_cdn_trash/` quarantine directory with `.htaccess` blocking direct access
- Default option values
- Two recurring Action Scheduler jobs (`keycdn_reconcile_manifest` and `keycdn_purge_trash`)

Verify the table was created:

```bash
wp db query "SHOW TABLES LIKE '%cdn_offload_log';"
# Must return one row
```

Verify the trash directory:

```bash
ls -la /path/to/wordpress/wp-content/uploads/_cdn_trash/
# Must exist and contain .htaccess
```

### Step 2 — Verify Action Scheduler jobs were scheduled

```bash
wp eval "
  echo as_next_scheduled_action('keycdn_reconcile_manifest') ? 'reconcile: OK' : 'reconcile: MISSING';
  echo PHP_EOL;
  echo as_next_scheduled_action('keycdn_purge_trash') ? 'purge_trash: OK' : 'purge_trash: MISSING';
"
```

Both lines must say `OK`. If either says `MISSING`, Action Scheduler was not active when the plugin was activated. Activate Action Scheduler first, then deactivate and reactivate this plugin.

### Step 3 — Enter credentials and configure settings

Go to **KeyCDN Offload → Settings**:

| Field                     | Value                                             |
| ------------------------- | ------------------------------------------------- |
| Zone URL                  | Your `https://yourzone.kxcdn.com` URL             |
| FTP Host                  | `ftp.keycdn.com`                                  |
| FTP Username              | Your subuser name                                 |
| FTP Password              | Your subuser password                             |
| Auto-Offload on Upload    | ✅ Checked                                        |
| Remove Local Files        | ☐ Unchecked (leave off until testing is complete) |
| Quarantine TTL            | `30` days                                         |
| WooCommerce Compatibility | ✅ Checked (if WooCommerce is active)             |

Click **Save Changes**.

### Step 4 — Verify configuration is readable

```bash
wp eval "
  \$enc  = new KeyCDN\Offload\Core\Encryption();
  \$cred = new KeyCDN\Offload\Core\Credentials(\$enc);
  echo 'Zone URL: '   . \$cred->get_zone_url()  . PHP_EOL;
  echo 'FTP User: '   . \$cred->get_ftp_user()  . PHP_EOL;
  echo 'FTP Pass: '   . ('' !== \$cred->get_ftp_pass() ? '[SET]' : '[EMPTY]') . PHP_EOL;
  echo 'Configured: ' . (\$cred->is_configured() ? 'YES' : 'NO') . PHP_EOL;
"
```

The last line must say `Configured: YES`.

---

## 6. Test — FTP Connection

```bash
wp eval "
  \$enc  = new KeyCDN\Offload\Core\Encryption();
  \$cred = new KeyCDN\Offload\Core\Credentials(\$enc);
  \$ftp  = new KeyCDN\Offload\Core\FtpClient(\$cred);
  try {
      \$ftp->connect();
      echo 'FTP connection: SUCCESS' . PHP_EOL;
      \$list = \$ftp->list_dir('/');
      echo 'Zone root listing (' . count(\$list) . ' entries):' . PHP_EOL;
      foreach (array_slice(\$list, 0, 5) as \$e) { echo '  ' . (\$e['name'] ?? '?') . PHP_EOL; }
      \$ftp->disconnect();
  } catch (Exception \$e) {
      echo 'FAILED: ' . \$e->getMessage() . PHP_EOL;
  }
"
```

Expected output:

```
FTP connection: SUCCESS
Zone root listing (N entries):
  your-zone-name/
```

---

## 7. Test — Single Upload

### 7.1 Upload a test image

1. Go to **Media → Add New** in WordPress admin
2. Upload any JPG or PNG image
3. Note the attachment ID from the URL bar (e.g., `post=42`)

### 7.2 Check the Action Scheduler queue

Go to **Tools → Scheduled Actions** (or **WooCommerce → Status → Scheduled Actions** if using WooCommerce). Filter by Group: `keycdn-offload`.

You should see a `keycdn_upload_attachment` job in **Pending** or **Complete** state.

Force it to run immediately:

```bash
wp action-scheduler run --group=keycdn-offload --limit=5
```

### 7.3 Verify the manifest

Replace `42` with your actual attachment ID:

```bash
wp db query "SELECT size_slug, state, remote_path, byte_size FROM $(wp db prefix)cdn_offload_log WHERE attachment_id = 42;"
```

Expected output — one row per image size, all in `confirmed` state:

```
+-----------+-----------+---------------------------+-----------+
| size_slug | state     | remote_path               | byte_size |
+-----------+-----------+---------------------------+-----------+
| full      | confirmed | /2026/06/test-image.jpg   | 204800    |
| thumbnail | confirmed | /2026/06/test-image-150.… | 12345     |
| medium    | confirmed | /2026/06/test-image-300.… | 45678     |
+-----------+-----------+---------------------------+-----------+
```

If any row shows `failed`, check the Action Scheduler log:

```bash
wp db query "
  SELECT l.log_date, l.message
  FROM $(wp db prefix)actionscheduler_logs l
  JOIN $(wp db prefix)actionscheduler_actions a ON a.action_id = l.action_id
  WHERE a.hook = 'keycdn_upload_attachment'
  ORDER BY l.log_date DESC
  LIMIT 10;
"
```

### 7.4 Verify the file is on the CDN

```bash
# Replace with the remote_path value from the manifest query above
curl -I "https://yourzone.kxcdn.com/2026/06/test-image.jpg"
# HTTP/2 200 confirms the file is live on the CDN
```

### 7.5 Verify URL rewriting

```bash
# Replace 42 with your attachment ID
wp eval "echo wp_get_attachment_url(42);"
# Must output the CDN URL:
#   https://yourzone.kxcdn.com/2026/06/test-image.jpg
# NOT the local URL:
#   https://yoursite.com/wp-content/uploads/2026/06/test-image.jpg
```

---

## 8. Test — Deletion

1. In WordPress admin go to **Media Library**, select the test image, click **Delete Permanently**
2. Flush the Action Scheduler queue:
   ```bash
   wp action-scheduler run --group=keycdn-offload --limit=5
   ```
3. Verify the file is removed from the CDN (propagation can take up to 30 minutes):
   ```bash
   curl -I "https://yourzone.kxcdn.com/2026/06/test-image.jpg"
   # Must return 404
   ```
4. Verify the quarantine directory received the local copy:
   ```bash
   ls -lR /path/to/wordpress/wp-content/uploads/_cdn_trash/
   ```

---

## 9. Test — Bulk Offload

### Via Admin UI

1. Go to **KeyCDN Offload → Bulk Offload**
2. Click **Start Bulk Offload**
3. The progress bar updates every 3 seconds
4. When complete, review counts at **KeyCDN Offload → Status Log**

### Via WP-CLI (recommended for large libraries)

```bash
wp keycdn-offload bulk-offload --batch-size=50
```

Monitor progress:

```bash
watch -n 10 'wp keycdn-offload status'
```

---

## 10. Enable Local File Removal (Optional)

Only enable this after confirming uploads and URL rewriting are working correctly in production.

**Via admin UI:** Go to **KeyCDN Offload → Settings** → check **Remove Local Files** → Save.

**Via WP-CLI:**

```bash
wp option update keycdn_offload_remove_local 1
```

After this is enabled, the plugin moves local files to `_cdn_trash/` in the background after each confirmed upload. Files remain in quarantine for 30 days (configurable via **Quarantine TTL**) before permanent deletion, giving you a recovery window if the CDN copy is ever lost.

---

## 11. Verify the Reconciliation Job

The plugin checks CDN integrity every 24 hours automatically. Run it manually to confirm it works:

```bash
wp keycdn-offload reconcile
```

Expected output:

```
Running reconciliation...
Success: Reconciliation complete.
```

Confirm `last_verified_at` timestamps were updated:

```bash
wp db query "SELECT attachment_id, size_slug, state, last_verified_at FROM $(wp db prefix)cdn_offload_log LIMIT 10;"
```

---

## 12. WP-CLI Reference

```bash
# Queue all un-offloaded media for CDN upload
wp keycdn-offload bulk-offload

# Same with a larger batch size
wp keycdn-offload bulk-offload --batch-size=100

# Show per-state file counts from the manifest
wp keycdn-offload status

# Run the CDN integrity reconciliation check immediately
wp keycdn-offload reconcile

# Hard-delete quarantine files that have exceeded the TTL
wp keycdn-offload purge-trash

# Manually flush the Action Scheduler queue
wp action-scheduler run --group=keycdn-offload --limit=25
```

---

## 13. Common Problems & Fixes

| Symptom                                                 | Likely Cause                                              | Fix                                                                                                                                          |
| ------------------------------------------------------- | --------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------- |
| `ftp_ssl_connect` returns false                         | PHP FTP extension missing SSL support                     | Rebuild PHP with `--with-openssl` or install `php-ftp` from the distro repo                                                                  |
| AS jobs stuck in Pending                                | WP-Cron disabled or not firing                            | Add a real server cron: `*/5 * * * * php /path/to/wp-cron.php`                                                                               |
| `Configured: NO`                                        | Credentials not saved or incorrect                        | Re-enter Zone URL, FTP Username, and FTP Password in **KeyCDN Offload → Settings** and save                                                   |
| Remote file size = 0 after upload                       | PASV mode issue or firewall blocking data port range      | Confirm outbound TCP ports 49152–65535 are allowed; plugin always calls `ftp_pasv(true)`                                                     |
| URLs not rewriting to CDN                               | Manifest rows not yet in `confirmed` state                | Run `wp action-scheduler run --group=keycdn-offload` to flush the queue; check manifest states                                               |
| `MISSING` on reconcile / purge-trash jobs at activation | Action Scheduler was not active when plugin was activated | Activate Action Scheduler, then deactivate and reactivate this plugin                                                                        |
| macOS Chrome/Firefox uploads have non-ASCII filenames   | Decomposed Unicode (NFD) not normalized                   | Install `php-intl`; the plugin normalizes to NFC before sanitizing when the extension is present                                             |
| Decryption returns empty string after salt rotation     | Encryption keys derived from WordPress salts that changed | Define `KEYCDN_ENCRYPTION_KEY` and `KEYCDN_ENCRYPTION_SALT` as immutable constants in `wp-config.php`; re-enter the FTP password in Settings |
| Critical error immediately after clicking Activate      | Composer `vendor/` not present and fallback autoloader broken | Run `composer install --no-dev` in the plugin directory, or ensure you are on plugin version ≥ 0.1.1 where the fallback autoloader correctly maps PascalCase class names to kebab-case filenames |

---

## Architecture Overview

```
wp-keycdn-plugin/
├── wp-keycdn-offload.php         # Bootstrap, constants, autoloader (Composer if available, else fallback)
├── includes/
│   ├── class-plugin.php           # Dependency wiring and hook registration
│   ├── class-activator.php        # DB table, trash dir, AS scheduling
│   ├── core/
│   │   ├── class-ftp-client.php       # FTPS connection, put/verify/delete/list
│   │   ├── class-credentials.php      # Constant → encrypted option resolver
│   │   ├── class-encryption.php       # AES-256-CTR (Felix Arntz pattern)
│   │   ├── class-manifest.php         # {prefix}cdn_offload_log CRUD
│   │   ├── class-state-machine.php    # Validates state transitions
│   │   └── class-file-sanitizer.php   # NFC normalize + sanitize_file_name()
│   ├── upload/
│   │   ├── class-upload-manager.php   # Enqueues AS jobs on media upload
│   │   ├── class-upload-job.php       # AS job: upload + verify each size variant
│   │   └── class-bulk-offload.php     # Paginated WP_Query bulk enqueueing
│   ├── rewrite/
│   │   ├── class-url-rewriter.php     # wp_get_attachment_url + srcset filters
│   │   └── class-woo-rewriter.php     # WooCommerce product image URL overrides
│   ├── cleanup/
│   │   ├── class-delete-job.php       # AS job: FTP delete + quarantine on attachment delete
│   │   ├── class-trash-manager.php    # Soft-delete to _cdn_trash/, 30-day TTL
│   │   └── class-reconcile-job.php    # AS recurring: 24h CDN integrity check
│   ├── sync/
│   │   └── class-cdn-scanner.php      # Walk CDN via ftp_mlsd → wp_insert_attachment
│   ├── admin/
│   │   ├── class-admin.php            # Menu registration
│   │   ├── class-settings-page.php    # Settings form + sanitize callbacks
│   │   ├── class-bulk-page.php        # Bulk offload UI
│   │   ├── class-status-page.php      # Manifest state log
│   │   └── class-ajax-handler.php     # Nonce + capability checked AJAX handlers
│   └── cli/
│       └── class-cli-command.php      # WP-CLI subcommands
└── templates/                         # Admin page PHP templates
```

### Key design decisions

- **Action Scheduler** is the job spine — every FTP operation runs asynchronously in a background worker, never blocking a web request. Jobs throw exceptions on failure so AS records them and enables retry.
- **Upload verification** uses `ftp_size()` compared to local `filesize()` before advancing to `confirmed` state. A zero-byte remote file is treated as a failure.
- **Soft-delete quarantine** (`_cdn_trash/`) means local files are never permanently deleted until 30 days after the CDN copy is confirmed — giving a recovery window for CDN failures.
- **Credentials** are entered in the admin settings UI and stored as an AES-256-CTR encrypted value in `wp_options`. Power users can override any credential with a `wp-config.php` constant (`KEYCDN_ZONE_URL`, `KEYCDN_FTP_HOST`, `KEYCDN_FTP_USER`, `KEYCDN_FTP_PASS`) and it will silently take precedence.
- **URL rewriting** checks the manifest at runtime — only confirmed/local_removed rows get CDN URLs, so partially-uploaded attachments always fall back to local URLs.
