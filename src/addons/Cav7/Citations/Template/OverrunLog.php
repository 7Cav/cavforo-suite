<?php

namespace Cav7\Citations\Template;

use Cav7\Citations\Render\LibraryVersions;
use Cav7\Citations\Render\Overrun;
use XF\Db\AbstractAdapter;

/**
 * One row per version and library pair, so a bad rebuild leaves one error per version, and a
 * new library logs again. A failed render takes the version's row with no libraries, since a
 * missing file fails under any.
 */
final class OverrunLog
{
    public const TABLE = 'xf_cav7_citation_overrun_log';

    /** @param \Closure(string): void $logError */
    public function __construct(
        private AbstractAdapter $db,
        private \Closure $logError,
    ) {
    }

    /** A failure here doesn't fail the render, which still serves. */
    public function record(int $versionId, int $grantMemberId, Overrun $overrun, LibraryVersions $libraries, int $date): void
    {
        if ($this->claim($versionId, $libraries->imagemagick_version, $libraries->freetype_version, $date)) {
            ($this->logError)(sprintf(
                'Citation overrun: template version %d, field %s, grant member %d, %.2fpx past its box, ImageMagick %s, FreeType %s.',
                $versionId,
                $overrun->fieldKey,
                $grantMemberId,
                $overrun->pixels,
                $libraries->imagemagick_version,
                $libraries->freetype_version,
            ));
        }
    }

    /** Whether this is the version's first failed render, the one worth logging. */
    public function firstFailure(int $versionId, int $date): bool
    {
        return $this->claim($versionId, '', '', $date);
    }

    private function claim(int $versionId, string $imagemagick, string $freetype, int $date): bool
    {
        try {
            return $this->db->query(
                'INSERT IGNORE INTO ' . self::TABLE . ' (template_version_id, imagemagick_version, freetype_version, log_date)
                VALUES (?, ?, ?, ?)',
                [$versionId, $imagemagick, $freetype, $date]
            )->rowsAffected() > 0;
        } catch (\Exception) {
            return false;
        }
    }
}
