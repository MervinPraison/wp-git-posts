#!/bin/bash
# DEPRECATED: Use ./scripts/publish.sh instead for full automation
# This script is kept for backward compatibility

echo "================================================"
echo "  NOTICE: This script is deprecated"
echo "================================================"
echo ""
echo "Use the new automated publishing system instead:"
echo ""
echo "  To bump version:  ./scripts/bump-version.sh patch"
echo "  To publish:       ./scripts/publish.sh"
echo ""
echo "Or push a git tag to trigger GitHub/GitLab CI/CD:"
echo "  git tag -a v1.0.7 -m 'Version 1.0.7'"
echo "  git push origin main --tags"
echo ""
echo "================================================"
echo ""

# Still create the zip for manual submission if needed
cd "$(dirname "$0")"
wp dist-archive . ../praison-file-content-git.zip --force

echo ""
echo "ZIP created at ../praison-file-content-git.zip"
