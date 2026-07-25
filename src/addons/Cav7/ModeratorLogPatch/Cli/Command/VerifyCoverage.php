<?php

namespace Cav7\ModeratorLogPatch\Cli\Command;

use Cav7\ModeratorLogPatch\AuthorshipRule;
use Cav7\ModeratorLogPatch\CategoryOverrides;
use Cav7\ModeratorLogPatch\ContentAuthor;
use Cav7\ModeratorLogPatch\ContentScope;
use Cav7\ModeratorLogPatch\HandlerCoverage;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use XF\Entity\Forum;
use XF\Entity\Thread;
use XF\Entity\User;
use XF\Mvc\Entity\Entity;
use XF\Service\Thread\CreatorService;

/**
 * Issue #187 — checks that this addon is actually in force on the install it is
 * run against.
 *
 * Everything the addon does hangs off class extensions against handler classes it
 * does not own, and XenForo applies an extension only to the class name its own
 * aliasing resolves the registered one to. Get that name wrong and the record
 * installs, validates, exports and does nothing — the same silent nothing the
 * addon exists to remove, and one no unit test can see. This command is the check
 * that can see it.
 *
 * The coverage and rule phases hardcode no content types: the list comes off the
 * install, from the `moderator_log_handler_class` content-type field, and they name
 * no third-party class and no forum, member or category of ours either. The
 * end-to-end phase is the exception and is written as one: it moderates a thread,
 * because a thread is what the issue was reported on, so `thread` and XenForo's own
 * thread classes appear there by name.
 *
 * Run:
 *   php cmd.php cav7-moderator-log-patch:verify <node> <user> <category>
 */
class VerifyCoverage extends Command
{
    /**
     * Prefixes the throwaway thread the end-to-end phase creates, so anything left
     * behind by an interrupted run is recognisable.
     */
    protected const PROBE_PREFIX = '[Cav7/ModeratorLogPatch verify] ';

    /**
     * One name from outside the author-reachable set, asked of every handler.
     * `stick` is the name the issue was reported on, which is why it is this one,
     * and the same reason the end-to-end phase below sticks a thread.
     */
    protected const OUTSIDE_SET_PROBE = 'stick';

    protected int $failures = 0;

    protected OutputInterface $out;

    /** Node id the operator named, for the content types filed under a node. */
    protected int $nodeId = 0;

    /** Category id the operator named, for the content types filed under a category. */
    protected int $categoryId = 0;

    /**
     * Per content type category ids, overriding the argument.
     *
     * @var array<string, int>
     */
    protected array $categoryByType = [];

    /**
     * Content types whose rule-phase sample was not content from the scope the
     * operator named, and why. Only a `fabricated` sample is not board content at
     * all; a `board` or `unscoped` one is a real row that nobody asked for.
     *
     * @var array<string, string>
     */
    protected array $sampleNotes = [];

