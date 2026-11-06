<?php

declare(strict_types=1);

namespace Cav7\Citations\Render;

/**
 * Attributes become constructor arguments by name, so strict types refuse a wrong
 * type and PHP refuses an unknown or missing attribute. A font key isn't checked
 * against the catalog, because a release may drop a font a published version pinned.
 */
final class FieldDefinitions
{
    private const KEY = '/^[a-z][a-z0-9_]{0,49}$/';
    private const COLOUR = '/^#[0-9a-f]{6}$/';

    /** @return list<TextField|SignatureSlot|RankArtField> */
    public static function fields(string $json): array
    {
        $fields = [];
        $keys = [];
        foreach (self::decode($json) as $definition) {
            $kind = $definition['kind'] ?? null;
            unset($definition['kind']);

            $field = self::build(fn (): object => match ($kind) {
                'text' => new TextField(...$definition),
                'signature' => new SignatureSlot(...$definition),
                'rank_art' => new RankArtField(...$definition),
                default => throw new \InvalidArgumentException('Unknown field kind'),
            });

            self::check($field);
            if (isset($keys[$field->key])) {
                throw new \InvalidArgumentException("Duplicate field key {$field->key}");
            }
            $keys[$field->key] = true;
            $fields[] = $field;
        }
        return $fields;
    }

    /** @return list<InputDefinition> */
    public static function inputs(string $json): array
    {
        $inputs = [];
        foreach (self::decode($json) as $definition) {
            $input = self::build(fn (): object => new InputDefinition(...$definition));
            self::require(preg_match(self::KEY, $input->key) === 1, "Bad input key {$input->key}");
            self::require(in_array($input->scope, ['grant', 'member'], true), "Bad scope on {$input->key}");
            self::require(!isset($inputs[$input->key]), "Duplicate input key {$input->key}");
            $inputs[$input->key] = $input;
        }
        return array_values($inputs);
    }

    private static function check(TextField|SignatureSlot|RankArtField $field): void
    {
        $key = $field->key;
        self::require(preg_match(self::KEY, $key) === 1, "Bad field key $key");
        self::require($field->x >= 0 && $field->y >= 0 && $field->w > 0 && $field->h > 0, "Bad box on $key");

        if ($field instanceof RankArtField) {
            return;
        }

        self::require($field->size >= Typesetter::LAST_RESORT_SIZE, "Bad size on $key");
        self::require(fmod($field->size, Typesetter::SIZE_STEP) === 0.0, "Size off the grid on $key");
        self::require($field->legible_size >= 0, "Bad legible_size on $key");
        self::require($field->leading > 0, "Bad leading on $key");
        self::require(preg_match(self::COLOUR, $field->colour) === 1, "Bad colour on $key");

        if ($field instanceof SignatureSlot) {
            self::require($field->ink_height > 0 && $field->ink_height < $field->h, "Bad ink_height on $key");
            return;
        }

        self::require(in_array($field->mode, ['line', 'wrap'], true), "Bad mode on $key");
        self::require(in_array($field->align, ['left', 'center', 'right', 'justify'], true), "Bad align on $key");
        self::require($field->align !== 'justify' || $field->mode === 'wrap', "justify needs wrap on $key");
    }

    /** @return list<array<string, mixed>> */
    private static function decode(string $json): array
    {
        $definitions = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        self::require(is_array($definitions) && array_is_list($definitions), 'Definitions must be a list');
        foreach ($definitions as $definition) {
            self::require(is_array($definition) && !array_is_list($definition), 'A definition must be an object');
        }
        return $definitions;
    }

    /**
     * @template T of object
     * @param \Closure(): T $construct
     * @return T
     */
    private static function build(\Closure $construct): object
    {
        try {
            return $construct();
        } catch (\Error $e) {
            throw new \InvalidArgumentException($e->getMessage(), 0, $e);
        }
    }

    private static function require(bool $holds, string $message): void
    {
        if (!$holds) {
            throw new \InvalidArgumentException($message);
        }
    }
}
