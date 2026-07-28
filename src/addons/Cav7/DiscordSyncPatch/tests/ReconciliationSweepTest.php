<?php

/**
 * Issue #157 — drives ReconciliationSweep::run(), the whole sweep, against a stubbed
 * Discord and an in-memory database.
 *
 * The pure units each have their own test. What had nothing running it was the
 * adapter: the walk through the guild's members, and what it does with them. Those
 * faults are the quiet kind — a cursor that stops advancing re-reads page one until
 * the run is killed, and parameters handed to the vendor's data argument are
 * json-encoded into the body of a GET, so Discord receives none of them and returns
 * the first page forever. Both look like a working sweep from outside.
 *
 * run() is the only public entry, and the only one Cron\Reconcile calls. The
 * assertions are on what a caller can see: the role sets Discord is actually sent,
 * and the members queued for correction. Never on a path string, a cursor value, a
 * query, or the wording of a log line — including in the scenarios that read a report
 * at all, which do it in two ways, neither of them prose. The three throttle scenarios
 * compare two reports for difference and have their own section below. The #242
 * scenarios match role IDS inside a report, or count reports; ids are data, so a
 * reword keeps them and dropping them fails.
 *
 * ---------------------------------------------------------------------------
 * On the SQLite database below — READ THIS BEFORE "FIXING" A QUERY
 * ---------------------------------------------------------------------------
 * FakeDb runs the sweep's real SQL against in-memory SQLite, so the test couples to
 * what the queries MEAN rather than to their text, their bound-parameter count, or
 * which helper issued them. Rewriting a query freely is the point.
 *
 * It is test infrastructure. It is NOT a promise that this addon runs on SQLite.
 * Production targets MySQL and its SQL must never be bent to keep this harness
 * happy: if a query needs MySQL-specific syntax, drop it from the harness, move its
 * coverage to the dev stack, and say so — do not rewrite the query.
 *
 * A query SQLite cannot execute is therefore reported as harness drift, in those
 * words, rather than as a failing assertion about the sweep. The fake models MySQL;
 * it does not certify against it. Collation and type-affinity differences are the
 * dev stack's business.
 *
 * ---------------------------------------------------------------------------
 * On reading a report at all — READ THIS BEFORE ASSERTING ON A LOG LINE
 * ---------------------------------------------------------------------------
 * A throttled read and a refused one differ in nothing a caller can see except the
 * report the sweep writes, so those three scenarios have to look at one. They never
 * read it for its wording: each runs the same fixture twice, changing one input —
 * which way the call fails — and asserts the two reports DIFFER. Rewording either cause keeps them green; only collapsing the two
 * back into one report turns them red.
 *
 * That works only while the two runs are otherwise identical, so each scenario first
 * asserts they are: same number of reports, same corrections queued, same patches
 * attempted. Without that pin an incidental difference — a member count, a guild id,
 * a different closing sentence — would satisfy the inequality on its own and the
 * scenario would pass with the causes still collapsed. The member-page pair throttles
 * on call ONE for the same reason: nothing is read either way, so there is no count
 * and no partial-page trailer left free to vary. Do not "improve" it to call two.
 *
 * Earlier versions of this file covered none of this, because the sweep caught
 * `RateLimitedException` and nothing could raise it — a stub that threw would have
 * proved the catch ran and nothing about production. The stub below instead models
 * what a real 429 was measured to do, and that measurement is the authority for it:
 * docs/verification/reconciliation-sweep-guards.md. Change the stub only against that
 * document, or these scenarios go back to testing a fiction.
 *
 * ---------------------------------------------------------------------------
 * What this file deliberately does not cover
 * ---------------------------------------------------------------------------
 * Whether a refused strip is reported as a refusal rather than as a strip. Its only
 * observable is the wording of a log line, and an assertion on prose reports that
 * someone edited a sentence. It is confirmed on the live guild beside the booster
 * check — see the addon README's pre-release list.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/ReconciliationSweepTest.php
 */

// ---------------------------------------------------------------------------
// Stub XenForo, NF/Discord and SV/StandardLib. Only what the sweep touches.
// ---------------------------------------------------------------------------

namespace NF\Discord {
    class RateLimitedException extends \Exception
    {
    }

    class Helper
    {
        public static function log(string $message, array $context = []): void
        {
        }
    }

    /**
     * Models the two Discord endpoints the sweep uses.
     *
     * The member list behaves the way Discord's does: it returns the members whose id
     * sorts numerically after the `after` cursor, up to `limit`, and a short page is
     * the end. The cursor is read out of the PATH's query string, because that is
     * where Discord reads it — a sweep that passed it through the vendor's data
     * argument would send a GET whose parameters never arrive, and this stub answers
     * such a request exactly as Discord would: the first page, every time.
     */
    class Api
    {
        /** @var array<string, array> every member of the guild, keyed by Discord id */
        public static array $guildMembers = [];
        /** @var array|null what getRoles() returns; null makes the request fail */
        public static ?array $guildRoles = [];
        /** @var array<int, array{0: string, 1: array}> every patch made, in order */
        public static array $patches = [];
        /** @var array<string, mixed>|null the integration's credentials */
        public static $configuration = ['token' => 'stub'];
        /** @var int|null 1-based member-list call to answer unreadably, if any */
        public static ?int $unreadableOnCall = null;
        /** @var int member-list calls made so far */
        public static int $memberCalls = 0;
        /** @var int|null 1-based member-list call to answer with a rate limit, if any */
        public static ?int $rateLimitedOnCall = null;
        /** @var bool whether the roles read is rate-limited */
        public static bool $rateLimitedRoles = false;
        /** @var bool whether every role patch is rate-limited */
        public static bool $rateLimitedPatches = false;
        /** @var bool whether Discord refuses every role patch */
        public static bool $patchesRefused = false;
        /** @var int|null what getRetryAfter() reports about the call just made */
        public static ?int $retryAfter = null;
        /** @var string[] the roles the bot itself holds on the guild */
        public static array $botRoleIds = [];
        /** @var bool whether the bot's own guild member record is unreadable */
        public static bool $currentMemberUnreadable = false;

        public static function getDiscordConfiguration()
        {
            return self::$configuration;
        }

        public static function factory(?string $guildId = null, bool $throw = true): ?Api
        {
            if ($guildId === null && self::$configuration === null) {
                return null;
            }

            return new self();
        }

