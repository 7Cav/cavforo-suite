<?php

namespace Cav7\DotTokenFix\XFES\Service;

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
