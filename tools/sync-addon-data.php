<?php

/**
 * sync-addon-data.php — derive an add-on's _output/ tree from its _data/ bundle,
 * or the other way round, with no XenForo install and no database.
 *
 * XenForo normally writes both trees itself: `xf-addon:export` writes _data/ and
 * `xf-dev:export` writes _output/. A change that is nothing but XenForo data —
 * a template modification's find string, an option's default — then needs a
 * working install purely to keep the two trees in step, and
 * check-data-consistency.php fails if they drift. This script is the second
 * path: edit one tree, derive the other, commit both.
 *
 * It is a reimplementation of XenForo's own exporters, not a wrapper around
 * them, so it is only correct while it agrees with them byte for byte. That is
 * what tools/tests/sync-addon-data-test.php holds down, using the committed
 * add-ons as the oracle.
 *
 * Usage:
 *   php tools/sync-addon-data.php <addon-dir> --to-output
 *   php tools/sync-addon-data.php <addon-dir> --to-data
 */

namespace Cav7\Tools;

// ---------------------------------------------------------------------------
// XenForo encoding primitives.
//
// Both mirror XF\Util\Json and XF\DevelopmentOutput. Their output is compared
// byte for byte against trees XenForo wrote, so they follow the original
// closely rather than being tidied.
// ---------------------------------------------------------------------------

/**
 * XF\Util\Json::jsonEncodePretty. Four-space indent, unescaped slashes, and all
 * carriage returns stripped — from the encoded text and from within values.
 */
