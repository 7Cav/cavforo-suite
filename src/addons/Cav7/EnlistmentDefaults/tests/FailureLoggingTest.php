<?php

/**
 * Issue #62 — a dropped grant has to be traceable to a member.
 *
 * Five of six PUCs on a new milpac looks entirely ordinary, so the admin error
 * log is the only place a dropped grant shows up (see
 * docs/adr/0002-error-log-is-the-only-failure-surface.md). An entry that names
 * the date but not the milpac cannot be acted on, and one flattened to
 * getMessage() loses the class and trace that say what actually broke.
 *
 * RosterUserGateway is the side that holds the milpac entity, so it stamps the
 * identity — relation_id and the member's user_id — onto every failure logged
 * from the applier path, and hands the exception to \XF::logException whole.
 * EnlistmentApplier stays free of the entity world (its own test covers the
 * context strings it supplies).
 *
 * Covered here, against the real gateway and the real entity extension:
 *  - A failure logged through the gateway names the milpac and the member, keeps
 *    the exception object, and does not roll the milpac save back.
 *  - The entity extension's outer catch — the one covering failures raised
 *    before the grant loop — logs through the same gateway, so it carries the
 *    same identity, and still lets the milpac save succeed.
 *  - A citation rollback breadcrumb, logged from inside a grant, carries the
 *    identity too.
 *
 * The vendor NF/Rosters classes and XenForo itself are not in this repo, so they
 * are stood in with minimal stubs modelling only what these paths touch — the
 * same approach as Cav7/RosterPatch's tests/MilpacDateGetterTest.php.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/FailureLoggingTest.php
 */

namespace NF\Rosters\Entity {

    /**
     * Stand-in for the vendor milpac entity. Only what the gateway reads or
     * calls: the identity columns, the fields the applier consults, and the two
     * row factories.
     */
    class RosterUser
    {
        public $relation_id = 0;
        public $user_id = 0;
        public $added_date = 1600000000;
        public $Awards = [];
        public $custom_fields;

        public ?RosterUserAward $newAward = null;
        public ?ServiceRecord $newServiceRecord = null;

        public function __construct()
        {
            $this->custom_fields = (object) ['joinDate' => ''];
        }

        public function getNewAward(): RosterUserAward
        {
            return $this->newAward ?? new RosterUserAward();
        }

        public function getNewServiceRecord(): ServiceRecord
        {
            return $this->newServiceRecord ?? new ServiceRecord();
        }
    }

    /** Stand-in for the vendor award row; can be told to fail its rollback delete. */
    class RosterUserAward
    {
        public $award_id;
        public $award_date;
        public $from_user_id;
        public $details;

        public bool $throwOnDelete = false;
        public int $saveCalls = 0;
        public int $deleteCalls = 0;

        public function save(): void
        {
            $this->saveCalls++;
        }

        public function delete(): void
        {
            $this->deleteCalls++;
            if ($this->throwOnDelete)
            {
                throw new \RuntimeException('vendor refused the rollback delete');
            }
        }
    }

    /** Stand-in for the vendor service record row. */
    class ServiceRecord
    {
        public $record_type_id;
        public $details;
        public $record_date;

        public int $saveCalls = 0;

        public function save(): void
        {
            $this->saveCalls++;
        }
    }
}

namespace NF\Rosters\Service\AwardRecord {

    /**
     * Stand-in for the vendor citation image service. Modelled as rejecting the
     * source file, which is the shortest route to the rollback breadcrumb.
     */
    class Image
    {
        public bool $acceptImage = false;

        /**
         * PUC dates whose citation is rejected even when the rest are accepted,
         * matched against the source filename. Lets one date drop out of a set
         * that otherwise grants cleanly.
         *
         * @var string[]
         */
        public array $rejectDates = [];

        public function setImage(string $path): bool
        {
            foreach ($this->rejectDates as $date)
            {
                if (str_contains($path, $date . '.jpg'))
                {
                    return false;
                }
            }

            return $this->acceptImage;
        }

        public function getError()
        {
            return 'not a readable JPG';
        }

        public function updateImage(): void {}

        public function deleteImageForAwardDelete(): void {}
    }
}

namespace Cav7\EnlistmentDefaults\NF\Rosters\Entity {

