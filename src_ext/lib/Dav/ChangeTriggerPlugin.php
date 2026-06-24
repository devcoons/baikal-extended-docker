<?php

declare(strict_types=1);

namespace BaikalExt\Dav;

use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;

/**
 * A sabre/dav plugin that turns CardDAV contact changes into lightweight,
 * per-user "dirty" markers, enabling event-driven birthday synchronisation
 * without polling the database.
 *
 * On every contact create / update / delete, it writes (touches) a single small
 * file naming the affected user into a queue directory. A separate watcher
 * process (see bin/birthday-watch) reacts to those files and runs the sync for
 * just that user.
 *
 * Design constraints:
 *  - Must be extremely cheap: it only writes a tiny file, never scans.
 *  - Must never throw: a failure here must not break a DAV operation.
 *  - Reacts only to address-book card paths, so our own calendar writes (done
 *    directly via the backend, outside the Server) never cause a loop.
 */
class ChangeTriggerPlugin extends ServerPlugin
{
    private string $queueDir;

    public function __construct(?string $queueDir = null)
    {
        $this->queueDir = $queueDir ?? self::defaultQueueDir();
    }

    public static function defaultQueueDir(): string
    {
        $explicit = getenv('BAIKAL_EXT_QUEUE');
        if ($explicit !== false && $explicit !== '') {
            return rtrim($explicit, '/');
        }

        $specific = getenv('BAIKAL_PATH_SPECIFIC');
        if ($specific === false || $specific === '') {
            $specific = '/var/www/baikal/Specific';
        }

        return rtrim($specific, '/') . '/birthday-queue';
    }

    public function initialize(Server $server): void
    {
        $server->on('afterCreateFile', function ($path, $parent): void {
            $this->onContactChange($path);
        });
        $server->on('afterWriteContent', function ($path, $node): void {
            $this->onContactChange($path);
        });
        $server->on('afterUnbind', function ($path): void {
            $this->onContactChange($path);
        });
    }

    public function getPluginName(): string
    {
        return 'baikal-ext-change-trigger';
    }

    /**
     * Records that the user owning the changed address-book path needs a resync.
     */
    private function onContactChange(string $path): void
    {
        try {
            $user = self::userFromPath($path);
            if ($user === null) {
                return;
            }

            if (!is_dir($this->queueDir)) {
                // Best effort; the entrypoint normally pre-creates this.
                @mkdir($this->queueDir, 0o775, true);
            }

            // Filename is a hash (filesystem-safe); content is the real username,
            // so multiple changes for one user coalesce into a single marker.
            $flag = $this->queueDir . '/' . sha1($user);
            @file_put_contents($flag, $user, LOCK_EX);
        } catch (\Throwable) {
            // Never let notification bookkeeping break a DAV request.
        }
    }

    /**
     * Extracts the principal/user name from an address-book card path such as
     * "addressbooks/alice/contacts/bob.vcf". Returns null for non-card paths.
     */
    public static function userFromPath(string $path): ?string
    {
        $path = ltrim($path, '/');
        // addressbooks/<user>/<book>/<card>
        if (preg_match('#^addressbooks/([^/]+)/[^/]+/[^/]+#', $path, $m) !== 1) {
            return null;
        }

        $user = $m[1];

        return $user !== '' ? $user : null;
    }
}
