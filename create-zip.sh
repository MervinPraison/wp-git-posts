#!/bin/bash
# WordPress.org ZIP creator using WP-CLI dist-archive (recommended)
# Requires: wp package install wp-cli/dist-archive-command

cd "$(dirname "$0")"

# Use wp dist-archive with .distignore file
wp dist-archive . ../praison-file-content-git.zip --force

echo ""
echo "Ready for WordPress.org submission"
