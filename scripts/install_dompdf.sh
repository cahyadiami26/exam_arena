#!/usr/bin/env bash
set -euo pipefail

# Manual, Composer-free installer for the PDF renderer and its runtime dependencies.
# Source archives are version-pinned and SHA-256 verified before extraction.
ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
LIB_DIR="$ROOT_DIR/lib"
DEST_DIR="$LIB_DIR/dompdf"

if [[ -e "$DEST_DIR" ]]; then
    echo "Refusing to overwrite existing directory: $DEST_DIR" >&2
    echo "Back it up or remove it deliberately, then rerun this script." >&2
    exit 1
fi
for command in curl unzip sha256sum; do
    command -v "$command" >/dev/null 2>&1 || {
        echo "Required command not found: $command" >&2
        exit 1
    }
done

mkdir -p "$LIB_DIR"
TMP_DIR="$(mktemp -d)"
STAGE_DIR="$(mktemp -d "$LIB_DIR/.dompdf-stage.XXXXXX")"
cleanup() {
    rm -rf -- "$TMP_DIR"
    if [[ -n "${STAGE_DIR:-}" && -d "$STAGE_DIR" ]]; then
        rm -rf -- "$STAGE_DIR"
    fi
}
trap cleanup EXIT HUP INT TERM

fetch_source() {
    local slug="$1" tag="$2" expected_sha="$3" name="$4"
    local archive="$TMP_DIR/$name.zip"
    local extract_dir="$TMP_DIR/$name"
    local source_dir
    local url="https://codeload.github.com/$slug/zip/refs/tags/$tag"

    curl --fail --location --silent --show-error --retry 3 \
        --user-agent 'EAssessment-LJU-manual-installer' "$url" --output "$archive"
    printf '%s  %s\n' "$expected_sha" "$archive" | sha256sum --check --status || {
        echo "SHA-256 verification failed for $slug@$tag" >&2
        exit 1
    }
    mkdir -p "$extract_dir"
    unzip -q "$archive" -d "$extract_dir"
    source_dir="$(find "$extract_dir" -mindepth 1 -maxdepth 1 -type d -print -quit)"
    [[ -n "$source_dir" && -d "$source_dir" ]] || {
        echo "Unexpected source archive layout for $slug@$tag" >&2
        exit 1
    }
    printf '%s\n' "$source_dir"
}

DOMPDF_SOURCE="$(fetch_source 'dompdf/dompdf' 'v3.1.6' '745b7e3440219ea085aa720ea2ae4cfae2c628c353c684b989ac83127824d995' 'dompdf')"
FONT_SOURCE="$(fetch_source 'dompdf/php-font-lib' '1.0.2' '6760784cba0f06ec18e6cac47d1588ec67a8849e41d16cdb804db8664c9010eb' 'php-font-lib')"
SVG_SOURCE="$(fetch_source 'dompdf/php-svg-lib' '1.0.2' '191019fb3ee9ab3a5908d4fcae254689faf183857a4b2bb02451364fc57e3af2' 'php-svg-lib')"
HTML5_SOURCE="$(fetch_source 'Masterminds/html5-php' '2.11.0' '30fa1540e052a176cd8e4e459a4199781aa34ad98c3aff9afb3807c5cd60e2ac' 'html5-php')"
CSS_SOURCE="$(fetch_source 'sabberworm/PHP-CSS-Parser' 'v9.5.0' '79a62b81c1fcc0c516bf353e07f652fb4d38820ea071719189498dc2a041c28c' 'php-css-parser')"

mkdir -p \
    "$STAGE_DIR/src" \
    "$STAGE_DIR/lib" \
    "$STAGE_DIR/vendor/dompdf/php-font-lib/src" \
    "$STAGE_DIR/vendor/dompdf/php-svg-lib/src" \
    "$STAGE_DIR/vendor/masterminds/html5/src" \
    "$STAGE_DIR/vendor/sabberworm/php-css-parser/src" \
    "$STAGE_DIR/licenses"
cp -a "$DOMPDF_SOURCE/src/." "$STAGE_DIR/src/"
cp -a "$DOMPDF_SOURCE/lib/." "$STAGE_DIR/lib/"
cp "$DOMPDF_SOURCE/VERSION" "$STAGE_DIR/VERSION"
cp -a "$FONT_SOURCE/src/." "$STAGE_DIR/vendor/dompdf/php-font-lib/src/"
cp -a "$SVG_SOURCE/src/." "$STAGE_DIR/vendor/dompdf/php-svg-lib/src/"
cp -a "$HTML5_SOURCE/src/." "$STAGE_DIR/vendor/masterminds/html5/src/"
cp -a "$CSS_SOURCE/src/." "$STAGE_DIR/vendor/sabberworm/php-css-parser/src/"
cp "$DOMPDF_SOURCE/LICENSE.LGPL" "$STAGE_DIR/licenses/dompdf-LGPL-2.1.txt"
cp "$FONT_SOURCE/LICENSE" "$STAGE_DIR/licenses/php-font-lib-LGPL-2.1.txt"
cp "$SVG_SOURCE/LICENSE" "$STAGE_DIR/licenses/php-svg-lib-LGPL-3.0.txt"
cp "$HTML5_SOURCE/LICENSE.txt" "$STAGE_DIR/licenses/html5-MIT.txt"
cp "$CSS_SOURCE/LICENSE" "$STAGE_DIR/licenses/php-css-parser-MIT.txt"
printf 'dompdf 3.1.6\nphp-font-lib 1.0.2\nphp-svg-lib 1.0.2\nmasterminds/html5 2.11.0\nsabberworm/php-css-parser 9.5.0\n' > "$STAGE_DIR/VERSIONS.txt"

for required in \
    "$STAGE_DIR/src/Dompdf.php" \
    "$STAGE_DIR/src/Options.php" \
    "$STAGE_DIR/lib/Cpdf.php" \
    "$STAGE_DIR/vendor/dompdf/php-font-lib/src/FontLib/Font.php" \
    "$STAGE_DIR/vendor/dompdf/php-svg-lib/src/Svg/Document.php" \
    "$STAGE_DIR/vendor/masterminds/html5/src/HTML5.php" \
    "$STAGE_DIR/vendor/sabberworm/php-css-parser/src/Rule/Rule.php" \
    "$STAGE_DIR/vendor/sabberworm/php-css-parser/src/RuleSet/RuleContainer.php"; do
    [[ -f "$required" ]] || {
        echo "Missing expected library file after extraction: $required" >&2
        exit 1
    }
done

mv -- "$STAGE_DIR" "$DEST_DIR"
STAGE_DIR=''
echo "Installed Dompdf 3.1.6 and pinned runtime dependencies in $DEST_DIR"
echo "The folder is intentionally ignored by Git; rerun this script on each deployment host."
