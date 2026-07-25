<?php

namespace Cav7\ModeratorLogPatch\NF\Tickets\ModeratorLog;

use Cav7\ModeratorLogPatch\AuthorshipLogging;

/**
 * Issue #187 — ticket-message moderation, logged for permission holders who hold no
 * moderator record.
 *
 * The behaviour is in {@see AuthorshipLogging}, composed here and into one subclass
 * per registered handler class. This file exists so the class-extension chain has
 * somewhere to land; it holds no logic of its own on purpose.
 *
 * The class extension that reaches this file is registered against
 * `NF\Tickets\ModeratorLog\MessageHandler`, a name with no file behind it, because
 * that is the name XenForo's aliasing autoloader resolves to when the moderator log
 * asks for this handler. `cav7-moderator-log-patch:verify` checks it on the install.
 */
class Message extends XFCP_Message
{
    use AuthorshipLogging;
}
