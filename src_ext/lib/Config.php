<?php

declare(strict_types=1);

namespace BaikalExt;

use Symfony\Component\Yaml\Yaml;

/**
 * Loads Baikal's own configuration and exposes a PDO connection plus the
 * extension's runtime options.
 *
 * The database connection is built to mirror exactly how Baikal/Flake connects
 * (see Flake\Framework::initDb*), so the extension talks to the same database
 * with the same semantics, regardless of the configured backend.
 */
final class Config
{
    private array $raw;
    private string $configDir;

    public function __construct(?string $configDir = null)
    {
        $this->configDir = rtrim($configDir ?? self::defaultConfigDir(), '/') . '/';

        $file = $this->configDir . 'baikal.yaml';
        if (!is_readable($file)) {
            throw new \RuntimeException("Baikal config not found or unreadable: {$file}");
        }

        $parsed = Yaml::parseFile($file);
        if (!is_array($parsed) || !isset($parsed['database'])) {
            throw new \RuntimeException("Invalid baikal.yaml: missing 'database' section. Has Baikal been installed yet?");
        }

        $this->raw = $parsed;
    }

    private static function defaultConfigDir(): string
    {
        $env = getenv('BAIKAL_PATH_CONFIG');
        if ($env !== false && $env !== '') {
            return $env;
        }

        return '/var/www/baikal/config/';
    }

    /**
     * Builds a PDO connection identical to the one Baikal uses at runtime.
     */
    public function createPdo(): \PDO
    {
        $db = $this->raw['database'];

        $legacyMysql = array_key_exists('mysql', $db) && $db['mysql'] === true;
        $backend = $db['backend'] ?? ($legacyMysql ? 'mysql' : 'sqlite');

        switch ($backend) {
            case 'mysql':
                return $this->createMysqlPdo($db);
            case 'pgsql':
                return $this->createPgsqlPdo($db);
            default:
                return $this->createSqlitePdo($db);
        }
    }

    private function createSqlitePdo(array $db): \PDO
    {
        $path = $db['sqlite_file'] ?? '';
        if ($path === '') {
            throw new \RuntimeException("SQLite backend selected but 'sqlite_file' is empty in baikal.yaml.");
        }
        if (!file_exists($path)) {
            throw new \RuntimeException("SQLite database file does not exist: {$path}");
        }

        $pdo = new \PDO('sqlite:' . $path);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        // Tolerate concurrent access with the live Apache/PHP process.
        $pdo->exec('PRAGMA busy_timeout = 10000');

        return $pdo;
    }

    private function createMysqlPdo(array $db): \PDO
    {
        foreach (['mysql_host', 'mysql_dbname', 'mysql_username'] as $required) {
            if (empty($db[$required])) {
                throw new \RuntimeException("MySQL backend selected but '{$required}' is missing in baikal.yaml.");
            }
        }

        $options = [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION];

        $caCert = $db['mysql_ca_cert'] ?? '';
        if ($caCert !== '') {
            $sslCaAttr = defined('PDO\Mysql::ATTR_SSL_CA')
                ? constant('PDO\Mysql::ATTR_SSL_CA')
                : constant('PDO::MYSQL_ATTR_SSL_CA');
            $options[$sslCaAttr] = $caCert;
        }

        $pdo = new \PDO(
            'mysql:host=' . $db['mysql_host'] . ';dbname=' . $db['mysql_dbname'],
            $db['mysql_username'],
            $db['mysql_password'] ?? '',
            $options
        );
        $pdo->exec('SET NAMES UTF8');

        return $pdo;
    }

    private function createPgsqlPdo(array $db): \PDO
    {
        foreach (['pgsql_host', 'pgsql_dbname'] as $required) {
            if (empty($db[$required])) {
                throw new \RuntimeException("PostgreSQL backend selected but '{$required}' is missing in baikal.yaml.");
            }
        }

        $pdo = new \PDO(
            'pgsql:host=' . $db['pgsql_host'] . ';dbname=' . $db['pgsql_dbname'],
            $db['pgsql_username'] ?? '',
            $db['pgsql_password'] ?? ''
        );
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec("SET NAMES 'UTF8'");

        return $pdo;
    }

    /** Raw database section of baikal.yaml (used by the backup tool). */
    public function rawDatabase(): array
    {
        return $this->raw['database'];
    }

    /** Resolved database backend: 'sqlite', 'mysql' or 'pgsql'. */
    public function databaseBackend(): string
    {
        $db = $this->raw['database'];
        $legacyMysql = array_key_exists('mysql', $db) && $db['mysql'] === true;

        return $db['backend'] ?? ($legacyMysql ? 'mysql' : 'sqlite');
    }

    /** Display name of the destination calendar that triggers processing. */
    public function calendarName(): string
    {
        return self::envString('BAIKAL_BIRTHDAY_CALENDAR', 'Important Dates');
    }

    /** Title template for birthday events. Tokens: {name}, {age}. */
    public function birthdayTitleTemplate(): string
    {
        return self::envString('BAIKAL_BIRTHDAY_TITLE_TEMPLATE', "{name}'s Birthday");
    }

    /** Append the age (when the birth year is known) to birthday titles. */
    public function birthdayShowAge(): bool
    {
        return self::envBool('BAIKAL_BIRTHDAY_SHOW_AGE', true);
    }

    /** Whether to also sync anniversaries (vCard ANNIVERSARY / X-ANNIVERSARY). */
    public function anniversaryEnabled(): bool
    {
        return self::envBool('BAIKAL_ANNIVERSARY_ENABLED', true);
    }

    /** Title template for anniversary events. Tokens: {name}, {years}. */
    public function anniversaryTitleTemplate(): string
    {
        return self::envString('BAIKAL_ANNIVERSARY_TITLE_TEMPLATE', "{name}'s Anniversary");
    }

    /** Append the number of years (when known) to anniversary titles. */
    public function anniversaryShowYears(): bool
    {
        return self::envBool('BAIKAL_ANNIVERSARY_SHOW_YEARS', true);
    }

    /**
     * Optional address-book display name to restrict the contact scan to.
     * Empty means: scan all of the user's address books.
     */
    public function addressBookFilter(): string
    {
        return self::envString('BAIKAL_BIRTHDAY_ADDRESSBOOK', '');
    }

    /** Local time the reminder should fire, as "HH:MM" (24h). */
    public function alarmTime(): string
    {
        $value = self::envString('BAIKAL_BIRTHDAY_ALARM_TIME', '08:00');

        return preg_match('/^\d{1,2}:\d{2}$/', $value) === 1 ? $value : '08:00';
    }

    /** Create the destination calendar automatically if it is missing. */
    public function createCalendarIfMissing(): bool
    {
        return self::envBool('BAIKAL_BIRTHDAY_CREATE_CALENDAR', false);
    }

    public function timezone(): string
    {
        $tz = $this->raw['system']['timezone'] ?? '';
        if ($tz !== '' && in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
            return $tz;
        }

        return 'UTC';
    }

    public function configDir(): string
    {
        return $this->configDir;
    }

    private static function envString(string $name, string $default): string
    {
        $value = getenv($name);

        return ($value === false || $value === '') ? $default : $value;
    }

    private static function envBool(string $name, bool $default): bool
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }
}
