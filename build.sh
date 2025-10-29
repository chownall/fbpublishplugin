#!/bin/zsh
set -euo pipefail

# Build and package the WordPress plugin with version bump and meta info.
# Usage:
#   ./build.sh                          # default: bump patch from current header version
#   ./build.sh 1.0.1                    # set explicit version
#   ./build.sh --bump patch|minor|major  # bump from current header version

SCRIPT_DIR=$(cd "$(dirname "$0")" && pwd)
cd "$SCRIPT_DIR"

PLUGIN_SLUG="fbpublishplugin"
MAIN_FILE="${SCRIPT_DIR}/fbpublishplugin.php"
README_FILE="${SCRIPT_DIR}/readme.txt"
ASSETS_DIR="${SCRIPT_DIR}/assets"
DIST_DIR="${SCRIPT_DIR}/dist"
BUILD_DIR="${SCRIPT_DIR}/build"

if [[ ! -f "$MAIN_FILE" ]]; then
  echo "Main file not found: $MAIN_FILE" >&2
  exit 1
fi

current_version() {
  grep -E "^\s*\*\s*Version:\s*" "$MAIN_FILE" | sed -E 's/.*Version:\s*([0-9]+\.[0-9]+\.[0-9]+).*/\1/'
}

increment_version() {
  local cur="$1"; local part="$2"
  local major minor patch
  IFS='.' read -r major minor patch <<< "$cur"
  case "$part" in
    patch) patch=$((patch+1));;
    minor) minor=$((minor+1)); patch=0;;
    major) major=$((major+1)); minor=0; patch=0;;
    *) echo "Unknown bump part: $part" >&2; exit 1;;
  esac
  echo "$major.$minor.$patch"
}

VALID_SEMVER_REGEX='^[0-9]+\.[0-9]+\.[0-9]+$'

TARGET_VERSION=""
if [[ $# -eq 0 ]]; then
  CURV=$(current_version)
  TARGET_VERSION=$(increment_version "$CURV" "patch")
elif [[ $# -eq 1 ]]; then
  if [[ "$1" =~ $VALID_SEMVER_REGEX ]]; then
    TARGET_VERSION="$1"
  elif [[ "$1" == "--bump" ]]; then
    echo "Error: --bump requires an argument patch|minor|major" >&2
    exit 1
  else
    echo "Usage: $0 [no-args=>bump patch] | $0 <x.y.z> | $0 --bump patch|minor|major" >&2
    exit 1
  fi
elif [[ $# -eq 2 && "$1" == "--bump" ]]; then
  CURV=$(current_version)
  TARGET_VERSION=$(increment_version "$CURV" "$2")
else
  echo "Usage: $0 [no-args=>bump patch] | $0 <x.y.z> | $0 --bump patch|minor|major" >&2
  exit 1
fi

echo "Building $PLUGIN_SLUG version $TARGET_VERSION"

# Update Version header in main plugin file
sed -E -i '' "s/^(\s*\*\s*Version:\s*).*/\\1${TARGET_VERSION}/" "$MAIN_FILE"

# Update Stable tag in readme.txt if present
if [[ -f "$README_FILE" ]]; then
  sed -E -i '' "s/^(Stable tag:\s*).*/\\1${TARGET_VERSION}/" "$README_FILE" || true
fi

# Update script enqueue version in PHP (best effort)
sed -E -i '' "s/'[0-9]+\.[0-9]+\.[0-9]+'/'${TARGET_VERSION}'/g" "$MAIN_FILE" || true

# Prepare build directories
rm -rf "$BUILD_DIR" && mkdir -p "$BUILD_DIR/$PLUGIN_SLUG"
mkdir -p "$DIST_DIR"

# Copy plugin files (exclude build/dist/git and common junk)
rsync -a \
  --exclude 'build' \
  --exclude 'dist' \
  --exclude '.git' \
  --exclude '.gitignore' \
  --exclude '.DS_Store' \
  --exclude '*.zip' \
  "$SCRIPT_DIR/" "$BUILD_DIR/$PLUGIN_SLUG/"

# Generate meta.json
GIT_SHA=$( (git -C "$SCRIPT_DIR" rev-parse --short HEAD) 2>/dev/null || echo "unknown")
BUILD_DATE=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
cat > "$BUILD_DIR/$PLUGIN_SLUG/meta.json" <<JSON
{
  "name": "FB Publish Plugin",
  "slug": "$PLUGIN_SLUG",
  "version": "$TARGET_VERSION",
  "buildDate": "$BUILD_DATE",
  "git": {
    "sha": "$GIT_SHA"
  }
}
JSON

# Create zip
ZIP_NAME="${PLUGIN_SLUG}-${TARGET_VERSION}.zip"
(cd "$BUILD_DIR" && zip -rq "$DIST_DIR/$ZIP_NAME" "$PLUGIN_SLUG")

echo "Created: $DIST_DIR/$ZIP_NAME"


