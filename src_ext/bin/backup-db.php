<?php

declare(strict_types=1);

/**
 * Writes a consistent snapshot of the Baïkal database into a target directory.
 *
 *   php backup-db.php /path/to/staging
 *
 * Backend-aware:
 *   - sqlite : uses "VACUUM INTO" for a consistent online snapshot (db.sqlite)
 *   - mysql  : uses mysqldump if available (dump.sql)
 *   - pgsql  : uses pg_dump if available (dump.sql)
 *
 * Exit codes: 0 success, non-zero on failure (the wrapper logs accordingly).
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$target = $argv[1] ?? '';
if ($target === '' || !is_dir($target)) {
    fwrite(STDERR, "Usage: backup-db.php <target-dir>\n");
    exit(2);
}
$target = rtrim($target, '/');

$baikalHome = getenv('BAIKAL_HOME') ?: '/var/www/baikal';
require $baikalHome . '/vendor/autoload.php';
require __DIR__ . '/../bootstrap.php';

use BaikalExt\Config;

try {
    $config = new Config(getenv('BAIKAL_PATH_CONFIG') ?: null);
    $backend = $config->databaseBackend();
    $db = $config->rawDatabase();

    switch ($backend) {
        case 'sqlite':
            backupSqlite($db, $target);
            break;
        case 'mysql':
            backupMysql($db, $target);
            break;
        case 'pgsql':
            backupPgsql($db, $target);
            break;
        default:
            fwrite(STDERR, "Unknown backend: {$backend}\n");
            exit(3);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'backup-db: ' . $e->getMessage() . "\n");
    exit(1);
}

exit(0);

function backupSqlite(array $db, string $target): void
{
    $src = $db['sqlite_file'] ?? '';
    if ($src === '' || !file_exists($src)) {
        throw new \RuntimeException("SQLite file not found: {$src}");
    }

    $out = $target . '/db.sqlite';
    @unlink($out); // VACUUM INTO requires the destination not to exist.

    $pdo = new \PDO('sqlite:' . $src);
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA busy_timeout = 15000');
    // Consistent snapshot even while Baïkal is serving requests.
    $pdo->exec('VACUUM INTO ' . $pdo->quote($out));

    echo "sqlite snapshot -> db.sqlite\n";
}

function backupMysql(array $db, string $target): void
{
    $bin = trim((string) shell_exec('command -v mysqldump 2>/dev/null'));
    if ($bin === '') {
        throw new \RuntimeException('mysqldump not installed; cannot back up MySQL. Install default-mysql-client or dump externally.');
    }

    $out = $target . '/dump.sql';
    putenv('MYSQL_PWD=' . ($db['mysql_password'] ?? ''));

    $cmd = sprintf(
        '%s --host=%s --user=%s --single-transaction --quick --routines %s > %s',
        escapeshellarg($bin),
        escapeshellarg($db['mysql_host'] ?? 'localhost'),
        escapeshellarg($db['mysql_username'] ?? ''),
        escapeshellarg($db['mysql_dbname'] ?? ''),
        escapeshellarg($out)
    );

    runDump($cmd, 'mysqldump');
    echo "mysql dump -> dump.sql\n";
}

function backupPgsql(array $db, string $target): void
{
    $bin = trim((string) shell_exec('command -v pg_dump 2>/dev/null'));
    if ($bin === '') {
        throw new \RuntimeException('pg_dump not installed; cannot back up PostgreSQL. Install postgresql-client or dump externally.');
    }

    $out = $target . '/dump.sql';
    putenv('PGPASSWORD=' . ($db['pgsql_password'] ?? ''));

    $cmd = sprintf(
        '%s --host=%s --username=%s --no-owner %s > %s',
        escapeshellarg($bin),
        escapeshellarg($db['pgsql_host'] ?? 'localhost'),
        escapeshellarg($db['pgsql_username'] ?? ''),
        escapeshellarg($db['pgsql_dbname'] ?? ''),
        escapeshellarg($out)
    );

    runDump($cmd, 'pg_dump');
    echo "pgsql dump -> dump.sql\n";
}

function runDump(string $cmd, string $tool): void
{
    $rc = 0;
    $output = [];
    exec($cmd . ' 2>&1', $output, $rc);
    if ($rc !== 0) {
        throw new \RuntimeException($tool . ' failed: ' . implode(' ', $output));
    }
}
