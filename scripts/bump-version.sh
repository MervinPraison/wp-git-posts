#!/bin/bash
# WordPress Plugin Version Bump Script
# Usage: ./scripts/bump-version.sh [major|minor|patch] OR ./scripts/bump-version.sh 1.2.3
# This script updates version in all required locations from a single source

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(dirname "$SCRIPT_DIR")"
VERSION_FILE="$PLUGIN_DIR/version.txt"
MAIN_PHP="$PLUGIN_DIR/praisonpressgit.php"
README_TXT="$PLUGIN_DIR/readme.txt"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Get current version
if [ ! -f "$VERSION_FILE" ]; then
    echo -e "${RED}Error: version.txt not found${NC}"
    exit 1
fi

CURRENT_VERSION=$(cat "$VERSION_FILE" | tr -d '[:space:]')
echo -e "${YELLOW}Current version: $CURRENT_VERSION${NC}"

# Parse current version
IFS='.' read -r MAJOR MINOR PATCH <<< "$CURRENT_VERSION"

# Determine new version
if [ -z "$1" ]; then
    echo "Usage: $0 [major|minor|patch|x.y.z]"
    echo "  major  - Bump major version (1.0.0 -> 2.0.0)"
    echo "  minor  - Bump minor version (1.0.0 -> 1.1.0)"
    echo "  patch  - Bump patch version (1.0.0 -> 1.0.1)"
    echo "  x.y.z  - Set specific version"
    exit 1
elif [ "$1" == "major" ]; then
    NEW_VERSION="$((MAJOR + 1)).0.0"
elif [ "$1" == "minor" ]; then
    NEW_VERSION="$MAJOR.$((MINOR + 1)).0"
elif [ "$1" == "patch" ]; then
    NEW_VERSION="$MAJOR.$MINOR.$((PATCH + 1))"
else
    # Assume it's a specific version
    NEW_VERSION="$1"
fi

echo -e "${GREEN}New version: $NEW_VERSION${NC}"

# Update version.txt
echo "$NEW_VERSION" > "$VERSION_FILE"
echo "✓ Updated version.txt"

# Update praisonpressgit.php - Plugin header
sed -i '' "s/Version: .*/Version: $NEW_VERSION/" "$MAIN_PHP"
echo "✓ Updated praisonpressgit.php header"

# Update praisonpressgit.php - PRAISON_VERSION constant
sed -i '' "s/define('PRAISON_VERSION', '.*');/define('PRAISON_VERSION', '$NEW_VERSION');/" "$MAIN_PHP"
echo "✓ Updated PRAISON_VERSION constant"

# Update readme.txt - Stable tag
sed -i '' "s/Stable tag: .*/Stable tag: $NEW_VERSION/" "$README_TXT"
echo "✓ Updated readme.txt stable tag"

echo ""
echo -e "${GREEN}✓ Version bumped to $NEW_VERSION${NC}"
echo ""
echo "Next steps:"
echo "  1. Update changelog in readme.txt"
echo "  2. Commit changes: git add -A && git commit -m 'Bump version to $NEW_VERSION'"
echo "  3. Create tag: git tag -a v$NEW_VERSION -m 'Version $NEW_VERSION'"
echo "  4. Push: git push origin main --tags"
echo "  5. Publish: ./scripts/publish.sh"
