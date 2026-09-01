<?php

/**
 * Issue #279 — a member holds at most one milpac, and the entity refuses a save
 * that would leave them holding two.
 *
 * Why the guard exists, and why it sits on the entity rather than on either
 * write path, is in the class docblock on ../NF/Rosters/Entity/RosterUser.php.
 *
 * The vendor entity and the XenForo facade are not in this repo, so they are
 * stood up as stubs and the real extension is driven against them — the pattern
 * MilpacDateGetterTest uses next door. The stubbed parent raises no errors of
 * its own, so an error on the entity afterwards can only have come from the
 * guard.
 *
 * What this cannot see: the read's own WHERE clause. The fake answers from the
 * parameters it is handed, so a guard that dropped the `user_id` filter and read
 * the whole table looks identical here. That mutation is caught on the dev stack
 * instead, where an unfiltered read finds other members' rows and refuses both
 * an ordinary profile edit and a move to an empty roster.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/OneMilpacPerMemberTest.php
 */

namespace NF\Rosters\Entity {

    /**
     * Stand-in for the vendor milpac entity, modelling only what the guard
     * touches: the two columns it reads, the insert/update distinction, the
     * error bag XF\Mvc\Entity\Entity::save() turns into a PrintableException,
     * and the database handle.
     */
    class RosterUser
    {
        public int $relation_id = 0;
        public int $user_id = 0;
        public bool $insert = true;

        /** @var object|null the fake standing in for XF's database adapter */
        public $fakeDb = null;

        /** @var array<int|string, mixed> */
        public array $errors = [];

        public function isInsert(): bool
        {
            return $this->insert;
        }

        public function error($message, $key = null): void
        {
            if ($key === null) {
                $this->errors[] = $message;
            } else {
                $this->errors[$key] = $message;
            }
        }

        public function getErrors(): array
        {
            return $this->errors;
        }

        public function db()
        {
            return $this->fakeDb;
        }

        protected function _preSave(): void {}
    }
}

namespace Cav7\RosterPatch\NF\Rosters\Entity {

    /** Stand-in for the XenForo-generated class-extension parent. */
    class XFCP_RosterUser extends \NF\Rosters\Entity\RosterUser {}
}

namespace {

    /** Stand-in for the XenForo facade. The guard uses it only for the phrase. */
    class XF
    {
        public static function phrase(string $title, array $params = []): string
        {
            return $title . ($params ? ' ' . json_encode($params) : '');
        }
    }
}

namespace Cav7\RosterPatch\Tests {

    use Cav7\RosterPatch\NF\Rosters\Entity\RosterUser;

    require __DIR__ . '/../NF/Rosters/Entity/RosterUser.php';

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
     * Stand-in for XF's database adapter, holding the rows `xf_nf_rosters_user`
     * carries for the fixture.
     *
     * It answers with the relation_ids whose user_id is among the parameters it
     * was handed, rather than by position or arity, so an added bind or a
     * reordered parameter list does not redden a test. Fixture user_ids (900+)
     * and relation_ids (1-99) are kept disjoint so "among the parameters" cannot
     * match the wrong column by coincidence.
     */
    class FakeDb
    {
        /** @var array<int, array{relation_id: int, user_id: int}> */
        public array $rows = [];

        public function fetchAllColumn(string $sql, $params = [], $column = null): array
        {
            $params = is_array($params) ? $params : [$params];

            $found = [];
            foreach ($this->rows as $row) {
                if (in_array($row['user_id'], $params, false)) {
                    $found[] = $row['relation_id'];
                }
            }

            return $found;
        }
    }

    /**
     * A milpac about to be saved, against a table holding $tableRows.
     *
     * @param array<int, array{relation_id: int, user_id: int}> $tableRows
     */
    function savingMilpac(array $tableRows, int $userId, int $relationId): RosterUser
    {
        $db = new FakeDb();
        $db->rows = $tableRows;

        $milpac = new RosterUser();
        $milpac->fakeDb = $db;
        $milpac->user_id = $userId;
        $milpac->relation_id = $relationId;
        // An insert is a row with no relation_id yet, which is the vendor's own
        // tell. Deriving it here keeps a fixture from claiming to be an insert
        // while carrying the relation_id of an existing row.
        $milpac->insert = ($relationId === 0);

        return $milpac;
    }

    /** Run the entity's own _preSave, the hook XenForo calls before the write. */
    function preSave(RosterUser $milpac): void
    {
        (new \ReflectionMethod($milpac, '_preSave'))->invoke($milpac);
    }

    // --- an add for a member who already holds a milpac is refused ----------
    // The bug: two POSTs to the roster add-user action naming one member both
    // succeeded, and the second row drew its own PUC set from EnlistmentDefaults'
    // insert-gated _postSave. Refusing in _preSave is what keeps that from
    // running at all.
    $secondAdd = savingMilpac(
        [['relation_id' => 11, 'user_id' => 900]],
        900,
        0
    );
    preSave($secondAdd);
    check(
        'an insert for a member who already holds a milpac is refused',
        count($secondAdd->getErrors()) === 1,
        'errors: ' . json_encode($secondAdd->getErrors())
    );

    // --- a milpac's own row does not count against it -----------------------
    // Every update of an existing milpac — a profile edit, a move, a uniform
    // upload — saves a row that is already in the table. Counting it as the
    // conflict would refuse every one of them, so the row being saved is
    // excluded by its own relation_id.
    $ordinaryEdit = savingMilpac(
        [['relation_id' => 11, 'user_id' => 900]],
        900,
        11
    );
    preSave($ordinaryEdit);
    check(
        'an update of the only row the member holds is allowed',
        $ordinaryEdit->getErrors() === [],
        'errors: ' . json_encode($ordinaryEdit->getErrors())
    );

    // --- the move path is covered too ---------------------------------------
    // A move is an UPDATE of an existing row, so a guard gated on isInsert()
    // would cover the add and leave the move exactly as it was. This is the row
    // that says otherwise: the rule is one milpac per member, whatever the save.
    $moveOntoHeldRoster = savingMilpac(
        [
            ['relation_id' => 11, 'user_id' => 900],
            ['relation_id' => 12, 'user_id' => 900],
        ],
        900,
        12
    );
    preSave($moveOntoHeldRoster);
    check(
        'an update is refused while another row exists for that member',
        count($moveOntoHeldRoster->getErrors()) === 1,
        'errors: ' . json_encode($moveOntoHeldRoster->getErrors())
    );

    // --- creating a milpac still works --------------------------------------
    // The everyday path: a new enlistment for a member holding nothing. Another
    // member's rows are in the table to keep the read honest.
    $firstMilpac = savingMilpac(
        [['relation_id' => 11, 'user_id' => 900]],
        901,
        0
    );
    preSave($firstMilpac);
    check(
        'an insert for a member who holds no milpac is allowed',
        $firstMilpac->getErrors() === [],
        'errors: ' . json_encode($firstMilpac->getErrors())
    );

    // --- Summary ------------------------------------------------------------
    if ($failures > 0) {
        echo "\n$failures test(s) FAILED\n";
        exit(1);
    }
    echo "\nAll tests passed\n";
    exit(0);
}
