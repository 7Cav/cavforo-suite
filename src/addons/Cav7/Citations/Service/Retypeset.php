<?php

namespace Cav7\Citations\Service;

use Cav7\Citations\Entity\Grant;
use Cav7\Citations\Entity\GrantLog;
use Cav7\Citations\Grant\FieldChange;
use Cav7\Citations\Grant\GrantRender;
use Cav7\Citations\Grant\RemakePlan;
use Cav7\Citations\Grant\RetypesetSelection;
use Cav7\Citations\Grant\Typesetting;
use Cav7\Citations\Grant\TypesettingDiff;
use Cav7\Citations\Render\RankArtField;
use XF\Service\AbstractService;

/**
 * Remakes a grant with the current renderer and its frozen inputs: pinned version, fonts and
 * signatures, and its snapshots. A changed font needs a new version and a move, not this.
 */
class Retypeset extends AbstractService
{
    private const OPERATOR = 'CLI';

    private ?GrantRender $render = null;

    /** @return list<int> Grant ids. */
    public function selected(RetypesetSelection $selection): array
    {
        return match ($selection->kind) {
            RetypesetSelection::GRANT => array_values(array_unique($selection->ids)),
            RetypesetSelection::ADDON_VERSION => $this->render()->rows->grantsStamped($selection->ids[0]),
            RetypesetSelection::TEMPLATE_VERSION => array_map('intval', $this->db()->fetchAllColumn(
                'SELECT grant_id FROM xf_cav7_citation_grant WHERE template_version_id = ? ORDER BY grant_id',
                $selection->ids[0]
            )),
        };
    }

    /**
     * @return array{grant: Grant, changes: list<FieldChange>}
     * @throws \Exception A grant that can't be read or set.
     */
    public function preview(int $grantId): array
    {
        $grant = $this->load($grantId);
        [, $changes] = $this->compare($grant);

        return ['grant' => $grant, 'changes' => $changes];
    }

    /**
     * Each grant is its own transaction with the grant row held, so a correction can't land
     * mid-way. That makes this the one place Imagick runs inside a transaction. A grant with
     * no changes takes new stamps alone, so `--addon-version` stops finding it.
     *
     * @return array{grant: Grant, changes: list<FieldChange>}
     * @throws \Exception A grant that can't be read or set, left as it was.
     */
    public function apply(int $grantId, RetypesetSelection $selection): array
    {
        return $this->db()->executeTransaction(function () use ($grantId, $selection): array {
            $this->db()->fetchOne('SELECT grant_id FROM xf_cav7_citation_grant WHERE grant_id = ? FOR UPDATE', $grantId);

            $grant = $this->load($grantId);
            [$fresh, $changes] = $this->compare($grant);
            $rows = $this->render()->rows;

            if ($changes === []) {
                $rows->restamp($grantId, $this->render()->addonVersionId, $this->render()->libraries);
                return ['grant' => $grant, 'changes' => []];
            }

            $before = $rows->stamps($grantId);
            $this->render()->store($grantId, $fresh);

            $grant->revision += 1;
            $grant->save(true, false);
            $this->log($grant, $selection, $before);

            return ['grant' => $grant, 'changes' => $changes];
        });
    }

    public function render(): GrantRender
    {
        return $this->render ??= GrantRender::forApp($this->app);
    }

    /** @return array{Typesetting, list<FieldChange>} */
    private function compare(Grant $grant): array
    {
        $version = $grant->Version;
        $design = $version->renderVersion();
        $values = $grant->values();

        $fresh = $this->render()->make(
            $version,
            $values,
            RemakePlan::everything($design, array_keys($values->members)),
            $this->em()->getRepository('Cav7\Citations:Grant')->pinned($values),
        );

        $thresholds = [];
        foreach ($design->fields as $field) {
            if (!$field instanceof RankArtField) {
                $thresholds[$field->key] = $field->legible_size;
            }
        }

        return [$fresh, TypesettingDiff::between($this->render()->rows->all($grant->grant_id), $fresh, $thresholds)];
    }

    /** Reads past the identity map, since a bulk run holds rows only as long as their lock. */
    private function load(int $grantId): Grant
    {
        $this->em()->clearEntityCache();

        /** @var Grant|null $grant */
        $grant = $this->em()->find('Cav7\Citations:Grant', $grantId);

        return $grant ?? throw new \InvalidArgumentException("No grant $grantId");
    }

    /** @param list<array<string, mixed>> $before */
    private function log(Grant $grant, RetypesetSelection $selection, array $before): void
    {
        $render = $this->render();

        /** @var GrantLog $log */
        $log = $this->em()->create('Cav7\Citations:GrantLog');
        $log->bulkSet([
            'grant_id' => $grant->grant_id,
            'event' => GrantLog::RETYPESET,
            'user_id' => 0,
            'username' => self::OPERATOR,
            'reason' => $selection->reason,
            'revision_after' => $grant->revision,
            'detail' => [
                'selector' => $selection->label(),
                'before' => $before,
                'after' => [
                    'addon_version_id' => $render->addonVersionId,
                    'imagemagick_version' => $render->libraries->imagemagick_version,
                    'freetype_version' => $render->libraries->freetype_version,
                ],
            ],
        ]);
        $log->save(true, false);
    }
}