function jsonEncodePretty($input): string
{
    $output = json_encode($input, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

    // PHP 5.4 outputs line breaks in empty arrays
    $output = preg_replace('#\[\n\s*\]#', '[]', $output);

    $output = str_replace("\r", '', $output);
    $output = preg_replace('#(?<!\\\\)\\\\r#', '', $output);

    return $output;
}

/**
 * XF\DevelopmentOutput::hashContents — the `hash` recorded in _metadata.json.
 */
function hashContents(string $contents): string
{
    return md5(str_replace("\r", '', $contents));
}

/**
 * Compare two ids the way the database ordered them, which decides the order of
 * a _data file: XenForo reads every record out with an ORDER BY before writing.
 *
 * Which comparison applies is a property of the column, not of XenForo. Almost
 * every id column XenForo sorts these exports on is `varbinary` — xf_phrase.title,
 * xf_option.option_id, xf_template.title, xf_template_modification.template and
 * modification_key, xf_route.route_prefix, xf_option_group.group_id,
 * xf_code_event_listener.event_id, xf_admin_navigation.navigation_id,
 * xf_cron_entry.entry_id, xf_api_scope.api_scope_id — and a binary column sorts
 * by bytes.
 *
 * xf_class_extension.from_class and to_class are the exception: they are
 * `varchar` under utf8mb4_general_ci, which folds case. That is why ADR 0004's
 * case-folded rule is scoped to class extensions and to nothing else, and the
 * two rules genuinely disagree — for lowercase ids, `_` (0x5F) sorts before every
 * letter as bytes but after them once folded to uppercase.
 */
function collationCompare(string $a, string $b, string $collation): int
{
    return $collation === 'general_ci'
        ? strcmp(strtoupper($a), strtoupper($b))
        : strcmp($a, $b);
}

// ---------------------------------------------------------------------------
// Type table.
//
// One entry per XenForo data type this repo commits.
//
//   dir        the _output directory   (XF\DevelopmentOutput\*::getTypeDir)
//   container  the _data basename/root (XF\AddOn\DataType\*::getContainerTag)
//   childTag   the _data record tag    (XF\AddOn\DataType\*::getChildTag)
//
// XenForo names each type twice and the two names do not always agree, which is
// why both are here rather than one being derived from the other.
//
//   attributes  the _data attributes XenForo maps, in its order
//               (getMappedAttributes). An attribute whose value is the empty
//               string is omitted from _data entirely.
//   cdata       fields written as CDATA child elements, in order
//   cdataBody   a field written as the record element's own CDATA body
//   shape       'json' — the _output record is a pretty-printed JSON object
//               'text' — the _output record is the raw value of one field
//   jsonKeys    for 'json', the record's keys in the order the DevelopmentOutput
//               handler writes them. JSON object order is part of the bytes and
//               so part of the contract.
//   textField   for 'text', the field holding the file's contents
//   extension   the _output record's file extension; null means the records
//               carry their own, as templates do
//   meta        fields recorded in _metadata.json, in order, before `hash`
//   casts       how a _data attribute string maps onto its _output type
//   sort        fields the _data ORDER BY uses, in order
//   collation   how `sort` compares ids: 'binary' (the default) or 'general_ci'
//   elements    fields written as child elements rather than attributes
//   relations   an option's group relations, written as child elements
//   outputDefaults
//               fields present in _output that _data cannot represent, with the
//               XenForo entity default an import would fall back to
//
// Fields encoded in the _output path rather than in the file — a template's type
// and title, an option's id — are handled by fileNameFor and fieldsFromPath.
// ---------------------------------------------------------------------------

const TYPES = [
    'class_extensions' => [
        'dir' => 'class_extensions',
        'container' => 'class_extensions',
        'childTag' => 'extension',
        'shape' => 'json',
        'attributes' => ['from_class', 'to_class', 'execute_order', 'active'],
        'jsonKeys' => ['from_class', 'to_class', 'execute_order', 'active'],
        'casts' => ['execute_order' => 'int', 'active' => 'bool'],
        'sort' => ['from_class', 'to_class', 'execute_order'],
        // The one type sorted on varchar/utf8mb4_general_ci columns. See ADR 0004.
        'collation' => 'general_ci',
    ],
    'template_modifications' => [
        'dir' => 'template_modifications',
        'container' => 'template_modifications',
        'childTag' => 'modification',
        'shape' => 'json',
        'attributes' => [
            'type',
            'template',
            'modification_key',
            'description',
            'execution_order',
            'enabled',
            'action',
        ],
        'cdata' => ['find', 'replace'],
        'jsonKeys' => [
            'template',
            'description',
            'execution_order',
            'enabled',
            'action',
            'find',
            'replace',
        ],
        'casts' => ['execution_order' => 'int', 'enabled' => 'bool'],
        'sort' => ['template', 'modification_key'],
    ],
    'phrases' => [
        'dir' => 'phrases',
        'container' => 'phrases',
        'childTag' => 'phrase',
        'shape' => 'text',
        'attributes' => ['title', 'version_id', 'version_string'],
        'flagAttributes' => ['global_cache'],
        'cdataBody' => 'phrase_text',
        'textField' => 'phrase_text',
        'extension' => '.txt',
        'meta' => ['global_cache', 'version_id', 'version_string'],
        'casts' => ['version_id' => 'int', 'global_cache' => 'bool'],
        'sort' => ['title'],
    ],
    'templates' => [
        'dir' => 'templates',
        'container' => 'templates',
        'childTag' => 'template',
        'shape' => 'text',
        'attributes' => ['type', 'title', 'version_id', 'version_string'],
        'cdataBody' => 'template',
        'textField' => 'template',
        // A template file keeps its own extension — .html, .less, .css — so any
        // file under the type directory is a record.
        'extension' => null,
        'meta' => ['version_id', 'version_string'],
        'casts' => ['version_id' => 'int'],
        'sort' => ['type', 'title'],
    ],
    'options' => [
        'dir' => 'options',
        'container' => 'options',
        'childTag' => 'option',
        'shape' => 'json',
        'attributes' => [
            'option_id',
            'edit_format',
            'data_type',
            'validation_class',
            'validation_method',
            'advanced',
        ],
        // Written as child elements rather than attributes, in this order.
        'elements' => [
            'default_value' => [],
            'edit_format_params' => ['when' => 'notEmptyString'],
            // Joined fields are omitted when empty, so they need no `when`.
            'sub_options' => ['join' => "\n"],
        ],
        'relations' => [
            'tag' => 'relation',
            'keyAttribute' => 'group_id',
            'valueAttribute' => 'display_order',
        ],
        'jsonKeys' => [
            'edit_format',
            'edit_format_params',
            'data_type',
            'sub_options',
            'validation_class',
            'validation_method',
            'advanced',
            'default_value',
            'relations',
        ],
        'casts' => ['advanced' => 'bool'],
        'sort' => ['option_id'],
    ],
    'option_groups' => [
        'dir' => 'option_groups',
        'container' => 'option_groups',
        'childTag' => 'group',
        'shape' => 'json',
        'attributes' => ['group_id', 'icon', 'display_order', 'debug_only'],
        'jsonKeys' => ['icon', 'display_order', 'advanced', 'debug_only'],
        // XenForo's _data export has no `advanced` attribute for this type, so
        // an import would fall back to the entity default and the re-export
        // would write that. See outputDefaults below.
        'outputDefaults' => ['advanced' => false],
        'casts' => ['display_order' => 'int', 'debug_only' => 'bool'],
        'sort' => ['group_id'],
    ],
    'routes' => [
        'dir' => 'routes',
        'container' => 'routes',
        'childTag' => 'route',
        'shape' => 'json',
        'attributes' => [
            'route_type',
            'route_prefix',
            'sub_name',
            'format',
            'build_class',
            'build_method',
            'controller',
            'context',
            'action_prefix',
        ],
        'jsonKeys' => [
            'route_type',
            'route_prefix',
            'sub_name',
            'format',
            'build_class',
            'build_method',
            'controller',
            'context',
            'action_prefix',
        ],
        'sort' => ['route_type', 'route_prefix', 'sub_name'],
    ],
    'code_event_listeners' => [
        'dir' => 'code_event_listeners',
        'container' => 'code_event_listeners',
        'childTag' => 'listener',
        'shape' => 'json',
        'attributes' => [
            'event_id',
            'execute_order',
            'callback_class',
            'callback_method',
            'active',
            'hint',
            'description',
        ],
        'jsonKeys' => [
            'event_id',
            'execute_order',
            'callback_class',
            'callback_method',
            'active',
            'hint',
            'description',
        ],
        'casts' => ['execute_order' => 'int', 'active' => 'bool'],
        'sort' => ['event_id', 'callback_class', 'callback_method'],
    ],
    'admin_navigation' => [
        'dir' => 'admin_navigation',
        'container' => 'admin_navigation',
        'childTag' => 'admin_navigation_entry',
        'shape' => 'json',
        'attributes' => [
            'navigation_id',
            'parent_navigation_id',
            'display_order',
            'link',
            'icon',
            'admin_permission_id',
            'debug_only',
            'development_only',
            'hide_no_children',
        ],
        'jsonKeys' => [
            'parent_navigation_id',
            'display_order',
            'link',
            'icon',
            'admin_permission_id',
            'debug_only',
            'development_only',
            'super_admin_only',
            'hide_no_children',
        ],
        'outputDefaults' => ['super_admin_only' => false],
        'casts' => [
            'display_order' => 'int',
            'debug_only' => 'bool',
            'development_only' => 'bool',
            'hide_no_children' => 'bool',
        ],
        'sort' => ['navigation_id'],
    ],
    'cron_entries' => [
        'dir' => 'cron_entries',
        'container' => 'cron',
        'childTag' => 'entry',
        'shape' => 'json',
        'attributes' => ['entry_id', 'cron_class', 'cron_method', 'active'],
        'cdataBody' => 'run_rules',
        // run_rules is an array; _data holds it as compact JSON in the record's
        // CDATA body, _output as a nested object.
        'cdataBodyCodec' => 'json',
        'jsonKeys' => ['cron_class', 'cron_method', 'run_rules', 'active'],
        'casts' => ['active' => 'bool'],
        'sort' => ['entry_id'],
    ],
    'api_scopes' => [
        'dir' => 'api_scopes',
        'container' => 'api_scopes',
        'childTag' => 'api_scope',
        'shape' => 'json',
        'attributes' => ['api_scope_id'],
        'jsonKeys' => ['api_scope_id', 'usable_with_oauth_clients'],
        'outputDefaults' => ['usable_with_oauth_clients' => true],
        'sort' => ['api_scope_id'],
    ],
];

/**
 * The _output path for a record, relative to its type directory, mirroring each
 * DevelopmentOutput handler's getFileName().
 */
function fileNameFor(string $type, array $record): string
{
    switch ($type) {
        case 'class_extensions':
            $from = ltrim(preg_replace('#[^a-z0-9_-]#i', '-', $record['from_class']), '-');
            $to = ltrim(preg_replace('#[^a-z0-9_-]#i', '-', $record['to_class']), '-');
            return "{$from}_{$to}.json";

        case 'template_modifications':
            return "{$record['type']}/{$record['modification_key']}.json";

        case 'phrases':
            return "{$record['title']}.txt";

        case 'templates':
            // convertTemplateNameToFile: a name with no dot gains .html, so a
            // .less or .css template keeps the extension it already carries.
            $name = $record['title'];
            if (!strpos($name, '.')) {
                $name .= '.html';
            }
            return "{$record['type']}/{$name}";

        case 'options':
            return "{$record['option_id']}.json";

        case 'option_groups':
            return "{$record['group_id']}.json";

        case 'routes':
            $subName = preg_replace('#/$#', '_', $record['sub_name']);
            $subName = preg_replace('#[^a-z0-9_-]#i', '-', $subName);
            $subName = preg_replace('#-{2,}#', '-', $subName);
            return "{$record['route_type']}_{$record['route_prefix']}_{$subName}.json";

        case 'code_event_listeners':
            $suffix = md5("{$record['callback_class']}-{$record['callback_method']}-{$record['hint']}");
            return "{$record['event_id']}_{$suffix}.json";

        case 'admin_navigation':
            return "{$record['navigation_id']}.json";

        case 'cron_entries':
            return "{$record['entry_id']}.json";

        case 'api_scopes':
            return str_replace(':', '-', $record['api_scope_id']) . '.json';
    }

    throw new \InvalidArgumentException("No filename rule for type '$type'");
}

/**
 * Recover the fields a record's _output path encodes, given that path relative
 * to the type directory. The inverse of fileNameFor for types whose identity
 * lives in the path.
 */
function fieldsFromPath(string $type, string $relativePath): array
{
    switch ($type) {
        case 'template_modifications':
            $parts = explode('/', $relativePath);
            return [
                'type' => $parts[0],
                'modification_key' => basename($parts[1], '.json'),
            ];

        case 'phrases':
            return ['title' => substr($relativePath, 0, -strlen('.txt'))];

        case 'templates':
            // convertTemplateFileToName strips .html and nothing else, so a
            // .less template's extension is part of its title.
            [$templateType, $name] = explode('/', $relativePath, 2);
            if (substr($name, -5) === '.html') {
                $name = substr($name, 0, -5);
            }
            return ['type' => $templateType, 'title' => $name];

        case 'options':
            return ['option_id' => basename($relativePath, '.json')];

        case 'option_groups':
            return ['group_id' => basename($relativePath, '.json')];

        case 'admin_navigation':
            return ['navigation_id' => basename($relativePath, '.json')];

        case 'cron_entries':
            return ['entry_id' => basename($relativePath, '.json')];
    }

    return [];
}

/**
 * Cast a _data attribute string to the type the _output record holds it as.
 * _data carries everything as text; _output distinguishes ints and bools.
 */
function castValue(string $type, string $key, string $raw)
{
    $cast = TYPES[$type]['casts'][$key] ?? 'string';

    switch ($cast) {
        case 'int':
            return (int) $raw;
        case 'bool':
            return $raw === '1';
        default:
            return $raw;
    }
}

/**
 * The type-table key for a container tag, or null when this repo commits no
 * records of that type.
 */
function containerToType(string $container): ?string
{
    foreach (TYPES as $type => $spec) {
        if ($spec['container'] === $container) {
            return $type;
        }
    }

    return null;
}

// ---------------------------------------------------------------------------
// Reading _data.
// ---------------------------------------------------------------------------

/**
 * Read one _data type file into a list of records keyed by field name.
 *
 * XenForo's exportMappedAttributes omits any attribute whose value is the empty
 * string, so an absent attribute here means '' rather than "missing". The field
 * list in the type table is what makes that recoverable.
 */
function readDataRecords(string $addonDir, string $type): array
{
    $spec = TYPES[$type];
    $path = $addonDir . '/_data/' . $spec['container'] . '.xml';

    if (!file_exists($path)) {
        return [];
    }

    $previous = libxml_use_internal_errors(true);
    $xml = simplexml_load_file($path);
    libxml_use_internal_errors($previous);

    if ($xml === false) {
        throw new \RuntimeException("Could not parse $path");
    }

    $records = [];
    foreach ($xml->{$spec['childTag']} as $node) {
        $record = [];

        foreach ($spec['attributes'] as $attr) {
            $record[$attr] = isset($node[$attr]) ? (string) $node[$attr] : '';
        }

        // Flag attributes are written only when true, so absence means false.
        foreach ($spec['flagAttributes'] ?? [] as $attr) {
            $record[$attr] = isset($node[$attr]) ? (string) $node[$attr] : '';
        }

        foreach ($spec['cdata'] ?? [] as $field) {
            $record[$field] = uncdata((string) $node->{$field});
        }

        if (isset($spec['cdataBody'])) {
            $body = uncdata((string) $node);
            $record[$spec['cdataBody']] = ($spec['cdataBodyCodec'] ?? 'raw') === 'json'
                ? (json_decode($body, true) ?? [])
                : $body;
        }

        foreach ($spec['elements'] ?? [] as $field => $rules) {
            $present = isset($node->{$field});
            $value = $present ? (string) $node->{$field} : '';

            $record[$field] = isset($rules['join'])
                ? ($value === '' ? [] : explode($rules['join'], $value))
                : $value;
        }

        if (isset($spec['relations'])) {
            $relations = [];
            foreach ($node->{$spec['relations']['tag']} as $relation) {
                $key = (string) $relation[$spec['relations']['keyAttribute']];
                $relations[$key] = (int) (string) $relation[$spec['relations']['valueAttribute']];
            }
            $record['relations'] = $relations;
        }

        foreach ($spec['outputDefaults'] ?? [] as $field => $default) {
            $record[$field] = $default;
        }

        $records[] = $record;
    }

    return $records;
}

/**
 * XF\Util\Xml::processSimpleXmlCdata — undo the `]]>` escaping the exporter
 * applies when writing a CDATA section.
 */
function uncdata(string $value): string
{
    return str_replace(']-]->', ']]>', $value);
}

/**
 * XF\Util\Xml::createDomCdataSection — escape `]]>` so it can live inside a
 * CDATA section.
 */
function cdata(string $value): string
{
    return str_replace(']]>', ']-]->', $value);
}

// ---------------------------------------------------------------------------
// Writing _output.
// ---------------------------------------------------------------------------

function writeFile(string $path, string $contents): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents($path, $contents);
}