    /**
     * Stand-in for the XenForo-generated class-extension parent. In production
     * it is generated on top of the vendor RosterUser, which is why the entity
     * extension can hand $this to RosterUserGateway.
     */
    class XFCP_RosterUser extends \NF\Rosters\Entity\RosterUser
    {
        public bool $insert = true;

        public function isInsert(): bool
        {
            return $this->insert;
        }

        protected function _postSave(): void {}
    }
}

namespace {

    /** Stand-in for the XenForo facade, recording what reaches the error log. */
    class XF
    {
        /** @var array<int, array{exception: \Throwable, rollback: bool, prefix: string}> */
        public static array $logged = [];

        public static array $options = [
            'cav7EnlistDefPucAwardId' => 61,
            'cav7EnlistDefRecordTypeId' => 3,
            'cav7EnlistDefSystemUserId' => 1,
        ];

        /** Set to make an option read blow up before the grant loop is reached. */
        public static bool $optionsThrow = false;

        public static ?object $serviceStub = null;

        public static function logException(\Throwable $e, bool $rollback = false, string $messagePrefix = '', bool $forceLog = false): void
        {
            self::$logged[] = ['exception' => $e, 'rollback' => $rollback, 'prefix' => $messagePrefix];
        }

        public static function options(): object
        {
            if (self::$optionsThrow)
            {
                throw new \RuntimeException('option read failed');
            }

            return (object) self::$options;
        }

        public static function visitor(): object
        {
            return (object) ['user_id' => 42];
        }

        public static function service(string $class, ...$args): object
        {
            return self::$serviceStub ?? new \NF\Rosters\Service\AwardRecord\Image();
        }
    }
}

namespace Cav7\EnlistmentDefaults\Tests {

    require __DIR__ . '/../PucSet.php';
    require __DIR__ . '/../EnlistmentDecisions.php';
    require __DIR__ . '/../EnlistmentGateway.php';
    require __DIR__ . '/../EnlistmentApplier.php';
    require __DIR__ . '/../CitationAttacher.php';
    require __DIR__ . '/../RosterUserGateway.php';
    require __DIR__ . '/../NF/Rosters/Entity/RosterUser.php';

    use Cav7\EnlistmentDefaults\PucSet;
    use Cav7\EnlistmentDefaults\RosterUserGateway;

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

    /** A milpac with an identity a log reader could look up. */
    function milpac(): \NF\Rosters\Entity\RosterUser
    {
        $milpac = new \NF\Rosters\Entity\RosterUser();
        $milpac->relation_id = 4211;
        $milpac->user_id = 9317;

        return $milpac;
    }

    /** The same milpac, as the entity extension XenForo actually saves. */
    function enlistingMilpac(): \Cav7\EnlistmentDefaults\NF\Rosters\Entity\RosterUser
    {
        $entity = new \Cav7\EnlistmentDefaults\NF\Rosters\Entity\RosterUser();
        $entity->relation_id = 4211;
        $entity->user_id = 9317;

        return $entity;
    }

    /** Run the real post-save hook; true if it let anything escape. */
    function postSaveThrew(object $entity): bool
    {
        $postSave = new \ReflectionMethod($entity, '_postSave');
        $postSave->setAccessible(true);

        try {
            $postSave->invoke($entity);
        } catch (\Throwable $e) {
            return true;
        }

        return false;
    }

    /**
     * The PUC dates the logged entries say dropped, in the order they were
     * logged.
     *
     * @param array<int, array{prefix: string}> $logged
     * @return string[]
     */
    function droppedDates(array $logged): array
    {
        $dates = [];

        foreach ($logged as $entry) {
            if (preg_match('/failed to grant PUC for (\d{4}-\d{2}-\d{2})/', $entry['prefix'], $m)) {
                $dates[] = $m[1];
            }
        }

        return $dates;
    }

    /** Whether every entry names milpac 4211 and user 9317. */
    function allStamped(array $logged): bool
    {
        foreach ($logged as $entry) {
            if (!str_contains($entry['prefix'], 'milpac 4211')
                || !str_contains($entry['prefix'], 'user 9317')
            ) {
                return false;
            }
        }

        return $logged !== [];
    }

    /** @param array<int, array{prefix: string}> $logged */
    function prefixes(array $logged): string
    {
        return implode(' | ', array_column($logged, 'prefix'));
    }

