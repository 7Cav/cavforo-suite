<?php

namespace Cav7\Citations\Render;

/**
 * Assumes annotation() prints its text literally, so `%w`, `%[fx:…]`, `@/path`, `\n`
 * and quotes need no escaping. Keep to that call. After an ImageMagick upgrade, render
 * a certificate on a dev stack and look at it.
 */
final class ImagickDrawer
{
    public function __construct(private FontMetrics $metrics)
    {
    }

    /** @throws RenderFailed A missing file or an Imagick throw. */
    public function draw(DrawPlan $plan): DrawResult
    {
        try {
            $image = new \Imagick();
            $image->readImage("{$plan->plate->format}:{$plan->plate->path}");
            // A greyscale plate would otherwise grey out colour art composited onto it.
            $image->transformImageColorspace(\Imagick::COLORSPACE_SRGB);

            if ($plan->lines !== []) {
                $image->drawImage(self::textDraw($plan));
            }
            foreach ($plan->images as $planned) {
                self::composite($image, $planned);
            }
            if ($plan->previewBoxes !== []) {
                $image->drawImage(self::previewDraw($image, $plan->previewBoxes));
            }

            return new DrawResult(JpegEncoder::encode($image), OverrunCheck::worst($plan, $this->metrics));
        } catch (\ImagickException | \ImagickDrawException $e) {
            throw new RenderFailed($e->getMessage(), 0, $e);
        }
    }

    private static function textDraw(DrawPlan $plan): \ImagickDraw
    {
        $draw = new \ImagickDraw();
        $draw->setTextAntialias(true);
        $draw->setTextEncoding('UTF-8');

        foreach ($plan->lines as $planned) {
            $line = $planned->line;
            $draw->setFont($planned->fontPath);
            $draw->setFontSize($planned->size);
            $draw->setFillColor($planned->colour);
            $draw->setTextInterwordSpacing($line->iw);
            $draw->annotation($line->x, $line->y, $line->t);
        }
        return $draw;
    }

    /** @param list<PreviewBox> $boxes */
    private static function previewDraw(\Imagick $image, array $boxes): \ImagickDraw
    {
        $draw = new \ImagickDraw();
        $draw->setTextAntialias(true);
        $draw->setTextEncoding('UTF-8');
        $draw->setFont(FontCatalog::paths()['tinos-regular']);
        $draw->setTextAlignment(\Imagick::ALIGN_CENTER);

        foreach ($boxes as $box) {
            $draw->setFillOpacity(0);
            $draw->setStrokeColor('rgba(70,90,140,0.75)');
            $draw->setStrokeWidth(max(1.5, $box->w / 250));
            $draw->setStrokeDashArray([10, 7]);
            $draw->rectangle($box->x, $box->y, $box->x + $box->w, $box->y + $box->h);

            $draw->setStrokeOpacity(0);
            $draw->setFillOpacity(1);
            $draw->setFillColor('rgba(70,90,140,0.9)');
            $size = max(12, min(26, $box->h * 0.22));
            $draw->setFontSize($size);
            $width = $image->queryFontMetrics($draw, $box->label)['textWidth'];
            if ($width > $box->w * 0.9) {
                $size *= $box->w * 0.9 / $width;
                $draw->setFontSize($size);
            }
            $draw->annotation($box->x + $box->w / 2, $box->y + $box->h / 2 + $size * 0.35, $box->label);
            $draw->setStrokeOpacity(1);
        }
        return $draw;
    }

    private static function composite(\Imagick $image, PlannedImage $planned): void
    {
        $placed = $planned->image;
        $art = new \Imagick();
        $art->readImage("png:{$planned->path}");

        if ($art->getImageWidth() !== $placed->w || $art->getImageHeight() !== $placed->h) {
            $art->resizeImage($placed->w, $placed->h, \Imagick::FILTER_LANCZOS, 1);
        }
        $image->compositeImage($art, \Imagick::COMPOSITE_OVER, $placed->x, $placed->y);
    }
}