/**
 * The bytes of a record's _output file.
 */
function outputContents(string $type, array $record): string
{
    $spec = TYPES[$type];

    if ($spec['shape'] === 'text') {
        return (string) $record[$spec['textField']];
    }

    $json = [];
    foreach ($spec['jsonKeys'] as $key) {
        $value = $record[$key] ?? '';
        $json[$key] = is_string($value) ? castValue($type, $key, $value) : $value;
    }

    return jsonEncodePretty($json);
}

/**
 * Write every record of one type into _output, plus that type's _metadata.json.
 *
 * _metadata.json is keyed by filename and sorted with ksort, matching
 * XF\DevelopmentOutput::writeTypeMetadata. `hash` is always last, because the
 * handlers build their metadata array first and writeFile appends the hash.
 */
function writeOutputType(string $addonDir, string $type, array $records): void
{
    $spec = TYPES[$type];
    $typeDir = $addonDir . '/_output/' . $spec['dir'];

    // A type with no records has no directory at all: XenForo deletes the file
    // of a record you remove, and the last one takes the directory with it.
    // Leaving a stale tree here would make _output claim records _data no longer
    // has, which is exactly the drift this tool exists to prevent.
    if (!$records) {
        removeTree($typeDir);
        return;
    }

    $metadata = [];

    foreach ($records as $record) {
        $fileName = fileNameFor($type, $record);
        $contents = outputContents($type, $record);

        writeFile($typeDir . '/' . $fileName, $contents);

        $entry = [];
        foreach ($spec['meta'] ?? [] as $field) {
            $value = $record[$field] ?? '';
            $entry[$field] = is_string($value) ? castValue($type, $field, $value) : $value;
        }
        $entry['hash'] = hashContents($contents);

        $metadata[$fileName] = $entry;
    }

    // Anything left over is a record that no longer exists in _data.
    pruneOutputType($typeDir, array_keys($metadata), $spec['extension'] ?? '.json');

    ksort($metadata);
    writeFile($typeDir . '/_metadata.json', jsonEncodePretty($metadata));
}

