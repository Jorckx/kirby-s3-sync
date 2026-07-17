# Kirby S3 Sync

Sync Kirby CMS files to Cloudflare R2 (or any S3-compatible storage) automatically, offloading local disk usage and serving assets through a CDN.

Files uploaded through the Panel are pushed to your bucket, verified, and then replaced locally with a tiny placeholder — so your Kirby installation stays lightweight while the real files live on R2. `file::url`, `file::version`, and `file::dimensions` are transparently rerouted to the CDN, so templates and the Panel keep working without any changes to your existing code.

Built and maintained by [Studio Dier](https://studiodier.com).

---

## Features

- Automatic upload on file create/replace via Kirby hooks
- Local file is only swapped for a placeholder **after** the upload is verified — nothing is lost on a failed upload
- CDN-aware `file::url`, `file::version`, and `file::dimensions` components — templates don't need to change
- Image dimensions read locally via `getimagesize()`, so width/height are always correct regardless of CDN processing delays
- Optional Cloudflare Images JSON metadata stored alongside each file
- Soft-delete: files are archived (`_archive/...`) in the bucket before deletion, not destroyed outright
- REST API route for uploading from custom Panel UI or external tools
- Standalone CLI scripts for bulk migration of existing files and for backfilling metadata after upgrades
- Works with any S3-compatible provider (R2, DigitalOcean Spaces, etc.) — CDN-specific metadata is skipped automatically if `s3.cdn` isn't configured

---

## Requirements

- Kirby 3.6+ (for automatic Composer autoloading of plugins)
- PHP 8.1+
- `aws/aws-sdk-php` and `vlucas/phpdotenv`, available via your site's root `composer.json`
- An S3-compatible bucket (Cloudflare R2, DigitalOcean Spaces, AWS S3, etc.)

---

## Installation

Place the plugin in `site/plugins/kirby-s3-sync`, either as a git submodule, a manual copy, or via Composer if you publish it to Packagist:

```
site/plugins/kirby-s3-sync/
```

Kirby automatically autoloads the plugin's `composer.json` PSR-4 mapping — no separate `composer install` step is needed for the plugin itself, as long as your site's root Composer setup already includes `aws/aws-sdk-php`.

---

## Configuration

Add the following to your site's `config.php`. Config options can be nested under an `s3` key:

```php
return [
    's3' => [
        'active'   => true,                                    // recommended: true in production only, false in local/staging
        'key'      => env('S3_KEY'),                          // never hardcode — see .env below
        'secret'   => env('S3_SECRET'),                        // never hardcode — see .env below
        'bucket'   => 'your-bucket-name',
        'region'   => 'auto',                                  // R2 always uses 'auto'
        'endpoint' => 'https://<account-id>.r2.cloudflarestorage.com',
        'sitename' => 'your-site-slug',                        // must be unique per site sharing a bucket — avoid '.' since it's used as a subdomain
        'cdn'      => 'https://cdn.yourdomain.com',             // optional — omit for non-Cloudflare providers
    ],
];
```

`key` and `secret` are your R2/S3 access credentials. **These must never be hardcoded in `config.php` or committed to version control.** Store the actual values in your site's `.env` file instead (which should be gitignored), and reference them via `env()` as shown above:

```
S3_KEY=your-access-key-id
S3_SECRET=your-secret-access-key
```

Kirby supports environment-specific config files (e.g. `config/config.local.php`), so `s3.active` can be set per environment — leaving it `false` on local/staging keeps development files on local disk (simpler to inspect and reset) and reserves bucket writes for production only.

### Option reference

| Option        | Required | Description |
|---------------|----------|--------------|
| `s3.active`   | Yes      | Master switch. When `false`, all hooks and API routes no-op and local files behave exactly as vanilla Kirby. Recommended: `true` only in production — keep it `false` on local/staging environments so development work doesn't write to your live bucket. |
| `s3.key`      | Yes      | Access key ID. Set via `env('S3_KEY')`, with the real value stored in `.env` — never hardcode this. |
| `s3.secret`   | Yes      | Secret access key. Set via `env('S3_SECRET')`, with the real value stored in `.env` — never hardcode this. |
| `s3.bucket`   | Yes      | Name of your S3-compatible bucket. |
| `s3.region`   | Yes      | Bucket region. Use `auto` for R2. |
| `s3.endpoint` | Yes      | S3-compatible API endpoint URL. |
| `s3.sitename` | Yes      | Namespace prefix for object keys (`sitename/page-id/assets/...`). Must be unique per site if multiple sites share one bucket, or files can collide. Avoid `.` in the value since it's used as a subdomain. |
| `s3.cdn`      | No       | Public CDN base URL. When set, enables Cloudflare Image Resizing URLs and JSON metadata fetching. Leave unset for providers without an equivalent (e.g. plain DigitalOcean Spaces). |

---

## How it works

### On file create / replace

1. File is uploaded to the bucket via `putObject`.
2. Upload is verified with a `doesObjectExist` check.
3. Image dimensions are read locally with `getimagesize()` — this doesn't depend on the CDN having finished processing the file yet.
4. If `s3.cdn` is configured, Cloudflare's image-info JSON endpoint is fetched as supplementary metadata (file size, format, etc.). This step is skipped entirely on non-Cloudflare providers.
5. `s3_key`, `s3_json`, `s3_width`, and `s3_height` are saved to the file's content file.
6. **Only after all of the above succeeds**, the local file is replaced with a 1×1px placeholder.

If any step fails, the error is logged and the local file is left completely untouched — nothing is deleted or replaced on a failed upload.

### On file delete

The object is copied to an `_archive/` prefix in the same bucket before the original key is deleted — a soft-delete safety net. You can periodically clear out `_archive/` manually once you're confident nothing needs recovering.

### Serving files

Three of Kirby's core components are overridden:

- **`file::url`** — returns the CDN/bucket URL when `s3_key` is set, otherwise falls back to Kirby's native local URL.
- **`file::dimensions`** — returns the stored `s3_width`/`s3_height` when available, avoiding a filesystem read on a file that's now just a placeholder.
- **`file::version`** — builds a Cloudflare Image Resizing URL (`/cdn-cgi/image/...`) from `width`, `height`, `quality`, `fit`, and `crop` options passed to Kirby's usual `$file->resize()` / `$file->crop()` calls.

Existing templates using `$file->url()`, `$file->resize()`, `$file->crop()`, etc. don't need any changes.

---

## Blueprint fields

The plugin ships a reusable field group (`blueprints/fields/s3meta.yml`) that can be included in any file blueprint where you want these fields visible in the Panel — all are managed automatically and don't need to be editable:

```yaml
fields:
  s3meta:
    extends: fields/s3meta
```

The fields in the group are:
- `s3_key` — the S3 key of the file (read-only).
- `s3_width` — the width of the file (read-only).
- `s3_height` — the height of the file (read-only).

`s3_json` is intentionally left out of the group by default since it's raw metadata, not something editors need to see — but it's stored in the content file and can be added to the group the same way if useful for debugging.

---

## REST API

A route is registered for programmatic uploads (e.g. from a custom Panel UI):

```
POST /api/s3-upload/{pageId}/{filename}
```

`{pageId}` should have any `/` characters replaced with `+`. Returns `{"status": "ok"}` on success, or `{"status": "error", "message": "..."}` on failure. Respects `s3.active` — returns an error if sync is disabled.

---

## CLI scripts

Two standalone scripts are included in `scripts/` for one-off and maintenance work outside the normal Panel flow. Both support `--dry-run` and prompt for confirmation before making any changes.

### `migrate-to-s3.php`

Bulk-migrates existing local files to your bucket — intended for the initial move to R2, or for re-syncing after restoring a backup.

```bash
php scripts/migrate-to-s3.php --dry-run
php scripts/migrate-to-s3.php
```

- Prompts to migrate all pages or a single page by ID
- Shows a scope summary (page/file counts, expected key structure) before asking for final confirmation
- Skips files already correctly placed; moves files whose expected key has changed; uploads new files
- Safe to re-run — already-migrated files are detected and skipped, failed files are retried automatically on the next run

### `update-s3-meta.php`

Backfills `s3_width` / `s3_height` (and `s3_json`) on files that were migrated before those fields existed, or after a plugin upgrade that adds new metadata fields.

```bash
php scripts/update-s3-meta.php --dry-run
php scripts/update-s3-meta.php
```

- Reuses a file's already-stored `s3_json` when present, only hitting the CDN if it's missing
- Skips files that already have both `s3_width` and `s3_height` populated
- Skips non-image files and files not yet on the bucket

---

## Provider compatibility

This plugin works with any S3-compatible endpoint. Cloudflare-specific behavior (the JSON metadata fetch and Image Resizing URLs in `file::version`) is only active when `s3.cdn` is set — leave it unset when using a provider without an equivalent feature, and the plugin falls back to plain bucket URLs and locally-read dimensions.

---

## Safety notes

- Nothing is ever deleted locally until the corresponding upload is verified in the bucket.
- Nothing is ever deleted from the bucket without first being archived under `_archive/`.
- All hooks and the API route respect `s3.active` — set it to `false` to fully disable the plugin's behavior without uninstalling it.

---

## License

MIT © [Jore Dierckx](https://studiodier.com)
