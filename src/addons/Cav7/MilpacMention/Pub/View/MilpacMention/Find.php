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
 * The users come pre-joined to their milpac (with Rank and Roster) by
 * MilpacResolver::findMilpacOwningUsers, so no per-row query runs here.
 */
class Find extends View
{
    public function renderJson()
    {
        $router = \XF::app()->router('public');
        $results = [];

        foreach ($this->params['users'] as $user) {
            /** @var \NF\Rosters\Entity\RosterUser|null $milpac */
            $milpac = $user->Milpac;
            if (!$milpac) {
                // The inner join guarantees a row; this only guards a race where a
                // milpac was deleted between the query and the render.
                continue;
            }

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