/**
 * Delete every record file under a type directory that the current export did
 * not write, then any subdirectory left empty behind it.
 */
function pruneOutputType(string $typeDir, array $keep, ?string $extension): void
{
    $keep = array_fill_keys($keep, true);

    foreach (outputFiles($typeDir, $extension) as $relativePath) {
        if (!isset($keep[$relativePath])) {
            unlink($typeDir . '/' . $relativePath);
        }
    }

    removeEmptyDirectories($typeDir);
}

/**
 * Remove empty directories under $dir, depth first, leaving $dir itself.
 */
function removeEmptyDirectories(string $dir): void
{
    $walk = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($walk as $entry) {
        if ($entry->isDir() && !glob($entry->getPathname() . '/*')) {
            rmdir($entry->getPathname());
        }
    }
}

/**
 * Delete a directory and everything under it. A no-op when it is not there.
 */
function removeTree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $walk = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($walk as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }

    rmdir($dir);
}

/**
 * XF\DevelopmentOutput\ClassExtension::getExtensionCacheFileValue — the
 * _output/extension_hint.php stub file, which gives an IDE the XFCP_ parent
 * classes that only exist at runtime. It derives entirely from the class
 * extension records, and is absent when an add-on registers none.
 */
function extensionHint(array $extensions): string
{
    $grouped = [];

    foreach ($extensions as $extension) {
        $parts = explode('\\', $extension['to_class']);
        $class = 'XFCP_' . array_pop($parts);
        $namespace = implode('\\', $parts);

        $grouped[ltrim($namespace, '\\')][] = [
            'class' => $class,
            'from_class' => '\\' . ltrim($extension['from_class'], '\\'),
        ];
    }

    $output = '';
    foreach ($grouped as $namespace => $extensionValues) {
        $output .= "namespace {$namespace}\n";
        $output .= "{\n";
        foreach ($extensionValues as $extensionValue) {
            $output .= "\tclass {$extensionValue['class']} extends {$extensionValue['from_class']} {}\n";
        }
        $output .= "}\n";
        $output .= "\n";
    }

    $output = rtrim($output);

    return <<<EOF
<?php

// ################## THIS IS A GENERATED FILE ##################
// DO NOT EDIT DIRECTLY. EDIT THE CLASS EXTENSIONS IN THE CONTROL PANEL.

/**
 * @noinspection PhpIllegalPsrClassPathInspection
 * @noinspection PhpMultipleClassesDeclarationsInOneFile
 */

{$output}

EOF;
}