        /**
         * The retry-after the vendor records about the call just made, which is the
         * only way a throttled read is distinguishable from a refused one. Both hand
         * back the same `false`.
         */
        public function getRetryAfter(): ?int
        {
            return self::$retryAfter;
        }

        public function getRoles(bool $cache = false): array
        {
            // A throttled roles read hands back exactly what an empty guild would.
            // getRoles() substitutes [] for a failed request — `$this->get(...) ?: []`
            // — so the rate limit is invisible in the return value.
            if (self::$rateLimitedRoles) {
                self::$retryAfter = \XF::$time + 60;

                return [];
            }
            self::$retryAfter = null;

            return self::$guildRoles ?? [];
        }

        /**
         * The bot's own guild member record, which is where its role positions — and
         * so everything it can reach — come from. The vendor spends two calls on this
         * because bots may not read 'users/@me/guilds/{id}/member'; what matters here
         * is only that a failure comes back as the same falsy answer every other
         * failure does.
         */
        public function getCurrentGuildMember(?string $guildId = null)
        {
            if (self::$currentMemberUnreadable) {
                return false;
            }

            return ['user' => ['id' => '1244685263242788928'], 'roles' => self::$botRoleIds];
        }

        public function get(string $path = '', array $data = [], array $options = [])
        {
            self::$memberCalls++;

            // A rate-limited read, as the vendor actually delivers one. Discord answers
            // 429, Guzzle throws ClientException, and request() catches it, records the
            // retry-after and returns false — the same false a refused endpoint gives.
            // Measured against a real XenForo with a mocked transport; the method and
            // the numbers are in docs/verification/reconciliation-sweep-guards.md.
            if (self::$rateLimitedOnCall === self::$memberCalls) {
                self::$retryAfter = \XF::$time + 60;

                return false;
            }

            // Every other answer clears it, because the vendor records the retry-after
            // per call rather than latching it.
            self::$retryAfter = null;

            // What the vendor hands back when the endpoint refuses — a revoked
            // GUILD_MEMBERS intent being the way that happens in practice.
            if (self::$unreadableOnCall === self::$memberCalls) {
                return false;
            }

            $query = [];
            $queryString = strstr($path, '?');
            if ($queryString !== false) {
                parse_str(substr($queryString, 1), $query);
            }

            // Discord's own default when the caller names no limit — which is what it
            // receives when the parameters were encoded into a GET body instead.
            $limit = isset($query['limit']) ? (int) $query['limit'] : 1;
            $after = $query['after'] ?? '0';

            // Snowflakes are unbounded decimals: compare by length, then by digits.
            $ascending = static fn (string $a, string $b): int =>
                strlen($a) <=> strlen($b) ?: strcmp($a, $b);

            $ids = array_map('strval', array_keys(self::$guildMembers));
            usort($ids, $ascending);

            $page = [];
            foreach ($ids as $id) {
                if ($ascending($id, (string) $after) <= 0) {
                    continue;
                }
                $page[] = self::$guildMembers[$id];
                if (count($page) >= $limit) {
                    break;
                }
            }

            return $page;
        }

        public function patchGuildMemberRoles(string $userId, array $groups)
        {
            self::$patches[] = [$userId, array_values($groups)];

            if (self::$rateLimitedPatches) {
                self::$retryAfter = \XF::$time + 60;

                return false;
            }
            self::$retryAfter = null;

            return self::$patchesRefused ? false : true;
        }
    }
}

namespace SV\StandardLib {
    class Helper
    {
        /** @var array<string, object> repository class name => stub */
        public static array $repositories = [];

        public static function repository(string $class): object
        {
            return self::$repositories[$class];
        }
    }
}

namespace Cav7\DiscordSyncPatch\Tests {

    /**
     * Runs the sweep's real SQL. See the header: models MySQL, does not certify it.
     */
    class FakeDb
    {
        protected \PDO $pdo;

        public function __construct()
        {
            $this->pdo = new \PDO('sqlite::memory:');
            $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            // Only the columns the sweep reads. Not a mirror of XenForo's schema.
            $this->pdo->exec('
                CREATE TABLE xf_user (
                    user_id INTEGER PRIMARY KEY,
                    user_group_id INTEGER,
                    secondary_group_ids TEXT
                );
                CREATE TABLE xf_user_group (
                    user_group_id INTEGER PRIMARY KEY,
                    nfd_server_group_ids TEXT
                );
                CREATE TABLE xf_user_connected_account (
                    user_id INTEGER,
                    provider TEXT,
                    provider_key TEXT
                );
                CREATE TABLE xf_nf_discord_sync_log (
                    user_id INTEGER,
                    guild_id TEXT,
                    user_group_ids TEXT,
                    active INTEGER,
                    user_error_phrase TEXT
                );
                CREATE TABLE xf_nf_discord_queue (
                    queue_id INTEGER PRIMARY KEY AUTOINCREMENT,
                    class_name TEXT,
                    user_id INTEGER,
                    queue_date INTEGER
                );
            ');
        }

        public function insert(string $table, array $rows): void
        {
            foreach ($rows as $row) {
                $columns = implode(', ', array_keys($row));
                $slots = implode(', ', array_fill(0, count($row), '?'));
                $this->pdo->prepare("INSERT INTO $table ($columns) VALUES ($slots)")
                    ->execute(array_values($row));
            }
        }

        public function fetchAll($sql, $params = []): array
        {
            return $this->run($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);
        }

        public function fetchPairs($sql, $params = []): array
        {
            $pairs = [];
            foreach ($this->run($sql, $params)->fetchAll(\PDO::FETCH_NUM) as $row) {
                $pairs[$row[0]] = $row[1];
            }

            return $pairs;
        }

        public function fetchOne($sql, $params = [])
        {
            $row = $this->run($sql, $params)->fetch(\PDO::FETCH_NUM);

            return $row === false ? null : $row[0];
        }

        protected function run(string $sql, array $params): \PDOStatement
        {
            try {
                $statement = $this->pdo->prepare($sql);
                $statement->execute(array_values($params));

                return $statement;
            } catch (\PDOException $e) {
                // Not a fact about the sweep. The harness models MySQL with SQLite, and
                // a query it cannot execute means the model has fallen behind the code.
                // Do not rewrite the query to suit this file — see the header.
                echo "HARNESS DRIFT: the fake database could not execute the sweep's SQL.\n"
                    . "  It models MySQL with SQLite and has fallen behind production.\n"
                    . "  Move this query's coverage to the dev stack; do not rewrite the query.\n"
                    . '  ' . $e->getMessage() . "\n  " . trim(preg_replace('/\s+/', ' ', $sql)) . "\n";
                exit(2);
            }
        }
    }

