<?php

/**
 * The configuration guards that stand between a misconfigured board and an
 * add-on that has quietly stopped working.
 *
 * Two families, filed separately and covered together because they are the same
 * shape of fault with the same failure surface:
 *
 *  - Configuration that is ABSENT or self-CONTRADICTORY (issue #208) — a list
 *    that parses to nothing, a status set colliding with a type prefix, no
 *    seated clerk, the vendor prefix add-on switched off, a link table that
 *    cannot be read or that comes back empty.
 *  - Configuration that still parses but has gone STALE (issue #191) — an id
 *    that no longer refers to anything, which fails in whichever direction is
 *    worse for the situation and leaves nothing in the log either way.
 *
 * These tests drive the real cron entry point — Cron\ScanQueue::run() — so
 * ScanQueue, QueueReminder, EnlistmentRouting, ProcessingStatus, PositionIdList
 * and ReminderDecision all run for real. Only \XF is stood in with a stub, the
 * same approach as Cav7/EnlistmentDefaults' tests/FailureLoggingTest.php. There
 * is deliberately no seam below this one: the guards being covered are early
 * returns in remind(), and an early return only means anything when you can see
 * the run that did not happen.
 *
 * WHAT THESE ASSERT, AND WHAT THEY DELIBERATELY DO NOT
 *
 * Only what an operator could observe from outside a cron run: which threads got
 * the reminder note, which clerks were alerted, which markers were
 * written, and which IDENTIFIERS were named in the error log. Never which guard
 * fired, never which branch produced a line, never which table was touched.
 *
 * In particular:
 *  - No assertion depends on the ORDER records were emitted in. remind()'s
 *    docblock explains why log-only checks currently sit above the aborts, but
 *    that ordering is the mechanism, not the contract. The contract is that a
 *    board carrying two faults hears about both from one run, and that is what
 *    is asserted.
 *  - No assertion counts the log as a whole. A total count breaks when an
 *    unrelated legitimate warning fires in the same run, though the specified
 *    behaviour is intact. Where de-duplication IS the specified behaviour (the
 *    drift record is emitted once per run however many threads carry the id),
 *    the count is scoped to that id.
 *  - No assertion pins message wording. Identifiers are data; sentences are
 *    prose, and fixing a typo must not turn this file red. This is checkable
 *    rather than aspirational: strip every authored sentence out of remind()'s
 *    log messages, keeping only the sprintf placeholders and the option keys,
 *    and this file stays green. It was run that way while these were written.
 *  - No assertion observes which TABLES a run read. That is choreography, not
 *    behaviour: it reports how the scan is arranged rather than what an
 *    operator gets, and it stays true under a rewrite that changes neither.
 *
 * An ACP option key IS allowed as an identifier, which reverses this file's
 * earlier rule. Several faults carry no id at all — a blank list has no value to
 * name — and for those the option key is the only handle an operator can act on,
 * so a test that refuses it can assert nothing but "something was logged".
 * Renaming an option is a real change that breaks live boards, not a tidy-up, so
 * the key is configuration contract in a way a sentence is not. Match it as a
 * bare substring and assert nothing about the words around it.
 *
 * WHAT IS NOT COVERED HERE, AND WHY
 *
 * Two guards in remind() have no behavioural seam. Both were confirmed by
 * deleting the guard outright and finding every test in this file still green:
 *
 *  - The unconfigured BOT USER abort. Without it the run reaches the note step,
 *    fails to resolve the user, reports, and reminds nobody — the same outcome
 *    and the same log the guard produces. It exists to say that once instead of
 *    once per thread, and log volume is not assertable here without counting
 *    records, which the rule above forbids.
 *  - The early return on a prefix-link read FAILURE. `null` is falsy, so the
 *    empty-result guard immediately below catches the same value and aborts
 *    anyway, and the exception was already logged inside fetchThreadPrefixLinks.
 *    The read-failure scenario below therefore covers that CATCH — that a failed
 *    read stays diagnosable at the log boundary — and not the guard.
 *
 * Neither is a defect to fix by widening what this file observes. They are
 * recorded so the gap is visible rather than papered over with a test that
 * passes for the wrong reason.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/ConfigurationGuardsTest.php
 */

// ---------------------------------------------------------------------------
// The board every stub reads from.
// ---------------------------------------------------------------------------
namespace Cav7\EnlistmentReminder\Tests {

    /**
     * A stand-in for the parts of the forum this add-on reads. Threads, the
     * prefixes each one carries, which prefixes and nodes exist at all, and who
     * is seated in which roster position.
     */
    class Board
    {
        /** Prefix ids present in xf_thread_prefix. */
        public array $livePrefixIds = [53, 54, 55, 57, 58, 66, 68, 110];

