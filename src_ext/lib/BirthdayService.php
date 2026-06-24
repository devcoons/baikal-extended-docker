<?php

declare(strict_types=1);

namespace BaikalExt;

use Sabre\CalDAV\Backend\PDO as CalendarBackend;
use Sabre\CardDAV\Backend\PDO as CardBackend;
use Sabre\DAVACL\PrincipalBackend\PDO as PrincipalBackend;
use Sabre\VObject\Reader;
use Sabre\VObject\Component\VCalendar;

/**
 * Scans every user's contacts for birthdays and keeps an all-day, yearly
 * "<name>'s Birthday" reminder in their "Important Dates" calendar in sync.
 *
 * Only events created by this extension are ever touched: they are tagged with
 * a stable UID prefix and an X-BAIKAL-EXT-SIG signature, so user-created events
 * are never modified or deleted.
 */
final class BirthdayService
{
    /** URI/UID prefix marking calendar objects owned by this extension. */
    public const MANAGED_PREFIX = 'baikal-ext-bday-';

    private const UID_DOMAIN = '@baikal-ext';

    private PrincipalBackend $principals;
    private CardBackend $cards;
    private CalendarBackend $calendars;

    /** @var array<string,int> */
    private array $totals = [
        'users_processed' => 0,
        'users_skipped'   => 0,
        'created'         => 0,
        'updated'         => 0,
        'unchanged'      => 0,
        'deleted'        => 0,
        'contacts_seen'  => 0,
        'birthdays_found' => 0,
    ];

    public function __construct(
        \PDO $pdo,
        private Config $config,
        private Logger $logger,
        private bool $dryRun = false,
    ) {
        $this->principals = new PrincipalBackend($pdo);
        $this->cards = new CardBackend($pdo);
        $this->calendars = new CalendarBackend($pdo);
    }

    /**
     * @param string|null $onlyUser process a single username only (optional)
     * @return array<string,int> summary counters
     */
    public function run(?string $onlyUser = null): array
    {
        $calendarName = $this->config->calendarName();
        $this->logger->info(sprintf(
            'Starting birthday sync (destination calendar: "%s"%s)%s',
            $calendarName,
            $this->config->addressBookFilter() !== '' ? ', address book: "' . $this->config->addressBookFilter() . '"' : '',
            $this->dryRun ? ' [DRY-RUN]' : ''
        ));

        foreach ($this->principals->getPrincipalsByPrefix('principals') as $principal) {
            $uri = $principal['uri'] ?? '';
            // Only top-level user principals (skip calendar-proxy-* sub-principals).
            if (preg_match('#^principals/[^/]+$#', $uri) !== 1) {
                continue;
            }

            $username = substr($uri, strlen('principals/'));
            if ($onlyUser !== null && $username !== $onlyUser) {
                continue;
            }

            $this->processUser($uri, $username, $calendarName);
        }

        $this->logger->info(sprintf(
            'Done. users=%d skipped=%d | birthdays=%d | created=%d updated=%d unchanged=%d deleted=%d',
            $this->totals['users_processed'],
            $this->totals['users_skipped'],
            $this->totals['birthdays_found'],
            $this->totals['created'],
            $this->totals['updated'],
            $this->totals['unchanged'],
            $this->totals['deleted'],
        ));

        return $this->totals;
    }

    private function processUser(string $principalUri, string $username, string $calendarName): void
    {
        $targetCalendar = $this->findCalendar($principalUri, $calendarName);

        if ($targetCalendar === null) {
            if ($this->config->createCalendarIfMissing() && !$this->dryRun) {
                $targetCalendar = $this->createCalendar($principalUri, $calendarName);
            }

            if ($targetCalendar === null) {
                $this->logger->debug(sprintf('User "%s": no "%s" calendar, skipping.', $username, $calendarName));
                $this->totals['users_skipped']++;

                return;
            }
        }

        $calendarId = $targetCalendar['id'];

        // 1. Collect desired birthday events from the user's contacts.
        $desired = $this->collectDesiredEvents($principalUri, $username);

        // 2. Reconcile against events this extension previously created.
        $this->reconcile($calendarId, $desired, $username);

        $this->totals['users_processed']++;
    }

