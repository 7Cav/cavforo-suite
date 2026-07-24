# Register the name XenForo resolves to, not the one on the file

2026-07-24

Two of the eight extensions are registered against a class name that has no
file behind it. `NF\Tickets\ModeratorLog\TicketHandler` and
`NF\Tickets\ModeratorLog\MessageHandler` are aliases XenForo creates at
runtime; the files are `Ticket.php` and `Message.php`, and the content-type
field names those. Registering against the names on the files installs
cleanly, exports cleanly, and does nothing.

`ModeratorLog` is one of XenForo's aliasable namespaces, paired with the suffix
`Handler`. Both `Extension::extendClass()` and `Extension::addClassExtension()`
run the class name through `XF::getClassForAlias()` before touching the
extension map, so the key the map is read with is whatever that returns. For a
core handler it returns the real class: `XF\ModeratorLog\Post` becomes
`XF\ModeratorLog\PostHandler`, because a file of that name exists. For the
ticket handlers there is no such file, so the answer depends on whether the
`Handler` name happens to be declared at the moment of the call.

It is. Loading `NF\Tickets\ModeratorLog\Ticket` makes XenForo's own aliasing
autoloader declare `NF\Tickets\ModeratorLog\TicketHandler` as an alias of it,
and `ModeratorLog\Logger::handler()` calls `class_exists()` on the registered
name immediately before `extendClass()`. So by the time the extension map is
consulted, the alias exists and the lookup key is the `Handler` name.

## Decision

Register the `Handler` spelling for those two, and let the runtime check in
`cav7-moderator-log-patch:verify` be the thing that says so.

## Considered options

Registering both spellings was the first fix, and it worked. It also produced
an `_output/extension_hint.php` declaring `XFCP_Ticket` twice in one namespace,
because that file is generated from the same rows. Two records per handler
also stops "one extension per registered handler" being true, which is the rule
the rest of this addon is built on.

## Consequences

- The registration is load-order dependent and nothing in the file says so.
  Called before the vendor class is loaded, `getClassForAlias()` answers with
  the file's name instead and these two rows stop matching. Only the moderator
  log resolves these classes, and it always loads first, so the order holds
  today. It is not a guarantee anybody made.
- That is the whole reason the verification command is a shipped deliverable
  rather than a note in the README. This failure writes nothing anywhere: the
  addon is enabled, the record is active, the handler is unpatched, and the
  entries stop appearing again. `tests/WiringTest.php` cannot see it either,
  since it reads files in this repo and this is a fact about the install.
- A vendor upgrade that adds a real `TicketHandler.php` would flip the answer
  back and take both rows out of service. So would a XenForo change to the
  aliasable-namespace list. Run the verification command after either.
