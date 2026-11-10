<?php

namespace Cav7\Citations\Service;

use Cav7\Citations\Entity\Template;
use Cav7\Citations\Entity\TemplateVersion;
use Cav7\Citations\Template\FieldForm;
use Cav7\Citations\Template\ImageUpload;
use Cav7\Citations\Template\VersionLifecycle;
use XF\Phrase;
use XF\PrintableException;
use XF\Service\AbstractService;

/**
 * Starting, filling and deleting drafts. Every write to an existing draft goes through
 * locked(), and the entities refuse a frozen version as well.
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

    /**
     * Publishing, retiring and every draft write hold the version row and work from it as
     * stored, so nothing lands on a version another request moved on after this one loaded it.
     *
     * @template T
     * @param \Closure(): T $write
     * @param string $state The state the write needs the version in.
     * @return T
     * @throws PrintableException
     */
    public function locked(TemplateVersion $draft, \Closure $write, string $state = VersionLifecycle::DRAFT): mixed
    {
        return $this->db()->executeTransaction(function () use ($draft, $write, $state): mixed {
            $row = $this->db()->fetchRow(
                'SELECT * FROM xf_cav7_citation_template_version WHERE template_version_id = ? FOR UPDATE',
                $draft->template_version_id
            );
            if (!$row || $row['state'] !== $state) {
                throw new PrintableException(\XF::phrase(VersionLifecycle::isDraft($state)
                    ? 'cav7_cit_refused_version_frozen'
                    : 'cav7_cit_refused_bad_transition'));
            }

            foreach ($row as $column => $value) {
                $draft->setAsSaved($column, $value);
            }
            $draft->clearCache('Kinds');
            $draft->clearCache('RankArt');

            return $write();
        });
    }

    /** @param list<array{'award'|'record_type', int}> $kinds */
    public function setKinds(TemplateVersion $draft, array $kinds): void
    {
        $this->locked($draft, fn () => $this->replaceKinds($draft, $kinds));
    }

    /**
     * @param list<array<string, mixed>> $inputs
     * @param list<array{'award'|'record_type', int}> $kinds
     */
    public function saveSettings(TemplateVersion $draft, string $rankScope, array $inputs, array $kinds): void
    {
        $this->locked($draft, function () use ($draft, $rankScope, $inputs, $kinds): void {
            $draft->rank_scope = $rankScope;
            $draft->input_definitions = self::json($inputs);
            $draft->save(true, false);

            $this->replaceKinds($draft, $kinds);
        });
    }

    /** @param array<string, mixed> $definition */
    public function saveField(TemplateVersion $draft, ?string $originalKey, array $definition): void
    {
        $this->writeFields(
            $draft,
            fn (array $fields): array => FieldForm::placed($fields, $originalKey, $definition),
            $originalKey === null ? [] : [$originalKey => $definition['key']]
        );
    }

    public function removeField(TemplateVersion $draft, string $key): void
    {
        $this->writeFields($draft, fn (array $fields): array => FieldForm::without($fields, $key));
    }

    /** @param -1|1 $step */
    public function moveField(TemplateVersion $draft, string $key, int $step): void
    {
        $this->writeFields($draft, fn (array $fields): array => FieldForm::stepped($fields, $key, $step));
    }

    /** @return Phrase|null A warning when the plate is off the width plates aim for. It saved either way. */
    public function setPlate(TemplateVersion $draft, string $path): ?Phrase
    {
        [$image, $hash] = $this->service(ImageIntake::class)->store($path, ImageUpload::PLATE_FORMATS);

        $this->locked($draft, function () use ($draft, $image, $hash): void {
            $draft->bulkSet([
                'plate_hash' => $hash,
                'plate_format' => $image->format,
                'plate_width' => $image->width,
                'plate_height' => $image->height,
            ]);
            $draft->save(true, false);
        });

        return $image->plateWidthWarns()
            ? \XF::phrase('cav7_cit_plate_width_warning', ['width' => $image->width])
            : null;
    }

    public function setRankArt(TemplateVersion $draft, string $fieldKey, int $rankId, string $path): void
    {
        [$image, $hash] = $this->service(ImageIntake::class)->store($path, ImageUpload::ART_FORMATS);

        $id = ['template_version_id' => $draft->template_version_id, 'field_key' => $fieldKey, 'rank_id' => $rankId];
        $this->locked($draft, function () use ($id, $image, $hash): void {
            $art = $this->em()->find('Cav7\Citations:RankArt', $id) ?? $this->em()->create('Cav7\Citations:RankArt');
            $art->bulkSet($id + ['image_hash' => $hash, 'image_width' => $image->width, 'image_height' => $image->height]);
            $art->save(true, false);
        });
        $draft->clearCache('RankArt');
    }

    public function removeRankArt(TemplateVersion $draft, string $fieldKey, int $rankId): void
    {
        $id = ['template_version_id' => $draft->template_version_id, 'field_key' => $fieldKey, 'rank_id' => $rankId];
        $this->locked($draft, fn () => $this->em()->find('Cav7\Citations:RankArt', $id)?->delete(true, false));
        $draft->clearCache('RankArt');
    }

    /** A template left with no version has nothing to show, so it goes with its last draft. */
    public function deleteDraft(TemplateVersion $draft): void
    {
        $template = $draft->Template;

        $this->locked($draft, function () use ($draft, $template): void {
            $draft->delete(true, false);

            $template->clearCache('Versions');
            if ($template->Versions->count() === 0) {
                $template->delete(true, false);
            }
        });
    }

    /**
     * Rank art follows its field: a rename carries it, and a field gone or no longer rank art
     * takes it along, so a later field under the same key doesn't inherit it.
     *
     * @param \Closure(list<array<string, mixed>>): list<array<string, mixed>> $edit The stored fields to the new ones.
     * @param array<string, string> $renamed New key by old key.
     */
    private function writeFields(TemplateVersion $draft, \Closure $edit, array $renamed = []): void
    {
        $this->locked($draft, function () use ($draft, $edit, $renamed): void {
            $definitions = $edit($draft->fieldDefinitions());
            $artKeys = [];
            foreach ($definitions as $field) {
                if ($field['kind'] === 'rank_art') {
                    $artKeys[] = $field['key'];
                }
            }

            $draft->field_definitions = self::json($definitions);
            $draft->save(true, false);

            foreach ($draft->RankArt as $art) {
                $key = $renamed[$art->field_key] ?? $art->field_key;
                if (!in_array($key, $artKeys, true)) {
                    $art->delete(true, false);
                } elseif ($key !== $art->field_key) {
                    $art->field_key = $key;
                    $art->save(true, false);
                }
            }
        });
        $draft->clearCache('RankArt');
    }

    /** @param list<array{'award'|'record_type', int}> $kinds */
    private function replaceKinds(TemplateVersion $draft, array $kinds): void
    {
        foreach ($draft->Kinds as $kind) {
            $kind->delete(true, false);
        }
        foreach ($kinds as [$type, $id]) {
            $this->addPart('Cav7\Citations:VersionKind', $draft, ['kind_type' => $type, 'kind_id' => $id]);
        }
        $draft->clearCache('Kinds');
    }

    /** @param list<array<string, mixed>> $definitions */
    private static function json(array $definitions): string
    {
        return json_encode($definitions, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