    /**
     * @return array<string,array{summary:string,signature:string,data:string}>
     */
    private function collectDesiredEvents(string $principalUri, string $username): array
    {
        $filter = $this->config->addressBookFilter();
        $desired = [];

        foreach ($this->cards->getAddressBooksForUser($principalUri) as $book) {
            if ($filter !== '' && ($book['{DAV:}displayname'] ?? '') !== $filter) {
                continue;
            }

            $bookId = $book['id'];
            $uris = array_column($this->cards->getCards($bookId), 'uri');
            if ($uris === []) {
                continue;
            }

            foreach (array_chunk($uris, 100) as $chunk) {
                foreach ($this->cards->getMultipleCards($bookId, $chunk) as $card) {
                    $this->totals['contacts_seen']++;
                    $event = $this->buildEventFromCard($card, (string) $bookId, $username);
                    if ($event !== null) {
                        $desired[$event['uri']] = $event;
                    }
                }
            }
        }

        return $desired;
    }

    /**
     * @param array{carddata?:string,uri?:string} $card
     * @return array{uri:string,summary:string,signature:string,data:string}|null
     */
    private function buildEventFromCard(array $card, string $bookId, string $username): ?array
    {
        $carddata = $card['carddata'] ?? '';
        if ($carddata === '') {
            return null;
        }

        try {
            $vcard = Reader::read($carddata);
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf('User "%s": unreadable vCard %s (%s)', $username, $card['uri'] ?? '?', $e->getMessage()));

            return null;
        }

        if (!isset($vcard->BDAY)) {
            return null;
        }

        $name = $this->extractName($vcard);
        if ($name === '') {
            $this->logger->debug(sprintf('User "%s": contact with birthday but no name, skipping (%s).', $username, $card['uri'] ?? '?'));

            return null;
        }

        $date = BirthdayParser::parse((string) $vcard->BDAY);
        if ($date === null) {
            $this->logger->debug(sprintf('User "%s": unparseable BDAY "%s" for %s', $username, (string) $vcard->BDAY, $name));

            return null;
        }

        $this->totals['birthdays_found']++;

        $sourceKey = isset($vcard->UID) && (string) $vcard->UID !== ''
            ? (string) $vcard->UID
            : $bookId . '/' . ($card['uri'] ?? '');

        $uri = self::MANAGED_PREFIX . sha1($sourceKey) . '.ics';
        $uid = self::MANAGED_PREFIX . sha1($sourceKey) . self::UID_DOMAIN;
        $summary = $name . "'s Birthday";

        $signature = sha1(implode('|', [
            $summary,
            sprintf('%02d-%02d', $date['month'], $date['day']),
            $date['year'] ?? '----',
            $this->config->alarmTime(),
        ]));

        $data = $this->renderCalendar($uid, $summary, $date, $signature, $sourceKey);

