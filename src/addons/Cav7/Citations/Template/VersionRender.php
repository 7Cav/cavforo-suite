<?php

namespace Cav7\Citations\Template;

use Cav7\Citations\Entity\TemplateVersion;
use Cav7\Citations\Render\DrawPlan;
use Cav7\Citations\Render\DrawResult;
use Cav7\Citations\Render\ImagickDrawer;
use Cav7\Citations\Render\ImagickMetrics;
use Cav7\Citations\Render\RankArtField;
use Cav7\Citations\Render\RenderFailed;
use Cav7\Citations\Render\SetField;
use Cav7\Citations\Render\SignatureSlot;
use Cav7\Citations\Render\TextField;
use Cav7\Citations\Render\Typesetter;
use Cav7\Citations\Render\Values;

/** A stored version joined to the render core. Share one instance across a bulk run, for its width table. */
final class VersionRender
{
    public function __construct(private FileStore $files, private ImagickMetrics $metrics)
    {
    }

    /**
     * @param list<TextField|SignatureSlot|RankArtField> $fields Only the fields to set.
     * @return array<string, SetField> By field key.
     * @throws RenderFailed A font file gone from the tree.
     */
    public function typeset(TemplateVersion $version, array $fields, Values $values): array
    {
        try {
            return (new Typesetter($this->metrics))->set($fields, $version->fontPaths($this->files), $values);
        } catch (\ImagickException | \ImagickDrawException $e) {
            throw new RenderFailed($e->getMessage(), 0, $e);
        }
    }

    /**
     * @param array<string, SetField> $rows By field key, a member's rows and the shared rows together.
     * @throws RenderFailed
     */
    public function draw(TemplateVersion $version, array $rows): DrawResult
    {
        $plan = DrawPlan::build(
            $version->renderVersion(),
            $rows,
            $version->plate($this->files),
            $version->fontPaths($this->files),
            fn (string $hash): string => $this->files->path($hash, 'png'),
        );

        return (new ImagickDrawer($this->metrics))->draw($plan);
    }
}