/**
 * XF\Repository\OptionRepository::getPhpDocType — the PHPDoc type an option's
 * data type maps onto.
 */
function phpDocType(string $dataType, array $subOptionKeys, $defaultValue): string
{
    switch ($dataType) {
        case 'boolean':
            return 'bool';
        case 'string':
            return 'string';
        case 'integer':
            return 'int';
        case 'unsigned_integer':
            return 'non-negative-int';
        case 'positive_integer':
            return 'positive-int';
        case 'numeric':
        case 'unsigned_numeric':
            return 'float';

        case 'array':
            if (!is_array($defaultValue)) {
                $defaultValue = [];
            }

            $definition = [];
            foreach ($subOptionKeys as $key) {
                $definition[$key] = 'mixed';
            }
            unset($definition['*']);

            foreach ($defaultValue as $key => $value) {
                $definition[$key] = phpDocType(
                    gettype($value),
                    is_array($value) ? array_keys($value) : [],
                    $value
                );
            }

            if (!$definition) {
                return 'array';
            }

            $parts = [];
            foreach ($definition as $key => $type) {
                $parts[] = $key . ': ' . $type;
            }

            return 'array{' . implode(', ', $parts) . '}';

        default:
            return 'mixed';
    }
}

/**
 * XF\Repository\OptionRepository::getOptionCacheFileValue — the
 * _output/option_hint.php stub, which gives an IDE a typed property per option
 * on XF\Options.
 *
 * Each line needs the option's title, which lives in the `option.<id>` phrase
 * rather than on the option record, so this reads across two data types.
 */