    // --- a failure logged through the gateway names the milpac ---------------
    \XF::$logged = [];
    $gateway = new RosterUserGateway(milpac());
    $dropped = new \DomainException('citation image rejected');
    $gateway->logFailure($dropped, 'failed to grant PUC for 2003-03-18');

    check('a failure logged through the gateway produces exactly one error-log entry', count(\XF::$logged) === 1);

    $entry = \XF::$logged[0] ?? ['exception' => null, 'rollback' => true, 'prefix' => ''];

    check(
        'the entry names the milpac by its relation_id',
        str_contains($entry['prefix'], 'milpac 4211'),
        $entry['prefix']
    );
    check(
        "the entry names the member's user_id",
        str_contains($entry['prefix'], 'user 9317'),
        $entry['prefix']
    );
    check(
        'the entry keeps the PUC date the applier named',
        str_contains($entry['prefix'], 'failed to grant PUC for 2003-03-18'),
        $entry['prefix']
    );
    check(
        'the entry says which add-on it came from',
        str_contains($entry['prefix'], 'Cav7/EnlistmentDefaults'),
        $entry['prefix']
    );
    check(
        'the exception object is logged whole, so its class and stack trace survive',
        $entry['exception'] === $dropped,
        $entry['exception'] === null ? 'nothing logged' : get_class($entry['exception'])
    );
    check(
        'logging a failure does not roll the milpac save back',
        $entry['rollback'] === false
    );

    // Two milpacs failing the same date are told apart by the entry, which is
    // the whole point of stamping the identity.
    \XF::$logged = [];
    $other = new \NF\Rosters\Entity\RosterUser();
    $other->relation_id = 8802;
    $other->user_id = 1155;
    (new RosterUserGateway($other))->logFailure(
        new \DomainException('same failure, different member'),
        'failed to grant PUC for 2003-03-18'
    );
    check(
        'the same failure on a different milpac logs a distinguishable entry',
        count(\XF::$logged) === 1
            && str_contains(\XF::$logged[0]['prefix'], 'milpac 8802')
            && str_contains(\XF::$logged[0]['prefix'], 'user 1155')
            && !str_contains(\XF::$logged[0]['prefix'], '4211'),
        \XF::$logged[0]['prefix'] ?? ''
    );

    // --- the entity extension's outer catch carries the same identity --------
    // It covers what is raised before the grant loop (option reads, the
    // award-date lookup), so an option read is made to blow up.
    \XF::$logged = [];
    \XF::$optionsThrow = true;

    $entity = new \Cav7\EnlistmentDefaults\NF\Rosters\Entity\RosterUser();
    $entity->relation_id = 4211;
    $entity->user_id = 9317;

    $postSave = new \ReflectionMethod($entity, '_postSave');
    $postSave->setAccessible(true);

    $threw = false;
    try {
        $postSave->invoke($entity);
    } catch (\Throwable $e) {
        $threw = true;
    }

    \XF::$optionsThrow = false;

    check('a failure before the grant loop never blocks the milpac save', !$threw);
    check(
        'the outer catch logs against the same milpac identity',
        count(\XF::$logged) === 1
            && str_contains(\XF::$logged[0]['prefix'], 'milpac 4211')
            && str_contains(\XF::$logged[0]['prefix'], 'user 9317'),
        \XF::$logged[0]['prefix'] ?? 'nothing logged'
    );
    check(
        'the outer catch keeps the exception whole',
        count(\XF::$logged) === 1
            && \XF::$logged[0]['exception'] instanceof \RuntimeException
            && \XF::$logged[0]['exception']->getMessage() === 'option read failed',
        count(\XF::$logged) === 1 ? get_class(\XF::$logged[0]['exception']) : 'nothing logged'
    );

    // An existing milpac being saved is not an enlistment: nothing runs, so
    // nothing is logged (the insert-only gate, unchanged by this).
    \XF::$logged = [];
    \XF::$optionsThrow = true;
    $existing = new \Cav7\EnlistmentDefaults\NF\Rosters\Entity\RosterUser();
    $existing->insert = false;
    $postSave = new \ReflectionMethod($existing, '_postSave');
    $postSave->setAccessible(true);
    $postSave->invoke($existing);
    \XF::$optionsThrow = false;
    check('an update saves without applying anything, so nothing is logged', \XF::$logged === []);

