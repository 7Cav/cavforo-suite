<?php

/**
 * Issue #191 — configuration that is present but no longer refers to anything.
 *
 * The add-on already refuses to run when configuration is ABSENT: a list that
 * parses to nothing, an unreadable vendor prefix table, a status set colliding
 * with a type prefix, no seated clerk. What it never checked is configuration
 * that still parses but has gone stale, which fails in whichever direction is
 * worse for the situation and leaves nothing in the log either way.
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
 * the applicant-visible note, which clerks were alerted, which markers were
 * written, and which IDS were named in the error log. Never which guard fired,
 * never which branch produced a line.
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
 *  - No assertion names an option key. The stale id alone proves the fault
 *    surfaced; naming the ACP handle as well would couple these tests to the
 *    current config key for nothing.
 *  - No assertion pins message wording. Ids are data; sentences are prose, and
 *    fixing a typo must not turn this file red.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/StaleConfigurationTest.php
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

        // --- what a run did, as an operator could see it ---------------------

        /** thread ids the applicant-visible note was posted into. */
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

    /** Records the applicant-visible note at the moment it is saved. */
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

    // === Summary ============================================================
    if ($failures > 0) {
        echo "\n$failures test(s) FAILED\n";
        exit(1);
    }
    echo "\nAll tests passed\n";
    exit(0);
}
