<?php

namespace Cav7\Citations\Template;

/** The message is a rule name, which is the tail of its phrase title. */
final class UploadRefused extends \RuntimeException
{
    public const NOT_AN_ACCEPTED_IMAGE = 'not_an_accepted_image';
    public const UNDECODABLE_IMAGE = 'undecodable_image';
    public const IMAGE_TOO_LARGE = 'image_too_large';
}
