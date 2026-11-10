<?php

namespace Cav7\Citations\Grant;

/** Who is acting, and what section 3 of the grant rules lets them open and do. Nothing splits own from any. */
final class Actor
{
    public const GROUP = 'cav7Citations';

    public const VIEW = 'viewGrantsTemplates';
    public const ISSUE = 'issueGrants';
    public const REMOVE_MEMBERS = 'removeGrantMembers';
    public const REGIMENT = 'manageRegimentGrants';
    public const EDIT_DRAFTS = 'editTemplateDrafts';
    public const PUBLISH = 'publishTemplates';

    public const PERMISSIONS = [
        self::VIEW, self::ISSUE, self::REMOVE_MEMBERS, self::REGIMENT, self::EDIT_DRAFTS, self::PUBLISH,
    ];

    /** @param list<string> $held Permission ids the user holds. */
    public function __construct(
        public readonly int $userId,
        public readonly string $username,
        private readonly array $held,
    ) {
    }

    public function canOpenGrantList(): bool
    {
        return $this->holds(self::VIEW, self::ISSUE, self::REMOVE_MEMBERS, self::REGIMENT);
    }

    public function canOpenGrant(bool $regiment): bool
    {
        return $regiment
            ? $this->holds(self::VIEW, self::REGIMENT)
            : $this->holds(self::VIEW, self::ISSUE, self::REMOVE_MEMBERS);
    }

    public function canOpenTemplateManager(): bool
    {
        return $this->holds(self::VIEW, self::EDIT_DRAFTS, self::PUBLISH, self::ISSUE);
    }

    /**
     * Issuing that kind of grant, and with it correcting one, moving its version and the
     * form's preview. For a member grant, adding a member too. For a regiment grant,
     * withdrawing it and seeing its award on the form.
     */
    public function canIssue(bool $regiment): bool
    {
        return $this->holds($regiment ? self::REGIMENT : self::ISSUE);
    }

    public function canRemoveMembers(): bool
    {
        return $this->holds(self::REMOVE_MEMBERS);
    }

    /** Creating templates and versions, uploads and fields, as G-4 sets out. */
    public function canEditDrafts(): bool
    {
        return $this->holds(self::EDIT_DRAFTS);
    }

    /** Publishing and retiring versions, and signatures, billets and pay grades, as G-4 and G-21 set out. */
    public function canPublish(): bool
    {
        return $this->holds(self::PUBLISH);
    }

    public function canPreviewTemplate(): bool
    {
        return $this->holds(self::VIEW, self::EDIT_DRAFTS, self::PUBLISH);
    }

    private function holds(string ...$any): bool
    {
        return array_intersect($any, $this->held) !== [];
    }
}
