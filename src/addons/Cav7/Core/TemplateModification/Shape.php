<?php

namespace Cav7\Core\TemplateModification;

/**
 * The ways a shipped template modification fails to be in force.
 *
 * Six of them, and the report names which one it hit because the remedies
 * differ: an add-on whose data never imported is fixed by rebuilding it, a find
 * that stopped matching is fixed by editing a template copy, and nothing about
 * the two looks alike from the board.
 *
 * The values are the words the report prints, and they are the six the suite
 * CONTEXT.md lists under *shape*, so a failure line and the domain doc say the
 * same thing. Two glossary terms deliberately have no constant here:
 * *master mismatch* and *style mismatch* are the two diagnoses behind
 * `MATCHED_NOTHING`, and the failure's reason tells them apart rather than the
 * shape, because an operator sorting a log wants one bucket for "the patch is
 * not on this copy" and the diagnosis inside it.
 */
class Shape
{
    /**
     * The add-on ships this modification in its `_data` and the board holds no
     * record of it. The add-on's own version stamp says current, because
     * nothing hashes `_data` — see the suite's release notes on why a version
     * that did not move imports nothing.
     */
    public const NOT_INSTALLED = 'shipped but not installed';

    /** The owning add-on is installed but not active, so XenForo skips it. */
    public const ADDON_INACTIVE = 'owning add-on inactive';

    /**
     * The record exists and is switched off. XenForo maintains this flag across
     * an upgrade rather than resetting it to what the add-on ships, so a board
     * that disabled one keeps it disabled forever.
     */
    public const DISABLED = 'installed but disabled';

    /** No copy of the target template exists: the vendor renamed or dropped it. */
    public const TEMPLATE_MISSING = 'target template does not exist';

    /**
     * The copy exists and does not carry the patch — either XenForo recorded
     * zero matches against it, or it recorded nothing at all. The failure's
     * reason tells the two apart.
     */
    public const MATCHED_NOTHING = 'matched nothing';

    /** XenForo recorded a status other than ok: a bad regex, a failed compile. */
    public const BAD_STATUS = 'recorded a non-ok status';
}
