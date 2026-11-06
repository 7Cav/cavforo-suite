<?php

namespace Cav7\Citations\Render;

/**
 * The shipped fonts. Static fonts only: Imagick loads a variable font as its
 * default instance. `narrow` marks a font missing common accented letters,
 * which Imagick leaves as a blank gap with no error.
 */
final class FontCatalog
{
    public const FONTS = [
        'tinos-regular' => ['file' => 'Tinos-Regular.ttf', 'label' => 'Tinos', 'narrow' => false],
        'tinos-bold' => ['file' => 'Tinos-Bold.ttf', 'label' => 'Tinos Bold', 'narrow' => false],
        'tinos-italic' => ['file' => 'Tinos-Italic.ttf', 'label' => 'Tinos Italic', 'narrow' => false],
        'tinos-bold-italic' => ['file' => 'Tinos-BoldItalic.ttf', 'label' => 'Tinos Bold Italic', 'narrow' => false],
        'eb-garamond-regular' => ['file' => 'EBGaramond-Regular.ttf', 'label' => 'EB Garamond', 'narrow' => false],
        'eb-garamond-semibold' => ['file' => 'EBGaramond-SemiBold.ttf', 'label' => 'EB Garamond SemiBold', 'narrow' => false],
        'eb-garamond-bold' => ['file' => 'EBGaramond-Bold.ttf', 'label' => 'EB Garamond Bold', 'narrow' => false],
        'eb-garamond-italic' => ['file' => 'EBGaramond-Italic.ttf', 'label' => 'EB Garamond Italic', 'narrow' => false],
        'charis-regular' => ['file' => 'CharisSIL-Regular.ttf', 'label' => 'Charis SIL', 'narrow' => false],
        'charis-bold' => ['file' => 'CharisSIL-Bold.ttf', 'label' => 'Charis SIL Bold', 'narrow' => false],
        'trirong-regular' => ['file' => 'Trirong-Regular.ttf', 'label' => 'Trirong', 'narrow' => false],
        'trirong-bold' => ['file' => 'Trirong-Bold.ttf', 'label' => 'Trirong Bold', 'narrow' => false],
        'unifraktur-maguntia' => ['file' => 'UnifrakturMaguntia-Book.ttf', 'label' => 'UnifrakturMaguntia', 'narrow' => true],
        'pirata-one' => ['file' => 'PirataOne-Regular.ttf', 'label' => 'Pirata One', 'narrow' => false],
    ];

    /** @return array<string, string> Font key => path in the addon's fonts directory. */
    public static function paths(): array
    {
        return array_map(
            fn (array $font): string => dirname(__DIR__) . '/fonts/' . $font['file'],
            self::FONTS
        );
    }
}
