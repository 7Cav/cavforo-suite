<?php

namespace Cav7\MilpacMention\Pub\View\MilpacMention;

use Cav7\MilpacMention\MilpacResolver;
use XF\Mvc\View;

/**
 * Renders the $name completer's find results as the standard XF autocomplete
 * envelope {q, results} (spec §4.3), mirroring XF\Pub\View\Member\Find and
 * XF\Pub\View\Misc\FindEmoji. Each row follows the find-emoji field convention
 * (id, iconHtml, text, desc, html) so the phase-2 completer (§4.1) can consume it
 * unchanged, and adds the discrete rank/name/roster display fields (§4.5):
 *
 *   text   "Rank Name" — the dropdown's primary line and the anchor's text
 *   desc   roster      — the secondary line that tells same-name members apart
 *   html   the value to insert: a NAMED anchor to /rosters/profile/<relation_id>/,
 *          which Froala serialises back to [URL='…']Rank Name[/URL] on save (§4.2)
 *
 * text and html are the canonical fields: text is what the dropdown renders, html
 * is what gets inserted, and both are built from the same display text so they
 * cannot drift. rank/name/roster are a convenience decomposition for the client.
 * The #89 completer must NOT rebuild the insert value from rank+name — it must
 * insert html verbatim, or the artifact the phase-1 engine detects can diverge.
 *
 * The users come pre-joined to their milpac (with Rank and Roster) by
 * MilpacResolver::findMilpacOwningUsers, so no per-row query runs here. A member with
 * two roster rows (one milpac per user is the intended rule but not schema-enforced)
 * is collapsed to a single dropdown entry keeping the lowest relation_id, with the
 * duplicate logged as a data error — see MilpacResolver::dedupeMilpacOwners (#112).
 */
class Find extends View
{
    public function renderJson()
    {
        $router = \XF::app()->router('public');

        // Build the (user_id, relation_id) rows from the joined owners, then collapse
        // to one per member. One milpac per user is the intended rule but
        // xf_nf_rosters_user does not enforce it, so dedupeMilpacOwners keeps the
        // lowest relation_id and logs a duplicate as a data error (§4.4, #112). The
        // finder orders the join by relation_id and XF's identity map keys the fetched
        // collection by user_id, so $user->Milpac is already the lowest and this view
        // normally sees one row per member; the collapse is the fail-safe that also
        // makes a raw duplicate visible instead of surfacing the member twice.
        $rows = [];
        $userById = [];
        foreach ($this->params['users'] as $user) {
            /** @var \NF\Rosters\Entity\RosterUser|null $milpac */
            $milpac = $user->Milpac;
            if (!$milpac) {
                // Milpac is fetch-hydrated by findMilpacOwningUsers (with('Milpac',
                // true)), so this reads already-materialized data — there is no
                // render-time query for a delete to race. With the INNER join a null
                // is effectively impossible; if one shows up the join has silently
                // degraded (e.g. the relation registration failed), and then EVERY
                // row hits this continue and the endpoint returns an empty result
                // with no other signal — indistinguishable from "no matches". Log so
                // the impossible is loud, then drop the row (matches the fail-loud
                // pattern in MilpacResolver::extractRelationIds).
                \XF::logError('[Cav7/MilpacMention] find: user ' . $user->user_id . ' returned without a joined milpac (INNER join expected a row)');
                continue;
            }

            $userId = (int) $user->user_id;
            $rows[] = ['user_id' => $userId, 'relation_id' => (int) $milpac->relation_id];
            if (!isset($userById[$userId])) {
                $userById[$userId] = $user; // first-seen entity carries the lowest milpac
            }
        }

        $results = [];
        foreach (MilpacResolver::dedupeMilpacOwners($rows) as $userId) {
            $user = $userById[$userId];
            /** @var \NF\Rosters\Entity\RosterUser $milpac */
            $milpac = $user->Milpac;

            $rank = $milpac->Rank ? (string) $milpac->Rank->title : '';
            $roster = $milpac->Roster ? (string) $milpac->Roster->title : '';
            $displayText = MilpacResolver::milpacDisplayText($rank, $user->username);
            $profileUrl = $router->buildLink('canonical:rosters/profile', $milpac);

            $avatarArgs = [$user, 'xxs', false, ['href' => '']];

            $results[] = [
                'id' => $user->username,
                'iconHtml' => $this->renderer->getTemplater()->func('avatar', $avatarArgs),
                'text' => $displayText,
                'desc' => $roster,
                'html' => MilpacResolver::milpacLinkHtml($displayText, $profileUrl),
                'rank' => $rank,
                'name' => $user->username,
                'roster' => $roster,
                'q' => $this->params['q'],
            ];
        }

        return [
            'results' => $results,
            'q' => $this->params['q'],
        ];
    }
}