    class FakeEntityManager
    {
        public function findByIds(string $class, array $ids): array
        {
            $users = [];
            foreach ($ids as $id) {
                $users[$id] = new FakeUser((int) $id);
            }

            return $users;
        }
    }

    class FakeUser
    {
        public int $user_id;

        public function __construct(int $userId)
        {
            $this->user_id = $userId;
        }
    }

    class FakeServerRepository
    {
        /** @var array<int, string> server id => guild id */
        public static array $serverMap = [];
        public static int $defaultServerId = 1;

        public function getServerMap(): array
        {
            return self::$serverMap;
        }

        public function getDefaultServerId(): int
        {
            return self::$defaultServerId;
        }
    }

    class FakeSyncRepository
    {
        /** @var array<int, array> every fan-out asked for, in order */
        public static array $queued = [];

        public function queueSyncJobsForUser(
            $user,
            bool $asNew = false,
            bool $skipLoggingChanges = false,
            bool $asManualJoin = false,
            array $serverIds = []
        ): void
        {
            self::$queued[] = [
                'user_id' => $user->user_id,
                'skip_logging_changes' => $skipLoggingChanges,
                'server_ids' => $serverIds,
            ];
        }
    }
}

namespace {

    use Cav7\DiscordSyncPatch\Tests\FakeDb;
    use Cav7\DiscordSyncPatch\Tests\FakeEntityManager;

    class XF
    {
        public static $time = 1700000000;
        /** @var FakeDb */
        public static $db;
        public static function db()
        {
            return self::$db;
        }

        public static function em()
        {
            return new FakeEntityManager();
        }

        /** @var string[] what the sweep reported this run */
        public static array $errors = [];

        public static function logError($message): void
        {
            // Collected, but never read for its wording. The two throttle scenarios
            // compare one run's report against another's and assert only that they
            // differ, so rewording either cause keeps them green — see those sections.
            self::$errors[] = $message;
        }
    }
}

namespace Cav7\DiscordSyncPatch\Tests {

    require __DIR__ . '/../MemberCursor.php';
    require __DIR__ . '/../RoleReach.php';
    require __DIR__ . '/../RoleScope.php';
    require __DIR__ . '/../RoleDivergence.php';
    require __DIR__ . '/../ManagedRoleStrip.php';
    require __DIR__ . '/../SyncRecordStaleness.php';
    require __DIR__ . '/../ReconciliationSweep.php';

    use Cav7\DiscordSyncPatch\ReconciliationSweep;
    use NF\Discord\Api;

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

    const GUILD_ID = '100000000000000001';
    const SERVER_ID = 1;
    const MANAGED_ROLE = '111111111111111111';
    const UNMANAGED_ROLE = '888888888888888888';
    const PRESERVED_ROLE = '999999999999999999';

    // The bot's own role, and one sitting above it. Positions are what decide reach,
    // so every role in the fixture carries one and the bot holds BOT_ROLE — a bot
    // holding nothing reaches nothing, and every role on the guild would be immovable.
    const BOT_ROLE = '222222222222222222';
    const OUT_OF_REACH_ROLE = '333333333333333333';

    /**
     * A guild member as Discord reports one.
     */
    function member(string $discordId, array $roleIds): array
    {
        return ['user' => ['id' => $discordId], 'roles' => $roleIds];
    }

    /**
     * A guild Discord serves over exactly two pages: the first full to the limit, the
     * second short enough to end the walk. Three of its members are unlinked holders,
     * one of them only reachable on the second page.
     *
     * The ids are this guild's real shape — 18- and 19-digit snowflakes mixed — so the
     * id that sorts last as text is not the numerically greatest one.
     *
     * @return array<string, array>
     */
    function guildOfTwoPages(): array
    {
        $members = [];

        // Sorts last as text, but is numerically the smallest id in the guild.
        $members['581229370245644289'] = member('581229370245644289', [MANAGED_ROLE, UNMANAGED_ROLE]);

        for ($i = 0; $i < ReconciliationSweep::MEMBER_PAGE_LIMIT - 1; $i++) {
            $id = (string) (1384909947564724000 + $i);
            $members[$id] = member($id, $id === '1384909947564724500'
                ? [MANAGED_ROLE]
                : [UNMANAGED_ROLE]);
        }

        $members['1384909947564725100'] = member('1384909947564725100', [UNMANAGED_ROLE]);
        $members['1384909947564725101'] = member('1384909947564725101', [MANAGED_ROLE, PRESERVED_ROLE]);
        $members['1384909947564725102'] = member('1384909947564725102', [UNMANAGED_ROLE]);

        return $members;
    }

    /**
     * One linked member whose sync record no longer describes their groups, so the
     * forum-side half of the run has something to correct whatever Discord does.
     *
     * The throttle scenarios compare two runs' outcomes for equality before comparing
     * their reports for difference, and two empty outcomes would pin nothing.
     */
    function staleMember(FakeDb $db, int $userId, string $discordId): void
    {
        $db->insert('xf_user', [
            ['user_id' => $userId, 'user_group_id' => 2, 'secondary_group_ids' => '3'],
        ]);
        $db->insert('xf_user_connected_account', [
            ['user_id' => $userId, 'provider' => 'nfDiscord', 'provider_key' => $discordId],
        ]);
        $db->insert('xf_nf_discord_sync_log', [
            ['user_id' => $userId, 'guild_id' => GUILD_ID, 'user_group_ids' => '2', 'active' => 1, 'user_error_phrase' => ''],
        ]);
    }

    /**
     * The members queued for correction, as a set. Sorted, because the order the sweep
     * happens to walk them in is not a behaviour anything should depend on.
     *
     * @return int[]
     */
    function queuedUserIds(): array
    {
        $ids = array_map(static fn (array $q): int => $q['user_id'], FakeSyncRepository::$queued);
        sort($ids);

        return $ids;
    }

    /**
     * The members Discord was asked to patch, as a set. Every call is recorded whether
     * Discord accepted it, refused it or throttled it, so this is what was attempted
     * rather than what succeeded.
     *
     * @return string[]
     */
    function patchedDiscordIds(): array
    {
        $ids = array_map(static fn (array $patch): string => $patch[0], Api::$patches);
        sort($ids, SORT_STRING);

        return $ids;
    }

