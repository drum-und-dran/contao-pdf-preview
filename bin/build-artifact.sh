#!/usr/bin/env bash
#
# Erzeugt ein installierbares Artefakt (ZIP mit Versionskennung) für den
# Contao Manager („Pakete hochladen“) oder ein Composer-"artifact"-Repository.
#
#   bin/build-artifact.sh 1.0.0
#
set -euo pipefail

VERSION="${1:-}"

if [[ -z "$VERSION" ]]; then
    VERSION="$(git describe --tags --abbrev=0 2>/dev/null || true)"
fi

if [[ ! "$VERSION" =~ ^v?[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$ ]]; then
    echo "Bitte eine gültige Version angeben, z. B.: bin/build-artifact.sh 1.0.0" >&2
    exit 1
fi

VERSION="${VERSION#v}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DIST="$ROOT/dist"
BUILD="$(mktemp -d)"
NAME="dud-contao-pdf-preview-$VERSION.zip"

trap 'rm -rf "$BUILD"' EXIT

# Nur die Dateien, die zur Laufzeit benötigt werden
for item in composer.json README.md README.en.md CHANGELOG.md LICENSE config contao docs public src; do
    [[ -e "$ROOT/$item" ]] && cp -R "$ROOT/$item" "$BUILD/"
done

# Versionskennung in die composer.json schreiben (nur im Artefakt, nicht im Repository)
php -r '
    $file = $argv[1];
    $json = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    $json = array_merge(["name" => $json["name"], "version" => $argv[2]], $json);
    $json["version"] = $argv[2];
    unset($json["require-dev"], $json["autoload-dev"]);
    file_put_contents($file, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
' "$BUILD/composer.json" "$VERSION"

mkdir -p "$DIST"
rm -f "$DIST/$NAME"

(
    cd "$BUILD"
    find . -name '.DS_Store' -delete
    # composer.json liegt im Wurzelverzeichnis des ZIP (erforderlich für Contao Manager/Composer)
    zip -qrX "$DIST/$NAME" . -x '*.tmp'
)

echo "Artefakt erstellt: $DIST/$NAME"
unzip -p "$DIST/$NAME" composer.json | grep -E '"(name|version)"'
