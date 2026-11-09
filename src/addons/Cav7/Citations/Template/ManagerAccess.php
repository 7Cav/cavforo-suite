<?php

namespace Cav7\Citations\Template;

use Cav7\Citations\Grant\Actor;

/**
 * Which template manager pages open for whom, the one table every action answers to, GET or
 * POST. A read page and the action that writes from it are separate rows, so a page can open
 * read-only for those who can't save it. An action not listed here refuses.
 */
final class ManagerAccess
{
    public const TEMPLATES = 'templates';
    public const VERSIONS = 'versions';
    public const SIGNATURES = 'signatures';

    /** @param string $action The controller's action, lower case with no separators. */
    public static function allows(string $page, string $action, Actor $actor): bool
    {
        return match ("$page/$action") {
            'templates/index', 'templates/legibility', 'versions/plate' => $actor->canOpenTemplateManager(),

            'templates/paygrades',
            'versions/index', 'versions/art', 'versions/field', 'versions/rankart', 'versions/preview',
            'signatures/index', 'signatures/ink' => $actor->canReadTemplates(),

            'templates/add', 'templates/newdraft',
            'versions/uploadplate', 'versions/settings', 'versions/savefield', 'versions/removefield',
            'versions/movefield', 'versions/saverankart', 'versions/delete' => $actor->canEditDrafts(),

            'templates/savepaygrades',
            'versions/publish', 'versions/retire',
            'signatures/add', 'signatures/edit', 'signatures/delete', 'signatures/retire', 'signatures/reinstate',
            'signatures/addbillet', 'signatures/editbillet', 'signatures/deletebillet' => $actor->canPublish(),

            'versions/movecitations' => $actor->canIssue(false) || $actor->canIssue(true),

            default => false,
        };
    }
}