    /**
     * Resets every stub and returns the database, ready for fixtures.
     */
    function freshStack(): FakeDb
    {
        Api::$patches = [];
        Api::$configuration = ['token' => 'stub'];
        Api::$guildRoles = [
            ['id' => GUILD_ID, 'position' => 0, 'managed' => false, 'tags' => []],
            ['id' => UNMANAGED_ROLE, 'position' => 4, 'managed' => false, 'tags' => []],
            ['id' => MANAGED_ROLE, 'position' => 5, 'managed' => false, 'tags' => []],
            ['id' => PRESERVED_ROLE, 'position' => 6, 'managed' => true, 'tags' => ['premium_subscriber' => null]],
            ['id' => BOT_ROLE, 'position' => 10, 'managed' => true, 'tags' => []],
            ['id' => OUT_OF_REACH_ROLE, 'position' => 20, 'managed' => false, 'tags' => []],
        ];
        Api::$botRoleIds = [BOT_ROLE];
        Api::$currentMemberUnreadable = false;
        Api::$guildMembers = [];
        Api::$unreadableOnCall = null;
        Api::$memberCalls = 0;
        Api::$rateLimitedOnCall = null;
        Api::$rateLimitedRoles = false;
        Api::$rateLimitedPatches = false;
        Api::$patchesRefused = false;
        Api::$retryAfter = null;

        \XF::$errors = [];

        FakeServerRepository::$serverMap = [SERVER_ID => GUILD_ID];
        FakeServerRepository::$defaultServerId = SERVER_ID;
        FakeSyncRepository::$queued = [];

        \SV\StandardLib\Helper::$repositories = [
            'NF\Discord\Repository\Server' => new FakeServerRepository(),
            'NF\Discord\Repository\Sync' => new FakeSyncRepository(),
        ];

        \XF::$db = new FakeDb();

        // One user group, granting two roles on this guild in the vendor's stored
        // "<serverId>:<roleId>" LIST_COMMA form. Without a managed role the sweep has
        // nothing to compare and never reaches Discord.
        //
        // The group grants the preserved role deliberately. That is the only way the
        // booster carve-out decides anything: a role no group grants is already
        // outside the managed set, so a test whose groups do not grant it passes
        // whether the carve-out is there or not.
        \XF::$db->insert('xf_user_group', [
            [
                'user_group_id' => 2,
                'nfd_server_group_ids' => SERVER_ID . ':' . MANAGED_ROLE
                    . ',' . SERVER_ID . ':' . PRESERVED_ROLE,
            ],
        ]);

        return \XF::$db;
    }

    // -----------------------------------------------------------------------
    // The walk through a guild that Discord serves over more than one page.
    // -----------------------------------------------------------------------
    //
    // Every unlinked holder must be reached, and reached once. A cursor that stops
    // advancing re-reads the first page until the sweep's own page bound stops it,
    // patching those members a hundred times over and never seeing the rest; a cursor
    // compared as text picks an id Discord has already passed and does the same. The
    // ids below are this guild's real shape — 18- and 19-digit snowflakes mixed — so
    // the id that sorts last as text is not the greatest.

    freshStack();
    Api::$guildMembers = guildOfTwoPages();

    (new ReconciliationSweep())->run();

    $patchedIds = array_map(static fn (array $patch): string => $patch[0], Api::$patches);
    $expectedIds = ['581229370245644289', '1384909947564724500', '1384909947564725101'];
    sort($patchedIds, SORT_STRING);
    sort($expectedIds, SORT_STRING);

    check(
        'every unlinked holder in the guild is reached, across every page',
        $patchedIds === $expectedIds,
        'patched ' . count($patchedIds) . ': ' . implode(', ', array_slice($patchedIds, 0, 5))
            . ' — a cursor that does not advance never reaches the last page'
    );

    check(
        'no member is patched twice',
        count($patchedIds) === count(array_unique($patchedIds)),
        'a cursor that does not advance re-reads the same page until the walk gives up'
    );

    // -----------------------------------------------------------------------
    // What an unlinked holder is left holding.
    // -----------------------------------------------------------------------
    //
    // patchGuildMemberRoles replaces the member's whole role set, so what goes out is
    // what they keep. Two roles must survive it: the ones no group grants, and the
    // ones Discord manages itself — a call that drops a managed role is refused
    // wholesale and the vendor swallows the refusal, so a booster's strip would be
    // lost every quarter-hour with nothing recorded. A member with no managed role
    // must produce no call at all, or the sweep rewrites thousands of members to
    // exactly what they already hold.

    freshStack();
    Api::$guildMembers = [
        '1384909947564730001' => member('1384909947564730001', [MANAGED_ROLE, UNMANAGED_ROLE]),
        '1384909947564730002' => member('1384909947564730002', [MANAGED_ROLE, PRESERVED_ROLE]),
        '1384909947564730003' => member('1384909947564730003', [UNMANAGED_ROLE]),
        '1384909947564730004' => member('1384909947564730004', [PRESERVED_ROLE]),
        '1384909947564730005' => member('1384909947564730005', [MANAGED_ROLE]),
    ];

    (new ReconciliationSweep())->run();

    $sent = [];
    foreach (Api::$patches as [$discordId, $roleIds]) {
        sort($roleIds, SORT_STRING);
        $sent[$discordId] = $roleIds;
    }
    ksort($sent, SORT_STRING);

    check(
        'the managed role comes off and the unmanaged one stays',
        ($sent['1384909947564730001'] ?? null) === [UNMANAGED_ROLE],
        'sent ' . json_encode($sent['1384909947564730001'] ?? null)
    );

    check(
        'a role Discord manages itself survives the strip',
        ($sent['1384909947564730002'] ?? null) === [PRESERVED_ROLE],
        'omitting it makes Discord refuse the whole call, and the vendor swallows that'
    );

    check(
        'a member whose every role is managed is left holding nothing',
        ($sent['1384909947564730005'] ?? null) === [],
        'an empty set is a real strip; it is not the same as making no call'
    );

    check(
        'no call is made for a member holding nothing the forum manages',
        !isset($sent['1384909947564730003']) && !isset($sent['1384909947564730004']),
        'patched: ' . implode(', ', array_keys($sent))
            . ' — most of the guild holds no managed role, and patching them spends the'
            . ' whole rate budget writing back what was already there'
    );

