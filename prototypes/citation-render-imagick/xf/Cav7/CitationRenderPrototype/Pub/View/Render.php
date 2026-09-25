<?php

namespace Cav7\CitationRenderPrototype\Pub\View;

/**
 * PROTOTYPE, throwaway (#303). The headers the read-path decision (#298) asks for.
 */
class Render extends \XF\Mvc\View
{
    public function renderRaw()
    {
        // the filename comes from the URL here, so keep it to a safe set. The real route
        // builds it from the grant member's name snapshot, never from the request.
        $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $this->params['filename']);

        $this->response
            ->contentType('image/jpeg', '')
            ->header('Cache-Control', 'public, max-age=691200')
            // XenForo adds a 1981 Expires to any response without one
            ->header('Expires', gmdate('D, d M Y H:i:s', \XF::$time + 691200) . ' GMT')
            ->header('X-Robots-Tag', 'noindex')
            ->header('Content-Disposition', 'inline; filename="' . $filename . '"')
            ->header('Server-Timing', 'render;dur=' . $this->params['renderMs']);

        return $this->params['bytes'];
    }
}
