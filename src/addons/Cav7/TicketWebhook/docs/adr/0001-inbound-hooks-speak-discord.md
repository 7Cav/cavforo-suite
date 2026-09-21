# ADR-0001: A hook accepts Discord's webhook request, token in the path

- **Status:** Accepted
- **Date:** 2026-09-21
- **Issues:** #288

## Context

#288 asks for a way for remote tools to open NF/Tickets tickets, with WUD
(What's Up Docker) as the first caller. Nothing in the suite takes an inbound
request and opens a ticket. `Cav7/TicketSchedule` opens tickets from a cron,
and the vendor's own inbound path is email piping, which needs a mailbox and a
poll and yields a ticket whose body is whatever the mail said.

WUD's two candidate triggers shape the choice. Its HTTP trigger posts a fixed
JSON container object, or an array of them in batch mode, with Basic or Bearer
auth and no body template. Its Discord trigger posts Discord's webhook JSON,
`{username, avatar_url, embeds: [{title, fields: [{name, value}]}]}`, to any
`https` URL without checking the host, and renders the title and the body from
WUD's own `SIMPLETITLE` and `SIMPLEBODY` templates. Most self-hosted tools
that can notify at all have that same Discord target: Uptime Kuma, Grafana,
Sonarr, Portainer, Diun, Gatus, Healthchecks, Home Assistant.

Three shapes were considered.

**Our own JSON, one adapter per caller.** Each adapter maps one tool's payload
to a ticket. WUD's fixed container object would need the first adapter, and
every later tool another, with the ticket's wording decided in our code.

**A XenForo REST API controller.** Stock API keys travel in an `XF-Api-Key`
header, which WUD cannot send. XenForo 2.3 OAuth tokens ride in a bearer
header, but they expire and WUD holds one static string.

**Discord's webhook contract.** One endpoint, `POST
.../ticket-webhooks/{id}/{token}`, taking Discord's body. The caller's own
templates decide the ticket's title, its body and whether one post carries one
update or a batch. No adapter per tool, and every tool with a Discord target is
a caller on the day it ships.

The cost of the third is that the secret rides in the URL path. That is a
capability URL, the same guessing space as a bearer header, and not security
through obscurity: the design is public and the token is 64 random characters.
What differs from a header is where the string ends up. Access logs on the web
server and any proxy record the full path. XenForo's error log captures the
request URL on every entry logged during a request, so one exception reaching
the logger from the hook controller writes the token into the ACP error log.
And a tool that validates a Discord URL against the `discord.com` host cannot
use it at all.

## Decision

A hook accepts Discord's webhook request. The route is
`ticket-webhooks/{id}/{token}`, where `{id}` is the numeric hook id and the
token is 64 characters from `[A-Za-z0-9_-]`, so a caller that validates the
URL against Discord's `/webhooks/\d+/[\w-]+` pattern accepts it. The same
token is also accepted as `Authorization: Bearer`, for a caller that can keep
it out of the path.

The token is generated on the board from `random_bytes`, shown once at
creation, stored as a hash, replaced from the ACP rather than recovered, and
compared in constant time. An unknown id, a wrong token and an inactive hook
all answer 404, as Discord does, so a probe learns nothing from the difference.
The hook controller catches its own failures and logs by hook id, never by URL.

The body maps as: the first embed's `title` becomes the ticket title, else the
first line of `content`, else the hook's name. The message is `content`, then
each embed's `description`, `url` and fields. Text passes through as-is. A
body with no usable text answers 400. Success answers 204, or 200 with
`{"id": "<ticket_id>"}` when the caller sends `?wait=true`.

## Consequences

- The caller decides the wording and the batching. One post is one ticket,
  whatever the post covers, and the addon does not de-duplicate. A tool that
  sends a down and an up notification opens two tickets.
- No Markdown is converted. Discord's `**bold**` arrives in the ticket as
  typed, and a caller that wants formatting writes BB code.
- A tool that pins the `discord.com` host in its Discord integration cannot
  call a hook. Its generic HTTP or webhook integration, if it has one, can.
- A leaked access log leaks a token. Rotation from the ACP is the remedy, and
  the hash at rest means the board's own database is not a second copy.
- Adding our own JSON shape later is one more accepted body on the same
  endpoint, not a new addon.