        /**
         * node_id => node_type_id, as xf_node holds it. Node 324 is taken from the
         * live board: a LinkForum named "Enlist" sitting under the same parent as
         * the real queue node, so a single mistyped digit lands on a node that
         * exists and can never hold a thread.
         */
        public array $nodeTypes = [
            324 => 'LinkForum',
            325 => 'Forum',
            326 => 'Forum',
            327 => 'Forum',
            7   => 'Category',
        ];

        /**
         * thread_id => [node_id, post_date, prefix_id, links]
         *
         * `prefix_id` is xf_thread.prefix_id, the primary prefix that decides
         * enlistment type. `links` is every prefix SV/MultiPrefix records for
         * the thread in xf_sv_thread_prefix_link, type prefix included.
         */
        public array $threads = [];

        /** position_id => seated user ids. */
        public array $seats = [];

        /** thread ids already in the marker table. */
        public array $markers = [];

        /** thread ids already carrying the bot's note. */
        public array $notes = [];

        public bool $multiPrefixActive = true;

        /**
         * A failure to inject into the vendor link-table read: the message the
         * database throws with, or null for a table that reads normally.
         *
         * The only way to reach fetchThreadPrefixLinks' catch, and the only
         * stub state that exists to drive a code path rather than to describe
         * a board. The message carries a token the test itself chose, so the
         * read-failure scenario can prove the failure stayed diagnosable at the
         * log boundary without asserting one word of production wording.
         */
        public ?string $linkReadFailure = null;

        // --- what a run did, as an operator could see it ---------------------

        /** thread ids the reminder note was posted into. */
        public array $noted = [];

        /** thread_id => user ids alerted. */
        public array $alerted = [];

        /** thread ids written to the marker table by this run. */
        public array $marked = [];

        public function resetObservations(): void
        {
            $this->noted = [];
            $this->alerted = [];
            $this->marked = [];
        }

        /**
         * Delete a thread prefix the way the ACP does, which is the route that
         * makes a configured status id go stale without anybody mistyping
         * anything.
         *
         * Two vendors are involved and they do different things. XenForo core's
         * ThreadPrefix::_postDelete clears the forum association map only
         * (XF\Repository\AbstractPrefixMap::removePrefixAssociations), leaving
         * xf_thread.prefix_id exactly as it was. SV/MultiPrefix's extension
         * deletes every xf_sv_thread_prefix_link row carrying the prefix. So the
         * threads keep their TYPE prefix and its link row, and lose only the mark
         * that said a clerk had them in hand — which is precisely why the
         * existing "no linked prefix at all" guard does not catch this.
         */
        public function deletePrefix(int $prefixId): void
        {
            $this->livePrefixIds = array_values(array_diff($this->livePrefixIds, [$prefixId]));
            foreach ($this->threads as $id => $thread) {
                $this->threads[$id]['links'] = array_values(array_diff($thread['links'], [$prefixId]));
            }
        }

        /** Prefix link rows for the given threads, as the vendor table holds them. */
        public function linkRows(array $threadIds): array
        {
            $rows = [];
            foreach ($threadIds as $threadId) {
                foreach ($this->threads[$threadId]['links'] ?? [] as $prefixId) {
                    $rows[] = ['thread_id' => $threadId, 'prefix_id' => $prefixId];
                }
            }

            return $rows;
        }
    }

    /** The board the stubs consult, swapped per scenario. */
    final class Ctx
    {
        public static Board $board;
    }
}

// ---------------------------------------------------------------------------
// The XenForo stub.
// ---------------------------------------------------------------------------
namespace {

    use Cav7\EnlistmentReminder\Tests\Ctx;

    class XF
    {
        public static int $time = 0;

        /** Every message that reached the admin error log this run. */
        public static array $logged = [];

        public static array $options = [];

        public static function logError(string $message, bool $forceLog = false): void
        {
            self::$logged[] = $message;
        }

        public static function logException(\Throwable $e, bool $rollback = false, string $prefix = '', bool $force = false): void
        {
            self::$logged[] = $prefix . $e->getMessage();
        }

        public static function options(): object
        {
            return (object) self::$options;
        }

        public static function isAddOnActive(string $addOnId): bool
        {
            return $addOnId === 'SV/MultiPrefix' ? Ctx::$board->multiPrefixActive : true;
        }

        public static function phrase(string $key, array $params = []): string
        {
            return '[phrase:' . $key . ']';
        }

        public static function db(): FakeDb
        {
            return new FakeDb();
        }

        public static function em(): FakeEm
        {
            return new FakeEm();
        }

        public static function app(): FakeApp
        {
            return new FakeApp();
        }
    }

