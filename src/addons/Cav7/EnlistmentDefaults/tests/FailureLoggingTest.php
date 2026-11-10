<?php

/**
 * Issue #62: a failure applying the enlistment defaults has to be traceable to a member,
 * and must never fail the milpac save (ADR-0002).
 *
 * Runs the real gateway and the real entity extension against stand-ins for \XF and the
 * vendor entities, modelling only what these paths touch.
 *
 * Run:
 *   php tests/FailureLoggingTest.php
 */

namespace NF\Rosters\Entity {

    class RosterUser
    {
        public $relation_id = 0;
        public $user_id = 0;
        public $added_date = 1600000000;
        public $custom_fields;

        /** @var ServiceRecord[] */
        public array $serviceRecords = [];

        public bool $recordsThrowOnSave = false;

        public function __construct()
        {
            $this->custom_fields = (object) ['joinDate' => ''];
        }

        public function getNewServiceRecord(): ServiceRecord
        {
            $record = new ServiceRecord();
            $record->throwOnSave = $this->recordsThrowOnSave;
            $this->serviceRecords[] = $record;

            return $record;
        }
    }

    /** Fails with a \TypeError: vendor drift arrives as an \Error, not an \Exception. */
    class ServiceRecord
    {
        public $record_type_id;
        public $details;
        public $record_date;

        public bool $throwOnSave = false;
        public int $saveCalls = 0;

        public function save(): void
        {
            $this->saveCalls++;
            if ($this->throwOnSave) {
                throw new \TypeError('vendor returned the wrong type');
            }
        }
    }
}

namespace Cav7\EnlistmentDefaults\NF\Rosters\Entity {

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

    class XF
    {
        /** @var list<array{exception: \Throwable, rollback: bool, prefix: string}> */
        public static array $logged = [];

        public static array $options = [
            'cav7EnlistDefPucSync' => false,
            'cav7EnlistDefRecordTypeId' => 3,
            'cav7EnlistDefSystemUserId' => 1,
        ];

        public static ?\Throwable $optionsThrow = null;
        public static ?\Throwable $logThrows = null;

        public static function logException(\Throwable $e, bool $rollback = false, string $messagePrefix = '', bool $forceLog = false): void
        {
            if (self::$logThrows) {
                throw self::$logThrows;
            }
            self::$logged[] = ['exception' => $e, 'rollback' => $rollback, 'prefix' => $messagePrefix];
        }

        public static function options(): object
        {
            if (self::$optionsThrow) {
                throw self::$optionsThrow;
            }
            return (object) self::$options;
        }
    }
}

namespace Cav7\EnlistmentDefaults\Tests {

    require __DIR__ . '/../EnlistmentDecisions.php';
    require __DIR__ . '/../EnlistmentGateway.php';
    require __DIR__ . '/../EnlistmentApplier.php';
    require __DIR__ . '/../PucCounts.php';
    require __DIR__ . '/../PucSync.php';
    require __DIR__ . '/../RosterUserGateway.php';
    require __DIR__ . '/../NF/Rosters/Entity/RosterUser.php';

    use Cav7\EnlistmentDefaults\NF\Rosters\Entity\RosterUser as EnlistingMilpac;
    use Cav7\EnlistmentDefaults\RosterUserGateway;

    const MILPAC_RELATION_ID = 4211;
    const MILPAC_USER_ID = 9317;
    const STAMP = 'Cav7/EnlistmentDefaults: milpac 4211 (user 9317): ';

    $failures = 0;

    function check(string $label, bool $ok, string $detail = ''): void
    {
        global $failures;
        if ($ok) {
            echo "PASS: $label\n";
        } else {
            $failures++;
            echo "FAIL: $label" . ($detail !== '' ? " ($detail)" : '') . "\n";
        }
    }

    function milpac(): EnlistingMilpac
    {
        $entity = new EnlistingMilpac();
        $entity->relation_id = MILPAC_RELATION_ID;
        $entity->user_id = MILPAC_USER_ID;

        return $entity;
    }

    /** Runs the real post-save hook; true if it let anything escape. */
    function postSaveThrew(object $entity): bool
    {
        $postSave = new \ReflectionMethod($entity, '_postSave');
        $postSave->setAccessible(true);

        try {
            $postSave->invoke($entity);
        } catch (\Throwable) {
            return true;
        }
        return false;
    }

    function allStamped(array $logged): bool
    {
        foreach ($logged as $entry) {
            if (!str_starts_with($entry['prefix'], STAMP)) {
                return false;
            }
        }
        return $logged !== [];
    }

    function prefixes(): string
    {
        return implode(' | ', array_column(\XF::$logged, 'prefix')) ?: 'nothing logged';
    }

    // --- an entry names the milpac and the member, and keeps the exception ---
    \XF::$logged = [];
    $failure = new \DomainException('save refused');
    (new RosterUserGateway(milpac()))->logFailure($failure, 'failed to add the PUC row for regiment grant 12');
    $entry = \XF::$logged[0] ?? ['exception' => null, 'rollback' => true, 'prefix' => ''];

    check(
        'the prefix is the addon, the milpac, the member, the context and a separator',
        $entry['prefix'] === STAMP . 'failed to add the PUC row for regiment grant 12: ',
        $entry['prefix']
    );
    check('the exception is logged whole', $entry['exception'] === $failure);
    check('logging does not roll the milpac save back', $entry['rollback'] === false);

    // --- failures inside the real post-save never fail it ---------------------
    foreach ([new \Error('option read failed'), new \RuntimeException('option read failed')] as $thrown) {
        \XF::$logged = [];
        \XF::$optionsThrow = $thrown;
        $threw = postSaveThrew(milpac());
        \XF::$optionsThrow = null;

        check('an option read failing (' . get_class($thrown) . ') still lets the milpac save through', !$threw);
        check(
            'and each entry names the milpac and keeps the exception',
            allStamped(\XF::$logged) && array_column(\XF::$logged, 'exception') === array_fill(0, count(\XF::$logged), $thrown),
            prefixes()
        );
    }

    \XF::$logged = [];
    $existing = milpac();
    $existing->insert = false;
    postSaveThrew($existing);
    check('an update applies nothing', \XF::$logged === [] && $existing->serviceRecords === []);

    \XF::$logged = [];
    $entity = milpac();
    $entity->recordsThrowOnSave = true;
    $threw = postSaveThrew($entity);
    check('a failing record write still lets the milpac save through', !$threw);
    check(
        'and is logged once, stamped, with the \Error whole',
        count(\XF::$logged) === 1
            && \XF::$logged[0]['prefix'] === STAMP . 'failed to write enlistment record: '
            && \XF::$logged[0]['exception'] instanceof \TypeError,
        prefixes()
    );

    // --- the last-resort guard survives a broken error log -------------------
    // The applier's own logFailure is not guarded, so its throw reaches the entity's catch,
    // which logs through the same seam and throws again. That one is swallowed.
    foreach ([new \Error('error log write failed'), new \RuntimeException('error log write failed')] as $thrown) {
        \XF::$logged = [];
        \XF::$logThrows = $thrown;
        $entity = milpac();
        $entity->recordsThrowOnSave = true;
        $threw = postSaveThrew($entity);
        \XF::$logThrows = null;

        check('a failure the error log refuses (' . get_class($thrown) . ') still lets the milpac save through', !$threw);
    }

    if ($failures > 0) {
        echo "\n$failures test(s) FAILED\n";
        exit(1);
    }
    echo "\nAll tests passed\n";
    exit(0);
}
