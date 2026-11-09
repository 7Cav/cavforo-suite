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

        public function saveRefusal(RowKind $kind, int $recordId, int $urlMilpac, int $recordTypeId, bool $hasFile, int $pick, bool $cutover): ?string
        {
            $this->checked[] = [$kind, $recordId, $urlMilpac, $recordTypeId, $hasFile, $pick, $cutover];

            return $this->refusal;
        }

        public function heldPick(RowKind $kind, int $recordId): ?int
        {
            return $this->held;
        }

        public function attach(RowKind $kind, int $recordId, int $grantMemberId): void
        {
            $this->attached[] = [$kind, $recordId, $grantMemberId];
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
    check('the pick reaches the check before the save', $picked->links->checked[0][5] === 5 && $picked->saves === 1);

    $record = saved(['record_id' => 40, 'record_type_id' => 2, 'citation_pick' => '5'], null, RowKind::Record);
    check('a record attaches as a record', $record->links->attached === [[RowKind::Record, 40, 5]]);
    check('the check gets the submitted record type', $record->links->checked[0][3] === 2);

    $new = saved(['citation_pick' => '5'], fn (Roster $r) => $r->createsRow = 91);
    check('a new row attaches by the id the vendor gave it', $new->links->attached === [[RowKind::Award, 91, 5]]);

    $stale = saved(['record_id' => 40, 'citation_pick' => '5'], fn (Roster $r) => $r->createsRow = 92);
    check('a stale record id lands on the row the vendor made instead', $stale->links->attached === [[RowKind::Award, 92, 5]]);

    $refused = saved(['record_id' => 40, 'citation_pick' => '5'], fn (Roster $r) => $r->links->refusal = 'refused');
    check('a refusal writes nothing', $refused->saves === 0 && $refused->links->attached === []);

    $failed = saved(['record_id' => 40, 'citation_pick' => '5'], fn (Roster $r) => $r->reply = new Error('bad date'));
    check('an error from the save attaches nothing', $failed->saves === 1 && $failed->links->attached === []);

    $outsider = saved(['record_id' => 40, 'citation_pick' => '5'], fn (Roster $r) => $r->milpac->manage = false);
    check('a visitor who can\'t edit the row is left to the vendor', $outsider->saves === 1 && $outsider->links->checked === []);
    check('and their pick attaches nothing, whatever the vendor replied', $outsider->links->attached === []);

    $removed = saved(['record_id' => 40, 'citation_pick' => 'none']);
    check('No citation takes the vendor\'s removal path', $removed->deleteImageAtSave === true && $removed->links->attached === []);

    $kept = saved(['record_id' => 40, 'citation_pick' => 'keep']);
    check('Keep the current image leaves the vendor alone', $kept->deleteImageAtSave === false && $kept->links->attached === [] && $kept->links->checked === []);

    $plain = saved(['record_id' => 40]);
    check('a form with no picker runs as the vendor\'s did', $plain->saves === 1 && $plain->links->checked === []);

    $both = saved(['record_id' => 40, 'citation_pick' => '5', 'delete_image' => 1]);
    check('a pick and the delete box end on the pick', $both->deleteImageAtSave === true && $both->links->attached === [[RowKind::Award, 40, 5]]);

    $own = saved(['record_id' => 40, 'citation_pick' => '5', 'delete_image' => 1], fn (Roster $r) => $r->links->held = 5);
    check('the delete box with the row\'s own pick ends on no citation', $own->deleteImageAtSave === true && $own->links->attached === []);

    $file = saved(['record_id' => 40], fn (Roster $r) => $r->file = true);
    check('a file is checked with no pick', $file->links->checked[0][4] === true && $file->links->checked[0][5] === 0);

    $off = saved(['record_id' => 40, 'citation_pick' => '5'], fn (Roster $r) => $r->cutover = false);
    check('the check is told the option', $off->links->checked[0][6] === false);

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
