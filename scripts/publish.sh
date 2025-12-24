#!/bin/bash
# WordPress.org SVN Publish Script
# Publishes the plugin to WordPress.org SVN repository
# 
# Usage: ./scripts/publish.sh
# 
# Environment variables (set these or use .env file):
#   SVN_USERNAME - Your WordPress.org username
#   SVN_PASSWORD - Your WordPress.org SVN password
#
# Security: Never commit credentials. Use environment variables or .env file.

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(dirname "$SCRIPT_DIR")"
VERSION_FILE="$PLUGIN_DIR/version.txt"
SVN_REPO="https://plugins.svn.wordpress.org/praison-file-content-git"
SVN_DIR="$PLUGIN_DIR/.svn-deploy"
PLUGIN_SLUG="praison-file-content-git"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}  WordPress.org Plugin Publisher${NC}"
echo -e "${BLUE}========================================${NC}"
echo ""

# Load .env file if exists (for local development)
if [ -f "$PLUGIN_DIR/.env" ]; then
    echo -e "${YELLOW}Loading credentials from .env file${NC}"
    export $(grep -v '^#' "$PLUGIN_DIR/.env" | xargs)
fi

# Check for credentials
if [ -z "$SVN_USERNAME" ] || [ -z "$SVN_PASSWORD" ]; then
    echo -e "${RED}Error: SVN_USERNAME and SVN_PASSWORD must be set${NC}"
    echo ""
    echo "Options:"
    echo "  1. Create a .env file with SVN_USERNAME and SVN_PASSWORD"
    echo "  2. Export environment variables before running this script"
    echo ""
    echo "Example .env file:"
    echo "  SVN_USERNAME=your_username"
    echo "  SVN_PASSWORD=your_svn_password"
    echo ""
    echo "Or run:"
    echo "  export SVN_USERNAME=your_username"
    echo "  export SVN_PASSWORD=your_svn_password"
    echo "  ./scripts/publish.sh"
    exit 1
fi

# Get version
VERSION=$(cat "$VERSION_FILE" | tr -d '[:space:]')
echo -e "${GREEN}Publishing version: $VERSION${NC}"
echo ""

# Check if wp-cli is available
if ! command -v wp &> /dev/null; then
    echo -e "${RED}Error: WP-CLI is required but not installed${NC}"
    echo "Install it from: https://wp-cli.org/"
    exit 1
fi

# Check if wp dist-archive is available
if ! wp package list 2>/dev/null | grep -q "dist-archive"; then
    echo -e "${YELLOW}Installing wp dist-archive command...${NC}"
    wp package install wp-cli/dist-archive-command
fi

# Create clean SVN directory
echo -e "${YELLOW}Step 1: Preparing SVN directory...${NC}"
rm -rf "$SVN_DIR"
mkdir -p "$SVN_DIR"

# Checkout SVN repository
echo -e "${YELLOW}Step 2: Checking out SVN repository...${NC}"
svn checkout "$SVN_REPO" "$SVN_DIR" --username "$SVN_USERNAME" --password "$SVN_PASSWORD" --non-interactive --quiet

# Create distribution archive
echo -e "${YELLOW}Step 3: Creating distribution archive...${NC}"
cd "$PLUGIN_DIR"
rm -f "$PLUGIN_DIR/$PLUGIN_SLUG.zip"
wp dist-archive . "$PLUGIN_DIR/$PLUGIN_SLUG.zip" --quiet

# Extract to trunk
echo -e "${YELLOW}Step 4: Updating trunk...${NC}"
rm -rf "$SVN_DIR/trunk/"*
unzip -q "$PLUGIN_DIR/$PLUGIN_SLUG.zip" -d "$SVN_DIR/trunk/"

# Move files from subdirectory if created
if [ -d "$SVN_DIR/trunk/$PLUGIN_SLUG" ]; then
    mv "$SVN_DIR/trunk/$PLUGIN_SLUG/"* "$SVN_DIR/trunk/"
    rmdir "$SVN_DIR/trunk/$PLUGIN_SLUG"
fi

# Copy assets if .wordpress-org directory exists
if [ -d "$PLUGIN_DIR/.wordpress-org" ]; then
    echo -e "${YELLOW}Step 5: Updating assets...${NC}"
    cp -r "$PLUGIN_DIR/.wordpress-org/"* "$SVN_DIR/assets/" 2>/dev/null || true
fi

# Add new files to SVN
cd "$SVN_DIR"
svn add --force trunk/* --auto-props --parents --depth infinity -q 2>/dev/null || true
svn add --force assets/* --auto-props --parents --depth infinity -q 2>/dev/null || true

# Remove deleted files from SVN
svn status | grep '^\!' | awk '{print $2}' | xargs -I {} svn rm {} 2>/dev/null || true

# Commit trunk
echo -e "${YELLOW}Step 6: Committing trunk...${NC}"
svn commit -m "Update to version $VERSION" --username "$SVN_USERNAME" --password "$SVN_PASSWORD" --non-interactive

# Create tag
echo -e "${YELLOW}Step 7: Creating tag $VERSION...${NC}"
if svn ls "$SVN_REPO/tags/$VERSION" --username "$SVN_USERNAME" --password "$SVN_PASSWORD" --non-interactive 2>/dev/null; then
    echo -e "${YELLOW}Tag $VERSION already exists, skipping...${NC}"
else
    svn copy trunk "tags/$VERSION"
    svn commit -m "Tagging version $VERSION" --username "$SVN_USERNAME" --password "$SVN_PASSWORD" --non-interactive
fi

# Cleanup
echo -e "${YELLOW}Step 8: Cleaning up...${NC}"
rm -rf "$SVN_DIR"
rm -f "$PLUGIN_DIR/$PLUGIN_SLUG.zip"

echo ""
echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}  ✓ Successfully published v$VERSION${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""
echo "Plugin URL: https://wordpress.org/plugins/$PLUGIN_SLUG/"
echo ""
echo "Note: It may take a few hours for changes to appear in search results."