    protected function configure(): void
    {
        $this
            ->setName('cav7-moderator-log-patch:verify')
            ->setDescription(
                'Checks that every registered moderator log handler resolves through this addon, and that a member with no moderator record is logged.'
            )
            ->addArgument(
                'node',
                InputArgument::REQUIRED,
                'Node id of a forum the run may create and delete a throwaway thread in.'
            )
            ->addArgument(
                'user',
                InputArgument::REQUIRED,
                'User id of the member the run acts as. Must hold no moderator record, or the run proves nothing.'
            )
            ->addArgument(
                'category',
                InputArgument::REQUIRED,
                'Category id to read sample content from, for the content types that are filed under one. Read-only.'
            )
            // Two content types filed under a category are filed under two different
            // id spaces: NF/Tickets keys on ticket_category_id and NF/Calendar on
            // category_id, and nothing relates the two. One number is right for both
            // only by coincidence, so the argument stays for the common case and this
            // is how an operator names the rest.
            ->addOption(
                'category-id',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Category id for one content type, as <content_type>=<id>, overriding the category argument for it. Repeatable.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->out = $output;

        $app = \XF::app();

        $actor = $app->em()->find(User::class, (int) $input->getArgument('user'));
        if (!$actor) {
            $output->writeln('<error>No member with that user id.</error>');
            return 1;
        }

        // A member who holds a moderator record was logged before this addon
        // existed, so a green run against one says nothing about the gate the addon
        // opens. Refused rather than warned about: a verification that can pass
        // without testing anything is worse than none.
        if ($actor->is_moderator) {
            $output->writeln(
                '<error>That member holds a moderator record, so they were already logged. Give a member who holds none.</error>'
            );
            return 1;
        }

        $forum = $app->em()->find(Forum::class, (int) $input->getArgument('node'));
        if (!$forum) {
            $output->writeln('<error>No forum with that node id.</error>');
            return 1;
        }

        $this->nodeId = (int) $input->getArgument('node');
        $this->categoryId = (int) $input->getArgument('category');

        // The overrides get the same treatment the node and user arguments get, and
        // for the same reason. A key was previously checked for its shape and
        // nothing else, so anything of the form <word>=<digits> was stored under a
        // key nothing would ever look up: a misspelling, a case difference, or a
        // correctly spelled type that has no category column at all were all
        // discarded in silence, and the run then read that type's category from the
        // positional argument and reported a scoped PASS against content the
        // operator had not named. Refused rather than warned about, because that is
        // the failure this whole command exists to make impossible.
        $registeredTypes = array_keys($app->getContentTypeField('moderator_log_handler_class'));
        sort($registeredTypes);

        $read = CategoryOverrides::parse((array) $input->getOption('category-id'));
        $this->categoryByType = $read['overrides'];

        $inputErrors = $read['errors'];
        foreach (CategoryOverrides::unknownKeys($this->categoryByType, $registeredTypes) as $unknown) {
            $inputErrors[] = "--category-id names '$unknown', which is not a content type this install registers a moderator log handler for";
        }
        // An override for a type filed under a node, or under nothing, provably
        // cannot be used: the search narrows a node-filed type by the node argument,
        // and a type with no scope column has no column to narrow at all.
        foreach (array_keys($this->categoryByType) as $overridden) {
            $filing = $this->filingOf($overridden);
            if ($filing !== null && $filing !== ContentScope::CATEGORY) {
                $inputErrors[] = "--category-id names '$overridden', which is filed "
                    . ($filing === ContentScope::NODE ? 'under a node' : 'under neither a node nor a category')
                    . ', so no category id can be used for it';
            }
        }

        if ($inputErrors) {
            foreach ($inputErrors as $inputError) {
                $output->writeln("<error>$inputError.</error>");
            }
            $output->writeln('The content types this install registers a moderator log handler for, and how each is filed:');
            foreach ($registeredTypes as $registered) {
                $output->writeln('  ' . $registered . ': ' . ($this->filingOf($registered) ?? 'no entity this install can build'));
            }
            $output->writeln('Only a content type filed under a category can take --category-id.');
            return 1;
        }

        // Echoed whether or not anything else goes wrong, so the run says out loud
        // which ids it is about to use for which type. Every confusion in this area
        // has been an override the operator believed was applied and was not.
        if ($this->categoryByType) {
            $applied = [];
            foreach ($this->categoryByType as $overridden => $overrideId) {
                $applied[] = "$overridden=$overrideId";
            }
            $output->writeln('<comment>Category id overrides applied: ' . implode(', ', $applied) . '</comment>');
        }

        $handlers = $this->checkCoverage();
        $this->checkRule($handlers);
        $this->checkEndToEnd($forum, $actor);

        if ($this->sampleNotes) {
            $output->writeln("\n<comment>Content types whose sample was not real content in the scope given</comment>");
            foreach ($this->sampleNotes as $type => $note) {
                $output->writeln("  $type: $note");
            }
        }

        if ($this->failures > 0) {
            $output->writeln("\n<error>{$this->failures} check(s) failed.</error>");
            return 1;
        }

        $output->writeln("\n<info>All checks passed.</info>");
        return 0;
    }

    /**
     * Every registered moderator log handler, resolved through XenForo's extension
     * system, keyed by content type. Fails any that does not carry this addon's
     * rule.
     *
     * The check is "is the rule anywhere in this object's class chain", not "is the
     * class name ours". Another addon may extend the same handler after this one,
     * which puts its class at the end of the chain and ours in the middle; that is
     * a working install, not a gap.
     *
     * @return array<string, object>
     */
    protected function checkCoverage(): array
    {
        $this->out->writeln('<comment>Registered moderator log handlers</comment>');

        $app = \XF::app();
        $logger = $app->logger()->moderatorLogger();

        $types = array_keys($app->getContentTypeField('moderator_log_handler_class'));
        sort($types);

        $handlers = [];

        foreach ($types as $type) {
            // A registered class whose file is missing throws from `new` rather than
            // answering null, and an uncaught throw here takes the rest of the run
            // with it: the remaining content types are never asked, neither later
            // phase runs, and no summary is printed. A partial upload or a botched
            // vendor upgrade is exactly when somebody runs this.
            $handler = null;
            $loadError = 'the registered handler class could not be loaded';
            try {
                $handler = $logger->handler($type, false);
            } catch (\Throwable $e) {
                $loadError = \get_class($e) . ': ' . $e->getMessage();
            }

            if (!$handler) {
                $this->check("$type resolves to a handler", false, $loadError);
                continue;
            }

            $handlers[$type] = $handler;

            $this->check(
                sprintf('%s resolves through this addon (%s)', $type, \get_class($handler)),
                HandlerCoverage::carriesRule($handler),
                'the class extension for this handler is missing, inactive, or registered against a name XenForo resolves differently'
            );

            // The user gate is replaced outright rather than deferred to, which is
            // right while the abstract handler is its only declaration anywhere in
            // the install. That is an assumption about somebody else's code, and NF
            // ships updates to both addons. If one adds a gate of its own, this
            // addon throws it away with no error, and every other check here still
            // passes.
            $discarded = HandlerCoverage::discardedUserGates($handler);
            $this->check(
                sprintf('%s: no user gate underneath is being discarded', $type),
                $discarded === [],
                'these classes declare ' . HandlerCoverage::USER_GATE . '() and this addon replaces it without deferring, so their rule about who may write to the log is gone: '
                    . implode(', ', $discarded)
            );
        }

        $this->check(
            'the install registers at least one moderator log handler',
            $types !== [],
            'reading the content-type field returned nothing, so this run checked no handler at all'
        );

        return $handlers;
    }

    /**
     * The rule itself, asked of each resolved handler for real.
     *
     * No writes: both gates are predicates, so this runs the handler's own code —
     * including any rule a third-party handler adds — against a real entity of its
     * content type without touching a row. Authorship is varied by changing who is
     * asking rather than by editing the content.
     *
     * Every author-reachable action is asked of every handler, not one of them. One
     * action proves nothing where the handler underneath happens to have a rule of
     * its own about that same action: it answers, the check passes, and this addon's
     * rule was never consulted. The set is read off the rule so it cannot fall behind
     * it, and covering the whole set means no handler's own rules can cover all of
     * what is asked.
     *
     * The handler underneath is built a second time without the extension and asked
     * the same questions, which is the only way this phase can show that the answers
     * changed because of this addon, and the only way it can exercise the promise
     * that a member holding a moderator record is answered exactly as before.
     *
     * @param array<string, object> $handlers
     */
    protected function checkRule(array $handlers): void
    {
        $this->out->writeln("\n<comment>The rule, per content type</comment>");
        $this->out->writeln(
            '  author-reachable actions asked of each handler: '
            . implode(', ', AuthorshipRule::AUTHOR_REACHABLE_ACTIONS)
        );

        $guest = $this->syntheticUser(0, false);
        $wrongCategoryScopes = [];

        foreach ($handlers as $type => $handler) {
            // The same scenario the coverage phase catches for reaches this read too,
            // and it used to abort the whole run from here: an `entity` field naming a
            // class that no longer loads throws from `em()->create()`, and a structure
            // that has gained a column the schema step never applied throws from the
            // finder. Either took every remaining content type, the end-to-end phase
            // and the summary with it.
            $sample = null;
            $readError = 'no entity of this content type could be built or found';
            try {
                $sample = $this->sampleContent($type);
            } catch (\Throwable $e) {
                $readError = \get_class($e) . ': ' . $e->getMessage();
            }

            if (!$sample) {
                $this->check("$type has content to ask about", false, $readError);
                continue;
            }

            $content = $sample['entity'];

            // Anything but a scoped sample still runs the handler's real code, and is
            // still not the content the operator asked about. Said out loud, and
            // carried into every label for this type, because a PASS earned against an
            // unsaved entity — or, for the types with no scope column at all, against
            // an arbitrary row from anywhere on the board — used to be byte-identical
            // to one earned against content somebody named.
            $provenance = $sample['provenance'];
            if ($provenance !== ContentScope::FROM_SCOPE) {
                $this->sampleNotes[$type] = $sample['note'];
                if ($provenance === ContentScope::FROM_BOARD && $sample['scope'] === ContentScope::CATEGORY) {
                    // Keyed by type and carrying the id the search actually used, so
                    // the failure below can name it. Naming the positional argument
                    // instead sent the operator to a category that was not the one
                    // read, and told them to use the option they had just used.
                    $wrongCategoryScopes[$type] = (int) $sample['scopeValue'];
                }
            }
            $label = $type . ($provenance === ContentScope::FROM_SCOPE ? '' : " [$provenance sample]");

            $authorId = ContentAuthor::userId($content);
            if ($authorId === null) {
                // Sample content with no author cannot answer the authorship half.
                // Reported rather than skipped quietly, and the two ways of having
                // no author are told apart, because the reader is a diagnosis.
                $this->check(
                    "$label sample content has a member author",
                    false,
                    $content->isValidColumn('user_id')
                        ? 'this sample is guest-written (user_id 0), so there is no member to compare the actor against'
                        : 'this content type carries no user_id column, so there is nothing here the rule can read as an author'
                );
                continue;
            }

            // For one registered content type the "author" is not an author. The
            // member handler logs actions taken against a member, and fills
            // content_user_id from that member's own id, so the rule reads "the
            // actor is the subject" where it says "the actor is the author". It is
            // inert — no action logged against a member is in the author-reachable
            // set — but the probe below cannot say which of the two it proved, so it
            // says neither. See
            // docs/adr/0006-two-cases-the-authorship-axis-cannot-express.md.
            if ($content->structure()->primaryKey === 'user_id') {
                $this->out->writeln(
                    "  <comment>NOTE</comment> $type: the content is a member, so \"the author\" here is the member being moderated, not somebody who wrote something. The authorship probe below is inert for this type."
                );
            }

            $author = $this->syntheticUser($authorId, false);
            $stranger = $this->syntheticUser($authorId + 1, false);
            $recordHolder = $this->syntheticUser($authorId + 1, true);
            // The fifth actor, and the only one that asks the question rule 1 exists
            // to answer: a member who holds a moderator record acting on content
            // they wrote. Without it nothing here exercises rule 1 against a real
            // handler, and the end-to-end phase structurally cannot, because it
            // refuses to run as a record holder.
            $recordHoldingAuthor = $this->syntheticUser($authorId, true);

            $this->check(
                "$label: a guest writes nothing",
                $handler->isLoggableUser($guest) === false,
                'the cron runner and the system actors run as a guest; opening the gate to them would log automated work as moderation'
            );
            $this->check(
                "$label: a member with no moderator record passes the user gate",
                $handler->isLoggableUser($author) === true,
                'this is the gate the addon opens; failing here means the extension is not in force for this content type'
            );
            $this->check(
                "$label: a member with a moderator record still passes the user gate",
                $handler->isLoggableUser($recordHolder) === true,
                'nobody who was logged before may stop being logged'
            );

            // One name from outside the author-reachable set, asked of every handler.
            // Not every content type can produce a `stick`; what the probe establishes
            // is that the rule hands a name from outside the set to the handler
            // underneath whoever wrote the content, and that the handler then logs it.
            $this->check(
                sprintf(
                    "%s: '%s', from outside the author-reachable set, is not withheld even from the author",
                    $label,
                    self::OUTSIDE_SET_PROBE
                ),
                $handler->isLoggable($content, self::OUTSIDE_SET_PROBE, $author) === true,
                'an action outside the set cannot be reached without authority over somebody else\'s content, so the rule has to hand it to the handler underneath'
            );

            $notWithheld = [];
            $notLogged = [];
            foreach (AuthorshipRule::AUTHOR_REACHABLE_ACTIONS as $action) {
                if ($handler->isLoggable($content, $action, $author) !== false) {
                    $notWithheld[] = $action;
                }
                if ($handler->isLoggable($content, $action, $stranger) !== true) {
                    $notLogged[] = $action;
                }
            }

            $this->check(
                sprintf(
                    '%s: every author-reachable action by the author is withheld (%d asked)',
                    $label,
                    count(AuthorshipRule::AUTHOR_REACHABLE_ACTIONS)
                ),
                $notWithheld === [],
                $notWithheld === []
                    ? ''
                    : 'a member tidying up their own content is not moderation, and these were still logged: ' . implode(', ', $notWithheld)
            );
            // The addon's contract for a stranger is deferral, not a yes, so a
            // handler that withholds an action from everybody is answering correctly
            // and lands here. The type list is read off the install by design, so a
            // ninth handler with such a rule is a red run that is not a gap; read the
            // handler before reading this as one.
            $this->check(
                sprintf(
                    '%s: every author-reachable action by somebody else is logged (%d asked)',
                    $label,
                    count(AuthorshipRule::AUTHOR_REACHABLE_ACTIONS)
                ),
                $notLogged === [],
                $notLogged === []
                    ? ''
                    : 'reaching another member\'s content took a permission over it, which makes this moderation, and these were withheld — unless the handler underneath withholds one of them from everybody, which is a correct deferral and not a gap: ' . implode(', ', $notLogged)
            );

            $this->checkAgainstUnpatched($label, $type, $handler, $content, $author, $recordHoldingAuthor);
        }

        // The node argument is validated as a forum before any phase runs. The
        // category ids cannot be validated the same way: there is no one category
        // entity, and the two content types filed under a category use unrelated id
        // spaces. What can be said is that a content type with content on the board
        // and none in the id used for it means that number is wrong for that type,
        // which is the mistyped-id case and used to produce an all-PASS run against
        // content from somewhere else.
        //
        // Each type is reported with the id the search used and where that id came
        // from, because the two are not the same question and the operator can only
        // fix the one they were given.
        $missed = [];
        foreach ($wrongCategoryScopes as $missedType => $missedId) {
            $missed[] = "$missedType (read category $missedId, "
                . (isset($this->categoryByType[$missedType]) ? 'from --category-id' : 'from the category argument')
                . ')';
        }
        $this->check(
            'every content type filed under a category has content in the category the run read for it',
            $wrongCategoryScopes === [],
            'these types have content on the board but none in the category the run read for them, so the rule phase did not read the content you named: '
                . implode(', ', $missed)
                . '. Give the right id for each with --category-id <content_type>=<id>'
        );
    }

    /**
     * The same handler built without the extension, asked the same questions.
     *
     * `Logger::handler()` runs the registered class through `XF::extendClass()` and
     * then constructs it; skipping that one call gives the handler as it was before
     * this addon existed. The class name comes off the content-type field, so
     * nothing here names a vendor class.
     *
     * Two things only this comparison can show. That the extension is what opened
     * the user gate for a member with no moderator record, rather than the handler
     * having answered that way all along. And that a member who does hold a record
     * is answered identically by both, which is the acceptance criterion rule 1
     * exists for and the one nothing else in this command exercises: the end-to-end
     * phase refuses to run as a record holder, and every other actor here holds none.
     */
    protected function checkAgainstUnpatched(
        string $label,
        string $type,
        object $handler,
        Entity $content,
        User $author,
        User $recordHoldingAuthor
    ): void
    {
        $registeredClass = (string) \XF::app()->getContentTypeFieldValue($type, 'moderator_log_handler_class');

        $unpatched = null;
        $why = "the content-type field names '$registeredClass', which does not exist";
        try {
            if ($registeredClass !== '' && class_exists($registeredClass)) {
                $unpatched = new $registeredClass($type);
            }
        } catch (\Throwable $e) {
            $why = \get_class($e) . ': ' . $e->getMessage();
        }

        $this->check(
            "$label: the handler underneath can be built without the extension",
            $unpatched !== null,
            'without it this run cannot show that this addon is what changed the answer, nor exercise the promise that a moderator-record holder is unaffected: ' . $why
        );
        if (!$unpatched) {
            return;
        }

        $this->check(
            "$label: the extension is what opened the user gate",
            $unpatched->isLoggableUser($author) === false && $handler->isLoggableUser($author) === true,
            'unpatched, this handler has to refuse a member who holds no moderator record. If it already accepted them then the gate above was never this addon\'s to open, and nothing here has tested it'
        );

        $probed = array_merge(AuthorshipRule::AUTHOR_REACHABLE_ACTIONS, [self::OUTSIDE_SET_PROBE]);
        $changed = [];
        foreach ($probed as $action) {
            $patchedSays = $handler->isLoggable($content, $action, $recordHoldingAuthor);
            $unpatchedSays = (bool) $unpatched->isLoggable($content, $action, $recordHoldingAuthor);
            if ($patchedSays !== $unpatchedSays) {
                $changed[] = $action;
            }
        }

        $this->check(
            sprintf(
                '%s: a member who holds a moderator record is answered exactly as the unpatched handler answers them (%d actions, on their own content)',
                $label,
                count($probed)
            ),
            $changed === [],
            'rule 1 steps aside for a record holder so the handler underneath decides, exactly as it did before this addon. These answers differ, so somebody who was being logged correctly has changed: ' . implode(', ', $changed)
        );
    }

    /**
     * The whole path, once: a member with no moderator record moderates a throwaway
     * thread and the rows land in the log.
     *
     * Once is enough. What the gates decide reaches the log through one shared
     * method on the handler base class, so a content type whose gates answer
     * correctly writes rows correctly. What this phase adds is proof that the
     * answers turn into rows at all.
     */
    protected function checkEndToEnd(Forum $forum, User $actor): void
    {
        $this->out->writeln("\n<comment>End to end, in the forum given</comment>");

        $app = \XF::app();
        $threadId = null;

        try {
            $thread = \XF::asVisitor($actor, function () use ($app, $forum)
            {
                /** @var CreatorService $creator */
                $creator = $app->service(CreatorService::class, $forum);
                $creator->setIsAutomated();
                $creator->setContent(
                    self::PROBE_PREFIX . 'throwaway thread',
                    'Created by cav7-moderator-log-patch:verify. Safe to delete.'
                );

                return $creator->save();
            });

            $threadId = (int) $thread->thread_id;

            \XF::asVisitor($actor, function () use ($thread)
            {
                $thread->sticky = true;
                $thread->save();
            });
            $this->check(
                'sticking a thread writes a stick entry',
                $this->actionsLogged('thread', $threadId) === ['stick'],
                'this is the reported bug: the member has the permission, holds no moderator record, and the entry never appeared'
            );

            \XF::asVisitor($actor, function () use ($thread)
            {
                $thread->title = self::PROBE_PREFIX . 'throwaway thread, retitled';
                $thread->save();
            });
            $this->check(
                'retitling their own thread writes nothing',
                $this->actionsLogged('thread', $threadId) === ['stick'],
                'a title is author-reachable, so its author retitling it is not moderation'
            );

            \XF::asVisitor($actor, function () use ($thread)
            {
                $thread->sticky = false;
                $thread->save();
            });
            $this->check(
                'unsticking it writes an unstick entry',
                $this->actionsLogged('thread', $threadId) === ['stick', 'unstick'],
                'the entry has to name the action that was taken, not just that something happened'
            );

            // The actor's IP is the one part of an entry this run cannot check.
            // `setupLogEntityActor()` fills `ip_address` from
            // `\XF::app()->request()->getIp()`, which reads `REMOTE_ADDR`; there is no
            // such server variable under the CLI, so the method skips the assignment
            // and the column keeps its empty default no matter how correct the entry
            // is. Asserting it here would fail every green run, so it is not selected
            // — check it from a browser action if you need it.
            $row = $app->db()->fetchRow(
                'SELECT user_id, log_date, discussion_content_type, discussion_content_id
                    FROM xf_moderator_log
                    WHERE content_type = ? AND content_id = ? ORDER BY moderator_log_id LIMIT 1',
                ['thread', $threadId]
            );
            $this->check(
                'the entry names the member who acted and when',
                $row && (int) $row['user_id'] === (int) $actor->user_id && (int) $row['log_date'] > 0,
                'an entry that does not say who did it is not an audit trail'
            );
            // The ACP list is a plain finder over the table, so the row above covers
            // it. The thread's own "Moderator actions" view is not: the repository
            // selects on the discussion columns instead, and a 0 or an empty string
            // there is an entry that shows in the ACP and nowhere else.
            $this->check(
                'the entry is reachable from the thread\'s moderator actions view',
                $row
                    && (string) $row['discussion_content_type'] === 'thread'
                    && (int) $row['discussion_content_id'] === $threadId,
                'the thread view is found through discussion_content_type and discussion_content_id, which are filled separately from the content columns the ACP list reads'
            );
        } finally {
            if ($threadId) {
                $this->removeProbeThread($threadId);
            }
        }
    }

    /**
     * A content entity of $type to ask the gates about, and where it came from.
     *
     * The scope column is discovered from the entity's own structure rather than
     * written down: a content type filed under a node is looked up in the node
     * given, one filed under a category in the category for that type. A content
     * type filed under neither — a post, a profile post or its comments, a member, a
     * ticket message — has no column to narrow on, so the newest row of that type on
     * the board is read instead. Everything here is read-only in every case; the
     * scope arguments bound which content the operator's own board contributes, not
     * whether anything is touched.
     *
     * Which scope column a type has, and what a sample found one way or another is
     * called, are both decided in `ContentScope`, where CI can execute them. The
     * caller reports the answer against every line it prints for the type, and the
     * names it can print are documented there. What matters here is that a type
     * with no scope column is never called `scoped`: its sample is the newest row of
     * that type anywhere on the board, and an operator reading `scoped` reads "the
     * content I named".
     *
     * @return array{entity: Entity, provenance: string, scope: string, scopeValue: int|null, note: string}|null
     */
    protected function sampleContent(string $type): ?array
    {
        $entity = $this->newEntity($type);
        if (!$entity) {
            return null;
        }

        $filing = ContentScope::of(array_keys($entity->structure()->columns));
        $scope = $filing['scope'];
        $scopeColumn = $filing['column'];
        // The entity's own short name, which is what the content-type field holds and
        // what the finder takes. Read off the structure rather than fetched a second
        // time, so the rows read are of the type the columns above were read from.
        $shortName = $entity->structure()->shortName;
        $scopeValue = null;
        if ($scopeColumn !== null) {
            // Resolved once, by the unit CI can run, and reported wherever the id is
            // named. A failure message that interpolated the positional argument
            // while the search had used an override sent the operator to inspect a
            // category that had nothing to do with the miss.
            $scopeValue = CategoryOverrides::effectiveId(
                $type,
                $scope,
                $this->categoryByType,
                $scope === ContentScope::NODE ? $this->nodeId : $this->categoryId
            );
        }

        $primaryKey = $entity->structure()->primaryKey;

        // Both reads in one place, and the label decided once from what they found,
        // so no return can name a provenance the search did not earn.
        $found = null;
        $foundInScope = false;
        if ($scopeColumn !== null) {
            $found = $this->newestOf($shortName, $primaryKey, $scopeColumn, $scopeValue);
            $foundInScope = $found !== null;
        }
        if (!$found) {
            $found = $this->newestOf($shortName, $primaryKey, null, null);
        }

        $provenance = ContentScope::provenance($scopeColumn, $foundInScope, $found !== null);

        if (!$found) {
            // Nothing of this type anywhere. An unsaved entity still carries the
            // content type's real class, so the handler's own override runs; give it
            // an author so the authorship half has something to compare.
            if ($entity->isValidColumn('user_id')) {
                $entity->setTrusted('user_id', 1);
            }
            $found = $entity;
        }

        return [
            'entity' => $found,
            'provenance' => $provenance,
            'scope' => $scope,
            'scopeValue' => $scopeValue,
            'note' => $this->sampleNote($provenance, $scopeColumn, $scopeValue),
        ];
    }

    /**
     * An unsaved entity of $type, or null when the content type names none.
     *
     * Throws rather than answering null when the class it names cannot be built:
     * that is a broken install and the caller reports it per content type, with the
     * exception's own words, instead of turning it into "no content found".
     */
    protected function newEntity(string $type): ?Entity
    {
        $entityShortName = (string) \XF::app()->getContentTypeFieldValue($type, 'entity');
        if ($entityShortName === '') {
            return null;
        }

        return \XF::app()->em()->create($entityShortName);
    }

    /**
     * How $type is filed, as `ContentScope` names it, or null when this install
     * cannot build an entity of the type to read its columns.
     *
     * Answers null rather than throwing: this runs while the arguments are still
     * being checked, and a content type whose entity will not load is the coverage
     * phase's to report loudly, not a reason to refuse the run before any phase has
     * said anything.
     */
    protected function filingOf(string $type): ?string
    {
        try {
            $entity = $this->newEntity($type);
            if (!$entity) {
                return null;
            }

            return ContentScope::of(array_keys($entity->structure()->columns))['scope'];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The newest row of the entity type named, optionally narrowed to one column
     * value.
     *
     * @param string              $entityShortName As the content-type field holds it.
     * @param string|list<string> $primaryKey      As the entity's structure declares it; a
     *                                             compound key cannot be ordered on, so
     *                                             such a type is read unordered.
     */
    protected function newestOf(string $entityShortName, $primaryKey, ?string $column, $value): ?Entity
    {
        $finder = \XF::app()->finder($entityShortName);
        if ($column !== null) {
            $finder->where($column, $value);
        }
        if (\is_string($primaryKey)) {
            $finder->order($primaryKey, 'DESC');
        }

        return $finder->fetchOne();
    }

    /**
     * What to tell the operator about a sample that is not content they named.
     *
     * The `scoped` case is here for completeness; the caller prints a note only for
     * the provenances that need one.
     */
    protected function sampleNote(string $provenance, ?string $scopeColumn, $scopeValue): string
    {
        if ($provenance === ContentScope::FROM_SCOPE) {
            return "$scopeColumn $scopeValue";
        }

        if ($provenance === ContentScope::FROM_ANYWHERE) {
            return 'this content type is filed under neither a node nor a category, so there is no column to narrow it: the newest of this type anywhere on the board was read, and neither argument you gave bounded it';
        }

        if ($provenance === ContentScope::FROM_BOARD) {
            return "nothing with $scopeColumn $scopeValue, so the newest of this type anywhere on the board was read instead";
        }

        return 'the board holds no content of this type, so the checks below ran against an unsaved entity: they exercise the handler\'s code, not this board\'s content';
    }

    /**
     * A member who exists only for the length of a predicate call. Never saved, so
     * a run cannot leave one behind, and the two gates read nothing off a member
     * beyond these two values.
     */
    protected function syntheticUser(int $userId, bool $holdsModeratorRecord): User
    {
        /** @var User $user */
        $user = \XF::app()->em()->create(User::class);
        $user->setTrusted('user_id', $userId);
        $user->setTrusted('is_moderator', $holdsModeratorRecord);

        return $user;
    }

    /**
     * The actions logged against one piece of content, oldest first.
     *
     * @return list<string>
     */
    protected function actionsLogged(string $contentType, int $contentId): array
    {
        return \XF::db()->fetchAllColumn(
            'SELECT action FROM xf_moderator_log
                WHERE content_type = ? AND content_id = ? ORDER BY moderator_log_id',
            [$contentType, $contentId]
        );
    }

    /**
     * Removes the throwaway thread and every log entry about it.
     *
     * The log rows go last on purpose, and the invariant is that no entry about this
     * thread outlives the run whether or not the delete writes one. Under the CLI it
     * does not: the delete runs outside `XF::asVisitor()`, so the actor is the CLI
     * guest and this addon's own user gate refuses `user_id` 0. Ordered defensively
     * anyway, because a hard delete is a logged action and anything that gives the
     * delete a real actor would leave its `delete_hard` entry behind.
     */
    protected function removeProbeThread(int $threadId): void
    {
        $thread = \XF::app()->em()->find(Thread::class, $threadId);
        if ($thread) {
            $thread->delete();
        }

        \XF::db()->delete(
            'xf_moderator_log',
            'content_type = ? AND content_id = ?',
            ['thread', $threadId]
        );
    }

    protected function check(string $label, bool $ok, string $detail = ''): void
    {
        if ($ok) {
            $this->out->writeln("  <info>PASS</info> $label");
            return;
        }

        $this->failures++;
        $this->out->writeln("  <error>FAIL</error> $label" . ($detail !== '' ? " — $detail" : ''));
    }
}
