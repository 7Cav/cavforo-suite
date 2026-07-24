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
 * The handler underneath has an authorship rule of its own, covering a narrower set
 * of actions than this addon does. Deferring is what keeps it: `status` and
 * `priority` reach it withheld, and everything it already withheld stays withheld.
 */
class Ticket extends XFCP_Ticket
{
    use AuthorshipLogging;
}
