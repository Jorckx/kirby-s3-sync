<?php

use Joredierckx\KirbyS3Sync\Env;
use Joredierckx\KirbyS3Sync\Migrator;
use Joredierckx\KirbyS3Sync\Restorer;
use Joredierckx\KirbyS3Sync\Uploader;
use Joredierckx\KirbyS3Sync\CommandContext;
use Kirby\CLI\CLI;
use Kirby\Cms\Pages;

// Kirby CLI commands: `vendor/bin/kirby s3:migrate --dry-run`
// kirby CLI on specific page: `vendor/bin/kirby s3:migrate --dry-run --page=<page-id>`
// or from the Panel with a Janitor button (`type: janitor`, `command: 's3:migrate'`)
return [
    's3:migrate' => [
        'description' => 'Upload/move existing files to S3 (run with --dry-run first)',
        'args' => [
            'dry-run' => [
                'longPrefix'  => 'dry-run',
                'description' => 'Only report what would happen',
                'noValue'     => true,
            ],
        ] + (class_exists(\Bnomei\Janitor::class) ? \Bnomei\Janitor::ARGS : []),
        'command' => static function (CLI $cli): void {
            $ctx = CommandContext::boot($cli, requireActive: true, requiredOptions: [
                's3.bucket', 's3.region', 's3.endpoint', 's3.sitename',
            ]);
            if (!$ctx) return;

            $forcedDryRun = Env::bypass();
            $dryRun       = (bool)$cli->arg('dry-run') || $forcedDryRun;

            $example = null;
            foreach ($ctx->pages as $p) {
                if ($f = $p->files()->first()) { $example = $f; break; }
            }

            $overview = $ctx->show([
                '--- About to migrate ---',
                "Scope:  {$ctx->scope}",
                'Pages:  ' . $ctx->pages->count(),
                'Files:  ' . Migrator::countFiles($ctx->pages),
                'Mode:   ' . ($dryRun
                    ? 'DRY RUN (no changes)' . ($forcedDryRun && !$cli->arg('dry-run') ? ' — forced on localhost' : '')
                    : 'LIVE (will upload/move/delete in S3)'),
                'Bucket: ' . option('s3.bucket'),
                'Example key: ' . ($example ? Uploader::key($example) : '(no files)'),
                '',
            ]);

            if (!$dryRun && !$ctx->confirmed()) return;

            $result = Migrator::run($ctx->pages, $dryRun, fn (string $l) => $cli->out($l));
            $ctx->done($result, $overview);
        },
    ],
    's3:restore' => [
        'description' => 'Download files from S3 back into Kirby (run with --dry-run first)',
        'args' => [
            'dry-run' => [
                'longPrefix'  => 'dry-run',
                'description' => 'Only report what would happen',
                'noValue'     => true,
            ],
            'delete-remote' => [
                'longPrefix'  => 'delete-remote',
                'description' => 'Delete the S3 object after a verified restore',
                'noValue'     => true,
            ],
            'yes' => [
                'prefix'      => 'y',
                'longPrefix'  => 'yes',
                'description' => 'Skip the confirmation prompt',
                'noValue'     => true,
            ],
        ] + (class_exists(\Bnomei\Janitor::class) ? \Bnomei\Janitor::ARGS : []),
        'command' => static function (CLI $cli): void {
            // no s3.active requirement, and sitename isn't needed to download
            $ctx = CommandContext::boot($cli, requireActive: false, requiredOptions: [
                's3.bucket', 's3.region', 's3.endpoint',
            ]);
            if (!$ctx) return;

            $dryRun       = (bool)$cli->arg('dry-run');
            $deleteRemote = (bool)$cli->arg('delete-remote');

            $overview = $ctx->show([
                '--- About to restore from S3 ---',
                "Scope:  {$ctx->scope}",
                'Pages:  ' . $ctx->pages->count(),
                'Files:  ' . Migrator::countFiles($ctx->pages),
                'Mode:   ' . ($dryRun ? 'DRY RUN (no changes)' : 'LIVE (will download and overwrite placeholders)'),
                'Remote: ' . ($deleteRemote ? 'objects will be DELETED from S3' : 'objects stay on S3'),
                '',
            ]);

            if (!$dryRun && !$ctx->confirmed()) return;

            $result = Restorer::run($ctx->pages, $dryRun, $deleteRemote, fn (string $l) => $cli->out($l));
            $ctx->done($result, $overview);
        },
    ],
];
