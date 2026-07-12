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
 * MilpacResolver::findMilpacOwningUsers, so no per-row query runs here.
 */
class Find extends View
{
    public function renderJson()
    {
        $router = \XF::app()->router('public');
        $results = [];
        $seenUsernames = [];

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

            // user_id is not DB-unique on xf_nf_rosters_user (only relation_id is),
            // so the TO_ONE join can emit more than one row for a member with two
            // RosterUser rows. Dedup by username so one member fills one slot, not two.
            if (isset($seenUsernames[$user->username])) {
                continue;
            }
            $seenUsernames[$user->username] = true;

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
