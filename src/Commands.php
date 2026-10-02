<?php

use Joredierckx\KirbyS3Sync\Env;
use Joredierckx\KirbyS3Sync\Migrator;
use Joredierckx\KirbyS3Sync\Uploader;
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
            $kirby = $cli->kirby();

            // Response for the Janitor button (no-op in the terminal)
            $respond = function (int $status, string $message, array $lines = []) use ($cli) {
                $status === 200 ? $cli->success($message) : $cli->error($message);
                if (function_exists('janitor')) {
                    janitor()->data($cli->arg('command'), [
                        'status'  => $status,
                        'message' => $message,
                        'log'     => implode("\n", $lines),
                    ]);
                }
            };

            // From the Panel only admins; in the terminal there is no user
            $user = $kirby->user();
            if ($user && !$user->isAdmin()) {
                $respond(403, 'Only admins can run the S3 migration');
                return;
            }

            if (!option('s3.active')) {
                $respond(400, 's3.active is off');
                return;
            }

            foreach (['s3.bucket', 's3.region', 's3.endpoint', 's3.sitename'] as $key) {
                if (empty(option($key))) {
                    $respond(400, "Missing required config: {$key}");
                    return;
                }
            }

            // Scope
            $id = $cli->arg('page');
            if ($id) {
                if (!$page = $kirby->page($id)) {
                    $respond(404, "Page not found: {$id}");
                    return;
                }
                $pages = new Pages([$page]);
                $scope = "single page → {$page->id()}";
            } else {
                $pages = $kirby->site()->index();
                $scope = 'ALL pages';
            }

            $forcedDryRun = Env::bypass();
            $dryRun       = (bool)$cli->arg('dry-run') || $forcedDryRun;

            // Overview (also ends up in the Janitor log)
            $fileCount = Migrator::countFiles($pages);

            // Real example key, built by the same code that does the upload
            $example = null;
            foreach ($pages as $p) {
                if ($f = $p->files()->first()) {
                    $example = $f;
                    break;
                }
            }

            $overview = [
                '--- About to migrate ---',
                "Scope:  {$scope}",
                'Pages:  ' . $pages->count(),
                "Files:  {$fileCount}",
                'Mode:   ' . ($dryRun
                    ? 'DRY RUN (no changes)' . ($forcedDryRun && !$cli->arg('dry-run') ? ' — forced on localhost' : '')
                    : 'LIVE (will upload/move/delete in S3)'),
                'Bucket: ' . option('s3.bucket'),
                'Example key: ' . ($example ? Uploader::key($example) : '(no files)'),
                '',
            ];
            foreach ($overview as $line) {
                $cli->out($line);
            }

            // Confirm only in a real terminal; never from the Panel (no TTY, it would hang)
            // Janitor is safe. Panel runs always have a $user, so they never prompt.
            // Because the overview is added to the log, the button still shows the same context.
            $interactive = !$user
                && !$cli->arg('yes')
                && function_exists('posix_isatty')
                && posix_isatty(STDIN);

            if ($interactive && !$dryRun) {
                if (!$cli->confirm('Proceed?')->confirmed()) {
                    $cli->out('Aborted.');
                    return;
                }
                $cli->out('');
            }

            $result = Migrator::run($pages, $dryRun, fn (string $line) => $cli->out($line));

            $respond(
                $result['errors'] ? 500 : 200,
                $result['summary'],
                array_merge($overview, $result['lines'])
            );
        },
    ],
];
