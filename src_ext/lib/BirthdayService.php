<?php

declare(strict_types=1);

namespace BaikalExt;

use Sabre\CalDAV\Backend\PDO as CalendarBackend;
use Sabre\CardDAV\Backend\PDO as CardBackend;
use Sabre\DAVACL\PrincipalBackend\PDO as PrincipalBackend;
use Sabre\VObject\Reader;
use Sabre\VObject\Component\VCalendar;

/**
 * Scans every user's contacts for "occasions" (birthdays and anniversaries) and
 * keeps all-day, yearly reminder events in their "Important Dates" calendar in
 * sync.
 *
 * For each contact with a birthday a `"<name>'s Birthday"` event is maintained;
 * with an anniversary, a `"<name>'s Anniversary"` event. When the source year is
 * known the age / number of years can be shown in the title (e.g.
 * "Bob's Birthday (41)").
 *
 * Only events created by this extension are ever touched: each is tagged with a
 * per-occasion URI/UID prefix and an X-BAIKAL-EXT-SIG signature, so user-created
 * events are never modified or deleted. Different occasion types reconcile
 * independently via their own prefix.
 */
final class BirthdayService
{
    /** URI/UID prefix for birthday objects (kept for backwards compatibility). */
    public const MANAGED_PREFIX = 'baikal-ext-bday-';

    /** URI/UID prefix for anniversary objects. */
    public const ANNIVERSARY_PREFIX = 'baikal-ext-anniv-';

    private const UID_DOMAIN = '@baikal-ext';

    private PrincipalBackend $principals;
    private CardBackend $cards;
    private CalendarBackend $calendars;

    /** @var list<array{key:string,props:list<string>,prefix:string,template:string,showCount:bool,foundKey:string}> */
    private array $occasions;

    /** @var array<string,int> */
    private array $totals = [
        'users_processed'    => 0,
        'users_skipped'      => 0,
        'created'            => 0,
        'updated'            => 0,
        'unchanged'          => 0,
        'deleted'            => 0,
        'contacts_seen'      => 0,
        'birthdays_found'    => 0,
        'anniversaries_found' => 0,
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

        $this->occasions = [[
            'key'      => 'birthday',
            'props'    => ['BDAY'],
            'prefix'   => self::MANAGED_PREFIX,
            'template' => $config->birthdayTitleTemplate(),
            'showCount' => $config->birthdayShowAge(),
            'foundKey' => 'birthdays_found',
        ]];

        if ($config->anniversaryEnabled()) {
            $this->occasions[] = [
                'key'      => 'anniversary',
                'props'    => ['ANNIVERSARY', 'X-ANNIVERSARY'],
                'prefix'   => self::ANNIVERSARY_PREFIX,
                'template' => $config->anniversaryTitleTemplate(),
                'showCount' => $config->anniversaryShowYears(),
                'foundKey' => 'anniversaries_found',
            ];
        }
    }

    /**
     * @param string|null $onlyUser process a single username only (optional)
     * @return array<string,int> summary counters
     */
    public function run(?string $onlyUser = null): array
    {
        $calendarName = $this->config->calendarName();
        $occasionKeys = array_map(static fn ($o) => $o['key'], $this->occasions);
        $this->logger->info(sprintf(
            'Starting sync (occasions: %s; calendar: "%s"%s)%s',
            implode(', ', $occasionKeys),
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
            'Done. users=%d skipped=%d | birthdays=%d anniversaries=%d | created=%d updated=%d unchanged=%d deleted=%d',
            $this->totals['users_processed'],
            $this->totals['users_skipped'],
            $this->totals['birthdays_found'],
            $this->totals['anniversaries_found'],
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

        // Read the user's contacts once, then derive each occasion type from them.
        $contacts = $this->loadContacts($principalUri);

        foreach ($this->occasions as $occasion) {
            $desired = [];
            foreach ($contacts as $contact) {
                foreach ($this->buildEvents($occasion, $contact, $username) as $event) {
                    $desired[$event['uri']] = $event;
                }
            }
            $this->reconcile($occasion['prefix'], $calendarId, $desired, $username);
        }

        $this->totals['users_processed']++;
    }

    /**
     * Loads and parses all of the user's contacts once.
     *
     * @return list<array{vcard:\Sabre\VObject\Component\VCard,name:string,sourceKey:string}>
     */
    private function loadContacts(string $principalUri): array
    {
        $filter = $this->config->addressBookFilter();
        $contacts = [];

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

                    $carddata = $card['carddata'] ?? '';
                    if ($carddata === '') {
                        continue;
                    }

                    try {
                        $vcard = Reader::read($carddata);
                    } catch (\Throwable $e) {
                        $this->logger->warning(sprintf('Unreadable vCard %s (%s)', $card['uri'] ?? '?', $e->getMessage()));
                        continue;
                    }

                    $sourceKey = isset($vcard->UID) && (string) $vcard->UID !== ''
                        ? (string) $vcard->UID
                        : $bookId . '/' . ($card['uri'] ?? '');

                    $contacts[] = [
                        'vcard'     => $vcard,
                        'name'      => $this->extractName($vcard),
                        'sourceKey' => $sourceKey,
                    ];
                }
            }
        }

