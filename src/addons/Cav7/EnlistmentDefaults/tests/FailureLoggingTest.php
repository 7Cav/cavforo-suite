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
 *  - Grants failing inside the real post-save: every dropped date gets its own
 *    stamped entry, and the dates that did not drop keep their own award rows.
 *  - A failing enlistment record write inside the real post-save: logged, stamped
 *    the same way, and the milpac still saves.
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
     *
     * The factories hand out a FRESH row every call, as the vendor's do. That is
     * what lets a test tell six surviving award rows apart from one row saved six
     * times: every handed-out row is kept in $awards / $serviceRecords with the
     * date the grant stamped on it.
     */
    class RosterUser
    {
        public $relation_id = 0;
        public $user_id = 0;
        public $added_date = 1600000000;
        public $Awards = [];
        public $custom_fields;

        /** @var RosterUserAward[] every award row handed out, in grant order */
        public array $awards = [];

        /** @var ServiceRecord[] every service record row handed out */
        public array $serviceRecords = [];

        /** Set to make every award row refuse its rollback delete. */
        public bool $awardsThrowOnDelete = false;

        /** Set to make every service record refuse to save. */
        public bool $recordsThrowOnSave = false;

        public function __construct()
        {
            $this->custom_fields = (object) ['joinDate' => ''];
        }

        public function getNewAward(): RosterUserAward
        {
            $award = new RosterUserAward();
            $award->throwOnDelete = $this->awardsThrowOnDelete;
            $this->awards[] = $award;

            return $award;
        }

        public function getNewServiceRecord(): ServiceRecord
        {
            $record = new ServiceRecord();
            $record->throwOnSave = $this->recordsThrowOnSave;
            $this->serviceRecords[] = $record;

            return $record;
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
            if ($this->throwOnDelete) {
                throw new \RuntimeException('vendor refused the rollback delete');
            }
        }
    }

    /**
     * Stand-in for the vendor service record row; can be told to fail its save.
     *
     * It fails with a \TypeError, not an \Exception, and that is deliberate. The
     * failures these guards exist for come from vendor drift, and vendor drift
     * arrives as an \Error rather than an \Exception — a renamed
     * getNewServiceRecord() is a "Call to undefined method" \Error, a changed
     * return type is a \TypeError. Since PHP 7 neither extends \Exception, so a
     * fixture that threw \RuntimeException would let the fail-open guards in
     * EnlistmentApplier and the entity extension be narrowed to
     * catch (\Exception) with the suite still green.
     */
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

        /**
         * PUC dates whose citation source the service rejects by THROWING rather
         * than by returning false. This is the vendor's real behaviour for a
         * missing or unreadable source: setImage() delegates to
         * validateImageForRecord(), which raises \InvalidArgumentException for
         * both, before reaching any branch that returns false. The message is the
         * vendor's own. Modelling only $rejectDates left the throwing half of the
         * contract uncovered in both fakes at once (issue #168).
         *
         * @var string[]
         */
        public array $throwDates = [];

        public function setImage(string $path): bool
        {
            foreach ($this->throwDates as $date) {
                if (str_contains($path, $date . '.jpg')) {
                    throw new \InvalidArgumentException("Invalid file '$path' passed to image service");
                }
            }

            foreach ($this->rejectDates as $date) {
                if (str_contains($path, $date . '.jpg')) {
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

        /**
         * Set to make an option read blow up before the grant loop is reached.
         * Raised as an \Error for the same reason ServiceRecord::save() is: the
         * entity extension's last-resort guard has to hold against vendor and
         * platform drift, which is \Error territory, not \Exception territory.
         */
        public static bool $optionsThrow = false;

        /**
         * The same pre-loop failure, raised \Exception-side. Pinning the guard
         * on \Error alone would trade one blind spot for its mirror image:
         * catch (\Error) would survive the suite, and the everyday production
         * failure here — an XF\Db\Exception out of the award-date lookup — is an
         * \Exception. Both flags together pin it against either narrowing.
         */
        public static bool $optionsThrowException = false;

        /**
         * Set to make the error log itself refuse the write. Raised as an \Error
         * for the same reason the other fixtures are: the guard around the
         * entity extension's own log call is the last one there is, so it has to
         * hold for the whole \Throwable range and not just \Exception.
         */
        public static bool $logThrows = false;

        /**
         * The same refusal, raised \Exception-side — an XF\Db\Exception from the
         * xf_error_log insert is the realistic one, and a guard of last resort
         * that only held for \Error would not be one. Paired with $logThrows for
         * the same both-directions reason as $optionsThrowException.
         */
        public static bool $logThrowsException = false;

        public static ?object $serviceStub = null;

        /**
         * PUC dates whose image-service RESOLUTION fails, matched against the
         * award row the service is asked for. Models vendor drift — a renamed or
         * removed NF\Rosters service class — landing in the window after the
         * award row is saved, which is why it is raised as an \Error.
         *
         * @var string[]
         */
        public static array $serviceThrowDates = [];

        public static function logException(\Throwable $e, bool $rollback = false, string $messagePrefix = '', bool $forceLog = false): void
        {
            if (self::$logThrows) {
                throw new \Error('error log write failed');
            }

            if (self::$logThrowsException) {
                throw new \RuntimeException('error log write failed');
            }

            self::$logged[] = ['exception' => $e, 'rollback' => $rollback, 'prefix' => $messagePrefix];
        }

        public static function options(): object
        {
            if (self::$optionsThrow) {
                throw new \Error('option read failed');
            }

            if (self::$optionsThrowException) {
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
            $award = $args[0] ?? null;
            $awardDate = $award instanceof \NF\Rosters\Entity\RosterUserAward
                ? gmdate('Y-m-d', (int) $award->award_date)
                : '';

            if (in_array($awardDate, self::$serviceThrowDates, true)) {
                throw new \Error("Call to undefined method $class");
            }

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

    /**
     * The fixture milpac's identity. Every assertion about what an entry names is
     * derived from these two, so changing the fixture cannot leave an assertion
     * passing against a stale literal.
     */
    const MILPAC_RELATION_ID = 4211;
    const MILPAC_USER_ID = 9317;

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
        $milpac->relation_id = MILPAC_RELATION_ID;
        $milpac->user_id = MILPAC_USER_ID;

        return $milpac;
    }

    /** The same milpac, as the entity extension XenForo actually saves. */
    function enlistingMilpac(): \Cav7\EnlistmentDefaults\NF\Rosters\Entity\RosterUser
    {
        $entity = new \Cav7\EnlistmentDefaults\NF\Rosters\Entity\RosterUser();
        $entity->relation_id = MILPAC_RELATION_ID;
        $entity->user_id = MILPAC_USER_ID;

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

    /**
     * The dates whose award row was saved and left in place — one distinct row
     * per surviving date, which is what tells five survivors apart from one row
     * saved five times.
     *
     * @param \NF\Rosters\Entity\RosterUserAward[] $awards
     * @return string[]
     */
    function survivingDates(array $awards): array
    {
        $dates = [];

        foreach ($awards as $award) {
            if ($award->saveCalls === 1 && $award->deleteCalls === 0) {
                $dates[] = gmdate('Y-m-d', (int) $award->award_date);
            }
        }

        return $dates;
    }

    /**
     * The dates whose award row was saved and then rolled back.
     *
     * @param \NF\Rosters\Entity\RosterUserAward[] $awards
     * @return string[]
     */
    function rolledBackDates(array $awards): array
    {
        $dates = [];

        foreach ($awards as $award) {
            if ($award->deleteCalls > 0) {
                $dates[] = gmdate('Y-m-d', (int) $award->award_date);
            }
        }

        return $dates;
    }

    /** Whether every entry names the fixture milpac and the fixture member. */
    function allStamped(array $logged): bool
    {
        foreach ($logged as $entry) {
            if (!str_contains($entry['prefix'], 'milpac ' . MILPAC_RELATION_ID)
                || !str_contains($entry['prefix'], 'user ' . MILPAC_USER_ID)
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

    /** @return string[] the bundled dates other than the ones named */
    function datesExcept(array $dropped): array
    {
        return array_values(array_diff(PucSet::dates(), $dropped));
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
        str_contains($entry['prefix'], 'milpac ' . MILPAC_RELATION_ID),
        $entry['prefix']
    );
    check(
        "the entry names the member's user_id",
        str_contains($entry['prefix'], 'user ' . MILPAC_USER_ID),
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
    // The one entry whose whole prefix is known and stable, so it is worth
    // spelling out in full, separator included. XF\Error::logException normalizes
    // the prefix before it concatenates — trim() then a single space — so the
    // trailing ': ' is not what keeps the prefix and the exception message apart;
    // the space XF adds does that. What the ': ' buys is the colon: drop it and
    // the line reads '...failed to grant PUC for 2003-03-18 citation image
    // rejected', with nothing marking where the context ends and the cause
    // begins. Nothing else in this file would notice it going missing.
    check(
        'the prefix is exactly the addon tag, the identity, the context and a separator',
        $entry['prefix'] === 'Cav7/EnlistmentDefaults: milpac ' . MILPAC_RELATION_ID
            . ' (user ' . MILPAC_USER_ID . '): failed to grant PUC for 2003-03-18: ',
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
            && !str_contains(\XF::$logged[0]['prefix'], (string) MILPAC_RELATION_ID),
        \XF::$logged[0]['prefix'] ?? ''
    );

    // --- the entity extension's outer catch carries the same identity --------
    // It covers what is raised before the grant loop (option reads, the
    // award-date lookup), so an option read is made to blow up.
    \XF::$logged = [];
    \XF::$optionsThrow = true;

    $threw = postSaveThrew(enlistingMilpac());

    \XF::$optionsThrow = false;

    check('a failure before the grant loop never blocks the milpac save', !$threw);
    check(
        'the outer catch logs against the same milpac identity',
        count(\XF::$logged) === 1 && allStamped(\XF::$logged),
        prefixes(\XF::$logged) ?: 'nothing logged'
    );
    check(
        'the outer catch keeps the exception whole',
        count(\XF::$logged) === 1
            && \XF::$logged[0]['exception'] instanceof \Error
            && \XF::$logged[0]['exception']->getMessage() === 'option read failed',
        count(\XF::$logged) === 1 ? get_class(\XF::$logged[0]['exception']) : 'nothing logged'
    );
    check(
        'the outer catch holds for an \Error, which no catch (\Exception) would have seen',
        count(\XF::$logged) === 1 && !\XF::$logged[0]['exception'] instanceof \Exception,
        count(\XF::$logged) === 1 ? get_class(\XF::$logged[0]['exception']) : 'nothing logged'
    );

    // The same pre-loop failure from the other half of the \Throwable range. The
    // \Error case above is the vendor-drift one; this is the everyday one — the
    // award-date lookup hitting an XF\Db\Exception. Proving only \Error would
    // leave the guard narrowable to catch (\Error), which is the mirror image of
    // the blind spot the \Error fixture was added to close.
    \XF::$logged = [];
    \XF::$optionsThrowException = true;

    $threw = postSaveThrew(enlistingMilpac());

    \XF::$optionsThrowException = false;

    check('an \Exception before the grant loop never blocks the milpac save either', !$threw);
    check(
        'the outer catch holds for an \Exception too, and stamps it the same way',
        count(\XF::$logged) === 1
            && allStamped(\XF::$logged)
            && \XF::$logged[0]['exception'] instanceof \Exception
            && \XF::$logged[0]['exception']->getMessage() === 'option read failed',
        prefixes(\XF::$logged) ?: 'nothing logged'
    );

    // An existing milpac being saved is not an enlistment: nothing runs, so
    // nothing is logged (the insert-only gate, unchanged by this).
    \XF::$logged = [];
    \XF::$optionsThrow = true;
    $existing = enlistingMilpac();
    $existing->insert = false;
    postSaveThrew($existing);
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
        'no award row survives when every citation is rejected',
        survivingDates($entity->awards) === []
            && rolledBackDates($entity->awards) === PucSet::dates(),
        'survivors: ' . implode(', ', survivingDates($entity->awards))
    );
    check(
        'the enlistment record is still written when every grant fails',
        count($entity->serviceRecords) === 1 && $entity->serviceRecords[0]->saveCalls === 1,
        (string) count($entity->serviceRecords)
    );

    // One bad date out of six: the rest of the set and the record still apply,
    // and the log names only the date that dropped. Each grant asks the entity
    // for its own award row, so the five that survive are five distinct rows —
    // counting saves alone could not tell them from one row saved six times.
    \XF::$logged = [];
    $service = new \NF\Rosters\Service\AwardRecord\Image();
    $service->acceptImage = true;
    $service->rejectDates = ['2010-09-18'];
    \XF::$serviceStub = $service;

    $entity = enlistingMilpac();

    $threw = postSaveThrew($entity);
    \XF::$serviceStub = null;

    check('one failing date still lets the milpac save through', !$threw);
    check(
        'only the date that dropped is logged, against this milpac and member',
        droppedDates(\XF::$logged) === ['2010-09-18'] && allStamped(\XF::$logged),
        prefixes(\XF::$logged)
    );
    check(
        'the other five dates each keep their own award row, dated to that date',
        survivingDates($entity->awards) === datesExcept(['2010-09-18']),
        'survivors: ' . implode(', ', survivingDates($entity->awards))
    );
    check(
        'only the failing date is rolled back',
        rolledBackDates($entity->awards) === ['2010-09-18'],
        'rolled back: ' . implode(', ', rolledBackDates($entity->awards))
    );
    check(
        'the enlistment record is still written after a date drops',
        count($entity->serviceRecords) === 1 && $entity->serviceRecords[0]->saveCalls === 1,
        (string) count($entity->serviceRecords)
    );

    // --- issue #168: a citation the service rejects by THROWING --------------
    // The same one-bad-date shape as above, except the service says no the way
    // the real vendor says no about a missing or unreadable source file: it
    // raises before it ever reaches a branch that returns false. The award row
    // is already saved at that point, so if that throw escapes the rollback the
    // milpac keeps a citationless PUC — and pendingDates() matches on
    // award_date, so nothing ever retries that date. Everything else about the
    // enlistment has to carry on regardless.
    \XF::$logged = [];
    $service = new \NF\Rosters\Service\AwardRecord\Image();
    $service->acceptImage = true;
    $service->throwDates = ['2010-09-18'];
    \XF::$serviceStub = $service;

    $entity = enlistingMilpac();

    $threw = postSaveThrew($entity);
    \XF::$serviceStub = null;

    check('a citation source the service throws over still lets the milpac save through', !$threw);
    check(
        'no citationless row survives a throwing rejection',
        survivingDates($entity->awards) === datesExcept(['2010-09-18'])
            && rolledBackDates($entity->awards) === ['2010-09-18'],
        'survivors: ' . implode(', ', survivingDates($entity->awards))
            . ' | rolled back: ' . implode(', ', rolledBackDates($entity->awards))
    );
    check(
        'the thrown-over date is logged against this milpac and member',
        droppedDates(\XF::$logged) === ['2010-09-18'] && allStamped(\XF::$logged),
        prefixes(\XF::$logged) ?: 'nothing logged'
    );
    check(
        'the entry keeps the vendor exception whole, so it says what broke',
        count(\XF::$logged) === 1
            && \XF::$logged[0]['exception'] instanceof \InvalidArgumentException
            && str_contains(\XF::$logged[0]['exception']->getMessage(), 'passed to image service'),
        count(\XF::$logged) === 1 ? get_class(\XF::$logged[0]['exception']) : 'nothing logged'
    );
    check(
        'the enlistment record is still written after a throwing rejection',
        count($entity->serviceRecords) === 1 && $entity->serviceRecords[0]->saveCalls === 1,
        (string) count($entity->serviceRecords)
    );

    // The rolled-back date is still pending on a re-run, which is the whole
    // point of rolling it back: the milpac now carries the five that landed, and
    // the applier asked for those five and no more.
    $survivors = [];
    foreach ($entity->awards as $award) {
        if ($award->deleteCalls === 0) {
            $survivors[] = (int) $award->award_date;
        }
    }
    check(
        're-running against the surviving rows still treats the failed date as pending',
        \Cav7\EnlistmentDefaults\EnlistmentDecisions::pendingDates($survivors) === ['2010-09-18'],
        implode(', ', \Cav7\EnlistmentDefaults\EnlistmentDecisions::pendingDates($survivors))
    );

    // --- issue #168: the image service failing to RESOLVE --------------------
    // The window between the award row save and the attach. The resolution needs
    // the saved record_id so it cannot be hoisted above the save; it has to be
    // reachable by the rollback instead.
    \XF::$logged = [];
    $service = new \NF\Rosters\Service\AwardRecord\Image();
    $service->acceptImage = true;
    \XF::$serviceStub = $service;
    \XF::$serviceThrowDates = ['2004-09-01'];

    $entity = enlistingMilpac();

    $threw = postSaveThrew($entity);
    \XF::$serviceStub = null;
    \XF::$serviceThrowDates = [];

    check('a failure resolving the image service still lets the milpac save through', !$threw);
    check(
        'no citationless row survives a failed image-service resolution',
        survivingDates($entity->awards) === datesExcept(['2004-09-01'])
            && rolledBackDates($entity->awards) === ['2004-09-01'],
        'survivors: ' . implode(', ', survivingDates($entity->awards))
            . ' | rolled back: ' . implode(', ', rolledBackDates($entity->awards))
    );
    check(
        'the date whose service could not be resolved is logged, and stamped',
        droppedDates(\XF::$logged) === ['2004-09-01'] && allStamped(\XF::$logged),
        prefixes(\XF::$logged) ?: 'nothing logged'
    );
    // On its own, "not an \Exception" says nothing: the fixture only ever throws
    // an \Error, so that conjunct holds however the guard behaves. Nor does the
    // message distinguish much — narrowing the applier's catch to \Exception
    // routes the SAME exception object to the entity's last-resort catch, so
    // class and message both survive unchanged and only the prefix moves to
    // 'enlistment defaults failed'. The droppedDates() check above is what
    // catches that (verified by mutation), and this one is here for the
    // narrower thing it does pin: the exception reaches the log whole rather
    // than flattened to its message, which is what keeps the class and stack
    // trace an admin needs. See ADR-0002.
    check(
        'the entry carries the resolution failure whole, class and message',
        count(\XF::$logged) === 1
            && !\XF::$logged[0]['exception'] instanceof \Exception
            && \XF::$logged[0]['exception']->getMessage() === 'Call to undefined method NF\Rosters:AwardRecord\Image',
        count(\XF::$logged) === 1
            ? get_class(\XF::$logged[0]['exception']) . ': ' . \XF::$logged[0]['exception']->getMessage()
            : 'nothing logged'
    );

    // The same re-run guarantee AC7 asks for, on this route too: the rolled-back
    // date has to come back as pending, not merely as "no row present".
    $survivors = [];
    foreach ($entity->awards as $award) {
        if ($award->deleteCalls === 0) {
            $survivors[] = (int) $award->award_date;
        }
    }
    check(
        're-running after a failed resolution still treats that date as pending',
        \Cav7\EnlistmentDefaults\EnlistmentDecisions::pendingDates($survivors) === ['2004-09-01'],
        implode(', ', \Cav7\EnlistmentDefaults\EnlistmentDecisions::pendingDates($survivors))
    );
    check(
        'the enlistment record is still written after a failed resolution',
        count($entity->serviceRecords) === 1 && $entity->serviceRecords[0]->saveCalls === 1,
        (string) count($entity->serviceRecords)
    );

    // --- the record write failing, through the real post-save ---------------
    // The other half of the fail-open policy, at the layer that ships: the grants
    // land, the record write blows up in the vendor row, and the enlistment still
    // completes with an entry a reader can act on.
    \XF::$logged = [];
    $service = new \NF\Rosters\Service\AwardRecord\Image();
    $service->acceptImage = true;
    \XF::$serviceStub = $service;

    $entity = enlistingMilpac();
    $entity->recordsThrowOnSave = true;

    $threw = postSaveThrew($entity);
    \XF::$serviceStub = null;

    check('a failing enlistment record write still lets the milpac save through', !$threw);
    check(
        'the failed record write is logged exactly once, naming the record',
        count(\XF::$logged) === 1
            && str_contains(\XF::$logged[0]['prefix'], 'failed to write enlistment record'),
        prefixes(\XF::$logged) ?: 'nothing logged'
    );
    check(
        'the record-write entry carries the same milpac identity as a dropped grant',
        allStamped(\XF::$logged),
        prefixes(\XF::$logged) ?: 'nothing logged'
    );
    check(
        'the record-write entry keeps the exception whole',
        count(\XF::$logged) === 1
            && \XF::$logged[0]['exception'] instanceof \TypeError
            && \XF::$logged[0]['exception']->getMessage() === 'vendor returned the wrong type',
        count(\XF::$logged) === 1 ? get_class(\XF::$logged[0]['exception']) : 'nothing logged'
    );
    check(
        'the record-write guard holds for an \Error, which no catch (\Exception) would have seen',
        count(\XF::$logged) === 1 && !\XF::$logged[0]['exception'] instanceof \Exception,
        count(\XF::$logged) === 1 ? get_class(\XF::$logged[0]['exception']) : 'nothing logged'
    );
    check(
        'the whole PUC set is still granted when the record write fails',
        survivingDates($entity->awards) === PucSet::dates(),
        'survivors: ' . implode(', ', survivingDates($entity->awards))
    );

    // --- a citation rollback breadcrumb carries the identity too -------------
    // Inside a grant, CitationAttacher records cleanup failures separately from
    // the failure it re-throws. Those are logged from the applier path as well,
    // so they are stamped the same way.
    \XF::$logged = [];
    $milpac = milpac();
    $milpac->awardsThrowOnDelete = true;
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
            && allStamped(\XF::$logged)
            && str_contains(\XF::$logged[0]['prefix'], 'award rollback'),
        prefixes(\XF::$logged) ?: 'nothing logged'
    );
    check(
        'the rollback breadcrumb names the date the grant was for',
        count(\XF::$logged) === 1 && str_contains(\XF::$logged[0]['prefix'], '2003-03-18'),
        prefixes(\XF::$logged) ?: 'nothing logged'
    );

    // --- the last-resort guard survives a broken error log -------------------
    // Both layers log through the same gateway seam now, so a fault in that seam
    // repeats rather than happening once: the per-grant catch calls logFailure,
    // it throws, the throw escapes the loop and apply(), the entity's outer catch
    // calls the identical logFailure, and it throws identically. If the outer
    // call is unguarded the milpac save fails and the recruiter is told the
    // creation failed — over a logging fault. The guard has to be last-resort for
    // real, so the failure it cannot log is the one it swallows.
    \XF::$logged = [];
    \XF::$serviceStub = null;   // every citation rejected, so every grant fails
    \XF::$logThrows = true;

    $brokenLog = enlistingMilpac();
    $threwOnBrokenLog = postSaveThrew($brokenLog);

    // The same fault reaching the outer catch directly, with no inner catch in
    // front of it: the option read blows up before the grant loop.
    \XF::$optionsThrow = true;
    $threwOnBrokenLogBeforeLoop = postSaveThrew(enlistingMilpac());
    \XF::$optionsThrow = false;

    \XF::$logThrows = false;

    check('a failing grant the error log itself refuses still lets the milpac save through', !$threwOnBrokenLog);
    check(
        'a pre-loop failure the error log itself refuses still lets the milpac save through',
        !$threwOnBrokenLogBeforeLoop
    );

    // What the milpac save surviving costs, spelled out. The applier's own
    // per-grant logFailure call is deliberately NOT guarded, so the throw out of
    // it escapes the loop and apply() both: grant 1 is the only one attempted and
    // the enlistment record is never written. That is the price of the broken
    // seam, and it is pinned here so guarding the applier's log call — which
    // would let all six grants and the record through — cannot be mistaken for a
    // no-op refactor. Changing it is a policy decision, not a tidy-up.
    check(
        'a broken log seam costs the grants after the first one',
        count($brokenLog->awards) === 1,
        'award rows taken: ' . count($brokenLog->awards)
    );
    check(
        'a broken log seam costs the enlistment record write as well',
        $brokenLog->serviceRecords === [],
        'service records taken: ' . count($brokenLog->serviceRecords)
    );

    // The broken seam from the \Exception half of the range. The guard around the
    // entity extension's own log call is the last one there is; proving it only
    // against \Error would let it be narrowed to catch (\Error), and an
    // XF\Db\Exception from the xf_error_log insert would then fail the milpac
    // save — the recruiter told the creation failed, over a logging fault.
    \XF::$logged = [];
    \XF::$serviceStub = null;
    \XF::$logThrowsException = true;

    $threwOnBrokenLogException = postSaveThrew(enlistingMilpac());

    \XF::$optionsThrow = true;
    $threwOnBrokenLogExceptionBeforeLoop = postSaveThrew(enlistingMilpac());
    \XF::$optionsThrow = false;

    \XF::$logThrowsException = false;

    check(
        'a failing grant an \Exception-throwing error log refuses still lets the milpac save through',
        !$threwOnBrokenLogException
    );
    check(
        'a pre-loop failure an \Exception-throwing error log refuses still lets the milpac save through',
        !$threwOnBrokenLogExceptionBeforeLoop
    );

    // --- Summary ------------------------------------------------------------
    if ($failures > 0) {
        echo "\n$failures test(s) FAILED\n";
        exit(1);
    }
    echo "\nAll tests passed\n";
    exit(0);
}
