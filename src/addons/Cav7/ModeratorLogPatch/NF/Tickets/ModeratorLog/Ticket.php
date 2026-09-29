<?php

namespace Cav7\ModeratorLogPatch\NF\Tickets\ModeratorLog;

use Cav7\ModeratorLogPatch\AuthorshipLogging;

/**
 * Issue #187 — ticket moderation, logged for permission holders who hold no
 * moderator record.
 *
 * The behaviour is in {@see AuthorshipLogging}, composed here and into one subclass
 * per registered handler class. This file exists so the class-extension chain has
 * somewhere to land; it holds no logic of its own on purpose.
 *
 * The handler underneath has an authorship rule of its own, and a narrow one: it
 * cases `title` and `custom_fields`, and the second is dead, because the vendor's
 * own field-to-action mapping turns that field into the action `custom_fields_edit`
 * and the handler never sees the field name. So `title` is the whole of it, and
 * deferring is what keeps that one case in force. It says nothing about `status` or
 * `priority`, which this addon decides.
 *
 * The class extension that reaches this file is registered against
 * `NF\Tickets\ModeratorLog\Ticket`, the class NF ships and the one the content-type
 * field names. XenForo files an extension under `XF::getClassForAlias()` of its
 * `from_class`. The moderator log looks the handler up under `XF::getClassForAlias()`
 * of that field. The extension applies only when both alias to the same name.
 * `getClassForAlias()` appends `Handler` when a file by that name exists, and never
 * strips it. NF ships no `TicketHandler.php`, so both sides stay at `...\Ticket`. If
 * NF adds one, both move to the suffixed name together.
 *
 * The `Handler` spelling that XenForo's own handlers are registered under is wrong
 * here. No file sits behind `...\TicketHandler`, so it aliases to itself and the
 * lookup never asks for it. An extension registered against it installs and does
 * nothing. `cav7-moderator-log-patch:verify` checks this on the install.
 */
class Ticket extends XFCP_Ticket
{
    use AuthorshipLogging;
}
