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
 * is already one entry here: XF's identity map keys the fetched collection by user_id
 * and the join is ordered by relation_id, so the collection carries a single hydrated
 * User per member with the lowest milpac. That collapse hides the second roster row,
 * so the duplicate is logged from the raw roster rows instead, after the loop — see
 * MilpacResolver::logMilpacOwnerDuplicates (§4.4, #112).
 */
class Find extends View
{
    public function renderJson()
    {
        $router = \XF::app()->router('public');

        // The joined collection is already one entity per member: findMilpacOwningUsers
        // orders the INNER join by relation_id and XF's identity map keys the fetched
        // collection by user_id, so two roster rows for one member collapse to a single
        // hydrated User carrying the lowest milpac. The dropdown therefore builds
        // straight from this collection — one row per member, lowest relation_id (§4.4).
        $results = [];
        $shownUserIds = [];
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

            $shownUserIds[] = (int) $user->user_id;

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

        // The collapse above hid any second roster row a shown member owns (the identity
        // map kept one entity per user_id), so the data error is invisible to $results.
        // Re-read the raw roster rows for the shown members and log a member owning more
        // than one milpac, keeping the bad data visible even though the dropdown is
        // already correct — see MilpacResolver::logMilpacOwnerDuplicates (§4.4, #112).
        MilpacResolver::logMilpacOwnerDuplicates($shownUserIds);

        return [
            'results' => $results,
            'q' => $this->params['q'],
        ];
    }
}
