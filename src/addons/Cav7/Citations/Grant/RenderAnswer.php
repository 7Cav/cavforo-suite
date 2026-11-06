<?php

namespace Cav7\Citations\Grant;

/** What a render URL gets. Lookup is by id and token alone, and a wrong token reads as a missing id. */
enum RenderAnswer
{
    case NotFound;
    case Redirect;
    case Serve;

    /** @param string|null $storedToken Null when the id finds nothing, or a grant with no URL of its own. */
    public static function for(?string $storedToken, string $givenToken, int $withdrawnDate, int $revision, int $givenRevision): self
    {
        return match (true) {
            $storedToken === null, !hash_equals($storedToken, $givenToken), $withdrawnDate !== 0 => self::NotFound,
            $givenRevision !== $revision => self::Redirect,
            default => self::Serve,
        };
    }
}
