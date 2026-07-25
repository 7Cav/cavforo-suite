# One extension per registered handler, sharing a trait

2026-07-24

The two methods this addon overrides both live on the moderator log's abstract
handler, and every registered handler inherits them. One class extension against
that abstract class would have covered all of them at once.

It does not work. XenForo only builds an extension chain for a class it is asked
to resolve, and the logger resolves the concrete handler it read from the
content type's `moderator_log_handler_class` field. The abstract class is never
passed to the extension system, so an extension registered against it is
installed, valid, and never loaded — the same silent nothing this addon exists to
remove.

## Decision

One class extension per registered handler class, each landing on a subclass that
does nothing but compose the shared trait. Eight are registered today: five from
XenForo core, two from the ticket addon, one from the calendar addon. The eight
`from_class` names are written down twice, in `_data/class_extensions.xml` and
again in `_output/class_extensions/`, which is what an addon's data is. Nothing in
the addon's *code* holds a list of them: `cav7-moderator-log-patch:verify` reads
the registered content types off the install, which is why it can report one
nobody registered an extension for.

The behaviour is a trait rather than a shared base class because the chain fixes
each subclass's parent to the XFCP proxy of the handler it extends. There is no
parent slot left to put a base class in.

## Considered options

Extending `XF\ModeratorLog\Logger` instead was the other way to cover every
content type from one registration. It is resolved through the extension system,
and the user-level gate is consulted from it: `logChanges()`, `logChange()` and
`log()` each call `isLoggableUser()` on the way in, so an override there would
work today for that half. The per-action check is not reachable from the logger at
all. It is called from `AbstractHandler::log()`, so covering it would mean
reimplementing the dispatch into the handlers, several of which override it with
rules of their own. We rejected the whole shape on that: the check belongs to the
handler, and the gate this addon replaces would have moved somewhere the next
reader would not look for it.

## Consequences

- A content type registered by a later addon is not covered until an extension is
  added for it, and nothing announces that. The verification script exists for
  this: it reads the registered handler content types off the install and reports
  any that do not resolve through this addon.
- The extension against a vendor handler is registered whether or not that vendor
  addon is installed. XenForo checks that the extending class file exists and not
  the extended one, so the record installs and sits inert. `addon.json` requires
  XenForo and nothing else because of it.
- Eight near-identical files. That is the cost of the chain being per-class; the
  alternative was eight copies of the behaviour rather than eight copies of a
  `use` statement.
