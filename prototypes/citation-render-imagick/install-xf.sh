#!/bin/sh
# PROTOTYPE, throwaway. Copies the route addon plus the renderer into a XenForo app tree.
#   ./install-xf.sh /path/to/xenforo/app fonts-dir
# then, inside the fpm container:  php cmd.php xf-addon:install Cav7/CitationRenderPrototype
# and to remove it:                 php cmd.php xf-addon:uninstall Cav7/CitationRenderPrototype
set -eu
here=$(cd "$(dirname "$0")" && pwd)
app=$1
fonts=$2
dest="$app/src/addons/Cav7/CitationRenderPrototype"
mkdir -p "$dest/proto/fonts"
cp -R "$here/xf/Cav7/CitationRenderPrototype/." "$dest/"
cp "$here/render.php" "$here/grant.php" "$here/plate-1275.png" "$here/ink.png" "$dest/proto/"
cp "$fonts"/*-Regular.ttf "$fonts"/*-Bold.ttf "$dest/proto/fonts/"
echo "installed into $dest"