        return $contacts;
    }

    /**
     * Builds the desired calendar object(s) for one contact + occasion.
     *
     * When the source year is known and the count should be shown, this returns
     * up to three objects so the age is shown on both the most recent and the
     * upcoming celebration, while older years stay name-only:
     *   - "last": the most recent past occurrence (within the last year), with
     *     the age/years reached then (kept so a birthday that just passed is not
     *     immediately removed);
     *   - "next": the upcoming occurrence, with the age/years in the title
     *     (e.g. "Bob's Birthday (41)");
     *   - "series": a recurring yearly event for every later year, titled with
     *     the name only ("Bob's Birthday").
     * Otherwise it returns a single recurring (name-only) event, anchored so it
     * still covers past years.
     *
     * @param array{key:string,props:list<string>,prefix:string,template:string,showCount:bool,foundKey:string} $occasion
     * @param array{vcard:\Sabre\VObject\Component\VCard,name:string,sourceKey:string} $contact
     * @return list<array{uri:string,summary:string,signature:string,data:string}>
     */
    private function buildEvents(array $occasion, array $contact, string $username): array
    {
        $vcard = $contact['vcard'];

        $rawValue = null;
        foreach ($occasion['props'] as $prop) {
            if (isset($vcard->{$prop}) && trim((string) $vcard->{$prop}) !== '') {
                $rawValue = (string) $vcard->{$prop};
                break;
            }
        }
        if ($rawValue === null) {
            return [];
        }

        if ($contact['name'] === '') {
            $this->logger->debug(sprintf('User "%s": contact with %s but no name, skipping (%s).', $username, $occasion['key'], $contact['sourceKey']));

            return [];
        }

        $date = BirthdayParser::parse($rawValue);
        if ($date === null) {
            $this->logger->debug(sprintf('User "%s": unparseable %s "%s" for %s', $username, $occasion['key'], $rawValue, $contact['name']));

            return [];
        }

        $this->totals[$occasion['foundKey']]++;

        $hash = sha1($contact['sourceKey']);
        $baseUri = $occasion['prefix'] . $hash . '.ics';
        $baseUid = $occasion['prefix'] . $hash . self::UID_DOMAIN;
        $plainTitle = $this->renderTitle($occasion['template'], $contact['name'], null, false);

        // Determine the age/years at the next occurrence (null if not computable).
        $today = new \DateTimeImmutable('today', new \DateTimeZone($this->config->timezone()));
        $upcoming = $this->nextOccurrenceDate($date['month'], $date['day'], $today);
        $count = $date['year'] !== null ? (int) $upcoming->format('Y') - $date['year'] : null;
        if ($count !== null && $count < 0) {
            $count = null; // future-dated year: nothing meaningful to show
        }

        // No age to show -> a single recurring (name-only) event, like before.
        if (!$occasion['showCount'] || $count === null) {
            $start = $this->seriesAnchor($date);

            return [$this->makeEvent(
                $baseUri,
                $baseUid,
                $plainTitle,
                $start,
                true,
                'series',
                $contact['sourceKey']
            )];
        }

        // Age known and wanted: split into "last" (with age) + "next" (with age)
        // + "series" (plain), so the most recent and upcoming celebrations carry
        // the age while later years stay name-only.
        $nextTitle = $this->renderTitle($occasion['template'], $contact['name'], $count, true);
        $seriesStart = $this->nextOccurrenceDate($date['month'], $date['day'], $upcoming->modify('+1 day'));

        $events = [];

        // Keep the most recent past occurrence (within the last year) so a
        // birthday/anniversary that just passed is not immediately removed.
        $previous = $this->previousOccurrenceDate($date['month'], $date['day'], $today);
        if ($previous !== null) {
            $lastCount = (int) $previous->format('Y') - $date['year'];
            if ($lastCount >= 0) {
                $events[] = $this->makeEvent(
                    $occasion['prefix'] . $hash . '-last.ics',
                    $occasion['prefix'] . $hash . '-last' . self::UID_DOMAIN,
                    $this->renderTitle($occasion['template'], $contact['name'], $lastCount, true),
                    $previous,
                    false,
                    'last',
                    $contact['sourceKey']
                );
            }
        }

        $events[] = $this->makeEvent(
            $occasion['prefix'] . $hash . '-next.ics',
            $occasion['prefix'] . $hash . '-next' . self::UID_DOMAIN,
            $nextTitle,
            $upcoming,
            false,
            'next',
            $contact['sourceKey']
        );

        $events[] = $this->makeEvent(
            $baseUri,
            $baseUid,
            $plainTitle,
            $seriesStart,
            true,
            'series',
            $contact['sourceKey']
        );

        return $events;
    }

    /**
     * Assembles one event array (uri/summary/signature/data).
     *
     * @return array{uri:string,summary:string,signature:string,data:string}
     */
    private function makeEvent(string $uri, string $uid, string $summary, \DateTimeImmutable $start, bool $recurring, string $role, string $sourceKey): array
    {
        $signature = sha1(implode('|', [
            $role,
            $summary,
            $start->format('Ymd'),
            $recurring ? 'yearly' : 'once',
            $this->config->alarmTime(),
        ]));

        return [
            'uri'       => $uri,
            'summary'   => $summary,
            'signature' => $signature,
            'data'      => $this->renderCalendar($uid, $summary, $start, $recurring, $role, $signature, $sourceKey),
        ];
    }

    /**
     * Next calendar date (>= $from) matching month/day, skipping years where the
     * date is invalid (e.g. Feb 29 in non-leap years).
     */
    private function nextOccurrenceDate(int $month, int $day, \DateTimeImmutable $from): \DateTimeImmutable
    {
        $startYear = (int) $from->format('Y');
        for ($i = 0; $i <= 8; $i++) {
            $year = $startYear + $i;
            if (!checkdate($month, $day, $year)) {
                continue;
            }
            $candidate = \DateTimeImmutable::createFromFormat(
                '!Ymd',
                sprintf('%04d%02d%02d', $year, $month, $day),
                $from->getTimezone()
            );
            if ($candidate >= $from) {
                return $candidate;
            }
        }

        return $from;
    }

    /**
     * Most recent calendar date strictly before $before matching month/day,
     * skipping years where the date is invalid (e.g. Feb 29). Returns null when
     * none can be found within a reasonable window.
     */
    private function previousOccurrenceDate(int $month, int $day, \DateTimeImmutable $before): ?\DateTimeImmutable
    {
        $startYear = (int) $before->format('Y');
        for ($i = 0; $i <= 8; $i++) {
            $year = $startYear - $i;
            if ($year < 1) {
                break;
            }
            if (!checkdate($month, $day, $year)) {
                continue;
            }
            $candidate = \DateTimeImmutable::createFromFormat(
                '!Ymd',
                sprintf('%04d%02d%02d', $year, $month, $day),
                $before->getTimezone()
            );
            if ($candidate < $before) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Leap-safe anchor for a name-only recurring series when the year is unknown.
     *
     * @param array{month:int,day:int,year:int|null} $date
     */
    private function seriesAnchor(array $date): \DateTimeImmutable
    {
        $year = $date['year'] ?? 2000;
        if (!checkdate($date['month'], $date['day'], $year)) {
            $year = 2000;
        }

        return \DateTimeImmutable::createFromFormat(
            '!Ymd',
            sprintf('%04d%02d%02d', $year, $date['month'], $date['day']),
            new \DateTimeZone($this->config->timezone())
        );
    }

    /**
     * Builds the event title from a template.
     *
     * Tokens: {name}, and {age}/{years}/{count} (interchangeable). When the
     * template has no count token but showCount is on and a count is known, it is
     * appended as " (N)".
     */
    private function renderTitle(string $template, string $name, ?int $count, bool $showCount): string
    {
        $title = str_replace('{name}', $name, $template);

        if (preg_match('/\{(age|years|count)\}/', $template) === 1) {
            $title = preg_replace('/\{(age|years|count)\}/', $count !== null ? (string) $count : '', $title);
        } elseif ($showCount && $count !== null) {
            $title .= ' (' . $count . ')';
        }

        // Tidy up after an empty count token: drop "()" and collapse whitespace.
        $title = preg_replace('/\(\s*\)/', '', (string) $title);
        $title = preg_replace('/\s{2,}/', ' ', (string) $title);

        return trim((string) $title);
    }

    private function renderCalendar(string $uid, string $summary, \DateTimeImmutable $start, bool $recurring, string $role, string $signature, string $sourceKey): string
    {
        $vcal = new VCalendar();
        $vcal->PRODID = '-//baikal-ext//occasion-sync//EN';

        $event = $vcal->add('VEVENT');
        $event->UID = $uid;
        // Assign (not add) so we replace VObject's auto-generated DTSTAMP.
        $event->DTSTAMP = new \DateTime('now', new \DateTimeZone('UTC'));

        $dtstart = $event->add('DTSTART', $start->format('Ymd'));
        $dtstart['VALUE'] = 'DATE';
        $dtend = $event->add('DTEND', $start->modify('+1 day')->format('Ymd'));
        $dtend['VALUE'] = 'DATE';

        if ($recurring) {
            $event->add('RRULE', ['FREQ' => 'YEARLY']);
        }
        $event->add('SUMMARY', $summary);
        $event->add('TRANSP', 'TRANSPARENT');
        $event->add('X-BAIKAL-EXT-SIG', $signature);
        $event->add('X-BAIKAL-EXT-ROLE', $role);
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
    private function reconcile(string $prefix, mixed $calendarId, array $desired, string $username): void
    {
        $existing = $this->existingManagedSignatures($prefix, $calendarId);

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

        // Remove managed events whose source contact/field no longer exists.
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
    private function existingManagedSignatures(string $prefix, mixed $calendarId): array
    {
        $managedUris = [];
        foreach ($this->calendars->getCalendarObjects($calendarId) as $object) {
            $uri = $object['uri'] ?? '';
            if (str_starts_with($uri, $prefix)) {
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
            '{urn:ietf:params:xml:ns:caldav}calendar-description' => 'Auto-managed birthdays and anniversaries',
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
