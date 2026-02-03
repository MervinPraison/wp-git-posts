# Troubleshooting

Common issues and fixes.

```mermaid
graph TB
    P[🔴 Problem]
    P --> C1{Files not loading?}
    P --> C2{Cache issues?}
    P --> C3{Git sync failing?}
    
    C1 --> S1[✅ Check permissions]
    C2 --> S2[✅ Clear cache]
    C3 --> S3[✅ Verify credentials]
    
    style P fill:#8B0000,stroke:#7C90A0,color:#fff
    style C1 fill:#F59E0B,stroke:#7C90A0,color:#fff
    style C2 fill:#F59E0B,stroke:#7C90A0,color:#fff
    style C3 fill:#F59E0B,stroke:#7C90A0,color:#fff
    style S1 fill:#10B981,stroke:#7C90A0,color:#fff
    style S2 fill:#10B981,stroke:#7C90A0,color:#fff
    style S3 fill:#10B981,stroke:#7C90A0,color:#fff
```

## Files Not Loading

**Cause:** Directory permissions or wrong path

**Fix:**
1. Check `PRAISON_CONTENT_DIR` is correct
2. Ensure directory is readable by PHP
3. Verify file extensions (`.md`, `.json`, `.yaml`)

## Cache Issues

**Cause:** Stale cache after file changes

**Fix:**
```php
praison_clear_cache();
```

Or use WP-CLI:
```bash
wp cache flush
```

## Git Sync Not Working

**Cause:** Credentials or network issue

**Fix:**
1. Verify `GITHUB_TOKEN` is set
2. Check repository permissions
3. Test network connectivity

## Frontmatter Not Parsed

**Cause:** Invalid YAML syntax

**Fix:**
1. Validate YAML at [yamllint.com](https://www.yamllint.com/)
2. Check for proper `---` delimiters
3. Ensure consistent indentation

## Still Stuck?

[Open an issue on GitHub](https://github.com/MervinPraison/PraisonAI-Git-Posts/issues)
