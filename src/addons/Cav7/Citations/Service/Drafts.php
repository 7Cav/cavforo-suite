<?php

namespace Cav7\Citations\Service;

use Cav7\Citations\Entity\Template;
use Cav7\Citations\Entity\TemplateVersion;
use Cav7\Citations\Template\ImageUpload;
use Cav7\Citations\Template\VersionLifecycle;
use XF\Phrase;
use XF\Service\AbstractService;

/**
 * Starting, filling and deleting drafts. Definitions and rank scope are plain columns on
 * the entity, and removing rank art is a delete on its row. All of it freezes on publish
 * in the entities, so a caller can't skip that.
 */
class Drafts extends AbstractService
{
    public function createTemplate(string $title, int $userId): TemplateVersion
    {
        return $this->db()->executeTransaction(function () use ($title, $userId): TemplateVersion {
            /** @var Template $template */
            $template = $this->em()->create('Cav7\Citations:Template');
            $template->bulkSet(['title' => $title, 'create_user_id' => $userId]);
            $template->save(true, false);

            return $this->newDraft($template, [], $userId);
        });
    }

    public function startDraft(Template $template, int $userId): TemplateVersion
    {
        $source = $template->Versions->last();

        return $this->db()->executeTransaction(function () use ($template, $source, $userId): TemplateVersion {
            $draft = $this->newDraft($template, VersionLifecycle::draftFrom($source->toArray(false)), $userId);

            foreach ($source->Kinds as $kind) {
                $this->addPart('Cav7\Citations:VersionKind', $draft, $kind->toArray(false));
            }
            foreach ($source->RankArt as $art) {
                $this->addPart('Cav7\Citations:RankArt', $draft, $art->toArray(false));
            }

            return $draft;
        });
    }

    /** @param list<array{'award'|'record_type', int}> $kinds */
    public function setKinds(TemplateVersion $draft, array $kinds): void
    {
        $this->db()->executeTransaction(function () use ($draft, $kinds): void {
            foreach ($draft->Kinds as $kind) {
                $kind->delete(true, false);
            }
            foreach ($kinds as [$type, $id]) {
                $this->addPart('Cav7\Citations:VersionKind', $draft, ['kind_type' => $type, 'kind_id' => $id]);
            }
        });
        $draft->clearCache('Kinds');
    }

    /** @return Phrase|null A warning when the plate is off the width plates aim for. It saved either way. */
    public function setPlate(TemplateVersion $draft, string $path): ?Phrase
    {
        [$image, $hash] = $this->service(ImageIntake::class)->store($path, ImageUpload::PLATE_FORMATS);

        $draft->bulkSet([
            'plate_hash' => $hash,
            'plate_format' => $image->format,
            'plate_width' => $image->width,
            'plate_height' => $image->height,
        ]);
        $draft->save();

        return $image->plateWidthWarns()
            ? \XF::phrase('cav7_cit_plate_width_warning', ['width' => $image->width])
            : null;
    }

    public function setRankArt(TemplateVersion $draft, string $fieldKey, int $rankId, string $path): void
    {
        [$image, $hash] = $this->service(ImageIntake::class)->store($path, ImageUpload::ART_FORMATS);

        $id = ['template_version_id' => $draft->template_version_id, 'field_key' => $fieldKey, 'rank_id' => $rankId];
        $art = $this->em()->find('Cav7\Citations:RankArt', $id) ?? $this->em()->create('Cav7\Citations:RankArt');
        $art->bulkSet($id + ['image_hash' => $hash, 'image_width' => $image->width, 'image_height' => $image->height]);
        $art->save();
        $draft->clearCache('RankArt');
    }

    /** A template left with no version has nothing to show, so it goes with its last draft. */
    public function deleteDraft(TemplateVersion $draft): void
    {
        $template = $draft->Template;

        $this->db()->executeTransaction(function () use ($draft, $template): void {
            $draft->delete(true, false);

            $template->clearCache('Versions');
            if ($template->Versions->count() === 0) {
                $template->delete(true, false);
            }
        });
    }

    /** @param array<string, mixed> $design */
    private function newDraft(Template $template, array $design, int $userId): TemplateVersion
    {
        $numbers = [];
        foreach ($template->Versions as $version) {
            $numbers[] = $version->version_number;
        }

        /** @var TemplateVersion $draft */
        $draft = $this->em()->create('Cav7\Citations:TemplateVersion');
        $draft->bulkSet($design + [
            'template_id' => $template->template_id,
            'version_number' => VersionLifecycle::nextNumber($numbers),
            'create_user_id' => $userId,
        ]);
        $draft->save(true, false);
        $template->clearCache('Versions');

        return $draft;
    }

    /** @param array<string, mixed> $values */
    private function addPart(string $entity, TemplateVersion $draft, array $values): void
    {
        $part = $this->em()->create($entity);
        $part->bulkSet(['template_version_id' => $draft->template_version_id] + $values);
        $part->save(true, false);
    }
}
