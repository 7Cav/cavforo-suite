<?php

namespace Cav7\ModeratorLogPatch\NF\Calendar\ModeratorLog;

use Cav7\ModeratorLogPatch\AuthorshipLogging;

/**
 * Issue #187 — calendar-event moderation, logged for permission holders who hold no
 * moderator record.
 *
 * The behaviour is in {@see AuthorshipLogging}, composed here and into one subclass
 * per registered handler class. This file exists so the class-extension chain has
 * somewhere to land; it holds no logic of its own on purpose.
 *
 * The handler underneath is the one that declares a `bool` return type on the
 * per-action check, which is why the trait declares one too: a subclass may add a
 * return type where its parent has none, but it may not drop one its parent
 * declared, and the composition is checked when the class loads. Left off, this
 * addon would fatal on the first calendar moderation rather than fail a test.
 */
class EventHandler extends XFCP_EventHandler
{
    use AuthorshipLogging;
}
