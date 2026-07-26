<?php

namespace Cav7\Core\TemplateModification;

/**
 * The check could not work out what to check.
 *
 * Distinct from finding nothing wrong, and distinct from finding a failure:
 * this is the case where the run does not know what the board is supposed to be
 * carrying, so neither a green nor a red result would mean anything. The
 * callers turn it into exit status 2.
 */
class DiscoveryFailed extends \RuntimeException
{
}
