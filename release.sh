#!/bin/bash
#
# Release script for PraisonPressGit Plugin
# Auto-detects version from praisonpressgit.php, syncs to readme.txt, commits, tags, and pushes
#
# Usage:
#   ./release.sh           # Release current version
#   ./release.sh --dry-run # Preview without making changes
#

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$SCRIPT_DIR"

DRY_RUN=false
if [[ "$1" == "--dry-run" ]]; then
    DRY_RUN=true
    echo "🔍 DRY RUN MODE - No changes will be made"
fi

# Auto-detect version from praisonpressgit.php
VERSION=$(grep -m1 "Version:" praisonpressgit.php | sed 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')

if [[ -z "$VERSION" ]]; then
    echo "❌ Could not detect version from praisonpressgit.php"
    exit 1
fi

TAG="v$VERSION"

echo ""
echo "🚀 Releasing PraisonPressGit"
echo "   Version: $VERSION"
echo "   Tag: $TAG"
echo ""

# Check if tag already exists
if git rev-parse "$TAG" >/dev/null 2>&1; then
    echo "⚠️  Tag $TAG already exists!"
    read -p "Delete and recreate tag? (y/n) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo "❌ Aborted"
        exit 1
    fi
    if [[ "$DRY_RUN" == false ]]; then
        git tag -d "$TAG" 2>/dev/null || true
        git push origin ":refs/tags/$TAG" 2>/dev/null || true
    fi
fi

# Sync version to readme.txt and version.txt
echo "📝 Syncing version..."
if [[ "$DRY_RUN" == false ]]; then
    sed -i '' "s/^Stable tag:.*/Stable tag: $VERSION/" readme.txt
    echo "$VERSION" > version.txt
fi

# Check for changes
if git diff --quiet && git diff --cached --quiet; then
    echo "✅ No file changes to commit"
else
    echo "📦 Committing changes..."
    if [[ "$DRY_RUN" == false ]]; then
        git add -A
        git commit -m "Release $TAG"
    fi
fi

# Create tag
echo "🏷️  Creating tag $TAG..."
if [[ "$DRY_RUN" == false ]]; then
    git tag -a "$TAG" -m "Release $TAG"
fi

# Push to GitHub
echo "⬆️  Pushing to GitHub..."
if [[ "$DRY_RUN" == false ]]; then
    git push origin main
    git push origin "$TAG"
fi

# Create GitHub release
echo "🎉 Creating GitHub release..."
if [[ "$DRY_RUN" == false ]]; then
    /opt/homebrew/bin/gh release create "$TAG" \
        --title "PraisonPressGit $TAG" \
        --notes "Release $TAG" \
        --latest
fi

echo ""
echo "✅ Released PraisonPressGit $TAG"
echo ""
echo "The GitHub Action will automatically deploy to WordPress.org SVN."
