# ADR-0006: Let the resync reply follow XenForo's own flash-message behaviour

- **Status:** Accepted
- **Date:** 2026-07-22
- **Issues:** design decision for #158

## Context

The resync button posts a form. Two of its outcomes are redirects that carry a
phrase — the resync was queued, or one was already pending — and the other four are
refusals returned through `$this->error()`.

A redirect's message only survives as far as the renderer that handles the reply.
`XF\Mvc\Renderer\Json::renderRedirect()` puts it in the response body, where
`js/xf/form.js` picks it up and calls `XF.flashMessage()`.
`XF\Mvc\Renderer\Html::renderRedirect()` takes the same `$message` argument and does
nothing with it: it sets the response code and a `Location` header, and returns. So
the message reaches a member through JavaScript or it does not reach them at all,
and that is true of every redirect in XenForo, not of this action.

The form asks for the JavaScript path explicitly. It carries `ajax="true"` so the
post goes through `js/xf/form.js`, and `data-force-flash-message="true"` so the
reply is flashed rather than swallowed by the redirect that follows it. With
JavaScript off the browser posts the form itself, follows the 303, and the phrase is
gone.

The question is whether that gap is worth closing, because closing it means building
something XenForo does not have: a message stashed in the session on the way out and
rendered by a second template modification on the page the redirect lands on.

## Decision

The reply ships as it is. A member with JavaScript off gets no flash message on the
two redirect outcomes.

Three things make that a smaller hole than it reads as.

Every refusal is already visible without JavaScript. All four go out through
`$this->error()`, which renders a full message page, so a member who is not linked,
or who presses on a forum with no credentials or no active server, is told why on
any browser. The quiet path is the one where the press worked.

The page a successful press lands on is not the page the member left. The vendor's
`ConnectedAccount\Provider\Discord::renderAssociated()` builds `$syncingServers` by
selecting `guild_id` out of `xf_nf_discord_queue` for this member's `SyncUser`
messages — the rows this action just wrote. The template modification renders
`cav7_discord_resync_pending` beside the button off the same variable. So a press
that queued something changes what the member reads when they arrive, flash message
or not, and it changes it from the queue itself rather than from anything this
action remembers.

And a member with JavaScript off already gets a silent redirect from every other
button on the forum. Giving this one action a session-backed message would make it
the only reply in the suite that behaves differently from the platform, and would
add persistent state plus a second template modification to do it.

## Consequences

- On the two redirect outcomes with JavaScript off, the member reads the pending
  line beside the button rather than a message naming what just happened. The two
  outcomes are not distinguishable from each other that way: a press that queued a
  resync and a press refused because one was already pending land on the same page
  showing the same line.
- The gap moves if XenForo ever renders redirect messages server-side. Nothing here
  would need unwinding — the `data-force-flash-message` attribute is a hint to the
  JavaScript path and is inert without it.
- Anyone adding a third redirect outcome to this action inherits the same silence,
  and should check that the page it lands on says something on its own.
