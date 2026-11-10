<?php

namespace Cav7\Citations\Service;

use Cav7\Citations\Entity\Signature;
use Cav7\Citations\Entity\TemplateVersion;
use Cav7\Citations\Grant\PreviewFit;
use Cav7\Citations\Render\FieldDefinitions;
use Cav7\Citations\Render\ImagickMetrics;
use Cav7\Citations\Render\PinnedSignature;
use Cav7\Citations\Render\RankSnapshot;
use Cav7\Citations\Render\RenderFailed;
use Cav7\Citations\Render\SignatureSlot;
use Cav7\Citations\Render\Values;
use Cav7\Citations\Render\Version;
use Cav7\Citations\Template\FieldForm;
use Cav7\Citations\Template\FileStore;
use Cav7\Citations\Template\PlateFit;
use Cav7\Citations\Template\SampleValues;
use Cav7\Citations\Template\VersionRender;
use XF\Service\AbstractService;

/**
 * The template manager's preview: sample values through the typesetter and draw every citation
 * goes through, so what lines up here lines up on the certificate. Stores nothing.
 */
class PlatePreview extends AbstractService
{
    /**
     * Puts an unsaved field in a draft's design for one preview. Never save the version after this.
     *
     * @param array<string, mixed> $definition
     * @throws \InvalidArgumentException|\JsonException A half-typed field that can't draw yet.
     */
    public function withField(TemplateVersion $version, ?string $originalKey, array $definition): void
    {
        $definition['key'] = $definition['key'] ?: 'new_field';
        $json = json_encode(
            FieldForm::placed($version->fieldDefinitions(), $originalKey, $definition),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        FieldDefinitions::fields($json);
        $version->field_definitions = $json;
    }

    /**
     * @return array{jpeg: string, fit: list<array<string, mixed>>}
     * @throws RenderFailed
     */
    public function draw(TemplateVersion $version, SampleValues $sample): array
    {
        $design = $version->renderVersion();
        $signatures = $this->signatures($design, $sample);
        $render = new VersionRender(FileStore::forApp($this->app), new ImagickMetrics());

        $rows = PreviewFit::rows($design, $render->typeset(
            $version,
            PreviewFit::printable(PlateFit::settable($design), $sample->date, $signatures),
            new Values(
                $sample->name,
                $this->rank($sample->rankId),
                $sample->date,
                $sample->citationText,
                $sample->inputs,
                $signatures,
                $version->rankArtFor($sample->rankId),
            ),
        ));

        return ['jpeg' => $render->draw($version, $rows)->jpeg, 'fit' => PlateFit::report($design, $rows)];
    }

    /** @param array<string, mixed> $posted The page's `sample` fields, empty for the defaults. */
    public function sample(TemplateVersion $version, array $posted = []): SampleValues
    {
        return SampleValues::from(
            $posted,
            $version->renderVersion()->inputs,
            gmdate('Y-m-d', \XF::$time),
            $this->defaultRank($version),
        );
    }

    /**
     * What the page's preview panel prints: the plate's boxes, and the sample form.
     *
     * @return array<string, mixed>
     */
    public function panel(TemplateVersion $version): array
    {
        $design = $version->renderVersion();
        $boxes = [];
        foreach ($design->fields as $field) {
            $boxes[] = ['key' => $field->key, 'x' => $field->x, 'y' => $field->y, 'w' => $field->w, 'h' => $field->h];
        }

        return [
            'boxes' => json_encode($boxes, JSON_THROW_ON_ERROR),
            'sample' => get_object_vars($this->sample($version)),
            'ranks' => $this->em()->getRepository('Cav7\Citations:PayGrade')->list(),
            'inputs' => array_map(fn (object $input): array => ['key' => $input->key, 'label' => $input->label], $design->inputs),
            'slots' => $this->slotChoices($design),
        ];
    }

    /** A rank this design has art for, so the art shows from the start. */
    private function defaultRank(TemplateVersion $version): int
    {
        $ranks = array_map('intval', array_column($this->em()->getRepository('Cav7\Citations:PayGrade')->list(), 'rank_id'));
        $withArt = array_intersect($ranks, array_merge([], ...array_values($version->artRanks())));

        return reset($withArt) ?: ($ranks[0] ?? 0);
    }

    /** @return list<array{key: string, options: array<int, string>}> */
    private function slotChoices(Version $design): array
    {
        $slots = [];
        foreach ($design->fields as $field) {
            if ($field instanceof SignatureSlot) {
                $options = [];
                foreach ($this->onBillet($field) as $signature) {
                    $options[$signature->signature_id] = $signature->pinned()->printed_lines[0];
                }
                $slots[] = ['key' => $field->key, 'options' => $options];
            }
        }
        return $slots;
    }

    /** @return array<string, PinnedSignature> By slot key, a slot with nothing on its billet left out. */
    private function signatures(Version $design, SampleValues $sample): array
    {
        $pinned = [];
        foreach ($design->fields as $field) {
            if ($field instanceof SignatureSlot) {
                $onBillet = $this->onBillet($field);
                $chosen = $onBillet[$sample->signatures[$field->key] ?? 0] ?? reset($onBillet);
                if ($chosen) {
                    $pinned[$field->key] = $chosen->pinned();
                }
            }
        }
        return $pinned;
    }

    /** @return array<int, Signature> The slot's billet, active ones first. */
    private function onBillet(SignatureSlot $slot): array
    {
        return $this->finder('Cav7\Citations:Signature')
            ->where('billet_id', $slot->billet_id)
            ->order('state')
            ->order('signature_id')
            ->fetch()
            ->toArray();
    }

    private function rank(int $rankId): ?RankSnapshot
    {
        foreach ($this->em()->getRepository('Cav7\Citations:PayGrade')->list() as $rank) {
            if ((int) $rank['rank_id'] === $rankId) {
                return new RankSnapshot($rank['title'], $rank['pay_grade'], $rank['abbreviation']);
            }
        }
        return null;
    }
}
