#!/bin/sh
# PROTOTYPE, throwaway (#303). Answers "what is this PHP image's Imagick, and how fast does it
# render a citation on this hardware". Run it in a throwaway container of the image under test,
# with networking off, so the running forum is not touched:
#
#   docker run --rm --network none -v "$PWD:/p" -w /p <php-fpm image> sh probe.sh > probe.txt
#
# Reads nothing outside this directory and writes only ./out. Takes about a minute.
set -u
section() { printf '\n== %s\n' "$1"; }

section host
uname -m
grep -m1 -iE "model name|^model|cpu part" /proc/cpuinfo || true
echo "cpus: $(nproc)"
grep MemTotal /proc/meminfo
echo "loadavg: $(cat /proc/loadavg)"

section "php and imagick"
php -v | head -1
php render.php --env

section "ImageMagick JPEG coder"
magick identify -list format 2>/dev/null | grep -E "^ +JPEG\*" || echo "no magick CLI in this image"

section packages
apk info -v 2>/dev/null | grep -E "^(imagemagick|imagemagick-jpeg|imagemagick-libs|freetype|libjpeg-turbo|harfbuzz|libraqm|php8[0-9]-pecl-imagick)-[0-9]" \
  || echo "not an apk image"

section "effective ImageMagick policy"
magick -list policy 2>/dev/null || echo "no magick CLI in this image"

section "one render of each text"
for t in short long tail; do
  php render.php --text=$t --out=out/probe-$t.jpg | grep -E '"citation text"|"total ms"|"load plate"|"draw text"|"encode"'
done

section "cold: a fresh PHP process per render, 5 each"
for t in short long; do
  for _ in 1 2 3 4 5; do php render.php --text=$t --json; done
done

section "warm: 30 renders in one process"
for t in short long tail; do php render.php --text=$t --bench=30 --json; done

section "output"
sha256sum out/probe-*.jpg