    // -----------------------------------------------------------------------
    // A role the bot cannot reach survives the strip.
    // -----------------------------------------------------------------------
    //
    // Issue #242. A bot may only add or remove roles below its own highest one, and
    // patchGuildMemberRoles replaces the whole set — so a set that omits a role sitting
    // above the bot is asking Discord to REMOVE it, which Discord refuses whole. The
    // strip then moves nothing, the member is still holding what they were, and the
    // next run tries the identical call.
    //
    // Keeping that role is what makes the rest of the write land: measured on a real
    // guild, a member whose top role is above the bot takes a 200 as long as that role
    // stays in the set. So the reachable managed role must still come off in the same
    // call. Asserting only that the out-of-reach role survives would pass just as well
    // against a sweep that had given up on the member entirely.

    freshStack();

    // A second group makes the out-of-reach role a managed one, with nobody in it:
    // managed is a property of the configuration, never of a member.
    \XF::$db->insert('xf_user_group', [
        ['user_group_id' => 4, 'nfd_server_group_ids' => SERVER_ID . ':' . OUT_OF_REACH_ROLE],
    ]);

    Api::$guildMembers = [
        '1384909947564740001' => member(
            '1384909947564740001',
            [MANAGED_ROLE, OUT_OF_REACH_ROLE, UNMANAGED_ROLE]
        ),
    ];

    (new ReconciliationSweep())->run();

    $sentToHolder = Api::$patches[0][1] ?? null;
    if (is_array($sentToHolder)) {
        sort($sentToHolder, SORT_STRING);
    }
    $keptByHolder = [OUT_OF_REACH_ROLE, UNMANAGED_ROLE];
    sort($keptByHolder, SORT_STRING);

    check(
        'a role above the bot survives the strip while the reachable managed role comes off',
        $sentToHolder === $keptByHolder,
        'sent ' . json_encode($sentToHolder) . ' — dropping the out-of-reach role makes'
            . ' Discord refuse the whole call, so the reachable role is not stripped either'
    );

    // -----------------------------------------------------------------------
    // A disagreement no correction could carry out is not a divergence.
    // -----------------------------------------------------------------------
    //
    // Issue #242's other half. A group granting a role above the bot leaves every
    // member of that group permanently short of it: the sync asks Discord to add a role
    // it will not add, the write is refused whole, the record is never updated, and the
    // next run selects them again. Queueing a correction that cannot land is the loop.
    //
    // The control member is what makes this mean anything. A sweep that had stopped
    // queueing anybody at all would satisfy the first check on its own.

    freshStack();

    \XF::$db->insert('xf_user_group', [
        ['user_group_id' => 4, 'nfd_server_group_ids' => SERVER_ID . ':' . OUT_OF_REACH_ROLE],
        ['user_group_id' => 5, 'nfd_server_group_ids' => SERVER_ID . ':' . MANAGED_ROLE],
    ]);

    // Both records agree with their member's groups, are active and carry no error, so
    // the forum-side scan leaves both alone and what happens next is Discord-side only.
    \XF::$db->insert('xf_user', [
        ['user_id' => 501, 'user_group_id' => 4, 'secondary_group_ids' => ''],
        ['user_id' => 502, 'user_group_id' => 5, 'secondary_group_ids' => ''],
    ]);
    \XF::$db->insert('xf_user_connected_account', [
        ['user_id' => 501, 'provider' => 'nfDiscord', 'provider_key' => '1384909947564750001'],
        ['user_id' => 502, 'provider' => 'nfDiscord', 'provider_key' => '1384909947564750002'],
    ]);
    \XF::$db->insert('xf_nf_discord_sync_log', [
        ['user_id' => 501, 'guild_id' => GUILD_ID, 'user_group_ids' => '4', 'active' => 1, 'user_error_phrase' => ''],
        ['user_id' => 502, 'guild_id' => GUILD_ID, 'user_group_ids' => '5', 'active' => 1, 'user_error_phrase' => ''],
    ]);

    // Neither holds what their group grants. For 501 that role is above the bot, so
    // nothing can be done about it; for 502 it is reachable and must still be fixed.
    Api::$guildMembers = [
        '1384909947564750001' => member('1384909947564750001', []),
        '1384909947564750002' => member('1384909947564750002', []),
    ];

    (new ReconciliationSweep())->run();

    check(
        'a member short only of a role above the bot is not queued',
        !in_array(501, queuedUserIds(), true),
        'queued ' . json_encode(queuedUserIds()) . ' — the correction cannot land, so'
            . ' queueing it re-queues them every quarter-hour for good'
    );

    check(
        'a member short of a reachable role is still queued',
        in_array(502, queuedUserIds(), true),
        'queued ' . json_encode(queuedUserIds()) . ' — without this the check above'
            . ' passes against a sweep that queues nobody'
    );

    // -----------------------------------------------------------------------
    // The bot's own position cannot be read.
    // -----------------------------------------------------------------------
    //
    // This fails OPEN, unlike the guild-roles read beside it, and the asymmetry is the
    // point. A wrong-empty preserved set breaks writes that would otherwise have
    // worked — every booster gets a set missing their booster role. A wrong-empty
    // out-of-reach set can only fail for the members who were already failing, so
    // abandoning the guild over it would trade everyone's correction for a subset's.
    //
    // Run twice against a fixture holding no out-of-reach role at all, so the two runs
    // must agree on every outcome and differ only in what was reported. Equality is the
    // fail-open assertion: a fail-closed sweep patches nothing and queues nothing.

