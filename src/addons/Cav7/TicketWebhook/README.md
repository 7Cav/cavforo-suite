# 7Cav - Ticket Webhook

Opens an [NF/Tickets](https://nixfifty.com/products/tickets.2/) ticket when a
remote tool calls a hook, so an alert from a monitor or an updater lands as
a ticket in the right queue rather than in a chat channel nobody is reading.
An admin writes a hook in the ACP: a name and the category its tickets go in.
The board issues the hook a token, shown once, and the admin pastes the hook's
URL into the remote tool as a Discord webhook. From then on a call to that URL
opens a ticket as the board's opener user, with the title and message the
tool's own templates render. A call that names an alert key lands on that key's
ticket as a reply, so a tool that keeps reporting the same problem fills one
ticket instead of a stack of them. An all-clear closes the ticket, and the
next firing call reopens it.

The terms this README uses (hook, caller, call, alert key, firing call,
all-clear, link, token, inactive) are defined in [GLOSSARY.md](GLOSSARY.md).
Opener is defined in the suite-wide [GLOSSARY.md](../../../../GLOSSARY.md).
Why a hook speaks Discord's webhook request, and what that costs, is in
[ADR-0001](docs/adr/0001-inbound-hooks-speak-discord.md). Why an alert key is a
line in the call's text is in
[ADR-0002](docs/adr/0002-a-call-with-an-alert-key-joins-its-ticket.md).

## Requirements

- XenForo 2.3.0+
- NF/Tickets 2.12.2+ (declared in `addon.json`; the floor is the version the
  code was read against, in the vendor's own `version_id` numbering)

## Installation

1. Copy `src/addons/Cav7/TicketWebhook` into your XenForo installation at the
   same path, or install the release zip through the admin panel.
2. Install it: `php cmd.php xf-addon:install Cav7/TicketWebhook`.

The install creates two tables. `xf_cav7_ticket_webhook_hook` holds the hooks,
and `xf_cav7_ticket_webhook_alert_key` links each hook's alert keys to their
tickets. It stores a hash of each key and never the key. Upgrading from 1.0.0
adds the alert key table and links no existing ticket. Uninstalling drops both
tables and leaves every ticket a hook opened in place.

One option, under **Setup > Options > Ticket Webhook**: the opener user ID.
Every hook ticket is opened as this user. The default is 598, the user
`Cav7/EnlistmentReminder` and `Cav7/TicketSchedule` already post as. While the
ID names no user, the hook screens refuse every save and a call answers 500
with one error in the XenForo error log.

## Writing a hook

The screens sit under **Tickets > Ticket hooks** in the ACP and are open to
anyone holding the vendor's `nfTickets` admin permission. There is no
permission of the addon's own.

A hook has a name, up to 150 characters, which is the ticket's title when a
call carries no title of its own, and a category. The picker lists only
categories that allow tickets to be opened. Priority, status, assignee and
prefix are not the hook's to set: the category's defaults and the vendor's
options supply them.

The save refuses, with the reason shown, a category the opener may not open a
ticket in, a category that requires a prefix and has no default prefix, and an
opener user ID that names no user. Each of these would otherwise fail when the
first call arrives.

Saving a new hook shows its token once, with the full URL to paste into the
caller. The token is stored only as a hash, so the edit screen cannot show it
again. **Replace token** on the edit screen issues a new one, shown once; the
old one stops working the moment you confirm.

The list shows each hook's category, when a call last reached it, and a link
to the ticket that call opened or replied to. The toggle in each row sets a
hook inactive or active without deleting it. An inactive hook opens nothing,
and a caller cannot tell it from a hook that does not exist.

Deleting a hook deletes its alert keys and leaves its tickets, so a hook made
again in its place starts with no keys. Replacing a token or setting a hook
inactive keeps them, and a call after either still reaches its key's ticket.

## Wiring a caller

A hook accepts Discord's webhook request, so a tool whose Discord target
sends that request to any URL is a caller with nothing to adapt. Paste the
hook URL where the tool asks for a Discord webhook URL.

Not every Discord target works. healthchecks.io's Discord integration takes
its URL from Discord's own sign-in, so it cannot be pointed at a hook, and it
sends a Slack-shaped body, which a hook answers with 400. healthchecks.io's
generic Webhook integration can call a hook, as
[healthchecks.io](#healthchecksio) below shows.

A tool that can send an `Authorization: Bearer <token>` header may instead
call the URL without the token part, which keeps the token out of access logs;
the token screen shows both forms.

The ticket's title is the first embed's title, else the first line of the
content, else the hook's name, and it arrives as typed. The message is the
content, then each embed's description, link and fields, in the order the tool
sent them. NF/Tickets formats the message as it saves it, so most of the
Markdown a caller writes for Discord arrives formatted:

- `**x**` and `__x__` turn bold, and `*x*` and `_x_` italic. An underscore
  inside a word, as in `my_app_web`, stays as typed.
- Backticks make inline code, and a fenced block makes a code block.
- `[text](url)` and a bare URL become links.
- A line starting with `>` becomes a quote. Lines starting with `- `, `* ` or
  `+ ` become a list, unless a space comes before the marker.
- A shortcode such as `:warning:` becomes its emoji.
- `@` followed by a member's name mentions that member, who gets the alert any
  mention sends.

BB code arrives as BB code. Numbered lines, `#` headings and `~~x~~` stay as
typed.

The answers a caller gets, which are Discord's own where Discord has one:

| Status | When |
| --- | --- |
| 204 | The call opened a ticket or replied to one. With `?wait=true` in the query, 200 and `{"id": "<ticket id>"}` instead, naming the ticket the call reached. |
| 400 | The body is not a JSON object, or carries no usable text. |
| 403 | The opener may not open a ticket in the hook's category. |
| 404 | No such hook, an inactive hook, or a wrong token. |
| 405 | A method other than POST. |
| 500 | The ticket or the reply could not be created, a call with the same alert key was still running after 10 seconds, or the opener names no user. The call did nothing, so a retry is safe. |

Every 403 and 500 leaves one entry in the XenForo error log naming the hook,
and the ticket too when a reply failed. When a ticket or reply saves and a
later step fails, such as closing the ticket or sending notifications, the
hook leaves one entry naming the hook and the ticket. The caller still hears
success, so its retry adds no duplicate. An entry logged during a call with
the token in the path carries the token, because the error log records the
request URL. [ADR-0001](docs/adr/0001-inbound-hooks-speak-discord.md) says
why that is accepted.

### Alert keys

Without an alert key every accepted call opens a ticket of its own. To keep a
problem on one ticket, put a key line in the caller's template:

```
Alert key: <key>
```

A call with that line is a firing call. When the problem clears, the caller
sends an all-clear, which writes `All-clear` in place of `Alert key` and names
the same key:

```
All-clear: <key>
```

The line has to stand on its own, in the content, in an embed's description,
or in a field's value. The hook ignores a key in an embed's title or link, in
a field's name, or in the URL. `Alert key:` and `All-clear:` match in any case
and with spaces around them, so `alert key :` works. The key is the rest of the
line with the spaces either side trimmed, and it matches exactly, case
included, so `nginx` and `Nginx` are two keys. Pick a key that stays the same
for as long as the problem does and differs between problems.

What does not count as a key:

- An empty key. A template variable that rendered empty does not merge every
  call onto one ticket.
- The label inside a sentence, such as `see Alert key: k1 here`.
- A key line wrapped in Markdown, such as `**Alert key:** k1` or a line in
  backticks. Write the line plain.
- Key lines that disagree. A call naming two keys has no key and opens a
  ticket, and so does a call that carries `Alert key: k1` and `All-clear: k1`
  together. Repeating the same key line is fine.

The hook takes every key line out of the text before it writes the ticket or
the reply, so staff never see it. A call whose only text is its key line is
accepted, with the hook's name as its title and message, so a bare
`All-clear: <key>` is a complete call.

A key belongs to its hook. The same key sent to two hooks reaches two tickets.

When a call carries a key, the hook looks for the ticket the key links and
does this:

| The key's ticket | Firing call | All-clear |
| --- | --- | --- |
| None yet | Opens a ticket. | Opens a ticket and closes it at once. |
| Open | Replies. | Replies, then closes the ticket. |
| Closed | Replies, then reopens the ticket. | Replies. The ticket stays closed. |
| Locked, deleted, or merged into another | Opens a new ticket. | Opens a new ticket and closes it at once. |

A ticket a call opens is the one the key links from then on.

A reply starts with the call's title, then its message, and the title appears
once when it is all the call says. The ticket keeps its own title and its
category, and moves to the top of the list as any reply moves it.

Closing sets the status that NF/Tickets' resolved-status option names,
Resolved by default. Reopening sets the status its open-status option names,
Open by default. A ticket staff already closed keeps the closed status they
gave it. The hook changes no other status, so a ticket staff put on hold stays
on hold through the caller's repeats. The opener owns every hook ticket, so
closing and reopening send no status alert.

To make the next call start a fresh ticket, lock the ticket. A ticket that is
locked and closed counts as locked.

A reply lands wherever the ticket now sits, even in a category where the
opener may not post. After an admin points the hook at a new category, a key
keeps reaching its ticket, and only new keys open their tickets there.

Calls with one key run one at a time, so two sent together land on one
ticket. Calls with different keys do not wait on each other.

A new ticket notifies the category's staff as any new ticket does. A reply,
a reopen included, notifies whoever NF/Tickets notifies for any reply, which
is fewer people: the ticket's watchers, and category watchers set to hear
messages. Category watchers set to new tickets only, and the category's and
the priority's notify addresses, do not hear it.

### WUD

[What's Up Docker](https://getwud.app/) is the first caller. Give its Discord
trigger the hook URL:

```
WUD_TRIGGER_DISCORD_TICKETS_URL=https://your-board/ticket-webhooks/1/<token>/
```

WUD's own settings on that trigger decide what the ticket says:
`WUD_TRIGGER_DISCORD_TICKETS_SIMPLETITLE` and `..._SIMPLEBODY` render the
title and message of a single update. `..._MODE=batch` sends several updates
as one call. Each container in it adds its own key line, so a batch call has
no key and opens a ticket of its own.

To land WUD's later calls about a container on that container's ticket, end
SIMPLEBODY with a key line whose key is `${container.watcher}_${container.name}`.
That pair survives recreating the container, and `container.id` does not. In a
Compose file, write every `$` as `$$`:

```yaml
environment:
  WUD_TRIGGER_DISCORD_TICKETS_SIMPLEBODY: |-
    Container $${container.name} running with $${container.updateKind.kind} $${container.updateKind.localValue} can be updated to $${container.updateKind.kind} $${container.updateKind.remoteValue}
    Alert key: $${container.watcher}_$${container.name}
```

WUD sends no all-clear. Staff close the ticket once the container is updated,
and WUD's next update for that container reopens it. Lock the ticket instead
to have the next update open a fresh one.

### Grafana

Give a Grafana Discord contact point the hook URL, and set its message to a
key line followed by Grafana's default message:

```
{{ if eq .Status "resolved" }}All-clear{{ else }}Alert key{{ end }}: {{ .GroupKey }}
{{ template "default.message" . }}
```

`.GroupKey` names the alert group and stays the same from the first firing
call to the all-clear, so one ticket follows one group. Your notification
policy's grouping decides how many tickets that makes, and under Grafana's
default grouping it is one ticket per alert rule and folder. `.Status` is `resolved`
only once every alert in the group has cleared.

Keep the key line first. Grafana cuts the message at 2000 characters, and a
key line cut short names a different key. Leave the contact point's "Disable
resolved message" setting off, or Grafana never sends the all-clear. Its Test
button sends a new group key on every click, so each test opens a ticket of
its own.

### healthchecks.io

Use the Webhook integration. It has a separate URL, body and headers for a
check going down and for a check coming back up. Give both the hook URL and
the POST method, and put the check's `$CODE` in the down body as an alert key
and in the up body as an all-clear.

Down body:

```json
{"content": "Check is down since $NOW\nAlert key: $CODE", "embeds": [{"title": $NAME_JSON}]}
```

Up body:

```json
{"content": "Check is up since $NOW\nAll-clear: $CODE", "embeds": [{"title": $NAME_JSON}]}
```

`$CODE` is the check's UUID, which never changes, so a check's down and its up
reach the same ticket, and the next down reopens it. The UUID is also the
secret that pings the check. Only the key line stays out of the ticket, so keep
`$CODE` off every other line of the body. [Installation](#installation) says
what the hook stores of a key.

Write the check's name as `$NAME_JSON`, which healthchecks.io quotes for JSON.
Plain `$NAME` goes in unescaped, and a name with a quote in it breaks the body.
To keep the token out of the URL, add an `Authorization: Bearer <token>`
header to both and use the URL without the token part. The integration's test
button uses a dummy check with a new UUID each time, so each test opens a
ticket of its own.

## Tests

Run them with `tools/run-tests.sh TicketWebhook`. They need only `php` and
cover `HookCall`, which decides what a call yields and what it does to its
key's ticket, and `Token`. Everything that needs a live XenForo is
verified by hand on a dev stack before a release, per
[CONTRIBUTING.md](../../../../CONTRIBUTING.md).

## License

See [LICENSE](LICENSE).

## Provenance

Written directly in this repo for issue #288. It was not imported from another
repository, so it has no history before its first commit here.