    /**
     * Answers only the queries this add-on issues, dispatched on the SQL. An
     * unrecognised query throws rather than returning [], so a production change
     * that starts reading something new fails loudly here instead of quietly
     * reading "nothing".
     */
    class FakeDb
    {
        public function quote($value): string
        {
            if (is_array($value)) {
                return implode(',', array_map('intval', $value));
            }

            return is_int($value) ? (string) $value : "'" . $value . "'";
        }

        /** The id list out of a `... IN (1,2,3)` clause. */
        private function inList(string $sql): array
        {
            preg_match('/IN \(([^)]*)\)/', $sql, $m);

            return array_filter(array_map('intval', explode(',', $m[1] ?? '')));
        }

        public function fetchAll(string $sql, array $params = []): array
        {
            $board = Ctx::$board;

            if (str_contains($sql, 'FROM xf_thread') && str_contains($sql, 'node_id = ?')) {
                $nodeId = (int) $params[0];
                $rows = [];
                foreach ($board->threads as $threadId => $thread) {
                    if ($thread['node_id'] === $nodeId) {
                        $rows[] = [
                            'thread_id' => $threadId,
                            'post_date' => $thread['post_date'],
                            'prefix_id' => $thread['prefix_id'],
                        ];
                    }
                }

                return $rows;
            }

            if (str_contains($sql, 'xf_sv_thread_prefix_link')) {
                if ($board->linkReadFailure !== null) {
                    // Renamed by a vendor release, permissions revoked, dropped
                    // under an add-on that is still active — the read itself
                    // throws, rather than returning nothing.
                    throw new \RuntimeException($board->linkReadFailure);
                }

                return $board->linkRows($this->inList($sql));
            }

            throw new \LogicException('unstubbed fetchAll: ' . $sql);
        }

        public function fetchAllColumn(string $sql, array $params = []): array
        {
            $board = Ctx::$board;

            if (str_contains($sql, 'xf_thread_prefix')) {
                return $board->livePrefixIds;
            }

            if (str_contains($sql, 'xf_nf_rosters_user')) {
                // Every position id the query mentions, from the IN list and the
                // FIND_IN_SET arms alike.
                preg_match_all('/\d+/', $sql, $m);
                $userIds = [];
                foreach (array_unique(array_map('intval', $m[0])) as $positionId) {
                    foreach ($board->seats[$positionId] ?? [] as $userId) {
                        $userIds[$userId] = true;
                    }
                }

                return array_keys($userIds);
            }

            if (str_contains($sql, 'xf_cav7_enlistment_reminder')) {
                return array_values(array_intersect($this->inList($sql), $board->markers));
            }

            if (str_contains($sql, 'FROM xf_post')) {
                return array_values(array_intersect($this->inList($sql), $board->notes));
            }

            throw new \LogicException('unstubbed fetchAllColumn: ' . $sql);
        }

        public function fetchOne(string $sql, array $params = [])
        {
            if (str_contains($sql, 'xf_node')) {
                // Returns the node's TYPE, as the board holds it: a row that exists
                // but is a Category or a LinkForum is a different answer from no row
                // at all, and only one of them can ever hold a queue thread.
                return Ctx::$board->nodeTypes[(int) $params[0]] ?? null;
            }

            if (str_contains($sql, 'FROM xf_post')) {
                // No OP post id, so the first_post_id correction is a no-op.
                return 0;
            }

            throw new \LogicException('unstubbed fetchOne: ' . $sql);
        }

        public function query(string $sql, array $params = []): void
        {
            if (str_contains($sql, 'INSERT IGNORE INTO xf_cav7_enlistment_reminder')) {
                $threadId = (int) $params[0];
                Ctx::$board->markers[] = $threadId;
                Ctx::$board->marked[] = $threadId;

                return;
            }

            if (str_contains($sql, 'UPDATE xf_thread')) {
                // The first_post_id correction. fetchOne returns no OP post id, so
                // this is never reached; kept so the write is named rather than
                // swallowed by the catch-all below.
                return;
            }

            // Throws for the same reason the reads do. A silent no-op here would
            // let a production change that starts writing something new pass green.
            throw new \LogicException('unstubbed query: ' . $sql);
        }
    }

    class FakeThread
    {
        public function __construct(public int $thread_id, public int $reply_count = 3) {}
    }

    class FakeUser
    {
        public function __construct(public int $user_id, public string $username) {}
    }

    /** Records the reminder note at the moment it is saved. */
    class FakePost
    {
        public $thread_id, $user_id, $username, $post_date, $message, $message_state, $ip_id, $position;

        public function save(): void
        {
            Ctx::$board->noted[] = (int) $this->thread_id;
        }
    }

