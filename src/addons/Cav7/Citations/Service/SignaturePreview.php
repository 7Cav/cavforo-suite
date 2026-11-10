<?php

namespace Cav7\Citations\Service;

use Cav7\Citations\Entity\TemplateVersion;
use Cav7\Citations\Grant\PreviewFit;
use Cav7\Citations\Render\DrawPlan;
use Cav7\Citations\Render\ImageFile;
use Cav7\Citations\Render\ImagickDrawer;
use Cav7\Citations\Render\ImagickMetrics;
use Cav7\Citations\Render\PinnedSignature;
use Cav7\Citations\Render\PreviewBox;
use Cav7\Citations\Render\RenderFailed;
use Cav7\Citations\Render\SetField;
use Cav7\Citations\Render\SignatureSlot;
use Cav7\Citations\Render\Values;
use Cav7\Citations\Render\Version;
use Cav7\Citations\Template\FileStore;
use Cav7\Citations\Template\VersionLifecycle;
use Cav7\Citations\Template\VersionRender;
use XF\Service\AbstractService;

/**
 * The signature form's preview: an unsaved signature set in every unretired slot on its billet,
 * through the typesetter and draw a citation uses, then cut down to the slot. Stores nothing.
 */
class SignaturePreview extends AbstractService
{
    /**
     * @param list<string> $printedLines
     * @param ImageFile|null $ink Null draws the ink box as an outline.
     * @param string $inkPath The ink's file, read only when there is ink.
     * @return list<array{template: string, version: int, slot: string, draft: bool, src: string|null, below: bool, size: float, legible: float}>
     */
    public function slots(int $billetId, array $printedLines, ?ImageFile $ink, string $inkPath): array
    {
        $files = FileStore::forApp($this->app);
        $metrics = new ImagickMetrics();
        $render = new VersionRender($files, $metrics);
        $drawer = new ImagickDrawer($metrics);

        $slots = [];
        foreach ($this->versions() as $version) {
            foreach ($version->renderVersion()->fields as $field) {
                if (!$field instanceof SignatureSlot || $field->billet_id !== $billetId) {
                    continue;
                }

                $entry = [
                    'template' => (string) $version->Template?->title,
                    'version' => $version->version_number,
                    'slot' => $field->key,
                    'draft' => VersionLifecycle::isDraft($version->state),
                    'src' => null,
                    'below' => false,
                    'size' => 0.0,
                    'legible' => PreviewFit::points($field->legible_size, $version->plate_width, $version->plate_height),
                ];

                try {
                    $row = $this->set($render, $version, $field, $printedLines, $ink);
                    $plan = DrawPlan::build(
                        new Version([$field], [], 'none'),
                        [$field->key => $row],
                        $version->plate($files),
                        $version->fontPaths($files),
                        fn (): string => $inkPath,
                        $ink === null
                            ? [new PreviewBox($field->x, $field->y, $field->w, $field->ink_height, (string) \XF::phrase('cav7_cit_ink_image'))]
                            : [],
                    );
                    $entry['src'] = 'data:image/jpeg;base64,' . base64_encode(
                        $drawer->drawRegion($plan, ...self::region($field, $version->plate_width, $version->plate_height))
                    );
                    $entry['below'] = $row->belowLegibility;
                    $entry['size'] = PreviewFit::points($row->size, $version->plate_width, $version->plate_height);
                } catch (RenderFailed $e) {
                    \XF::logException($e, false, 'Signature preview failed: ');
                }

                $slots[] = $entry;
            }
        }

        return $slots;
    }

    /**
     * The slot with a margin of plate around it, kept on the plate.
     *
     * @return array{int, int, int, int} x, y, width, height
     */
    public static function region(SignatureSlot $slot, int $plateWidth, int $plateHeight): array
    {
        $left = max(0, $slot->x - intdiv($slot->w, 4));
        $top = max(0, $slot->y - intdiv($slot->h, 5));
        $right = min($plateWidth, $slot->x + $slot->w + intdiv($slot->w, 4));
        $bottom = min($plateHeight, $slot->y + $slot->h + intdiv($slot->h, 10));

        return [$left, $top, $right - $left, $bottom - $top];
    }

    /**
     * With no ink the lines still set: a stand-in ink fills the pin and its placement is dropped.
     *
     * @param list<string> $printedLines
     * @throws RenderFailed
     */
    private function set(VersionRender $render, TemplateVersion $version, SignatureSlot $slot, array $printedLines, ?ImageFile $ink): SetField
    {
        $signature = new PinnedSignature($printedLines, $ink ?? new ImageFile('', 1, 1));
        $row = $render->typeset(
            $version,
            [$slot],
            new Values('', null, gmdate('Y-m-d', \XF::$time), '', signatures: [$slot->key => $signature]),
        )[$slot->key];

        return $ink === null ? new SetField($row->key, $row->size, $row->belowLegibility, $row->lines) : $row;
    }

    /** @return list<TemplateVersion> Published and draft, as the signature list counts slots. */
    private function versions(): array
    {
        return array_values($this->finder('Cav7\Citations:TemplateVersion')
            ->with('Template')
            ->where('state', '!=', VersionLifecycle::RETIRED)
            ->order('template_id')
            ->order('version_number')
            ->fetch()
            ->toArray());
    }
}
