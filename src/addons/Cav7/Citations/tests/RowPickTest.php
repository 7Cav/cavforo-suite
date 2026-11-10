<?php

/**
 * What runs around the vendor's row save: the refusal first, the pick after. The vendor's
 * controller is a stand-in here, so its real save actions are checked on a dev stack.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/RowPickTest.php
 */

namespace {
    class XF
    {
        public static function phrase(string $name, array $params = []): string
        {
            return $name . json_encode($params);
        }
    }
}

namespace XF {
    class PrintableException extends \Exception
    {
    }
}

namespace XF\Mvc {
    class ParameterBag
    {
        public int $relation_id = 7;
    }
}

namespace XF\Mvc\Reply {
    class AbstractReply
    {
    }

    class Redirect extends AbstractReply
    {
        public mixed $message = '';

        public function setMessage(mixed $message): void
        {
            $this->message = $message;
        }
    }

    class Error extends AbstractReply
    {
        public function __construct(public mixed $message = null)
        {
        }
    }

    class View extends AbstractReply
    {
        public array $params = [];

        public function setParam(string $key, mixed $value): void
        {
            $this->params[$key] = $value;
        }

        public function getParam(string $key): mixed
        {
            return $this->params[$key] ?? null;
        }
    }
}

namespace Cav7\Citations\Service {
    use Cav7\Citations\Storage\RowKind;

    /** Records what the controller asks of the real service, whose rules have their own tests. */
    class GrantLinks
    {
        public ?string $refusal = null;

        /** @var list<array<int, mixed>> */
        public array $checked = [];

        /** @var list<array{RowKind, int, int}> */
        public array $attached = [];

        /** The grant member the row holds before the save. */
        public ?int $held = null;

        /** Why attach refuses, as a removal landing between the check and the attach would. */
        public ?string $attachRefusal = null;

        public function saveRefusal(RowKind $kind, int $recordId, int $urlMilpac, int $recordTypeId, bool $hasFile, bool $clears, int $pick, bool $cutover): ?string
        {
            $this->checked[] = [$kind, $recordId, $urlMilpac, $recordTypeId, $hasFile, $clears, $pick, $cutover];

            return $this->refusal;
        }

        public function heldPick(RowKind $kind, int $recordId): ?int
        {
            return $this->held;
        }

        public function attach(RowKind $kind, int $recordId, int $grantMemberId): void
        {
            if ($this->attachRefusal !== null) {
                throw new \XF\PrintableException($this->attachRefusal);
            }
            $this->attached[] = [$kind, $recordId, $grantMemberId];
            $this->attachedHolding = $this->holding;
        }

        /** Rows that exist before the save. */
        public array $rows = [40];

        public ?int $holding = null;
        public ?int $attachedHolding = null;
        public bool $rolledBack = false;

        public function makesRow(RowKind $kind, int $recordId): bool
        {
            return !in_array($recordId, $this->rows, true);
        }

        /** Stands in for the transaction: an exception out of $save undoes it. */
        public function holdingPick(int $grantMemberId, \Closure $save): mixed
        {
            $this->holding = $grantMemberId;
            try {
                return $save();
            } catch (\Throwable $e) {
                $this->rolledBack = true;
                throw $e;
            } finally {
                $this->holding = null;
            }
        }

        public function picker(int $relationId): array
        {
            return [['grant_member_id' => 5, 'for' => $relationId]];
        }
    }
}

namespace Cav7\Citations\NF\Rosters\Pub\Controller {
    use Cav7\Citations\Service\GrantLinks;
    use XF\Mvc\ParameterBag;
    use XF\Mvc\Reply\AbstractReply;
    use XF\Mvc\Reply\Error;
    use XF\Mvc\Reply\Redirect;
    use XF\Mvc\Reply\View;

    /** The vendor's controller as far as the extension can see it. */
    class XFCP_Roster
    {
        public array $input = [];
        public bool $post = true;
        public bool $file = false;
        public bool $canManage = true;
        public bool $cutover = true;
        public object $milpac;
        public GrantLinks $links;
        public object $request;

        /** What the save does: the reply, and the id of a row it creates. */
        public AbstractReply $reply;
        public ?int $createsRow = null;
        public ?\Throwable $saveThrows = null;
        public int $saves = 0;
        public ?bool $deleteImageAtSave = null;

        public function __construct()
        {
            $this->links = new GrantLinks();
            $this->reply = new Redirect();
            $this->milpac = new class {
                public int $relation_id = 7;
                public ?object $newRow = null;
                public bool $manage = true;

                public function canManageAwards(): bool
                {
                    return $this->manage;
                }

                public function canManageRecords(): bool
                {
                    return $this->manage;
                }
            };
            $controller = $this;
            $this->request = new class ($controller) {
                public function __construct(private XFCP_Roster $controller)
                {
                }

                public function getFile(string $key, bool $multiple, bool $errorOnFail): mixed
                {
                    return $this->controller->file ? new \stdClass() : null;
                }

                public function set(string $key, mixed $value): void
                {
                    $this->controller->input[$key] = $value;
                }
            };
        }