function optionHint(array $options, array $phrasesByTitle): string
{
    if (!$options) {
        return '';
    }

    $definitions = [];

    foreach ($options as $option) {
        $default = $option['default_value'];
        if ($option['data_type'] === 'array') {
            $default = json_decode($default, true);
        }

        $type = phpDocType($option['data_type'], $option['sub_options'], $default);
        if ($type !== 'mixed') {
            $type .= '|null';
        }

        $phraseTitle = 'option.' . $option['option_id'];
        // A missing phrase renders as its own name, which is what XenForo does.
        $description = $phrasesByTitle[$phraseTitle] ?? $phraseTitle;

        $definitions[] = "@property {$type} \${$option['option_id']} {$description}";
    }

    $definitions = implode("\n * ", $definitions);

    return <<<EOF
<?php

// ################## THIS IS A GENERATED FILE ##################
// DO NOT EDIT DIRECTLY. EDIT THE OPTIONS IN THE CONTROL PANEL.

/**
 * @noinspection PhpMultipleClassDeclarationsInspection
 * @noinspection PhpIllegalPsrClassPathInspection
 */

namespace XF;

/**
 * {$definitions}
 */
class Options
{
}

EOF;
}

/**
 * Derive the whole _output tree from _data.
 */
function toOutput(string $addonDir): void
{
    $byType = [];
    foreach (array_keys(TYPES) as $type) {
        $byType[$type] = sortDataRecords($type, readDataRecords($addonDir, $type));
        writeOutputType($addonDir, $type, $byType[$type]);
    }

    // The two hint files derive from records rather than from a _data type of
    // their own, and are absent when the add-on has no records to build them
    // from.
    if ($byType['class_extensions']) {
        writeFile($addonDir . '/_output/extension_hint.php', extensionHint($byType['class_extensions']));
    } else {
        removeFile($addonDir . '/_output/extension_hint.php');
    }

    if ($byType['options']) {
        $phrasesByTitle = [];
        foreach ($byType['phrases'] as $phrase) {
            $phrasesByTitle[$phrase['title']] = $phrase['phrase_text'];
        }
        writeFile($addonDir . '/_output/option_hint.php', optionHint($byType['options'], $phrasesByTitle));
    } else {
        removeFile($addonDir . '/_output/option_hint.php');
    }
}

/**
 * Delete a file if it is there. XenForo's deleteSpecialFile, which is how a hint
 * file goes away once the last record it described has gone.
 */
function removeFile(string $path): void
{
    if (is_file($path)) {
        unlink($path);
    }
}

// ---------------------------------------------------------------------------
// Reading _output.
// ---------------------------------------------------------------------------

/**
 * Read every record of one type out of _output. Returns records keyed by field
 * name, in no particular order — ordering is the writer's job.
 */
function readOutputRecords(string $addonDir, string $type): array
{
    $spec = TYPES[$type];
    $typeDir = $addonDir . '/_output/' . $spec['dir'];

    if (!is_dir($typeDir)) {
        return [];
    }

    $metadata = [];
    $metadataPath = $typeDir . '/_metadata.json';
    if (file_exists($metadataPath)) {
        $metadata = json_decode(file_get_contents($metadataPath), true) ?: [];
    }

    $records = [];

    // Not `?? '.json'`: a declared null extension means "any", and null-coalescing
    // would quietly turn that back into a .json-only filter.
    $extension = array_key_exists('extension', $spec) ? $spec['extension'] : '.json';

    $files = outputFiles($typeDir, $extension);

    // The type directory exists, so XenForo wrote records into it. Finding none
    // means this tool failed to recognise them, and carrying on would write an
    // empty _data file over a populated one.
    if (!$files) {
        throw new \RuntimeException("No $type records found under $typeDir, but the directory exists");
    }

    foreach ($files as $relativePath) {
        $contents = file_get_contents($typeDir . '/' . $relativePath);

        if ($spec['shape'] === 'text') {
            $record = [$spec['textField'] => $contents];
        } else {
            $decoded = json_decode($contents, true);
            if (!is_array($decoded)) {
                throw new \RuntimeException("Could not parse $typeDir/$relativePath");
            }
            $record = $decoded;
        }

        $record += fieldsFromPath($type, $relativePath);

        foreach ($spec['meta'] ?? [] as $field) {
            $record[$field] = $metadata[$relativePath][$field] ?? '';
        }

        $records[] = $record;
    }

    return $records;
}

