<?php

namespace Cav7\Core\TemplateModification;

use XF\App;

/**
 * What one board says about every template modification this suite ships.
 *
 * The gathering half of the check. It reads the shipped set off the add-on
 * files installed on this board, resolves each against the board's own records,
 * works out which template copies ought to be carrying it, and collects the
 * results XenForo recorded. Deciding which of those are failures is
 * `Reconciliation`'s job and happens nowhere else.
 *
 * No CI coverage: every read here needs a live XenForo entity layer and
 * database. The standing pass is `docs/verification/template-modifications-in-force.md`
 * in this addon; what its absence from CI costs is in the addon's README.
 */
class BoardFacts
{
    /** Only this suite's add-ons. Somebody else's zero-match row is their alarm. */
    public const SUITE_PREFIX = 'Cav7/';

    /**
     * One entry per shipped modification, as `Reconciliation` reads them.
     *
     * @var list<array>
     */
    public array $modifications = [];

    /**
     * @throws DiscoveryFailed when what to check cannot be established
     */
    public static function gather(App $app): self
    {
        $facts = new self();

        $shipped = self::shipped($app);
        if (!$shipped) {
            return $facts;
        }

        $records = self::records($app, array_column($shipped, 'modification_key'));
        $results = self::results($app, array_column($records, 'modification_id'));
        $inUseStyles = self::inUseStyles($app);

        $copiesByTarget = [];

        foreach ($shipped as $modification) {
            $key = $modification['modification_key'];
            $record = $records[$key] ?? null;

            // Where XenForo would actually apply it. The record's own target
            // wins where there is a record, because that is what the board acts
            // on; a record whose target has drifted from the shipped one is a
            // freshness question and out of this check's scope, but reporting
            // against a template the board is not patching would be a wrong
            // diagnosis rather than a silent one.
            $type = $record ? $record['type'] : $modification['type'];
            $template = $record ? $record['template'] : $modification['template'];

            // Enumerated whether or not anything was attempted against them.
            // A disabled modification, or one whose add-on is off, still costs
            // whichever copies it would have reached, and a failure line that
            // cannot say which reads the same for one inert style as for every
            // member on the board.
            $target = "$type\n$template";
            if (!isset($copiesByTarget[$target])) {
                $copiesByTarget[$target] = self::copies($app, $type, $template, $inUseStyles);
            }

            $copies = [];
            foreach ($copiesByTarget[$target] as $copy) {
                $copy['result'] = $record
                    ? ($results[$record['modification_id']][$copy['template_id']] ?? null)
                    : null;
                $copies[] = $copy;
            }

            $facts->modifications[] = [
                'addon_id' => $modification['addon_id'],
                'modification_key' => $key,
                'type' => $type,
                'template' => $template,
                'installed' => $record !== null,
                // The record's own owner, not the add-on whose files ship it.
                // XenForo skips a modification on the owner the record names,
                // so reading the shipping add-on would call one in force that
                // the board never applies. They agree on any board where the
                // data was imported normally.
                'addon_active' => $record ? $record['addon_active'] : $modification['addon_active'],
                'enabled' => $record ? $record['enabled'] : false,
                'copies' => $copies,
            ];
        }

        return $facts;
    }

    public function modificationCount(): int
    {
        return count($this->modifications);
    }

    /**
     * Distinct template copies this run looked at. One copy carrying two
     * modifications is one copy, which is what an operator counts on the board.
     */
    public function copyCount(): int
    {
        $templateIds = [];
        foreach ($this->modifications as $modification) {
            foreach ($modification['copies'] as $copy) {
                $templateIds[$copy['template_id']] = true;
            }
        }

        return count($templateIds);
    }

    /**
     * Every modification shipped by an installed add-on of this suite, read off
     * this board's filesystem.
     *
     * @return list<array>
     */
    protected static function shipped(App $app): array
    {
        $shipped = [];

        foreach ($app->addOnManager()->getInstalledAddOns() as $addOn) {
            $addOnId = $addOn->getAddOnId();
            if (strpos($addOnId, self::SUITE_PREFIX) !== 0) {
                continue;
            }

            // Installed with its files gone. An add-on in this state ships
            // whatever the operator last uploaded and this run cannot read it,
            // so it refuses rather than recording that the add-on ships
            // nothing — which is indistinguishable from a clean pass.
            //
            // Narrower than it looks, and knowingly so: XenForo only ever puts
            // `addon.json` in this list (`XF\AddOn\AddOn::__construct`), so it
            // catches a directory that is gone or gutted, not one whose `_data`
            // alone was deleted. That case still reads as "ships nothing",
            // because nothing on the board says what the files ought to
            // contain — the same gap `version_id` leaves, and out of reach
            // without XenForo's file-health hashes.
            if ($addOn->getMissingFiles()) {
                throw new DiscoveryFailed(
                    "$addOnId is installed and its files are missing from this board ("
                    . implode(', ', $addOn->getMissingFiles())
                    . '), so what it ships cannot be established'
                );
            }

            $modifications = ShippedModifications::read(
                $addOn->getDataDirectory() . \XF::$DS . 'template_modifications.xml',
                $addOnId
            );

            foreach ($modifications as $modification) {
                $modification['addon_active'] = $addOn->isActive();
                $shipped[] = $modification;
            }
        }

        return $shipped;
    }

