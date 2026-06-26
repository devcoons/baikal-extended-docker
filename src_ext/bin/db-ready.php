<?php

declare(strict_types=1);

/**
 * Database readiness probe for the entrypoint's first-run sync.
 *
 * Exits 0 only when Baïkal's database is both reachable AND installed:
 *   - baikal.yaml exists and has a database section (Config),
 *   - a PDO connection can be opened (SQLite file present / remote DB reachable),
 *   - the schema exists (the `principals` table can be queried).
 *
 * Any other state (not configured yet, DB still starting, installer not run)
 * exits non-zero so the caller keeps waiting. Output is intentionally quiet.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$baikalHome = getenv('BAIKAL_HOME') ?: '/var/www/baikal';
$autoload = $baikalHome . '/vendor/autoload.php';
if (!is_readable($autoload)) {
    exit(1);
}
require $autoload;
require __DIR__ . '/../bootstrap.php';

use BaikalExt\Config;

try {
    $config = new Config(getenv('BAIKAL_PATH_CONFIG') ?: null);
    $pdo = $config->createPdo();
    // Confirms the schema has been created (Baïkal installer completed).
    $pdo->query('SELECT 1 FROM principals LIMIT 1');
} catch (\Throwable $e) {
    exit(1);
}

exit(0);
