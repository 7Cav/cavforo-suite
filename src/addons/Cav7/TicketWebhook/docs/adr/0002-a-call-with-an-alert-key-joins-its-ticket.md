# ADR-0002: A call that carries an alert key joins that key's ticket

- **Status:** Accepted
- **Date:** 2026-10-17
- **Issues:** #321, #294
- **Amends:** [ADR-0001](0001-inbound-hooks-speak-discord.md), whose consequence
  "One post is one ticket, and the addon does not de-duplicate" this replaces.
  The rest of ADR-0001 stands.

Callers repeat themselves. Grafana calls again every 4 hours by default while
an alert fires, and again when it clears. WUD calls again when a newer version
replaces the one it reported. healthchecks.io calls once when a check goes down
and once when it comes back. Under ADR-0001 every call opened a ticket of its
own, so one problem became a stack of tickets and a down never met its up.

A call now says what it is about with an alert key, written as a line of its
own anywhere in the call's text, for example `Alert key: local_nginx`. An
all-clear writes `All-clear:` in place of `Alert key:` on that line. A key
belongs to the hook it arrived at, and a call without one opens a ticket as
before. Discord's webhook body stays the only body a hook accepts, and no hook
gains a setting. The line is the whole contract, so any caller that can template
its text can use it, and the hook holds no code for a particular caller.

A key points at one ticket at a time, and that ticket follows the alert:

- A firing call replies to the key's open ticket, as the opener.
- An all-clear replies to the key's ticket, then sets the board's resolved
  status.
- A firing call for a closed ticket replies and sets the board's open status.
- A locked, deleted or merged-away ticket ends the link. The next firing call
  opens a new ticket. An all-clear with no ticket to reach opens one and closes
  it at once.

The hook's replies change a ticket's status only in those cases. A reply goes
through wherever the ticket now sits, whatever the opener's permissions there,
and only a lock stops it. The hook keeps the links in a table of its own, stores
a hash of each key rather than the key, and leaves the key line out of the
ticket's text.

## Considered options

- A body shape of our own, with key and state fields, beside Discord's.
  Grafana's webhook contact point and healthchecks.io's webhook could send it.
  WUD's HTTP trigger cannot, because it sends a fixed container object, so WUD
  would have stayed on the Discord path and the hook would carry two shapes.
- A per-hook setting saying where the key is, such as a pattern over the title.
  WUD's default title would then work with no template change. But Grafana's
  title changes with the alert's state, WUD's batch title would merge unrelated
  batches, and the key would move out of the caller, which already decides what
  a ticket says, into the ACP.
- The key in an embed field, the title or the URL. Grafana sends no fields, and
  WUD's one field holds its whole body. Staff read the title in the queue, and
  Grafana's title changes with state. WUD cannot template its URL.
- The state read from each caller's own words, such as Grafana's `[RESOLVED]`
  prefix. That is the per-caller code ADR-0001 chose to avoid.
- A new ticket each time a closed alert fires again, linking the old one. Every
  re-fire would reach the whole category, but an alert that flaps all night
  would open a ticket per flap, and each one would notify everyone.
- NF/Tickets' tags, a custom ticket field, or `ticket_ref` to hold the link.
  NF/Tickets lowercases a tag and trims its punctuation, so a key would have to
  become a hash, which staff with tag permission can delete. A custom field
  needs an admin to set it up in every category a hook uses. `ticket_ref` is
  the vendor's own column, filled with its own random reference.

## Consequences

- Dedup is opt-in, one template at a time. No caller sends an alert key by
  default, so an existing hook behaves as before until an operator adds the
  line. A call with no key, or with key lines that disagree, as WUD's batch mode
  produces, still opens one ticket.
- A reopen is a reply, and NF/Tickets notifies fewer people for a reply than for
  a new ticket. Category watchers set to new tickets only, and the notify
  addresses, do not hear that an alert fired again. The design accepts that so
  a flapping alert stays on one ticket.
- An alert that fires in July and again in October reopens July's ticket. Staff
  who want the next one to start fresh lock the ticket.
- Replying where the opener may not reply follows NF/Tickets' own
  email-to-ticket importer, which checks neither the view nor the reply
  permission. The hook can only write to tickets its own calls opened.
- The hook hashes keys and keeps them out of ticket text because
  healthchecks.io's natural key, the check's UUID, is also the secret that pings
  the check.
- The hook trusts each caller's delivery. A duplicate call adds a second reply,
  a lost call stays lost, and a healthchecks.io up that overtakes its down
  leaves the down's ticket open for staff to close.
