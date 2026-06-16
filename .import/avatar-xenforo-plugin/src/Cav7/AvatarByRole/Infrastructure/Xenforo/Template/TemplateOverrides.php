<?php

namespace Cav7\AvatarByRole\Infrastructure\Xenforo\Template;

use Cav7\AvatarByRole\Application\Config\MourningConfig;
use Cav7\AvatarByRole\Application\Mapper\UserGroupAvatarMapper;
use XF\Container;
use XF\Template\Templater;

class TemplateOverrides
{
    public static function templaterSetup(Container $container, Templater $templater)
    {
        $templater->addFunction('avatar', [self::class, 'customAvatar']);
    }

    public static function customAvatar(
        Templater $templater,
        &$escape,
        $user,
        $size = 's',
        $canonical = false,
        $href = '',
    ) {
        if (is_string($href)) {
            $attributes = ['href' => $href];
        }

        if (is_array($href)) {
            $attributes = array_merge($href, $attributes ?? []);
        }

        $primary = $user['user_group_id'] ?? 0;
        $secondary = $user['secondary_group_ids'] ?? [];

        if (is_string($secondary)) {
            $secondary = array_map('intval', explode(',', $secondary));
        }

        $imgSrc = UserGroupAvatarMapper::getAvatarForGroupIds($primary, $secondary);

        if (!$imgSrc) {
            $imgSrc = UserGroupAvatarMapper::DEFAULT_AVATAR;
        }


        $mourning = new MourningConfig();
        if ($mourning->isActiveAt()) {
            $imgSrc = $mourning->applyVariantIfExists($imgSrc);
        }

        $default = $templater->fnAvatar($templater, $escape, $user, $size, $canonical, $attributes);

        $dom = new \DOMDocument('1.0', 'UTF-8');
        \libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $default, \LIBXML_HTML_NOIMPLIED | \LIBXML_HTML_NODEFDTD);
        \libxml_clear_errors();

        $xpath = new \DOMXPath($dom);

        // Avatar container can be <span class="avatar..."> or <a class="avatar...">
        $container = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " avatar ")]')->item(0);
        if (!$container) {
            // Fallback: if markup changes unexpectedly, just return the default.
            return $default;
        }

        // Nuke children (removes user-uploaded <img> AND default initials spans)
        while ($container->firstChild) {
            $container->removeChild($container->firstChild);
        }

        $img = $dom->createElement('img');
        $img->setAttribute('src', $imgSrc);
        $img->setAttribute('loading', 'lazy');
        $img->setAttribute('alt', '');

        $container->appendChild($img);

        return $dom->saveHTML();
    }
}
