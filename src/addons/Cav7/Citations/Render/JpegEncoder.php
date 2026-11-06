<?php

namespace Cav7\Citations\Render;

/**
 * The one JPEG profile, for the render and the re-encode. Any change here changes
 * ENCODER_LABEL. Never XF\Image\Imagick, whose save() puts the ICC profile back.
 * After a read with setFormat('png'), setImageFormat() alone still writes PNG. A PNG's
 * pHYs reaches the JFIF header unless resolution is reset after the strip.
 */
final class JpegEncoder
{
    public const ENCODER_LABEL = 'q85-444-islow-huffopt-white-v1';

    public static function encode(\Imagick $image): string
    {
        $image->setImageBackgroundColor('white');
        $image->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
        $image->stripImage();
        $image->setImageResolution(0, 0);
        $image->setImageUnits(\Imagick::RESOLUTION_UNDEFINED);
        $image->setFormat('jpeg');
        $image->setImageFormat('jpeg');
        $image->setImageCompression(\Imagick::COMPRESSION_JPEG);
        $image->setImageCompressionQuality(85);
        $image->setSamplingFactors(['1x1', '1x1', '1x1']);
        $image->setInterlaceScheme(\Imagick::INTERLACE_NO);
        $image->setOption('jpeg:dct-method', 'islow');
        $image->setOption('jpeg:optimize-coding', 'true');

        return $image->getImageBlob();
    }
}
