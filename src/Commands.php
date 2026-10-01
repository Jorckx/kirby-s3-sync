<?php

use Joredierckx\KirbyS3Sync\Env;
use Joredierckx\KirbyS3Sync\Migrator;
use Kirby\CLI\CLI;
use Kirby\Cms\Pages;

// Kirby CLI commands: `vendor/bin/kirby s3:migrate --dry-run`
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

            // Button on a page blueprint → only that page, otherwise the whole site
            if ($id = $cli->arg('page')) {
                if (!$page = $kirby->page($id)) {
                    $respond(404, "Page not found: {$id}");
                    return;
                }
                $pages = new Pages([$page]);
            } else {
                $pages = $kirby->site()->index();
            }

            // Same rule as the hooks: localhost only does a dry run unless s3.localhost is on
            $dryRun = (bool)$cli->arg('dry-run') || Env::bypass();

            $result = Migrator::run($pages, $dryRun, fn (string $line) => $cli->out($line));

            $respond(
                $result['errors'] ? 500 : 200,
                $result['summary'],
                $result['lines']
            );
        },
    ],
];