    class FakeEm
    {
        public function find(string $type, int $id)
        {
            if ($type === 'XF:Thread') {
                return isset(Ctx::$board->threads[$id]) ? new FakeThread($id) : null;
            }

            return $type === 'XF:User' ? new FakeUser($id, 'S6Bot') : null;
        }

        public function create(string $type)
        {
            return new FakePost();
        }

        public function findByIds(string $type, array $ids): array
        {
            return array_map(fn($id) => new FakeUser((int) $id, 'clerk' . $id), $ids);
        }
    }

    class FakeAlertRepo
    {
        public function alert($user, $senderId, $senderName, $type, $contentId, $action, $extra = [], $opts = []): void
        {
            Ctx::$board->alerted[(int) $contentId][] = $user->user_id;
        }
    }

    class FakeApp
    {
        public function repository(string $class)
        {
            return new FakeAlertRepo();
        }
    }
}

// ---------------------------------------------------------------------------
// Scenarios.
// ---------------------------------------------------------------------------
namespace Cav7\EnlistmentReminder\Tests {

    require __DIR__ . '/../PositionIdList.php';
    require __DIR__ . '/../EnlistmentRouting.php';
    require __DIR__ . '/../ProcessingStatus.php';
    require __DIR__ . '/../ReminderDecision.php';
    require __DIR__ . '/../QueueReminder.php';
    require __DIR__ . '/../Cron/ScanQueue.php';

    use Cav7\EnlistmentReminder\Cron\ScanQueue;

    const NOW = 1800000000;
    const HOUR = 3600;

    /**
     * A token this file invents and injects into the failing database read, so
     * the read-failure scenario can recognise its own failure in the log. Chosen
     * to be findable as a standalone number and to appear nowhere else.
     */
    const READ_FAILURE_TOKEN = 990001;

    $failures = 0;

    function check(string $label, bool $ok, string $detail = ''): void
    {
        global $failures;
        if ($ok) {
            echo "PASS: $label\n";
        } else {
            $failures++;
            echo "FAIL: $label" . ($detail !== '' ? " — $detail" : '') . "\n";
        }
    }

    /**
     * The queue in the shape RRD runs it: standard enlistments well past a 24h
     * deadline, most of them picked up and carrying a processing status.
     */
    function healthyBoard(): Board
    {
        $board = new Board();
        $board->threads = [
            // Un-actioned: type prefix only. The one thread that should be reminded.
            101 => ['node_id' => 325, 'post_date' => NOW - 50 * HOUR, 'prefix_id' => 57, 'links' => [57]],
            // Picked up by a clerk: type prefix + In Progress.
            102 => ['node_id' => 325, 'post_date' => NOW - 60 * HOUR, 'prefix_id' => 57, 'links' => [57, 55]],
            103 => ['node_id' => 325, 'post_date' => NOW - 70 * HOUR, 'prefix_id' => 57, 'links' => [57, 55]],
        ];
        $board->seats = [579 => [9001], 580 => [9002], 751 => [9003], 960 => [9004], 1012 => [9005]];

        return $board;
    }

    /**
     * The healthy queue plus one un-actioned RE-ENLISTMENT, so a run has an
     * application of each type to succeed or fail on independently. Needed by
     * the one-blank-list scenarios: with only standard applications, starving
     * the re-enlistment side is unobservable.
     */
    function mixedTypeBoard(): Board
    {
        $board = healthyBoard();
        $board->threads[104] = [
            'node_id' => 325, 'post_date' => NOW - 80 * HOUR, 'prefix_id' => 58, 'links' => [58],
        ];

        return $board;
    }

    /** The shipped defaults, as _data/options.xml carries them. */
    function defaultOptions(): array
    {
        return [
            'cav7ERQueueNodeId'              => 325,
            'cav7ERBotUserId'                => 598,
            'cav7ERDeadlineHours'            => 24,
            'cav7ERInProcessingPrefixIds'    => '53,54,55',
            'cav7ERStandardPrefixIds'        => '57',
            'cav7ERReenlistPrefixIds'        => '58',
            'cav7ERStandardClerkPositionIds' => '579,580,751,1012',
            'cav7ERReenlistClerkPositionIds' => '579,960,1012',
            'cav7ERDecorativePrefixIds'      => '66,68,110',
        ];
    }

    /** Run the real cron entry point against a board. */
    function scan(Board $board, array $optionOverrides = []): void
    {
        Ctx::$board = $board;
        $board->resetObservations();
        \XF::$time = NOW;
        \XF::$logged = [];
        \XF::$options = $optionOverrides + defaultOptions();

        ScanQueue::run();

        sort($board->noted);
        sort($board->marked);
    }

    /** Whether any record named this id, as a standalone number. */
    function someRecordNames(int $id): bool
    {
        return recordsNaming($id) > 0;
    }

