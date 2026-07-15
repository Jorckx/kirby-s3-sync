# Script to Migrate existing data to Cloudflare R2

This script migrates existing data from the S3 bucket to Cloudflare R2.

## Prerequisites

- Place the script-file `migrate-to-r2.php` in the folder: `/site/scripts`
- PHP installed on the server
- Access to the S3 bucket and Cloudflare R2 account with appropriate credentials (config.php)
- kirby should be installed with composer, meaning the `composer.json` file should be present in the root directory and the `vendor` directory should be present as well for the script to work.

## Usage


🔍 DRY RUN — nothing will be changed:
```
php site/scripts/migrate-to-r2.php --dry-run
```

Migrate data to R2:
```
php site/scripts/migrate-to-r2.php
```
