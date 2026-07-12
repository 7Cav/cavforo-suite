<?php

namespace Cav7\MilpacMention\XF\Service\Message;

use Cav7\MilpacMention\MilpacResolver;
use Cav7\MilpacMention\MilpacStash;
use XF\Repository\UserRepository;

/**
 * The shared detection hook (spec §2.2). Every mention surface — post, profile
 * post, profile-post comment, report, ticket message — runs its message through
 * XF\Service\Message\PreparerService::prepare(), so extending this one class finds
 * milpac links on every surface at once and can never miss one. NF/Tickets extends
 * the same class through the legacy XF\Service\Message\Preparer alias, so this
 * extension sits in the one inheritance chain rather than being bypassed there
 * (build-time check §8.1).
 *
 * After the parent runs, the message is BbCode-processed text with every roster
 * href literal and the @-mention set already computed, so detection, reverse
 * resolution, and the shared author cap all run here. The surviving milpac-only
 * recipients are stashed against the content entity for the surface's firing
 * extension to raise the distinct milpac_mention alert; this hook fires nothing
 * itself. Because only the creator/replier paths build a notifier (never the
 * editor path), a link added by a later edit stashes but never fires — "new
 * content only", with no edit-time code (rule §2.5.4).
 */
class PreparerService extends XFCP_PreparerService
{
    /**
     * Signature matched exactly against the installed XF 2.3 parent
     * (build-time check §8.2): prepare($message, $checkValidity = true).
     */
    public function prepare($message, $checkValidity = true)
    {
        $message = parent::prepare($message, $checkValidity);

        $this->stashMilpacMentions($message);

        return $message;
    }

    protected function stashMilpacMentions($message)
    {
        $entity = $this->getMessageEntity();
        if (!$entity) {
            // Preview / validation with no owning content: nothing can fire.
            return;
        }

        $relationIds = MilpacResolver::extractRelationIds((string) $message);
        if (!$relationIds) {
            return;
        }

        $milpacUserIds = MilpacResolver::resolveUserIds($relationIds);
        if (!$milpacUserIds) {
            return;
        }

        // The @-mention set parent::prepare() already resolved, keyed by user_id.
        $atUserIds = array_keys($this->getMentionedUsers());

        $author = $entity->User ?? null;
        if ($author) {
            $authorUserId = (int) $author->user_id;
        } else {
            // A guest author is capped by the guest permission set, as core's own
            // Post preparer does when $post->User is null.
            $author = $this->repository(UserRepository::class)->getGuestUser();
            $authorUserId = 0;
        }

        // The shared cap: getAllowedUserMentions reads general:maxMentionedUsers
        // (0 => none, < 0 => unlimited, else first N). Reading the same permission
        // value keeps per-user and per-group overrides carrying automatically
        // (build-time check §8.3).
        $cap = (int) $author->hasPermission('general', 'maxMentionedUsers');

        $recipients = MilpacResolver::milpacRecipients(
            $atUserIds,
            $milpacUserIds,
            $authorUserId,
            $cap
        );

        MilpacStash::stash($entity, $recipients);
    }
}
