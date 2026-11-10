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
            return new DrawResult(JpegEncoder::encode($this->compose($plan)), OverrunCheck::worst($plan, $this->metrics));
        } catch (\ImagickException | \ImagickDrawException $e) {
            throw new RenderFailed($e->getMessage(), 0, $e);
        }
    }

    /**
     * One region of the plate, for a preview that shows a single field. Not a render: it skips the overrun check.
     *
     * @throws RenderFailed
     */
    public function drawRegion(DrawPlan $plan, int $x, int $y, int $w, int $h): string
    {
        try {
            $image = $this->compose($plan);
            $image->cropImage($w, $h, $x, $y);
            $image->setImagePage(0, 0, 0, 0);

            return JpegEncoder::encode($image);
        } catch (\ImagickException | \ImagickDrawException $e) {
            throw new RenderFailed($e->getMessage(), 0, $e);
        }
    }

    private function compose(DrawPlan $plan): \Imagick
    {
        $image = new \Imagick();
        $image->readImage("{$plan->plate->format}:{$plan->plate->path}");
        // A greyscale plate would otherwise grey out colour art composited onto it.
        $image->transformImageColorspace(\Imagick::COLORSPACE_SRGB);

        // Runs of lines share one draw, flushed before each image so field order holds.
        $text = null;
        foreach ($plan->steps as $step) {
            if ($step instanceof PlannedLine) {
                self::annotate($text ??= self::textDraw(), $step);
                continue;
            }
            if ($text !== null) {
                $image->drawImage($text);
                $text = null;
            }
            self::composite($image, $step);
        }
        if ($text !== null) {
            $image->drawImage($text);
        }
        if ($plan->previewBoxes !== []) {
            $image->drawImage(self::previewDraw($image, $plan->previewBoxes));
        }

        return $image;
    }

    private static function textDraw(): \ImagickDraw
    {
        $draw = new \ImagickDraw();
        $draw->setTextAntialias(true);
        $draw->setTextEncoding('UTF-8');
        return $draw;
    }

    private static function annotate(\ImagickDraw $draw, PlannedLine $planned): void
    {
        $line = $planned->line;
        $draw->setFont($planned->fontPath);
        $draw->setFontSize($planned->size);
        $draw->setFillColor($planned->colour);
        $draw->setTextInterwordSpacing($line->iw);
        $draw->annotation($line->x, $line->y, $line->t);
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
