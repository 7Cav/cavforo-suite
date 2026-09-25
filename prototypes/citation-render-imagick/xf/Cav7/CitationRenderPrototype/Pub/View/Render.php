<?php

namespace Cav7\CitationRenderPrototype\Pub\View;

/**
 * PROTOTYPE, throwaway (#303). The headers the read-path decision (#298) asks for.
 */
class Render extends \XF\Mvc\View
{
    public function renderRaw()
    {
        $this->response
            ->contentType('image/jpeg', '')
            ->header('Cache-Control', 'public, max-age=691200')
            // XenForo adds a 1981 Expires to any response without one
            ->header('Expires', gmdate('D, d M Y H:i:s', \XF::$time + 691200) . ' GMT')
            ->header('X-Robots-Tag', 'noindex')
            ->header('Content-Disposition', 'inline; filename="' . $this->params['filename'] . '"')
            ->header('Server-Timing', 'render;dur=' . $this->params['renderMs']);

        return $this->params['bytes'];
    }
}