    // --- the grants themselves failing, through the real post-save ----------
    // The outer catch above only covers what breaks before the loop. These two
    // drive the same _postSave() with the grants failing, which is the case the
    // fail-open policy exists for: the enlistment has to complete anyway, and
    // every dropped date has to be traceable to this member.
    //
    // The default image service stub rejects every citation, so the whole set
    // drops — an unreadable bundled JPG would look exactly like this.
    \XF::$logged = [];
    \XF::$serviceStub = null;

    $entity = enlistingMilpac();
    $record = new \NF\Rosters\Entity\ServiceRecord();
    $entity->newServiceRecord = $record;

    check('every grant failing still lets the milpac save through', !postSaveThrew($entity));
    check(
        'each dropped date gets its own entry, naming that date, in earned order',
        droppedDates(\XF::$logged) === PucSet::dates(),
        prefixes(\XF::$logged)
    );
    check(
        'every one of those entries names the milpac and the member',
        count(\XF::$logged) === count(PucSet::dates()) && allStamped(\XF::$logged),
        prefixes(\XF::$logged)
    );
    check(
        'the enlistment record is still written when every grant fails',
        $record->saveCalls === 1,
        (string) $record->saveCalls
    );

    // One bad date out of six: the rest of the set and the record still apply,
    // and the log names only the date that dropped.
    \XF::$logged = [];
    $service = new \NF\Rosters\Service\AwardRecord\Image();
    $service->acceptImage = true;
    $service->rejectDates = ['2010-09-18'];
    \XF::$serviceStub = $service;

    $entity = enlistingMilpac();
    $award = new \NF\Rosters\Entity\RosterUserAward();
    $entity->newAward = $award;
    $record = new \NF\Rosters\Entity\ServiceRecord();
    $entity->newServiceRecord = $record;

    $threw = postSaveThrew($entity);
    \XF::$serviceStub = null;

    check('one failing date still lets the milpac save through', !$threw);
    check(
        'only the date that dropped is logged, against this milpac and member',
        droppedDates(\XF::$logged) === ['2010-09-18'] && allStamped(\XF::$logged),
        prefixes(\XF::$logged)
    );
    check(
        'the other five dates are granted and left in place',
        $award->saveCalls === count(PucSet::dates()) && $award->deleteCalls === 1,
        "saved {$award->saveCalls}, rolled back {$award->deleteCalls}"
    );
    check(
        'the enlistment record is still written after a date drops',
        $record->saveCalls === 1,
        (string) $record->saveCalls
    );

    // --- a citation rollback breadcrumb carries the identity too -------------
    // Inside a grant, CitationAttacher records cleanup failures separately from
    // the failure it re-throws. Those are logged from the applier path as well,
    // so they are stamped the same way.
    \XF::$logged = [];
    $milpac = milpac();
    $award = new \NF\Rosters\Entity\RosterUserAward();
    $award->throwOnDelete = true;
    $milpac->newAward = $award;
    \XF::$serviceStub = new \NF\Rosters\Service\AwardRecord\Image();

    $rethrown = null;
    try {
        (new RosterUserGateway($milpac))->grantAward(61, 1047945600, 42, '/citations/2003-03-18.jpg');
    } catch (\Throwable $e) {
        $rethrown = $e;
    }
    \XF::$serviceStub = null;

    check(
        'a grant whose citation cannot be attached still fails the grant',
        $rethrown instanceof \Throwable
            && str_contains($rethrown->getMessage(), 'not a readable JPG'),
        $rethrown === null ? 'nothing thrown' : $rethrown->getMessage()
    );
    check(
        'the rollback breadcrumb names the milpac it belongs to',
        count(\XF::$logged) === 1
            && str_contains(\XF::$logged[0]['prefix'], 'milpac 4211')
            && str_contains(\XF::$logged[0]['prefix'], 'user 9317')
            && str_contains(\XF::$logged[0]['prefix'], 'award rollback'),
        \XF::$logged[0]['prefix'] ?? 'nothing logged'
    );
    check(
        'the rollback breadcrumb names the date the grant was for',
        count(\XF::$logged) === 1 && str_contains(\XF::$logged[0]['prefix'], '2003-03-18'),
        \XF::$logged[0]['prefix'] ?? 'nothing logged'
    );

    // --- Summary ------------------------------------------------------------
    if ($failures > 0) {
        echo "\n$failures test(s) FAILED\n";
        exit(1);
    }
    echo "\nAll tests passed\n";
    exit(0);
}