    /**
     * The board's own record for each key, keyed by modification key, which
     * XenForo holds unique board-wide.
     *
     * @param list<string> $keys
     *
     * @return array<string, array>
     */
    protected static function records(App $app, array $keys): array
    {
        $db = $app->db();

        $rows = $db->fetchAll(
            'SELECT m.modification_id, m.modification_key, m.type, m.template, m.enabled, a.active
                FROM xf_template_modification AS m
                LEFT JOIN xf_addon AS a ON (a.addon_id = m.addon_id)
                WHERE m.modification_key IN (' . $db->quote($keys) . ')'
        );

        $records = [];
        foreach ($rows as $row) {
            $records[$row['modification_key']] = [
                'modification_id' => (int) $row['modification_id'],
                'type' => (string) $row['type'],
                'template' => (string) $row['template'],
                'enabled' => (bool) $row['enabled'],
                // Null where the record names an add-on the board has no row
                // for, which is not an active add-on either.
                'addon_active' => (bool) $row['active'],
            ];
        }

        return $records;
    }

    /**
     * What XenForo recorded the last time each copy was compiled, as
     * [modification id][template id].
     *
     * @param list<int> $modificationIds
     *
     * @return array<int, array<int, array>>
     */
    protected static function results(App $app, array $modificationIds): array
    {
        if (!$modificationIds) {
            return [];
        }

        $db = $app->db();

        $rows = $db->fetchAll(
            'SELECT modification_id, template_id, status, apply_count
                FROM xf_template_modification_log
                WHERE modification_id IN (' . $db->quote($modificationIds) . ')'
        );

        $results = [];
        foreach ($rows as $row) {
            $results[(int) $row['modification_id']][(int) $row['template_id']] = [
                'status' => (string) $row['status'],
                'apply_count' => (int) $row['apply_count'],
            ];
        }

        return $results;
    }

    /**
     * The styles whose template copies render for somebody: the board default
     * together with every style a member can select.
     *
     * Computed at run time rather than written down, so a style made selectable
     * later widens the check on its own.
     *
     * @return array<int, string> Style id to title.
     */
    protected static function inUseStyles(App $app): array
    {
        $db = $app->db();
        $defaultStyleId = (int) $app->options()->defaultStyleId;

        $rows = $db->fetchAll(
            'SELECT style_id, title FROM xf_style WHERE user_selectable = 1 OR style_id = ?',
            [$defaultStyleId]
        );

        $styles = [];
        foreach ($rows as $row) {
            $styles[(int) $row['style_id']] = (string) $row['title'];
        }

        return $styles;
    }

    /**
     * The copies of one template this run reports on.
     *
     * The master copy always, because a find failing there means the vendor's
     * markup moved rather than a style being edited — a different diagnosis,
     * and it is what any style created later inherits. Beyond that, only the
     * copies an in-use style resolves to through XenForo's template map: a copy
     * no in-use style reaches renders for nobody, and reporting failures nobody
     * can see trains the reader to skim.
     *
     * @param array<int, string> $inUseStyles
     *
     * @return list<array>
     */
    protected static function copies(App $app, string $type, string $template, array $inUseStyles): array
    {
        $db = $app->db();

        $stylesByTemplateId = [];
        if ($inUseStyles) {
            $mapped = $db->fetchAll(
                'SELECT style_id, template_id
                    FROM xf_template_map
                    WHERE type = ? AND title = ? AND style_id IN (' . $db->quote(array_keys($inUseStyles)) . ')',
                [$type, $template]
            );

            foreach ($mapped as $row) {
                $styleId = (int) $row['style_id'];
                $stylesByTemplateId[(int) $row['template_id']][] = sprintf(
                    '%s (%d)',
                    $inUseStyles[$styleId],
                    $styleId
                );
            }
        }

        // Read straight from xf_template rather than through the map, so the
        // master copy is found whether or not the map has been rebuilt since it
        // was created.
        $rows = $db->fetchAll(
            'SELECT template_id, style_id FROM xf_template WHERE type = ? AND title = ? ORDER BY style_id',
            [$type, $template]
        );

        $copies = [];
        foreach ($rows as $row) {
            $templateId = (int) $row['template_id'];
            $styleId = (int) $row['style_id'];
            $renderedFor = $stylesByTemplateId[$templateId] ?? [];

            if ($styleId !== 0 && !$renderedFor) {
                continue;
            }

            $copies[] = [
                'template_id' => $templateId,
                'style_id' => $styleId,
                'styles' => $renderedFor,
            ];
        }

        return $copies;
    }
}