/**
 * Every record file under an _output type directory, at any depth, as paths
 * relative to that directory. _metadata.json is an index, not a record.
 */
function outputFiles(string $typeDir, ?string $extension): array
{
    $found = [];

    $walk = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($typeDir, \FilesystemIterator::SKIP_DOTS)
    );
    foreach ($walk as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $relativePath = substr($file->getPathname(), strlen($typeDir) + 1);
        if ($relativePath === '_metadata.json') {
            continue;
        }
        // A null extension means the type's records carry their own — templates
        // are .html, .less or .css — so every remaining file is a record.
        if ($extension !== null && substr($relativePath, -strlen($extension)) !== $extension) {
            continue;
        }
        $found[] = $relativePath;
    }

    // Directory order is the filesystem's, and differs between machines. Sort so
    // a derived tree depends only on its inputs.
    sort($found);

    return $found;
}

/**
 * Order records the way XenForo's exporter read them out of the database, so the
 * derived _data file matches the committed one.
 */
function sortDataRecords(string $type, array $records, array $priorOrder = []): array
{
    $keys = TYPES[$type]['sort'];
    $collation = TYPES[$type]['collation'] ?? 'binary';

    // XenForo's ORDER BY is not always a total order. ApiKeyManager registers two
    // code event listeners that agree on event_id, callback_class and
    // callback_method and differ only in hint, so the database is free to return
    // them either way round and does so in primary key — that is, insertion —
    // order. Nothing in _output records that: a listener's file is named
    // event_id_md5(class-method-hint), which carries no position.
    //
    // So where the ORDER BY ties, the committed _data file is the only evidence
    // of what the database returned, and its order is preserved. Records it does
    // not know fall in behind, ordered by their _output filename so the result
    // depends on the records alone and not on the order the filesystem listed
    // them in.
    $rank = static function (array $record) use ($type, $priorOrder): array {
        $identity = fileNameFor($type, $record);

        return isset($priorOrder[$identity])
            ? [0, $priorOrder[$identity], '']
            : [1, 0, $identity];
    };

    usort($records, static function (array $a, array $b) use ($keys, $collation, $rank): int {
        foreach ($keys as $key) {
            $left = $a[$key] ?? '';
            $right = $b[$key] ?? '';

            $order = is_int($left) || is_int($right)
                ? ((int) $left <=> (int) $right)
                : collationCompare((string) $left, (string) $right, $collation);

            if ($order !== 0) {
                return $order;
            }
        }

        return $rank($a) <=> $rank($b);
    });

    return $records;
}

/**
 * Where each record of a committed _data file sits, keyed by the _output
 * filename that identifies it. Empty when the file is not there yet.
 */
function priorDataOrder(string $addonDir, string $type): array
{
    $order = [];

    foreach (readDataRecords($addonDir, $type) as $position => $record) {
        $order[fileNameFor($type, $record)] = $position;
    }

    return $order;
}

// ---------------------------------------------------------------------------
// Writing _data.
// ---------------------------------------------------------------------------

/**
 * Render a value as XenForo's exportMappedAttributes would. Returns null when
 * the attribute is omitted entirely, which is what an empty string becomes.
 */
function dataAttributeValue($value): ?string
{
    if ($value === '' || $value === null) {
        return null;
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }

    return (string) $value;
}

/**
 * Write one _data type file. Uses DOMDocument with formatOutput, the same way
 * XF\Service\AddOn\ExporterService does, so indentation and escaping come from
 * the same place rather than from string building here.
 */
