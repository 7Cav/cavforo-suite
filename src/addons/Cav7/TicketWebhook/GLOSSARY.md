# Cav7/TicketWebhook

Opens an NF/Tickets ticket when a remote tool calls a hook, so an alert from
a monitor or an updater lands as a ticket rather than in a chat channel nobody
is reading. Hooks are written and edited in the ACP. For cross-addon terms, such
as opener, see the suite-wide [GLOSSARY.md](../../../../GLOSSARY.md).

## Language

**hook**:
One address a caller sends calls to, and the record behind it. It names the
NF/Tickets category every ticket it opens goes in, and holds its own token.
Anything else the ticket ends up with, such as priority or assignee, comes from
the category's defaults, not from the hook.
_Avoid_: webhook on its own (the addon's name; a hook is one record under it),
endpoint (there is one endpoint and many hooks), integration, channel (the
Discord word for what a hook stands in for)

**caller**:
The remote tool that calls a hook. A caller holds the hook's token and decides
what the ticket says, when to make a call, and how many updates one call
covers.
_Avoid_: source, sender, client, bot

**call**:
One request a caller sends to a hook, carrying what it has to report at that
moment.
_Avoid_: post, which on this board means a forum post; notification, which
XenForo uses for its own alerts and emails; webhook, the addon's name

**alert key**:
The text a caller puts in a call to say what the call is about, so later calls
about the same thing reach the same ticket.
_Avoid_: dedup key; alert id; group key and fingerprint, which are Grafana's
names for particular values

**all-clear**:
A call saying that whatever its alert key names is fine again.
_Avoid_: resolve and resolved, which are NF/Tickets' words for closing a
ticket; up; recovery

**token**:
The secret that lets a caller make calls to one hook. Issued once, shown once,
and replaced rather than recovered. A hook has exactly one at a time.
_Avoid_: key (an API key is a different thing on this board), secret, password

**inactive**:
A hook that opens nothing. A caller cannot tell an inactive hook from one that
does not exist. Turning it active again changes nothing else about it.
_Avoid_: paused, disabled, revoked (that is what happens to a token, not a hook)
