<?php

namespace Cav7\DotTokenFix\XFES\Service;

/**
 * Adds a `pattern_replace` char filter to the analyzer config XFES hands to
 * ElasticSearch, so a dot between two alphanumerics is split before
 * tokenization and a username like `Molitor.K` is findable as `molitor`.
 *
 * The filter is attached by name, to whichever of the `default`, `suggest` and
 * `elasticess_near_exact` analyzers the config declares. If XFES or
 * ElasticSearch Essentials renames or drops one, that analyzer is skipped with
 * no error and dotted usernames go back to being a single token there. Nothing
 * in CI can see it: checking the filter really applies needs a live
 * ElasticSearch. Re-check by hand on a dev stack after a XenForo upgrade, and
 * whenever XFES or ElasticSearch Essentials moves — rebuild the search index,
 * then search a dotted username by its first part alone.
 */
class Analyzer extends XFCP_Analyzer
{
    public function getAnalyzerFromConfig(array $config): array
    {
        $result = parent::getAnalyzerFromConfig($config);

        $result['analysis']['char_filter']['cav7_dot_split'] = [
            'type'        => 'pattern_replace',
            'pattern'     => '(?<=[\p{L}\p{N}])\.(?=[\p{L}\p{N}])',
            'replacement' => ' ',
        ];

        $analyzersToFix = ['default', 'suggest', 'elasticess_near_exact'];
        foreach ($analyzersToFix as $name)
        {
            if (isset($result['analysis']['analyzer'][$name]))
            {
                $result['analysis']['analyzer'][$name]['char_filter'] = ['cav7_dot_split'];
            }
        }

        return $result;
    }
}
