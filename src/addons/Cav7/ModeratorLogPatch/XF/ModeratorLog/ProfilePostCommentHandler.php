<?php

namespace Cav7\ModeratorLogPatch\XF\ModeratorLog;

use Cav7\ModeratorLogPatch\AuthorshipLogging;

/**
 * Issue #187 — profile-post-comment moderation, logged for permission holders who
 * hold no moderator record.
 *
 * The behaviour is in {@see AuthorshipLogging}, composed here and into one subclass
 * per registered handler class. This file exists so the class-extension chain has
 * somewhere to land; it holds no logic of its own on purpose.
 */
class ProfilePostCommentHandler extends XFCP_ProfilePostCommentHandler
{
    use AuthorshipLogging;
}
