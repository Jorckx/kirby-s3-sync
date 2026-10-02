<?php

namespace Joredierckx\KirbyS3Sync;

use Kirby\Cms\Pages;
use Kirby\CLI\CLI;

/**
 * Shared plumbing for the s3:* commands: admin check, config check,
 * page scope, Janitor response and the "should we prompt?" decision.
 */
class CommandContext
{
    public function __construct(
        public readonly CLI $cli,
        public readonly Pages $pages,
        public readonly string $scope,
        public readonly bool $fromPanel = false,
    ) {}

    /**
     * Runs the guards and resolves the scope.
     * Returns null after sending an error response, so the command should just `return`.
     */
    public static function boot(CLI $cli, bool $requireActive, array $requiredOptions): ?self
    {
        $kirby = $cli->kirby();

        // From the Panel only admins; in the terminal there is no user
        $user = $kirby->user();
        if ($user && !$user->isAdmin()) {
            static::respond($cli, 403, 'Only admins can run this command');
            return null;
        }

        $fromPanel = (bool)$user;

        // Terminal: no user, so content updates would be refused
        if (!$user) {
            $kirby->impersonate('kirby');
        }

        if ($requireActive && !option('s3.active')) {
            static::respond($cli, 400, 's3.active is off');
            return null;
        }

        foreach ($requiredOptions as $key) {
            if (empty(option($key))) {
                static::respond($cli, 400, "Missing required config: {$key}");
                return null;
            }
        }

        // Button on a page blueprint (or --page) → only that page, otherwise the whole site
        if ($id = $cli->arg('page')) {
            if (!$page = $kirby->page($id)) {
                static::respond($cli, 404, "Page not found: {$id}");
                return null;
            }
            return new static($cli, new Pages([$page]), "single page → {$page->id()}", $fromPanel);
        }

        return new static($cli, $kirby->site()->index(), 'ALL pages', $fromPanel);
    }

    /** CLI output plus the Janitor response (no-op in the terminal) */
    public static function respond(CLI $cli, int $status, string $message, array $lines = []): void
    {
        $status === 200 ? $cli->success($message) : $cli->error($message);

        if (function_exists('janitor')) {
            janitor()->data($cli->arg('command'), [
                'status'  => $status,
                'message' => $message,
                'log'     => implode("\n", $lines),
            ]);
        }
    }

    public function done(array $result, array $overview = []): void
    {
        static::respond(
            $this->cli,
            $result['errors'] ? 500 : 200,
            $result['summary'],
            array_merge($overview, $result['lines'])
        );
    }

    /** Prints the overview lines (and returns them so they can go into the Janitor log) */
    public function show(array $lines): array
    {
        foreach ($lines as $line) {
            $this->cli->out($line);
        }
        return $lines;
    }

    /** Only prompt in a real terminal, never from the Panel (no TTY, it would hang) */
    public function confirmed(string $question = 'Proceed?'): bool
    {
        $interactive = !$this->fromPanel
            && !$this->cli->arg('yes')
            && function_exists('posix_isatty')
            && posix_isatty(STDIN);

        if (!$interactive) {
            return true;
        }

        if (!$this->cli->confirm($question)->confirmed()) {
            $this->cli->out('Aborted.');
            return false;
        }

        $this->cli->out('');
        return true;
    }
}
