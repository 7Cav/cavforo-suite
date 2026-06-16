<?php

declare(strict_types=1);

namespace Cav7\AvatarByRole\Application\Config;

use XF;

final class MourningConfig
{
    public function enabled(): bool
    {
        $user = \XF::visitor();
        $optedOut = !empty($user->Profile->custom_fields['cav7_opt_out_mourning']);

        if ($optedOut) {
            return false;
        }

        return (bool) XF::options()->cav7AbrMourningActive;
    }

    public function isActiveAt(?int $nowTs = null): bool
    {
        if (!$this->enabled()) {
            return false;
        }

        $nowTs ??= time();

        $start = $this->parseTs((string) XF::options()->cav7AbrMourningStart);
        $end   = $this->parseTs((string) XF::options()->cav7AbrMourningEnd);

        if ($start !== null && $nowTs < $start) {
            return false;
        }

        if ($end !== null && $nowTs > $end) {
            return false;
        }

        if ($start !== null && $end !== null && $start > $end) {
            return false;
        }

        return true;
    }

    private function parseTs(string $input): ?int
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }

        $ts = strtotime($input);
        return $ts === false ? null : $ts;
    }

    public function applyVariantIfExists(string $avatarWebPath): string
    {
        $mourningPath = preg_replace('/(\.\w+)$/', '_Mourning$1', $avatarWebPath);
        if (!$mourningPath || $mourningPath === $avatarWebPath) {
            return $avatarWebPath;
        }

        // If the avatar is a recruiter variant, we want to check for a regular mourning version of that avatar instead of a recruiter mourning version, since the recruiter mourning version doesn't exist and we don't want to force users to create it.
        if (strpos($avatarWebPath, '_Recruiter') !== false) {
            $mourningPath = str_replace(
                '_Recruiter',
                '_Mourning',
                $avatarWebPath,
            );
        }


        $root = \XF::getRootDirectory(); // xenforo/ (install root)
        $fsPath = $root . $mourningPath;

        return is_file($fsPath) ? $mourningPath : $avatarWebPath;
    }
}
