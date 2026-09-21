# ADR-0001: A hook accepts Discord's webhook request, token in the path

- **Status:** Accepted
- **Date:** 2026-09-21
- **Issues:** #288

Remote tools need to open NF/Tickets tickets, and WUD is the first. WUD's
Discord trigger, and the Discord target of most self-hosted tools, posts
Discord's webhook JSON to any `https` URL and renders the title and body from
the tool's own templates. So a hook accepts exactly that request, at
`ticket-webhooks/{id}/{token}` with a numeric id and a `[A-Za-z0-9_-]` token so
a caller that validates Discord's URL pattern accepts it, and takes the same
token as a bearer header too, with either credential enough on its own. Every
tool with a Discord target is a caller with no adapter in our code, and the
caller's templates decide what the ticket says.

## Considered options

- Our own JSON shape with an adapter per caller. WUD's HTTP trigger sends a
  fixed container object with no body template, so the wording would be decided
  in our code, one adapter per tool.
- A XenForo REST API controller. Stock keys travel in an `XF-Api-Key` header
  WUD cannot send, and 2.3 OAuth tokens expire while WUD holds one static
  string.

## Consequences

- The token rides in the URL path. That is a capability URL with the same
  guessing space as a bearer header, but access logs record it, and XenForo's
  error log records the request URL on every entry, so an entry logged during
  a path-form post carries the token. The token is stored hashed and replaced
  rather than recovered, the controller names the hook in its own message, and
  the error log is readable only by admins, who hold the rotate action. A
  caller that can send a header instead keeps the token out of both logs.
- The caller decides wording and batching. One post is one ticket, and the
  addon does not de-duplicate. A monitor's down and up notifications open two
  tickets.
- No Markdown is converted. Discord's `**bold**` arrives in the ticket as typed.
- A tool that pins the `discord.com` host in its Discord integration cannot
  call a hook.
