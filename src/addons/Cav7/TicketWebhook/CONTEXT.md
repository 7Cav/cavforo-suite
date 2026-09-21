# Cav7/TicketWebhook

Opens an NF/Tickets ticket when a remote tool posts to a hook, so an alert from
a monitor or an updater lands as a ticket rather than in a chat channel nobody
is reading. Hooks are written and edited in the ACP. For cross-addon terms, such
as opener, see the suite-wide [CONTEXT.md](../../../../CONTEXT.md).

## Language

**hook**:
One address a caller can post to, and the record behind it. It names the
NF/Tickets category every ticket it opens goes in, and holds its own token.
Anything else the ticket ends up with, such as priority or assignee, comes from
the category's defaults, not from the hook.
_Avoid_: webhook on its own (the addon's name; a hook is one record under it),
endpoint (there is one endpoint and many hooks), integration, channel (the
Discord word for what a hook stands in for)

**caller**:
The remote tool that posts to a hook. A caller holds the hook's token and
decides what the ticket says, when to post, and how many updates one post
covers.
_Avoid_: source, sender, client, bot

**token**:
The secret that lets a caller post to one hook. Issued once, shown once, and
replaced rather than recovered. A hook has exactly one at a time.
_Avoid_: key (an API key is a different thing on this board), secret, password

**inactive**:
A hook that opens nothing. A caller cannot tell an inactive hook from one that
does not exist. Turning it active again changes nothing else about it.
_Avoid_: paused, disabled, revoked (that is what happens to a token, not a hook)
