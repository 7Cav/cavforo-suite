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
 * `NF\Tickets\ModeratorLog\TicketHandler`, a name with no file behind it, because
 * that is the name XenForo's aliasing autoloader resolves to when the moderator log
 * asks for this handler. `cav7-moderator-log-patch:verify` checks it on the install.
 */
class Ticket extends XFCP_Ticket
{
    use AuthorshipLogging;
}
