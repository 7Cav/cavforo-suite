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
XenForo core, two from the ticket addon, one from the calendar addon, all read off
the install rather than written down anywhere in the code.

The behaviour is a trait rather than a shared base class because the chain fixes
each subclass's parent to the XFCP proxy of the handler it extends. There is no
parent slot left to put a base class in.

## Considered options

Extending `XF\ModeratorLog\Logger` instead was the other way to cover every
content type from one registration. It is resolved through the extension system,
and both gates are consulted from it, so an override there would work today. We
rejected it because the per-action check is not the logger's to make: it belongs
to the handler, several handlers already override it with rules of their own, and
answering it from the logger means reimplementing the dispatch to reach them. The
gate this addon replaces would move somewhere the next reader would not look for
it.

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