    /**
     * How many records named this id. Matched on a word boundary so 55 is not
     * found inside 550, and scoped to the one id rather than counting the log,
     * which would break when an unrelated warning fires in the same run.
     */
    function recordsNaming(int $id): int
    {
        $count = 0;
        foreach (\XF::$logged as $message) {
            if (preg_match('/(?<![0-9])' . $id . '(?![0-9])/', $message)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Whether any record names this ACP option key or vendor add-on id — the two
     * stable identifiers an operator can act on when a fault carries no id.
     *
     * The identifier of last resort, for the faults that carry no id of their
     * own: a blank list has no value to name, so without this the only available
     * assertion is "something was logged", which passes for any fault at all.
     * Matched as a bare substring, so nothing about the surrounding sentence is
     * pinned. See this file's header for why an option key counts as contract.
     */
    function someRecordNamesOptionOrAddOnId(string $handle): bool
    {
        foreach (\XF::$logged as $message) {
            if (str_contains($message, $handle)) {
                return true;
            }
        }

        return false;
    }

    /** Every record, joined, for failure output only — never asserted on. */
    function records(): string
    {
        return implode(' | ', \XF::$logged) ?: '(nothing logged)';
    }

    /** Nothing reached an applicant, a clerk, or the marker table. */
    function nothingHappened(Board $board): bool
    {
        return $board->noted === [] && $board->alerted === [] && $board->marked === [];
    }

    // === Baseline ===========================================================
    // Not an acceptance criterion, but the guard against every assertion below
    // passing vacuously: if the stub board did not reproduce a working scan,
    // "nothing was reminded" would be true everywhere for the wrong reason.
    echo "\n--- a healthy board still works ---\n";
    $board = healthyBoard();
    scan($board);
    check(
        'the un-actioned application is reminded and the picked-up ones are not',
        $board->noted === [101],
        'reminded: ' . implode(',', $board->noted)
    );
    check('its clerks are alerted', isset($board->alerted[101]));
    check('and it is marked so it never reminds twice', $board->marked === [101]);
    check('a healthy run records nothing', \XF::$logged === [], records());

    // === AC1: a configured status prefix no longer exists ===================
    echo "\n--- AC1: an in-processing prefix id that names no live prefix ---\n";
    $board = healthyBoard();
    $board->deletePrefix(55);
    scan($board);
    check(
        'no application is reminded, so work already underway is not chased',
        nothingHappened($board),
        'reminded: ' . implode(',', $board->noted) . '; marked: ' . implode(',', $board->marked)
    );
    check('the stale id is named, so an operator knows which one went', someRecordNames(55), records());

    // === AC3: an empty queue is still legitimate ============================
    // Deliberately ahead of AC2: it is the guard against over-fixing, and the
    // node check is the thing most likely to break it.
    echo "\n--- AC3: a live node holding no open threads ---\n";
    $board = healthyBoard();
    $board->threads = [];
    scan($board);
    check('an empty queue does nothing', nothingHappened($board));
    check('and stays silent, because an empty queue is normal', \XF::$logged === [], records());

    // === AC2: the queue node no longer exists ===============================
    echo "\n--- AC2: a queue node id that names no live node ---\n";
    $board = healthyBoard();
    scan($board, ['cav7ERQueueNodeId' => 3255]);
    check('nothing is reminded, as nothing can be found', nothingHappened($board));
    check('the dead node id is named', someRecordNames(3255), records());

    // Existence is not the question — holding threads is. The live board carries
    // 97 Categories, 3 LinkForums and a Page, none of which ever holds one, and
    // node 324 is a LinkForum sitting directly beside the real queue node under
    // the same parent. A one-digit slip therefore lands on a node that exists,
    // scans nothing, and would pass any check that only asked whether the row is
    // there — reproducing the very fault this record was added to make visible.
    echo "\n--- AC2: a node that exists but can never hold a thread ---\n";
    $board = healthyBoard();
    scan($board, ['cav7ERQueueNodeId' => 324]);
    check('nothing is reminded', nothingHappened($board));
    check('the node id is named even though the node exists', someRecordNames(324), records());

    // === AC4: drift the configuration has not caught up with ================
    echo "\n--- AC4: a status prefix the configuration does not name ---\n";
    $board = healthyBoard();
    $board->livePrefixIds[] = 71;
    // Two threads moved to the new status, so once-per-run means once, not twice.
    $board->threads[102]['links'] = [57, 71];
    $board->threads[103]['links'] = [57, 71];
    scan($board);
    check(
        'the unaccounted-for prefix is recorded exactly once, however many threads carry it',
        recordsNaming(71) === 1,
        records()
    );

    echo "\n--- AC4: a healthy board records no drift ---\n";
    $board = healthyBoard();
    // The decorations that ride with a status on the live queue. They are known
    // and inert, so they must never appear: a record that always fires is one an
    // operator learns to ignore, which is the whole failure this criterion is for.
    $board->threads[102]['links'] = [57, 55, 66, 110];
    $board->threads[103]['links'] = [57, 54, 68];
    scan($board);
    check('S1 is not reported as drift', !someRecordNames(66), records());
    check('RTC is not reported as drift', !someRecordNames(68), records());
    check('the "!!!" modifier is not reported as drift', !someRecordNames(110), records());
    check('nor is anything else, on a board that has not drifted', \XF::$logged === [], records());

    // === AC5: the deadline has a ceiling as well as a floor =================
    echo "\n--- AC5: a deadline far above anything usable ---\n";
    $board = healthyBoard();
    scan($board, ['cav7ERDeadlineHours' => 240000]);
    check(
        'the queue is still scanned, on the substituted deadline',
        $board->noted === [101],
        'reminded: ' . implode(',', $board->noted)
    );
    check('the rejected value is named', someRecordNames(240000), records());

    // The slip the ceiling is actually for. 2400 hours is not an absurd-looking
    // number the way 240000 is, but it is 100 days: past the age of anything in
    // the queue, so every scan selects nothing, forever. A ceiling generous enough
    // to wave it through would leave the defect in place while looking fixed.
    echo "\n--- AC5: one extra zero, which is the realistic slip ---\n";
    $board = healthyBoard();
    scan($board, ['cav7ERDeadlineHours' => 2400]);
    check(
        'a deadline of 2400 hours is substituted, not acted on',
        $board->noted === [101],
        'reminded: ' . implode(',', $board->noted)
    );
    check('and the rejected value is named', someRecordNames(2400), records());

    echo "\n--- AC5: the floor, which already behaved this way ---\n";
    $board = healthyBoard();
    scan($board, ['cav7ERDeadlineHours' => 0]);
    check('a deadline below the floor also still scans', $board->noted === [101]);

    // === AC7: two faults at once are both reported in one run ===============
    // The contract is that an operator holding two faults hears about both from a
    // single run, so they fix both rather than fixing one, waiting an hour and
    // then learning about the other. Which record lands first is not asserted:
    // that is how remind() currently achieves this, not what it owes.
    //
    // The pairing is a dead queue node (log-only) with a deleted status prefix
    // (an abort), chosen because each fault carries an ID of its own, so both can
    // be asserted on membership alone. A pairing whose second fault has no id —
    // a blank type list, say — would leave nothing to anchor on but the number of
    // records, and a count passes on volume rather than on the fault: combining
    // both reports into one record would keep the behaviour and break the test,
    // while splitting one report in two would let the other be deleted unnoticed.
    //
    // This pairing also covers the property the ordering exists for. Move the node
    // check below the abort and its record is swallowed, which is precisely what
    // "every log-only check ahead of the earliest abort" is written to prevent.
    echo "\n--- AC7: a board carrying two faults at once ---\n";
    $board = healthyBoard();
    $board->deletePrefix(55);                // a configured status that no longer exists
    scan($board, ['cav7ERQueueNodeId' => 3255]);  // and a node that no longer exists
    check('the dead node id is reported', someRecordNames(3255), records());
    check('the stale status prefix is reported in the same run', someRecordNames(55), records());

    // === AC1, the other direction: deleting a prefix it does not own ========
    // The stale-prefix abort is scoped to the in-processing set alone, and that
    // scoping is load-bearing rather than incidental. Widened to every configured
    // id, deleting the "!!!" modifier in the ACP would abort every run and take
    // the reminder permanently dark — the same class of silent outage AC1 exists
    // to prevent, introduced by the fix for it.
    echo "\n--- AC1: deleting a decorative prefix is not this guard's business ---\n";
    $board = healthyBoard();
    $board->threads[101]['links'] = [57, 110];
    $board->deletePrefix(110);
    scan($board);
    check(
        'the run carries on and still reminds the un-actioned application',
        $board->noted === [101],
        'reminded: ' . implode(',', $board->noted)
    );
    check('and nothing is reported, because no status prefix went missing', \XF::$logged === [], records());

    // === #208: a type prefix configured as an in-processing status ==========
    // The worst fault the add-on has, and the quietest. Every valid queue thread
    // carries its type prefix in the same link table the status is read from, so
    // one type id typed into the status option reads the WHOLE queue as handled.
    // Without the guard nothing is reminded and nothing is logged: the add-on is
    // indistinguishable from an add-on with no work to do. So the assertion that
    // matters is not "nothing happened" — that is true either way — but that the
    // operator is told, and told which prefix did it.
    echo "\n--- #208: a type prefix listed as an in-processing status ---\n";
    $board = healthyBoard();
    scan($board, ['cav7ERInProcessingPrefixIds' => '53,54,55,57']);
    check(
        'no application is reminded',
        nothingHappened($board),
        'reminded: ' . implode(',', $board->noted) . '; marked: ' . implode(',', $board->marked)
    );
    check('and the colliding prefix id is named', someRecordNames(57), records());

    // === #208: both enlistment-type prefix lists blank ======================
    // Nothing can route as an enlistment of either type, and the collision guard
    // above has nothing left to intersect against, so the run stops. What makes
    // this worth a test is not that it stops — without the guard nothing is
    // reminded either — but WHERE it points the operator. Un-guarded, the run
    // reaches per-thread routing and blames each application's prefix in turn,
    // sending someone to look at a thread when the fault is two empty textboxes.
    echo "\n--- #208: neither type-prefix list is configured ---\n";
    $board = healthyBoard();
    scan($board, ['cav7ERStandardPrefixIds' => '', 'cav7ERReenlistPrefixIds' => '']);
    check('no application is reminded', nothingHappened($board), records());
    check('the standard type option is named', someRecordNamesOptionOrAddOnId('cav7ERStandardPrefixIds'), records());
    check('the re-enlistment type option is named', someRecordNamesOptionOrAddOnId('cav7ERReenlistPrefixIds'), records());
    check('and no individual application is blamed instead', !someRecordNames(101), records());

    // === #208: exactly ONE type-prefix list blank ===========================
    // The asymmetry worth protecting. Both blank aborts; one blank must NOT,
    // because the other type still routes and still deserves its reminders. So
    // the run continues on the healthy half and the blank option is named.
    //
    // Covered in both directions, and that is not duplication for symmetry's
    // sake: these are two separate statements in remind(), and deleting either
    // one leaves the other's test green. The realistic slip is a copy-paste that
    // leaves both testing the same list, which one direction alone would miss.
    //
    // Each direction needs a board carrying one application of EACH type, so the
    // healthy half has something to remind and the starved half has something to
    // fail to route.
    echo "\n--- #208: no standard type prefix configured ---\n";
    $board = mixedTypeBoard();
    scan($board, ['cav7ERStandardPrefixIds' => '']);
    check(
        'the re-enlistment is still reminded, because its half is healthy',
        $board->noted === [104],
        'reminded: ' . implode(',', $board->noted)
    );
    check('the blank option is named', someRecordNamesOptionOrAddOnId('cav7ERStandardPrefixIds'), records());
    check(
        'and the standard application that can no longer route is named',
        someRecordNames(101),
        records()
    );

    echo "\n--- #208: no re-enlistment type prefix configured ---\n";
    $board = mixedTypeBoard();
    scan($board, ['cav7ERReenlistPrefixIds' => '']);
    check(
        'the standard enlistment is still reminded',
        $board->noted === [101],
        'reminded: ' . implode(',', $board->noted)
    );
    check('the blank option is named', someRecordNamesOptionOrAddOnId('cav7ERReenlistPrefixIds'), records());
    check(
        'and the re-enlistment that can no longer route is named',
        someRecordNames(104),
        records()
    );

    // === #208: the queue node is not configured at all ======================
    // A board where nobody has filled the option in. Un-guarded, the scan asks
    // the database about node 0, finds nothing, and returns in silence — which
    // is indistinguishable from a queue that is genuinely clear. The guard's
    // whole product is that the run SAYS something, so that is what is asserted.
    //
    // Deliberately no assertion about WHICH option, because the message does not
    // name one — see this file's header on option keys as identifiers. Adding
    // the name to the message would allow a stronger test, but that is a change
    // to production behaviour and out of scope for coverage work.
    echo "\n--- #208: no queue node configured ---\n";
    $board = healthyBoard();
    scan($board, ['cav7ERQueueNodeId' => 0]);
    check('no application is reminded', nothingHappened($board), records());
    check('and the run does not fall silent', \XF::$logged !== [], records());

    // === #208: the in-processing status list is blank =======================
    // Nothing could read as handled, so every application in the queue would be
    // chased. ProcessingStatus refuses that input outright, so this guard is not
    // what stands between a blank option and a mass remind — delete it and the
    // run aborts on that refusal instead, loudly but from the wrong place and
    // without naming the box to edit. What the guard adds is the admin-facing
    // message, and the option key is the only identifier the fault has.
    echo "\n--- #208: no in-processing status configured ---\n";
    $board = healthyBoard();
    scan($board, ['cav7ERInProcessingPrefixIds' => '']);
    check('no application is reminded', nothingHappened($board), records());
    check(
        'and the option to fix is named',
        someRecordNamesOptionOrAddOnId('cav7ERInProcessingPrefixIds'),
        records()
    );

    // === #208: no configured clerk position has a seated holder =============
    // A reminder that can reach nobody. Un-guarded the run gets all the way to
    // per-thread routing and complains once per remindable application, every
    // hour, about threads that are perfectly fine — so the operator is sent to
    // look at applications when the fault is a position list. Say it once,
    // against the option, and stop: which is why "no application is named" is
    // the assertion that carries this one.
    echo "\n--- #208: no clerk is seated in any configured position ---\n";
    $board = healthyBoard();
    $board->seats = [];
    scan($board);
    check('no application is reminded', nothingHappened($board), records());
    check(
        'a clerk-position option is named',
        someRecordNamesOptionOrAddOnId('cav7ERStandardClerkPositionIds'),
        records()
    );
    check('and no individual application is blamed', !someRecordNames(101), records());

    // === #208: the vendor add-on supplying prefix links is switched off =====
    // Disabled rather than uninstalled, so its link table and every stale row in
    // it are still there and still readable. XenForo checks `require` only on
    // install and upgrade, so nothing else notices. Un-guarded the scan reads
    // that abandoned table as current and reminds against it — the fault is not
    // silence here but a wrong answer delivered confidently, so "nothing is
    // reminded" is the assertion that matters.
    echo "\n--- #208: SV/MultiPrefix disabled but still installed ---\n";
    $board = healthyBoard();
    $board->multiPrefixActive = false;
    scan($board);
    check(
        'no application is reminded off the abandoned table',
        nothingHappened($board),
        'reminded: ' . implode(',', $board->noted)
    );
    check('and the vendor add-on is named', someRecordNamesOptionOrAddOnId('SV/MultiPrefix'), records());

    // === #208: the vendor link table cannot be read =========================
    // This covers the CATCH in fetchThreadPrefixLinks, not the guard in remind()
    // that reads its null return — see this file's header. The guard's early
    // return is redundant with the empty-result guard below it, so deleting it
    // changes nothing; the catch is the part that carries a contract.
    //
    // That contract is not "some warning happened". It is that the underlying
    // failure stays diagnosable at the operator-facing log boundary: an operator
    // told only "a read failed" cannot tell a renamed table from revoked
    // permissions. The token below is the test's own, so proving it surfaced
    // pins no exception type and no production sentence.
    echo "\n--- #208: the prefix link table throws on read ---\n";
    $board = healthyBoard();
    $board->linkReadFailure = 'injected read failure ' . READ_FAILURE_TOKEN;
    scan($board);
    check('no application is reminded', nothingHappened($board), records());
    check(
        'and the underlying failure is still diagnosable from the log',
        someRecordNames(READ_FAILURE_TOKEN),
        records()
    );

    // === #208: the vendor link table reads clean but holds nothing ==========
    // A different fault from the one above and the more dangerous of the two:
    // the query works, so nothing errors anywhere. Every valid queue thread
    // carries at least its type prefix in this table, so no rows at all across a
    // non-empty queue means the table is not being populated — and reading that
    // as "no application is being worked" would chase the entire queue.
    echo "\n--- #208: the prefix link table returns no rows ---\n";
    $board = healthyBoard();
    foreach ($board->threads as $threadId => $thread) {
        $board->threads[$threadId]['links'] = [];
    }
    scan($board);
    check(
        'the whole queue is not reminded',
        nothingHappened($board),
        'reminded: ' . implode(',', $board->noted)
    );
    check('and the run reports what it saw', \XF::$logged !== [], records());
    // Deliberately NOT asserted here: that this was reported as an empty read
    // rather than as a failed one. This board injects no failure, so a check for
    // the read-failure token could never fail whatever production did — vacuous,
    // not strict. The two faults are separated only by which sentence is logged,
    // and pinning a sentence is the change detector this file refuses to be.

    // === #208: one prefix listed under both enlistment types ================
    // Ambiguous routing rather than a stoppage: route() fail-safes to the union
    // of both clerk sets so no responsible clerk is dropped, and the run carries
    // on. Both halves are asserted — silently widening the audience with nothing
    // logged, and aborting a run that should have continued, are both wrong.
    echo "\n--- #208: a prefix configured under both types ---\n";
    $board = healthyBoard();
    scan($board, ['cav7ERStandardPrefixIds' => '57,58']);
    check('the ambiguous prefix id is named', someRecordNames(58), records());
    check(
        'and the run carries on and still reminds',
        $board->noted === [101],
        'reminded: ' . implode(',', $board->noted)
    );

    // === Summary ============================================================
    if ($failures > 0) {
        echo "\n$failures test(s) FAILED\n";
        exit(1);
    }
    echo "\nAll tests passed\n";
    exit(0);
}