        protected function isPost(): bool
        {
            return $this->post;
        }

        protected function filter(string $key, string $type): mixed
        {
            $value = $this->input[$key] ?? null;

            return $type === 'str' ? (string) $value : (int) $value;
        }

        protected function assertRosterUserExists(int $id): object
        {
            return $this->milpac;
        }

        protected function options(): object
        {
            return (object) ['cav7CitCutover' => $this->cutover];
        }

        protected function error(mixed $message): Error
        {
            return new Error($message);
        }

        protected function service(string $class): GrantLinks
        {
            return $this->links;
        }

        private function vendorSave(): AbstractReply
        {
            $this->saves++;
            if ($this->saveThrows !== null) {
                throw $this->saveThrows;
            }
            $this->deleteImageAtSave = (bool) ($this->input['delete_image'] ?? false);
            if ($this->createsRow !== null) {
                $this->milpac->newRow = (object) ['record_id' => $this->createsRow];
            }
            return $this->reply;
        }

        public function actionProfileAwardsSave(ParameterBag $params): AbstractReply
        {
            return $this->vendorSave();
        }

        public function actionProfileServiceRecordSave(ParameterBag $params): AbstractReply
        {
            return $this->vendorSave();
        }

        private function form(): AbstractReply
        {
            $view = new View();
            $view->setParam('rosterUser', $this->milpac);

            return $view;
        }

        public function actionProfileAwardsAdd(ParameterBag $params): AbstractReply
        {
            return $this->form();
        }

        public function actionAwardsEdit(ParameterBag $params): AbstractReply
        {
            return $this->canManage ? $this->form() : new Error('no permission');
        }

        public function actionProfileServiceRecordAdd(ParameterBag $params): AbstractReply
        {
            return $this->form();
        }

        public function actionServiceRecordEdit(ParameterBag $params): AbstractReply
        {
            return $this->form();
        }
    }
}

namespace Cav7\Citations\Tests {
    require __DIR__ . '/../Storage/RowKind.php';
    require __DIR__ . '/../Grant/RowSaveRules.php';
    require __DIR__ . '/../NF/Rosters/Pub/Controller/Roster.php';

    use Cav7\Citations\NF\Rosters\Pub\Controller\Roster;
    use Cav7\Citations\Storage\RowKind;
    use XF\Mvc\ParameterBag;
    use XF\Mvc\Reply\Error;
    use XF\Mvc\Reply\Redirect;
    use XF\Mvc\Reply\View;

    $failures = 0;

    function check(string $label, bool $ok, string $detail = ''): void
    {
        global $failures;
        if ($ok) {
            echo "PASS: $label\n";
        } else {
            $failures++;
            echo "FAIL: $label" . ($detail !== '' ? ": $detail" : '') . "\n";
        }
    }

    /** @param array<string, mixed> $input */
    function saved(array $input, ?\Closure $setup = null, RowKind $kind = RowKind::Award): Roster
    {
        $roster = new Roster();
        $roster->input = $input;
        if ($setup !== null) {
            $setup($roster);
        }

        $kind === RowKind::Award
            ? $roster->actionProfileAwardsSave(new ParameterBag())
            : $roster->actionProfileServiceRecordSave(new ParameterBag());

        return $roster;
    }

    $picked = saved(['record_id' => 40, 'citation_pick' => '5']);
    check('a pick on an existing row attaches to that row', $picked->links->attached === [[RowKind::Award, 40, 5]]);
    check('the pick reaches the check before the save', $picked->links->checked[0][6] === 5 && $picked->saves === 1);

    $record = saved(['record_id' => 40, 'record_type_id' => 2, 'citation_pick' => '5'], null, RowKind::Record);
    check('a record attaches as a record', $record->links->attached === [[RowKind::Record, 40, 5]]);
    check('the check gets the submitted record type', $record->links->checked[0][3] === 2);

    $new = saved(['citation_pick' => '5'], fn (Roster $r) => $r->createsRow = 91);
    check('a new row attaches by the id the vendor gave it', $new->links->attached === [[RowKind::Award, 91, 5]]);
    check('and saves and attaches with the pick held', $new->links->attachedHolding === 5);
    check('an existing row saves as before', $picked->links->attachedHolding === null);

    $stale = saved(['record_id' => 41, 'citation_pick' => '5'], fn (Roster $r) => $r->createsRow = 92);
    check('a stale record id lands on the row the vendor made instead', $stale->links->attached === [[RowKind::Award, 92, 5]]);
    check('and counts as a new row', $stale->links->attachedHolding === 5);

