<?php

declare(strict_types=1);

/**
 * Baïkal birthday-sync extension — CLI entrypoint.
 *
 * Scans each user's contacts for birthdays and maintains all-day reminders in
 * their "Important Dates" calendar. Designed to be run periodically (cron) or
 * manually from inside the container.
 *
 * Usage:
 *   birthdays.php [run] [--dry-run] [--user=NAME] [-v|--verbose] [-q|--quiet]
 */

use BaikalExt\Config;
use BaikalExt\Logger;
use BaikalExt\BirthdayService;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$baikalHome = getenv('BAIKAL_HOME') ?: '/var/www/baikal';
$autoload = $baikalHome . '/vendor/autoload.php';
if (!is_readable($autoload)) {
    fwrite(STDERR, "Could not find Baikal's autoloader at {$autoload}.\n");
    fwrite(STDERR, "Set BAIKAL_HOME to the Baikal installation directory.\n");
    exit(1);
}
require $autoload;

require __DIR__ . '/../bootstrap.php';

$options = parseArgs($argv);
if ($options['help']) {
    printUsage();
    exit(0);
}

$logger = new Logger($options['verbose'], $options['quiet']);

try {
    $config = new Config(getenv('BAIKAL_PATH_CONFIG') ?: null);
    $pdo = $config->createPdo();
    $service = new BirthdayService($pdo, $config, $logger, $options['dry-run']);
    $service->run($options['user']);
} catch (\Throwable $e) {
    $logger->error($e->getMessage());
    exit(1);
}

exit(0);

/**
 * @param string[] $argv
 * @return array{help:bool,verbose:bool,quiet:bool,dry-run:bool,user:?string}
 */
function parseArgs(array $argv): array
{
    $opts = [
        'help'    => false,
        'verbose' => false,
        'quiet'   => false,
        'dry-run' => false,
        'user'    => null,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        switch (true) {
            case $arg === 'run':
                break;
            case $arg === '-h' || $arg === '--help':
                $opts['help'] = true;
                break;
            case $arg === '-v' || $arg === '--verbose':
                $opts['verbose'] = true;
                break;
            case $arg === '-q' || $arg === '--quiet':
                $opts['quiet'] = true;
                break;
            case $arg === '--dry-run':
                $opts['dry-run'] = true;
                break;
            case str_starts_with($arg, '--user='):
                $opts['user'] = substr($arg, strlen('--user='));
                break;
            default:
                fwrite(STDERR, "Unknown argument: {$arg}\n");
                printUsage();
                exit(2);
        }
    }

    return $opts;
}

function printUsage(): void
{
    echo <<<TXT
Baikal birthday-sync

Usage:
  baikal-birthdays [run] [options]

Options:
  --dry-run        Show what would change without writing to the database
  --user=NAME      Only process the given username
  -v, --verbose    Verbose, per-contact logging
  -q, --quiet      Suppress informational output (errors still shown)
  -h, --help       Show this help

Configuration (environment variables):
  BAIKAL_BIRTHDAY_CALENDAR          Destination calendar name (default: "Important Dates")
  BAIKAL_BIRTHDAY_ADDRESSBOOK       Restrict scan to one address book (default: all)
  BAIKAL_BIRTHDAY_ALARM_TIME        Reminder time HH:MM (default: 08:00)
  BAIKAL_BIRTHDAY_CREATE_CALENDAR   Auto-create the calendar if missing (default: false)
  BAIKAL_PATH_CONFIG                Baikal config dir (default: /var/www/baikal/config)

TXT;
}