    $reconciledWith = static function (bool $unreadable): array {
        freshStack();
        Api::$currentMemberUnreadable = $unreadable;

        // Divergent on the DISCORD side only — their record agrees with their groups,
        // so the forum-side scan leaves them alone. A member the forum-side half picks
        // up would be queued whether this failed open or closed, and would pin nothing.
        \XF::$db->insert('xf_user_group', [
            ['user_group_id' => 5, 'nfd_server_group_ids' => SERVER_ID . ':' . MANAGED_ROLE],
        ]);
        \XF::$db->insert('xf_user', [
            ['user_id' => 601, 'user_group_id' => 5, 'secondary_group_ids' => ''],
        ]);
        \XF::$db->insert('xf_user_connected_account', [
            ['user_id' => 601, 'provider' => 'nfDiscord', 'provider_key' => '1384909947564760001'],
        ]);
        \XF::$db->insert('xf_nf_discord_sync_log', [
            ['user_id' => 601, 'guild_id' => GUILD_ID, 'user_group_ids' => '5', 'active' => 1, 'user_error_phrase' => ''],
        ]);

        Api::$guildMembers = [
            '1384909947564760001' => member('1384909947564760001', []),
            '1384909947564760002' => member('1384909947564760002', [MANAGED_ROLE, UNMANAGED_ROLE]),
        ];

        (new ReconciliationSweep())->run();

        return [
            'patches' => Api::$patches,
            'queued' => queuedUserIds(),
            'reports' => count(\XF::$errors),
        ];
    };

    $readable = $reconciledWith(false);
    $unreadable = $reconciledWith(true);

    check(
        'the guild is still reconciled when the bot cannot read its own position',
        $unreadable['patches'] === $readable['patches'] && $unreadable['patches'] !== [],
        'patched ' . json_encode($unreadable['patches']) . ' against ' . json_encode($readable['patches'])
            . ' — failing closed here abandons every member over a read that concerns a few'
    );

    check(
        'the correction is still queued when the bot cannot read its own position',
        $unreadable['queued'] === $readable['queued'] && $unreadable['queued'] !== [],
        'queued ' . json_encode($unreadable['queued']) . ' against ' . json_encode($readable['queued'])
    );

    check(
        'a run that could not read its own position says so',
        $unreadable['reports'] === $readable['reports'] + 1,
        $unreadable['reports'] . ' report(s) against ' . $readable['reports']
            . ' — a run that silently reverted to the old behaviour is indistinguishable'
            . ' from a healthy one, which is how this bug lasted'
    );

    // -----------------------------------------------------------------------
    // What the run tells an admin about roles it cannot reach.
    // -----------------------------------------------------------------------
    //
    // Excluding these roles silently would close #242 on every count — no doomed call,
    // no permanent divergence, no repeated log — and leave the board quietly not
    // enforcing a role a group explicitly grants, with every run reporting clean. So
    // the run names them, because the ids are what the remedy acts on.
    //
    // The assertion is on the role IDS, which are data. It is not on the sentence
    // around them: rewording keeps the ids, and dropping them fails. Two roles are out
    // of reach here so that "every" is a claim — naming only the first goes red — and
    // the count pins the aggregation at one line per guild per run rather than one per
    // role, which is what makes the repetition affordable.

    const SECOND_OUT_OF_REACH_ROLE = '444444444444444444';

    $reportsNamingOutOfReach = static function (): array {
        return array_values(array_filter(
            \XF::$errors,
            static fn (string $report): bool => str_contains($report, OUT_OF_REACH_ROLE)
                || str_contains($report, SECOND_OUT_OF_REACH_ROLE)
        ));
    };

    freshStack();
    Api::$guildRoles[] = ['id' => SECOND_OUT_OF_REACH_ROLE, 'position' => 21, 'managed' => false, 'tags' => []];
    \XF::$db->insert('xf_user_group', [
        [
            'user_group_id' => 4,
            'nfd_server_group_ids' => SERVER_ID . ':' . OUT_OF_REACH_ROLE
                . ',' . SERVER_ID . ':' . SECOND_OUT_OF_REACH_ROLE,
        ],
    ]);

    (new ReconciliationSweep())->run();

    $outOfReachReports = $reportsNamingOutOfReach();

    check(
        'the run names every managed role it cannot reach',
        count($outOfReachReports) === 1
            && str_contains($outOfReachReports[0], OUT_OF_REACH_ROLE)
            && str_contains($outOfReachReports[0], SECOND_OUT_OF_REACH_ROLE),
        json_encode($outOfReachReports) . ' — an admin cannot move a role the report'
            . ' does not name, and one line per guild is what makes saying it every run affordable'
    );

    // The same guild with the bot above everything. Nothing is out of reach, so there
    // is nothing to say — a report printed here would be describing no event.
    freshStack();
    Api::$botRoleIds = [BOT_ROLE];
    Api::$guildRoles[] = ['id' => SECOND_OUT_OF_REACH_ROLE, 'position' => 1, 'managed' => false, 'tags' => []];
    \XF::$db->insert('xf_user_group', [
        ['user_group_id' => 4, 'nfd_server_group_ids' => SERVER_ID . ':' . SECOND_OUT_OF_REACH_ROLE],
    ]);

    (new ReconciliationSweep())->run();

    // Asserted on the total, not on reports naming a role. This fixture has nothing
    // else to report — the guild is empty, so no strip is attempted, and both reads
    // succeed — so any line at all is a spurious one. Filtering by role id would let
    // through the shape most likely to be written by accident: a report that fires
    // unconditionally and names an empty list.
    check(
        'a guild with nothing out of reach is not reported at all',
        \XF::$errors === [],
        json_encode(\XF::$errors) . ' — a line every quarter-hour about a guild that is'
            . ' fine is the noise this change exists to remove'
    );

    // -----------------------------------------------------------------------
    // Which members the forum-side scan picks up.
    // -----------------------------------------------------------------------
    //
    // Three things make a sync record stale, and copying the vendor cron's
    // `sync_log.active = 1` filter excludes two of them: setInvalid() clears the
    // active flag and writes the error phrase together, so an errored row IS an
    // inactive row and a scan that skips inactive rows skips exactly the members it
    // was meant to retry. Two populations stay out: a member with no connected
    // account has unlinked, and one with no sync-log row at all was never synced here
    // — the join path's business, not reconciliation's.

    $db = freshStack();
    Api::$guildMembers = [];

    $db->insert('xf_user', [
        ['user_id' => 1, 'user_group_id' => 2, 'secondary_group_ids' => ''],
        ['user_id' => 2, 'user_group_id' => 2, 'secondary_group_ids' => '3'],
        ['user_id' => 3, 'user_group_id' => 2, 'secondary_group_ids' => ''],
        ['user_id' => 4, 'user_group_id' => 2, 'secondary_group_ids' => ''],
        ['user_id' => 5, 'user_group_id' => 2, 'secondary_group_ids' => '3'],
        ['user_id' => 6, 'user_group_id' => 2, 'secondary_group_ids' => '3'],
    ]);

