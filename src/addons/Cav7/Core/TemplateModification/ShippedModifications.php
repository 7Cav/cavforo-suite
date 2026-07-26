<?php

namespace Cav7\Core\TemplateModification;

/**
 * The template modifications an add-on ships, read off its `_data` bundle as it
 * exists on this board's filesystem.
 *
 * This is the expected set the check reconciles against, and it deliberately
 * does not come from the board's own modification records. Sourcing it from the
 * records would make a modification that shipped and never installed invisible:
 * there would be no row for it to be missing from, so it would be a pass by
 * absence. That is a live state rather than a hypothetical.
 */
class ShippedModifications
{
    /**
     * What $file declares, stamped with the add-on shipping it.
     *
     * An add-on with no such file ships nothing, which is the ordinary case for
     * over half the suite. A file that will not parse is refused instead: read
     * as empty it becomes an add-on that ships nothing, and every modification
     * it does ship then goes unchecked while the run still reports health.
     *
     * `enabled` is not read here. The board's record carries its own, XenForo
     * maintains that flag across an add-on upgrade rather than resetting it to
     * what the add-on ships, and it is the board's value that decides whether
     * anything is applied.
     *
     * @return list<array{addon_id: string, modification_key: string, type: string, template: string}>
     */
    public static function read(string $file, string $addOnId): array
    {
        if (!is_file($file)) {
            return [];
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $xml = simplexml_load_file($file);
        $errors = libxml_get_errors();

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            $messages = array_map(fn ($error) => trim($error->message), $errors);
            throw new DiscoveryFailed(
                "$addOnId ships a template_modifications.xml that will not parse, so what it ships cannot be"
                . " established: $file (" . implode('; ', $messages) . ')'
            );
        }

        $shipped = [];
        foreach ($xml->modification as $modification) {
            $shipped[] = [
                'addon_id' => $addOnId,
                'modification_key' => (string) $modification['modification_key'],
                'type' => (string) $modification['type'],
                'template' => (string) $modification['template'],
            ];
        }

        return $shipped;
    }
}
