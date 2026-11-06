<?php

namespace Cav7\Citations\Storage;

/**
 * A file or blob that can't be processed as it stands. The caller reports it and
 * stops for that item.
 */
final class BlobDamage extends \RuntimeException
{
}