    // Everyone but user 5, who has unlinked.
    $db->insert('xf_user_connected_account', [
        ['user_id' => 1, 'provider' => 'nfDiscord', 'provider_key' => '1384909947564740001'],
        ['user_id' => 2, 'provider' => 'nfDiscord', 'provider_key' => '1384909947564740002'],
        ['user_id' => 3, 'provider' => 'nfDiscord', 'provider_key' => '1384909947564740003'],
        ['user_id' => 4, 'provider' => 'nfDiscord', 'provider_key' => '1384909947564740004'],
        ['user_id' => 6, 'provider' => 'nfDiscord', 'provider_key' => '1384909947564740006'],
    ]);

    // Everyone but user 6, who linked and never synced.
    $db->insert('xf_nf_discord_sync_log', [
        // Agrees on every count.
        ['user_id' => 1, 'guild_id' => GUILD_ID, 'user_group_ids' => '2', 'active' => 1, 'user_error_phrase' => ''],
        // Gained group 3 since the last sync.
        ['user_id' => 2, 'guild_id' => GUILD_ID, 'user_group_ids' => '2', 'active' => 1, 'user_error_phrase' => ''],
        // Carries an error phrase.
        ['user_id' => 3, 'guild_id' => GUILD_ID, 'user_group_ids' => '2', 'active' => 1, 'user_error_phrase' => 'nf_discord_error'],
        // Left inactive by a sync that did not finish.
        ['user_id' => 4, 'guild_id' => GUILD_ID, 'user_group_ids' => '2', 'active' => 0, 'user_error_phrase' => ''],
        // Would be stale, but this member has no connected account.
        ['user_id' => 5, 'guild_id' => GUILD_ID, 'user_group_ids' => '2', 'active' => 1, 'user_error_phrase' => ''],
    ]);

    (new ReconciliationSweep())->run();

    $queuedIds = array_map(
        static fn (array $q): int => $q['user_id'],
        FakeSyncRepository::$queued
    );
    sort($queuedIds);

    check(
        'a member whose groups moved since the last sync is corrected',
        in_array(2, $queuedIds, true),
        'the recorded group set no longer describes their groups'
    );

    check(
        'a member whose last sync recorded an error is corrected',
        in_array(3, $queuedIds, true),
        'an errored row is also an inactive row; a scan filtering on active = 1 drops it'
    );

    check(
        'a member whose sync record was left inactive is corrected',
        in_array(4, $queuedIds, true),
        'the vendor left the row inactive, which is what the live board actually shows'
    );

    check(
        'a member whose record still agrees is left alone',
        !in_array(1, $queuedIds, true),
        're-correcting members who agree spends the whole rate budget every quarter-hour'
    );

    check(
        'a member with no connected account and no sync-log row are both left out',
        !in_array(5, $queuedIds, true) && !in_array(6, $queuedIds, true),
        'queued ' . implode(', ', $queuedIds)
            . ' — one has unlinked, the other linked and was never synced'
    );

    // -----------------------------------------------------------------------
    // A walk that ends somewhere other than the end of the guild.
    // -----------------------------------------------------------------------
    //
    // A partial read is not an error: the members it did reach are still reconciled
    // and the rest wait for the next run. What it must never be is silent, or fatal
    // to the half of the sweep that never touches Discord.

    freshStack();
    Api::$guildMembers = guildOfTwoPages();
    Api::$unreadableOnCall = 2;

    (new ReconciliationSweep())->run();

    $patchedIds = array_map(static fn (array $patch): string => $patch[0], Api::$patches);
    sort($patchedIds, SORT_STRING);

    check(
        'a page that cannot be read keeps the members already walked past',
        $patchedIds === ['1384909947564724500', '581229370245644289'],
        'patched ' . implode(', ', $patchedIds)
            . ' — throwing the partial page away costs a whole cycle of corrections'
    );

    // The forum-side half costs one query and no Discord budget. It has to stand on
    // its own: a revoked intent or a refused endpoint halves detection, and taking
    // the database scan down with it would leave the sweep doing nothing at all.
    $db = freshStack();
    Api::$guildMembers = guildOfTwoPages();
    Api::$unreadableOnCall = 1;

    $db->insert('xf_user', [['user_id' => 7, 'user_group_id' => 2, 'secondary_group_ids' => '3']]);
    $db->insert('xf_user_connected_account', [
        ['user_id' => 7, 'provider' => 'nfDiscord', 'provider_key' => '1384909947564740007'],
    ]);
    $db->insert('xf_nf_discord_sync_log', [
        ['user_id' => 7, 'guild_id' => GUILD_ID, 'user_group_ids' => '2', 'active' => 1, 'user_error_phrase' => ''],
    ]);

    (new ReconciliationSweep())->run();

    check(
        'a member list that cannot be read strips nobody',
        Api::$patches === [],
        'acting on a guild whose members could not be read would strip on no evidence'
    );

    check(
        'the forum-side half still corrects its members when Discord cannot be read',
        array_map(static fn (array $q): int => $q['user_id'], FakeSyncRepository::$queued) === [7],
        'the database scan needs no Discord budget and must not fail with the fetch'
    );

    // -----------------------------------------------------------------------
    // A throttled read is not a refused one.
    // -----------------------------------------------------------------------
    //
    // Both reach the sweep as the same `false`. Discord answers 429, Guzzle throws
    // ClientException, and the vendor catches it, records the retry-after and returns
    // what a revoked GUILD_MEMBERS intent returns. Only the retry-after tells them
    // apart, and they are different operator problems: one clears itself by the next
    // quarter-hour, the other needs the bot's intents fixed and will not.
    //
    // The two runs differ in one input — which way member-page call 1 fails. Nothing
    // is read either way, so both reports carry the same member count, the same guild
    // and the same closing sentence, and the cause is the only thing left free. That
    // the runs are otherwise alike is asserted rather than assumed, below.
    //
    // The assertion is an inequality. Rewording either cause keeps it green; only
    // collapsing the two back into one report turns it red.

    $db = freshStack();
    Api::$guildMembers = guildOfTwoPages();
    Api::$rateLimitedOnCall = 1;
    staleMember($db, 7, '1384909947564740007');

    (new ReconciliationSweep())->run();

    $throttledReports = \XF::$errors;
    $throttledQueued = queuedUserIds();
    $throttledPatched = patchedDiscordIds();

