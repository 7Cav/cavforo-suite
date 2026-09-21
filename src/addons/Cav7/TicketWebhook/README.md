# 7Cav - Ticket Webhook

Opens an [NF/Tickets](https://nixfifty.com/products/tickets.2/) ticket when a
remote tool posts to a hook, so an alert from a monitor or an updater lands as
a ticket in the right queue rather than in a chat channel nobody is reading.
An admin writes a hook in the ACP: a name and the category its tickets go in.
The board issues the hook a token, shown once, and the admin pastes the hook's
URL into the remote tool as a Discord webhook. From then on every post to that
URL opens one ticket as the board's opener user, with the title and message the
tool's own templates render, and the category's staff are notified as they are
for any other ticket.

The terms this README uses (hook, caller, token, inactive) are defined in
[CONTEXT.md](CONTEXT.md). Opener is defined in the suite-wide
[CONTEXT.md](../../../../CONTEXT.md). Why a hook speaks Discord's webhook
request, and what that costs, is in
[ADR-0001](docs/adr/0001-inbound-hooks-speak-discord.md).

## Requirements

- XenForo 2.3.0+
- NF/Tickets 2.11.1+ (declared in `addon.json`; the floor is the version the
  code was read against, in the vendor's own `version_id` numbering)

## Installation

1. Copy `src/addons/Cav7/TicketWebhook` into your XenForo installation at the
   same path, or install the release zip through the admin panel.
2. Install it: `php cmd.php xf-addon:install Cav7/TicketWebhook`.

The install creates one table, `xf_cav7_ticket_webhook_hook`. Uninstalling
drops it and leaves every ticket a hook opened in place.

One option, under **Setup > Options > Ticket Webhook**: the opener user ID.
Every hook ticket is opened as this user. The default is 598, the user
`Cav7/EnlistmentReminder` and `Cav7/TicketSchedule` already post as. While the
ID names no user, the hook screens refuse every save and a post answers 500
with one error in the XenForo error log.

## Writing a hook

The screens sit under **Tickets > Ticket hooks** in the ACP and are open to
anyone holding the vendor's `nfTickets` admin permission. There is no
permission of the addon's own.

A hook has a name, up to 150 characters, which is the ticket's title when a
post carries no title of its own, and a category. The picker lists only
categories that allow tickets to be opened. Priority, status, assignee and
prefix are not the hook's to set: the category's defaults and the vendor's
options supply them.

The save refuses, with the reason shown, a category the opener may not open a
ticket in, a category that requires a prefix and has no default prefix, and an
opener user ID that names no user. Each of these would otherwise fail when the
first post arrives.

Saving a new hook shows its token once, with the full URL to paste into the
caller. The token is stored only as a hash, so the edit screen cannot show it
again. **Replace token** on the edit screen issues a new one, shown once; the
old one stops working the moment you confirm.

The list shows each hook's category, when it was last used, and a link to the
last ticket it opened. The toggle in each row sets a hook inactive or active
without deleting it. An inactive hook opens nothing, and a caller cannot tell
it from a hook that does not exist.

## Wiring a caller

A hook accepts Discord's webhook request, so any tool with a Discord
notification target is a caller with nothing to adapt. Paste the hook URL
where the tool asks for a Discord webhook URL. A tool that can send an
`Authorization: Bearer <token>` header may instead post to the URL without the
token part, which keeps the token out of access logs; the token screen shows
both forms.

The ticket's title is the first embed's title, else the first line of the
content, else the hook's name. The message is the content, then each embed's
description, link and fields, in the order the tool sent them. Text arrives as
typed: a tool that writes BB code gets BB code, and no Markdown is converted.
One accepted post is one ticket; the addon does not de-duplicate, and a batch
the tool chose to send as one message opens one ticket.

The answers a caller gets, which are Discord's own where Discord has one:

| Status | When |
| --- | --- |
| 204 | The ticket opened. With `?wait=true` in the query, 200 and `{"id": "<ticket id>"}` instead. |
| 400 | The body is not a JSON object, or carries no usable text. |
| 403 | The opener may not open a ticket in the hook's category. |
| 404 | No such hook, an inactive hook, or a wrong token. |
| 405 | A method other than POST. |
| 500 | The ticket could not be created, or the opener names no user. |

Every 403 and 500 leaves one entry in the XenForo error log naming the hook.
A ticket that opened but whose notifications failed leaves one entry too, and
the caller still hears success. An entry logged during a post with the token
in the path carries the token, because the error log records the request URL;
what that costs and why it is accepted is in
[ADR-0001](docs/adr/0001-inbound-hooks-speak-discord.md).

### WUD

[What's Up Docker](https://getwud.dev/) is the first caller. Give its Discord
trigger the hook URL:

```
WUD_TRIGGER_DISCORD_TICKETS_URL=https://your-board/ticket-webhooks/1/<token>/
```

WUD's own settings on that trigger decide what the ticket says:
`WUD_TRIGGER_DISCORD_TICKETS_SIMPLETITLE` and `..._SIMPLEBODY` render the
title and message of a single update, and `..._MODE=batch` sends several
updates as one post, which opens one ticket.

## Tests

Run them with `tools/run-tests.sh TicketWebhook`. They need only `php` and
cover `HookPost`, which decides what a post yields, and `Token`. Everything
that needs a live XenForo is verified by hand on a dev stack before a release,
per [CONTRIBUTING.md](../../../../CONTRIBUTING.md).

## License

See [LICENSE](LICENSE).

## Provenance

Written directly in this repo for issue #288. It was not imported from another
repository, so it has no history before its first commit here.