    $undone = new Roster();
    $undone->input = ['citation_pick' => '5'];
    $undone->createsRow = 93;
    $undone->links->attachRefusal = 'That citation is gone.';
    $undoneReply = $undone->actionProfileAwardsSave(new ParameterBag());
    check(
        'a refused attach on a new row takes the row back, so a resubmit can\'t duplicate it',
        $undone->saves === 1 && $undone->links->rolledBack && $undoneReply instanceof Error
            && $undoneReply->message === \XF::phrase('cav7_cit_row_not_saved', ['reason' => 'That citation is gone.']),
        json_encode($undoneReply)
    );

    $vendorRefusal = new \XF\PrintableException('The vendor refused the row.');
    $vendorRefused = new Roster();
    $vendorRefused->input = ['citation_pick' => '5'];
    $vendorRefused->saveThrows = $vendorRefusal;
    try {
        $vendorRefused->actionProfileAwardsSave(new ParameterBag());
        $escaped = null;
    } catch (\XF\PrintableException $e) {
        $escaped = $e;
    }
    check('the vendor\'s own refusal of a new row reaches the visitor as it is', $escaped === $vendorRefusal);

    $refused = saved(['record_id' => 40, 'citation_pick' => '5'], fn (Roster $r) => $r->links->refusal = 'refused');
    check('a refusal writes nothing', $refused->saves === 0 && $refused->links->attached === []);

    $failed = saved(['record_id' => 40, 'citation_pick' => '5'], fn (Roster $r) => $r->reply = new Error('bad date'));
    check('an error from the save attaches nothing', $failed->saves === 1 && $failed->links->attached === []);

    $late = new Roster();
    $late->input = ['record_id' => 40, 'citation_pick' => '5'];
    $late->links->attachRefusal = 'That citation is gone.';
    $lateReply = $late->actionProfileAwardsSave(new ParameterBag());
    check(
        'a refused attach after the save says the row saved and why the citation is missing',
        $late->saves === 1 && $late->links->attached === [] && $lateReply instanceof Error
            && $lateReply->message === \XF::phrase('cav7_cit_saved_without_citation', ['reason' => 'That citation is gone.']),
        json_encode($lateReply)
    );

    $outsider = saved(['record_id' => 40, 'citation_pick' => '5'], fn (Roster $r) => $r->milpac->manage = false);
    check('a visitor who can\'t edit the row is left to the vendor', $outsider->saves === 1 && $outsider->links->checked === []);
    check('and their pick attaches nothing, whatever the vendor replied', $outsider->links->attached === []);

    $removed = saved(['record_id' => 40, 'citation_pick' => 'none']);
    check('No citation takes the vendor\'s removal path', $removed->deleteImageAtSave === true && $removed->links->attached === []);
    check('and is checked as a clear', $removed->links->checked[0][5] === true);

    $deleteBox = saved(['record_id' => 40, 'delete_image' => 1]);
    check('the delete box alone is checked as a clear before the save', $deleteBox->links->checked[0][5] === true && $deleteBox->saves === 1);

    $kept = saved(['record_id' => 40, 'citation_pick' => 'keep']);
    check('Keep the current image leaves the vendor alone', $kept->deleteImageAtSave === false && $kept->links->attached === [] && $kept->links->checked === []);

    $plain = saved(['record_id' => 40]);
    check('a form with no picker runs as the vendor\'s did', $plain->saves === 1 && $plain->links->checked === []);

    $both = saved(['record_id' => 40, 'citation_pick' => '5', 'delete_image' => 1]);
    check('a pick and the delete box end on the pick', $both->deleteImageAtSave === true && $both->links->attached === [[RowKind::Award, 40, 5]]);

    $own = saved(['record_id' => 40, 'citation_pick' => '5', 'delete_image' => 1], fn (Roster $r) => $r->links->held = 5);
    check('the delete box with the row\'s own pick ends on no citation', $own->deleteImageAtSave === true && $own->links->attached === []);

    $file = saved(['record_id' => 40], fn (Roster $r) => $r->file = true);
    check('a file is checked with no pick', $file->links->checked[0][4] === true && $file->links->checked[0][6] === 0);

    $off = saved(['record_id' => 40, 'citation_pick' => '5'], fn (Roster $r) => $r->cutover = false);
    check('the check is told the option', $off->links->checked[0][7] === false);

    foreach (['actionProfileAwardsAdd', 'actionAwardsEdit', 'actionProfileServiceRecordAdd', 'actionServiceRecordEdit'] as $action) {
        $on = new Roster();
        $reply = $on->$action(new ParameterBag());
        check("$action: the picker reaches the form after cutover", $reply instanceof View && $reply->getParam('citationPicker')[0]['for'] === 7);

        $before = new Roster();
        $before->cutover = false;
        check("$action: before cutover the form gets nothing", $before->$action(new ParameterBag())->getParam('citationPicker') === null);
    }

    $denied = new Roster();
    $denied->canManage = false;
    check('a refused form stays the vendor\'s reply', $denied->actionAwardsEdit(new ParameterBag()) instanceof Error);

    exit($failures === 0 ? 0 : 1);
}