    $db = freshStack();
    Api::$guildMembers = guildOfTwoPages();
    Api::$unreadableOnCall = 1;
    staleMember($db, 7, '1384909947564740007');

    (new ReconciliationSweep())->run();

    $refusedReports = \XF::$errors;

    check(
        'a throttled first page and a refused one are alike in everything but the cause',
        count($throttledReports) === 1
            && count($refusedReports) === 1
            && $throttledQueued === queuedUserIds()
            && $throttledQueued === [7]
            && $throttledPatched === patchedDiscordIds()
            && $throttledPatched === [],
        'reports ' . count($throttledReports) . '/' . count($refusedReports)
            . ', queued ' . json_encode($throttledQueued) . '/' . json_encode(queuedUserIds())
            . ' — the next check is only meaningful while the two runs agree on all of this'
    );

    check(
        'a throttled member page is not reported as a refused endpoint',
        $throttledReports[0] !== $refusedReports[0],
        'both reported: ' . json_encode($throttledReports[0] ?? null)
            . ' — a throttle that reads as a revoked intent sends an admin to fix'
            . ' something that is not broken, and the throttle clears itself unnoticed'
    );

    // A throttle part-way through is a partial read, not a failed one. The members
    // already walked past are still reconciled and the rest wait for the next run —
    // the same bargain every other partial exit makes. Naming the cause is worth
    // nothing if buying that name costs a cycle of corrections, which is what
    // returning early with nothing would do.

    $db = freshStack();
    Api::$guildMembers = guildOfTwoPages();
    Api::$rateLimitedOnCall = 2;
    staleMember($db, 7, '1384909947564740007');

    (new ReconciliationSweep())->run();

    $expectedHolders = ['581229370245644289', '1384909947564724500'];
    sort($expectedHolders, SORT_STRING);

    check(
        'a throttled walk still strips the unlinked holders it read before the throttle',
        patchedDiscordIds() === $expectedHolders,
        'patched ' . json_encode(patchedDiscordIds())
            . ' — discarding the page already read costs a whole cycle of corrections'
    );

    check(
        'a throttled walk still corrects the members the forum-side half found',
        queuedUserIds() === [7],
        'queued ' . json_encode(queuedUserIds())
            . ' — the database scan needs no Discord budget and must not fail with the walk'
    );

    // The same distinction on the roles read, which happens before the walk and
    // decides whether the Discord side runs at all.
    //
    // getRoles() substitutes [] for a failed request, so a throttled read and a guild
    // that somehow reported no roles arrive identically. Both are refused — an empty
    // list cannot be taken at face value when every guild has an @everyone role — but
    // an admin told only "could not read the roles" has no way to know whether the
    // next run will fix it by itself.
    //
    // Neither run reads a member or patches anybody, so each reports exactly one line
    // carrying nothing but the guild id, and the cause is the only free variable.

    $db = freshStack();
    Api::$rateLimitedRoles = true;
    Api::$guildMembers = guildOfTwoPages();
    staleMember($db, 7, '1384909947564740007');

    (new ReconciliationSweep())->run();

    $throttledReports = \XF::$errors;
    $throttledQueued = queuedUserIds();

    $db = freshStack();
    Api::$guildRoles = [];
    Api::$guildMembers = guildOfTwoPages();
    staleMember($db, 7, '1384909947564740007');

    (new ReconciliationSweep())->run();

    $emptyReports = \XF::$errors;

    check(
        'a throttled roles read and an empty one are alike in everything but the cause',
        count($throttledReports) === 1
            && count($emptyReports) === 1
            && $throttledQueued === queuedUserIds()
            && $throttledQueued === [7]
            && patchedDiscordIds() === [],
        'reports ' . count($throttledReports) . '/' . count($emptyReports)
            . ', queued ' . json_encode($throttledQueued) . '/' . json_encode(queuedUserIds())
            . ' — the next check is only meaningful while the two runs agree on all of this'
    );

    check(
        'a throttled roles read is not reported as a guild that returned no roles',
        $throttledReports[0] !== $emptyReports[0],
        'both reported: ' . json_encode($throttledReports[0] ?? null)
            . ' — one clears itself by the next run and the other never will'
    );

    // And on the strip, which is where a throttle is likeliest to be met: the live
    // guild's run issued one roles read and nine member pages against eighty patches.
    //
    // A refused strip and a throttled one both moved no role, and both come back as
    // the same false. They mean opposite things about the next run. A refusal is
    // Discord rejecting a role set whole — the run will make the same call and be
    // refused again — while a throttle means the same call would have worked with
    // more room. Folding them together makes the per-run line, the only forum-side
    // trace a strip leaves at all, report a permissions problem the guild may not have.
    //
    // Both runs walk the whole guild and attempt the same patches, so neither reports
    // a partial walk and the strip line is the only thing either logs.

    $db = freshStack();
    Api::$guildMembers = guildOfTwoPages();
    Api::$rateLimitedPatches = true;
    staleMember($db, 7, '1384909947564740007');

    (new ReconciliationSweep())->run();

    $throttledReports = \XF::$errors;
    $throttledAttempts = patchedDiscordIds();
    $throttledQueued = queuedUserIds();

    $db = freshStack();
    Api::$guildMembers = guildOfTwoPages();
    Api::$patchesRefused = true;
    staleMember($db, 7, '1384909947564740007');

    (new ReconciliationSweep())->run();

    $refusedReports = \XF::$errors;

    check(
        'a throttled strip and a refused one are alike in everything but the cause',
        count($throttledReports) === 1
            && count($refusedReports) === 1
            && $throttledAttempts === patchedDiscordIds()
            && count($throttledAttempts) === 3
            && $throttledQueued === queuedUserIds(),
        'reports ' . count($throttledReports) . '/' . count($refusedReports)
            . ', attempted ' . json_encode($throttledAttempts) . '/' . json_encode(patchedDiscordIds())
            . ' — the next check is only meaningful while the two runs agree on all of this'
    );

    check(
        'a throttled strip is not reported as one Discord refused',
        $throttledReports[0] !== $refusedReports[0],
        'both reported: ' . json_encode($throttledReports[0] ?? null)
            . ' — a refusal will happen again next run and a throttle will not,'
            . ' and this line is the only forum-side trace a strip leaves'
    );

    if ($failures > 0) {
        echo "\n$failures test(s) FAILED\n";
        exit(1);
    }
    echo "\nAll tests passed\n";
    exit(0);
}