function writeDataType(string $addonDir, string $container, array $records, array $priorOrder = []): void
{
    $doc = new \DOMDocument('1.0', 'utf-8');
    $doc->formatOutput = true;

    $root = $doc->createElement($container);

    $type = containerToType($container);
    if ($type !== null) {
        $spec = TYPES[$type];

        foreach (sortDataRecords($type, $records, $priorOrder) as $record) {
            $node = $doc->createElement($spec['childTag']);

            foreach ($spec['attributes'] as $attr) {
                $value = dataAttributeValue($record[$attr] ?? '');
                if ($value !== null) {
                    $node->setAttribute($attr, $value);
                }
            }

            // Written only when true, and after the mapped attributes.
            foreach ($spec['flagAttributes'] ?? [] as $attr) {
                if (!empty($record[$attr])) {
                    $node->setAttribute($attr, '1');
                }
            }

            foreach ($spec['cdata'] ?? [] as $field) {
                $child = $doc->createElement($field);
                $child->appendChild($doc->createCDATASection(cdata((string) ($record[$field] ?? ''))));
                $node->appendChild($child);
            }

            if (isset($spec['cdataBody'])) {
                $body = $record[$spec['cdataBody']] ?? '';
                if (($spec['cdataBodyCodec'] ?? 'raw') === 'json') {
                    $body = json_encode($body);
                }
                $node->appendChild($doc->createCDATASection(cdata((string) $body)));
            }

            foreach ($spec['elements'] ?? [] as $field => $rules) {
                $value = $record[$field] ?? '';

                if (isset($rules['join'])) {
                    if (!$value) {
                        continue;
                    }
                    $value = implode($rules['join'], $value);
                } elseif (($rules['when'] ?? null) === 'notEmptyString' && $value === '') {
                    continue;
                }

                $child = $doc->createElement($field);
                $child->appendChild($doc->createTextNode((string) $value));
                $node->appendChild($child);
            }

            if (isset($spec['relations'])) {
                foreach ($record['relations'] ?? [] as $key => $value) {
                    $relation = $doc->createElement($spec['relations']['tag']);
                    $relation->setAttribute($spec['relations']['keyAttribute'], (string) $key);
                    $relation->setAttribute($spec['relations']['valueAttribute'], (string) $value);
                    $node->appendChild($relation);
                }
            }

            $root->appendChild($node);
        }
    }

    $doc->appendChild($root);

    writeFile($addonDir . '/_data/' . $container . '.xml', $doc->saveXML());
}

/**
 * Every container tag XenForo registers a data type handler for, and so every
 * file `xf-addon:export` writes into _data/ — including the ones an add-on has
 * no records for, which are written as an empty container rather than skipped.
 *
 * Re-derive this against a newer XenForo by reading getContainerTag() off the
 * XF\AddOn\DataType\* classes. This tool runs without XenForo and so cannot
 * notice a type added later.
 */
const CONTAINERS = [
    'activity_summary_definitions',
    'admin_navigation',
    'admin_permission',
    'advertising_positions',
    'api_scopes',
    'bb_code_media_sites',
    'bb_codes',
    'class_extensions',
    'code_event_listeners',
    'code_events',
    'content_type_fields',
    'cron',
    'help_pages',
    'member_stats',
    'navigation',
    'option_groups',
    'options',
    'permission_interface_groups',
    'permissions',
    'phrases',
    'routes',
    'style_properties',
    'style_property_groups',
    'template_modifications',
    'templates',
    'widget_definitions',
    'widget_positions',
];

/**
 * Derive the whole _data bundle from _output.
 */
function toData(string $addonDir): void
{
    foreach (CONTAINERS as $container) {
        $type = containerToType($container);

        if ($type === null) {
            writeDataType($addonDir, $container, []);
            continue;
        }

        // Read the order of the file about to be overwritten, for the ties the
        // ORDER BY cannot settle.
        $priorOrder = priorDataOrder($addonDir, $type);

        writeDataType($addonDir, $container, readOutputRecords($addonDir, $type), $priorOrder);
    }
}

// ---------------------------------------------------------------------------
// Entry point.
// ---------------------------------------------------------------------------

function main(array $argv): int
{
    $addonDir = $argv[1] ?? null;
    $direction = $argv[2] ?? null;

    if ($addonDir === null || $direction === null) {
        fwrite(STDERR, "usage: sync-addon-data.php <addon-dir> --to-output|--to-data\n");
        return 1;
    }

    $addonDir = rtrim($addonDir, '/');

    if (!is_dir($addonDir)) {
        fwrite(STDERR, "error: not a directory: $addonDir\n");
        return 1;
    }

    // Deriving from a tree that is not there would not fail — it would quietly
    // write an add-on's worth of empty containers, or delete every record in
    // _output. Refuse instead: an absent source tree means this add-on commits
    // no XenForo data (Cav7/Core is one), not that its data is empty.
    switch ($direction) {
        case '--to-output':
            if (!is_dir($addonDir . '/_data')) {
                fwrite(STDERR, "error: no _data/ to derive from in $addonDir\n");
                return 1;
            }
            toOutput($addonDir);
            return 0;

        case '--to-data':
            if (!is_dir($addonDir . '/_output')) {
                fwrite(STDERR, "error: no _output/ to derive from in $addonDir\n");
                return 1;
            }
            toData($addonDir);
            return 0;

        default:
            fwrite(STDERR, "error: unknown direction '$direction'\n");
            return 1;
    }
}

exit(main($argv));
