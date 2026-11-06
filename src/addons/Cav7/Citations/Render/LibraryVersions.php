<?php

namespace Cav7\Citations\Render;

/**
 * Property names are the typesetting row's columns. No Imagick API gives the FreeType
 * version, so it comes from GD's module info: `FreeType Version => 2.14.3` on the CLI,
 * an HTML table row under FPM. Assumes GD and ImageMagick link the same FreeType, as
 * on the fpm image. Re-check after an image rebuild.
 */
final class LibraryVersions
{
    public const UNKNOWN = 'unknown';

    public function __construct(
        public readonly string $imagemagick_version,
        public readonly string $freetype_version,
    ) {
    }

    public static function read(): self
    {
        return new self(
            self::imageMagickIn(\Imagick::getVersion()['versionString']),
            self::freeTypeIn(self::gdInfo()),
        );
    }

    public static function imageMagickIn(string $versionString): string
    {
        return preg_match('/^ImageMagick (\S+)/', $versionString, $match) === 1 ? $match[1] : self::UNKNOWN;
    }

    public static function freeTypeIn(string $moduleInfo): string
    {
        return preg_match('/FreeType Version\D*?(\d+(?:\.\d+)+)/', $moduleInfo, $match) === 1
            ? $match[1]
            : self::UNKNOWN;
    }

    private static function gdInfo(): string
    {
        if (!extension_loaded('gd')) {
            return '';
        }
        ob_start();
        (new \ReflectionExtension('gd'))->info();
        return (string) ob_get_clean();
    }
}
