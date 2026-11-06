<?php

namespace Cav7\Citations\Storage;

/**
 * What the re-encode refuses and keeps, on plain values.
 */
final class ReencodeRules
{
    /**
     * @return int|null the unix time, or null for anything but a full ISO 8601 timestamp
     */
    public static function parseSyncedAt(string $value): ?int
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $value);
        $errors = \DateTimeImmutable::getLastErrors();

        return $parsed && !($errors && ($errors['warning_count'] || $errors['error_count']))
            ? $parsed->getTimestamp()
            : null;
    }

    /**
     * @param list<string> $jpegFormats what Imagick::queryFormats('JPEG') returned
     * @return string|null why the run can't start, or null when it can
     */
    public static function refusal(bool $enabled, bool $imagickLoaded, array $jpegFormats, ?string $syncedAt, int $now): ?string
    {
        if (!$enabled) {
            return 'the re-encode is switched off in the options';
        }
        if (!$imagickLoaded || !$jpegFormats) {
            return 'Imagick with JPEG support is not available';
        }
        if ($syncedAt === null || $syncedAt === '') {
            return '--synced-at is required';
        }

        $time = self::parseSyncedAt($syncedAt);
        if ($time === null) {
            return '--synced-at must be ISO 8601, such as 2026-11-05T14:30:00Z';
        }

        return $time > $now ? '--synced-at is in the future' : null;
    }

    /**
     * Imagick's PHP API doesn't expose the delegate's version, so this reads the JPEG line
     * of `magick -list format`. Empty when the binary gave nothing, since the version
     * doesn't change the output.
     */
    public static function libjpegVersionIn(string $formatList): string
    {
        return preg_match('/^\s*JPEG\*?\s.*\((libjpeg\S* [0-9][0-9.]*)\)/mi', $formatList, $match) === 1 ? $match[1] : '';
    }

    public static function keepsSource(int $encodedSize, int $sourceSize): bool
    {
        return $encodedSize >= $sourceSize;
    }
}
