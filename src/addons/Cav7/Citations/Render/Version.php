<?php

namespace Cav7\Citations\Render;

/** What the render reads from a template version. Fields are in draw order. */
final class Version
{
    /**
     * @param list<TextField|SignatureSlot|RankArtField> $fields
     * @param list<InputDefinition> $inputs
     * @param string $rank_scope `member`, `grant` or `none`
     */
    public function __construct(
        public readonly array $fields,
        public readonly array $inputs,
        public readonly string $rank_scope,
    ) {
    }

    public static function fromJson(string $fieldDefinitions, string $inputDefinitions, string $rankScope): self
    {
        return new self(
            FieldDefinitions::fields($fieldDefinitions),
            FieldDefinitions::inputs($inputDefinitions),
            $rankScope,
        );
    }

    /**
     * The required inputs with no value, the check that blocks an issue and a
     * move to another version. Values for keys this version doesn't declare are ignored.
     *
     * @param array<string, string> $values Grant and member inputs merged, by key.
     * @return list<string>
     */
    public function missingInputs(array $values): array
    {
        $missing = [];
        foreach ($this->inputs as $input) {
            if ($input->required && !PatternResolver::filled($values[$input->key] ?? '')) {
                $missing[] = $input->key;
            }
        }
        return $missing;
    }

    /**
     * @param 'grant'|'member' $scope
     * @return list<string>
     */
    public function inputKeys(string $scope): array
    {
        $keys = [];
        foreach ($this->inputs as $input) {
            if ($input->scope === $scope) {
                $keys[] = $input->key;
            }
        }
        return $keys;
    }

    /** @return list<string> The fonts this version sets text in. */
    public function fontKeys(): array
    {
        $keys = [];
        foreach ($this->fields as $field) {
            if (!$field instanceof RankArtField) {
                $keys[$field->font] = true;
            }
        }
        return array_keys($keys);
    }

    public function isMemberInput(string $key): bool
    {
        foreach ($this->inputs as $input) {
            if ($input->key === $key) {
                return $input->scope === 'member';
            }
        }
        return false;
    }
}
