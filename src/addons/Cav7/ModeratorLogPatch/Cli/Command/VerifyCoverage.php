<?php

namespace Cav7\ModeratorLogPatch\Cli\Command;

use Cav7\ModeratorLogPatch\AuthorshipLogging;
use Cav7\ModeratorLogPatch\AuthorshipRule;
use Cav7\ModeratorLogPatch\ContentAuthor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use XF\Entity\Forum;
use XF\Entity\Thread;
use XF\Entity\User;
use XF\Mvc\Entity\Entity;
use XF\Service\Thread\CreatorService;

use function in_array;

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
 * It hardcodes no content types: the list comes off the install, from the
 * `moderator_log_handler_class` content-type field. It names no third-party class
 * and no forum, member or category of ours either — the three it exercises are
 * arguments.
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
     * A name outside the author-reachable set, used to ask each handler what it does
     * with one. Not every content type can produce this particular action; what the
     * probe establishes is that the rule does not withhold a name from outside the
     * set, whoever wrote the content, and that the handler underneath then logs it.
     * `stick` is the name the issue was reported on, which is why it is this one.
     */
    protected const MODERATION_ACTION = 'stick';

    /**
     * @var int
     */
    protected $failures = 0;

    /**
     * @var OutputInterface
     */
    protected $out;

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

        $handlers = $this->checkCoverage();
        $this->checkRule($handlers, (int) $input->getArgument('node'), (int) $input->getArgument('category'));
        $this->checkEndToEnd($forum, $actor);

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
            $handler = $logger->handler($type, false);
            if (!$handler) {
                $this->check("$type resolves to a handler", false, 'the registered handler class could not be loaded');
                continue;
            }

            $handlers[$type] = $handler;

            $this->check(
                sprintf('%s resolves through this addon (%s)', $type, \get_class($handler)),
                $this->carriesRule($handler),
                'the class extension for this handler is missing, inactive, or registered against a name XenForo resolves differently'
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
     * @param array<string, object> $handlers
     */
    protected function checkRule(array $handlers, int $nodeId, int $categoryId): void
    {
        $this->out->writeln("\n<comment>The rule, per content type</comment>");
        $this->out->writeln(
            '  author-reachable actions asked of each handler: '
            . implode(', ', AuthorshipRule::AUTHOR_REACHABLE_ACTIONS)
        );

        $guest = $this->syntheticUser(0, false);

        foreach ($handlers as $type => $handler) {
            $content = $this->sampleContent($type, $nodeId, $categoryId);
            if (!$content) {
                $this->check("$type has content to ask about", false, 'no entity of this content type could be built or found');
                continue;
            }

            $authorId = ContentAuthor::userId($content);
            if ($authorId === null) {
                // Sample content with no author cannot answer the authorship half.
                // Reported rather than skipped quietly.
                $this->check("$type sample content has an author", false, 'every registered handler logs content with an author; this sample has none');
                continue;
            }

            $author = $this->syntheticUser($authorId, false);
            $stranger = $this->syntheticUser($authorId + 1, false);
            $recordHolder = $this->syntheticUser($authorId + 1, true);

            $this->check(
                "$type: a guest writes nothing",
                $handler->isLoggableUser($guest) === false,
                'the cron runner and the system actors run as a guest; opening the gate to them would log automated work as moderation'
            );
            $this->check(
                "$type: a member with no moderator record passes the user gate",
                $handler->isLoggableUser($author) === true,
                'this is the gate the addon opens; failing here means the extension is not in force for this content type'
            );
            $this->check(
                "$type: a member with a moderator record still passes the user gate",
                $handler->isLoggableUser($recordHolder) === true,
                'nobody who was logged before may stop being logged'
            );

            $this->check(
                sprintf(
                    "%s: '%s', from outside the author-reachable set, is not withheld even from the author",
                    $type,
                    self::MODERATION_ACTION
                ),
                $handler->isLoggable($content, self::MODERATION_ACTION, $author) === true,
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
                    $type,
                    count(AuthorshipRule::AUTHOR_REACHABLE_ACTIONS)
                ),
                $notWithheld === [],
                $notWithheld === []
                    ? ''
                    : 'a member tidying up their own content is not moderation, and these were still logged: ' . implode(', ', $notWithheld)
            );
            $this->check(
                sprintf(
                    '%s: every author-reachable action by somebody else is logged (%d asked)',
                    $type,
                    count(AuthorshipRule::AUTHOR_REACHABLE_ACTIONS)
                ),
                $notLogged === [],
                $notLogged === []
                    ? ''
                    : 'reaching another member\'s content took a permission over it, which makes this moderation, and these were withheld: ' . implode(', ', $notLogged)
            );
        }
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

            $row = $app->db()->fetchRow(
                'SELECT user_id, ip_address, log_date FROM xf_moderator_log
                    WHERE content_type = ? AND content_id = ? ORDER BY moderator_log_id LIMIT 1',
                ['thread', $threadId]
            );
            $this->check(
                'the entry names the member who acted and when',
                $row && (int) $row['user_id'] === (int) $actor->user_id && (int) $row['log_date'] > 0,
                'an entry that does not say who did it is not an audit trail'
            );
        } finally {
            if ($threadId) {
                $this->removeProbeThread($threadId);
            }
        }
    }

    /**
     * Whether this addon's rule is anywhere in $handler's class chain.
     *
     * Asked of the trait rather than of the class name. The name only tells you
     * which extension XenForo put last, and another addon extending the same
     * handler after this one is a working install; the trait is the thing that has
     * to be there.
     */
    protected function carriesRule(object $handler): bool
    {
        $chain = array_merge(
            [\get_class($handler)],
            array_values(class_parents($handler) ?: [])
        );

        foreach ($chain as $class) {
            if (in_array(AuthorshipLogging::class, class_uses($class) ?: [], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A content entity of $type to ask the gates about, preferring real content in
     * the scopes the operator named.
     *
     * The scope column is discovered from the entity's own structure rather than
     * written down: a content type filed under a node is looked up in the node
     * given, one filed under a category in the category given. Nothing outside those
     * two scopes is read, and nothing is written in any case.
     *
     * Content types that are filed under neither, and scopes that hold nothing, fall
     * back to an unsaved entity of the right class. That still runs the real
     * handler's real code — both gates read the actor and the content's author and
     * nothing else — it just cannot say the content exists.
     */
    protected function sampleContent(string $type, int $nodeId, int $categoryId): ?Entity
    {
        $app = \XF::app();

        $entityClass = $app->getContentTypeFieldValue($type, 'entity');
        if (!$entityClass) {
            return null;
        }

        $entity = $app->em()->create($entityClass);
        $columns = $entity->structure()->columns;

        $scopeColumn = null;
        $scopeValue = null;
        foreach ($columns as $name => $definition) {
            if ($name === 'node_id') {
                $scopeColumn = $name;
                $scopeValue = $nodeId;
                break;
            }
            if (substr($name, -12) === '_category_id' || $name === 'category_id') {
                $scopeColumn = $name;
                $scopeValue = $categoryId;
                break;
            }
        }

        $finder = $app->finder($entityClass);
        if ($scopeColumn !== null) {
            $finder->where($scopeColumn, $scopeValue);
        }

        $primaryKey = $entity->structure()->primaryKey;
        if (\is_string($primaryKey)) {
            $finder->order($primaryKey, 'DESC');
        }

        $found = $finder->fetchOne();
        if ($found) {
            return $found;
        }

        // Nothing in scope. An unsaved entity still carries the content type's real
        // class, so the handler's own override runs; give it an author so the
        // authorship half has something to compare.
        if ($entity->isValidColumn('user_id')) {
            $entity->setTrusted('user_id', 1);
        }

        return $entity;
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
     * The log rows go last on purpose: deleting a thread is itself a logged action,
     * so clearing them first leaves the `delete_hard` entry this run caused behind.
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