        return [
            'uri'       => $uri,
            'summary'   => $summary,
            'signature' => $signature,
            'data'      => $data,
        ];
    }

    /**
     * @param array{month:int,day:int,year:int|null} $date
     */
    private function renderCalendar(string $uid, string $summary, array $date, string $signature, string $sourceKey): string
    {
        // No-year birthdays use a leap-safe base year so Feb 29 stays valid.
        $year = $date['year'] ?? 2000;
        if (!checkdate($date['month'], $date['day'], $year)) {
            $year = 2000;
        }
        $start = \DateTimeImmutable::createFromFormat('!Ymd', sprintf('%04d%02d%02d', $year, $date['month'], $date['day']));

        $vcal = new VCalendar();
        $vcal->PRODID = '-//baikal-ext//birthday-sync//EN';

        $event = $vcal->add('VEVENT');
        $event->UID = $uid;
        // Assign (not add) so we replace VObject's auto-generated DTSTAMP.
        $event->DTSTAMP = new \DateTime('now', new \DateTimeZone('UTC'));

        $dtstart = $event->add('DTSTART', $start->format('Ymd'));
        $dtstart['VALUE'] = 'DATE';
        $dtend = $event->add('DTEND', $start->modify('+1 day')->format('Ymd'));
        $dtend['VALUE'] = 'DATE';

        $event->add('RRULE', ['FREQ' => 'YEARLY']);
        $event->add('SUMMARY', $summary);
        $event->add('TRANSP', 'TRANSPARENT');
        $event->add('X-BAIKAL-EXT-SIG', $signature);
        $event->add('X-BAIKAL-EXT-SOURCE', $sourceKey);

        $alarm = $event->add('VALARM');
        $alarm->add('ACTION', 'DISPLAY');
        $alarm->add('DESCRIPTION', $summary);
        $alarm->add('TRIGGER', $this->alarmTrigger(), ['RELATED' => 'START']);

        return $vcal->serialize();
    }

    /** Converts the configured "HH:MM" into an iCalendar duration from midnight. */
    private function alarmTrigger(): string
    {
        [$h, $m] = array_map('intval', explode(':', $this->config->alarmTime()));
        if ($h === 0 && $m === 0) {
            return 'PT0S';
        }
        $trigger = 'PT';
        if ($h > 0) {
            $trigger .= $h . 'H';
        }
        if ($m > 0) {
            $trigger .= $m . 'M';
        }

        return $trigger;
    }

    /**
     * @param array<string,array{uri:string,summary:string,signature:string,data:string}> $desired
     */
    private function reconcile(mixed $calendarId, array $desired, string $username): void
    {
        $existing = $this->existingManagedSignatures($calendarId);

        foreach ($desired as $uri => $event) {
            if (!array_key_exists($uri, $existing)) {
                $this->logger->debug(sprintf('User "%s": + %s', $username, $event['summary']));
                if (!$this->dryRun) {
                    $this->calendars->createCalendarObject($calendarId, $uri, $event['data']);
                }
                $this->totals['created']++;
            } elseif ($existing[$uri] !== $event['signature']) {
                $this->logger->debug(sprintf('User "%s": ~ %s', $username, $event['summary']));
                if (!$this->dryRun) {
                    $this->calendars->updateCalendarObject($calendarId, $uri, $event['data']);
                }
                $this->totals['updated']++;
            } else {
                $this->totals['unchanged']++;
            }
        }

        // Remove birthday events whose source contact/BDAY no longer exists.
        foreach (array_keys($existing) as $uri) {
            if (!array_key_exists($uri, $desired)) {
                $this->logger->debug(sprintf('User "%s": - %s', $username, $uri));
                if (!$this->dryRun) {
                    $this->calendars->deleteCalendarObject($calendarId, $uri);
                }
                $this->totals['deleted']++;
            }
        }
    }

    /**
     * @return array<string,string> map of managed object uri => stored signature
     */
    private function existingManagedSignatures(mixed $calendarId): array
    {
        $managedUris = [];
        foreach ($this->calendars->getCalendarObjects($calendarId) as $object) {
            $uri = $object['uri'] ?? '';
            if (str_starts_with($uri, self::MANAGED_PREFIX)) {
                $managedUris[] = $uri;
            }
        }

        $signatures = [];
        foreach (array_chunk($managedUris, 100) as $chunk) {
            foreach ($this->calendars->getMultipleCalendarObjects($calendarId, $chunk) as $object) {
                $signatures[$object['uri']] = $this->readSignature($object['calendardata'] ?? '');
            }
        }

        return $signatures;
    }

    private function readSignature(string $calendarData): string
    {
        if ($calendarData === '') {
            return '';
        }
        try {
            $vcal = Reader::read($calendarData);
            if (isset($vcal->VEVENT->{'X-BAIKAL-EXT-SIG'})) {
                return (string) $vcal->VEVENT->{'X-BAIKAL-EXT-SIG'};
            }
        } catch (\Throwable) {
            // Treat unparseable as needing refresh.
        }

        return '';
    }

    /**
     * @return array{id:mixed,uri:string}|null
     */
    private function findCalendar(string $principalUri, string $calendarName): ?array
    {
        foreach ($this->calendars->getCalendarsForUser($principalUri) as $calendar) {
            if (($calendar['{DAV:}displayname'] ?? '') === $calendarName) {
                return $calendar;
            }
        }

        return null;
    }

    /**
     * @return array{id:mixed,uri:string}|null
     */
    private function createCalendar(string $principalUri, string $calendarName): ?array
    {
        $uri = 'important-dates-' . substr(sha1($calendarName), 0, 8);
        $this->logger->info(sprintf('Creating calendar "%s" for %s', $calendarName, $principalUri));
        $this->calendars->createCalendar($principalUri, $uri, [
            '{DAV:}displayname' => $calendarName,
            '{urn:ietf:params:xml:ns:caldav}calendar-description' => 'Auto-managed birthdays',
        ]);

        return $this->findCalendar($principalUri, $calendarName);
    }

    private function extractName(\Sabre\VObject\Component\VCard $vcard): string
    {
        if (isset($vcard->FN) && trim((string) $vcard->FN) !== '') {
            return trim((string) $vcard->FN);
        }

        if (isset($vcard->N)) {
            $parts = $vcard->N->getParts();
            // N = Family;Given;Additional;Prefix;Suffix
            $given = $parts[1] ?? '';
            $family = $parts[0] ?? '';
            $name = trim($given . ' ' . $family);
            if ($name !== '') {
                return $name;
            }
        }

        return '';
    }
}
