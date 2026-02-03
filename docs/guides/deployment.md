# Deployment Guide

Deploy PraisonPressGit to any environment.

```mermaid
graph LR
    A[📁 Git] --> B[🚀 CI/CD]
    B --> C[☁️ Server]
    C --> D[🌐 WordPress]
    
    style A fill:#6366F1,stroke:#7C90A0,color:#fff
    style B fill:#F59E0B,stroke:#7C90A0,color:#fff
    style C fill:#14B8A6,stroke:#7C90A0,color:#fff
    style D fill:#10B981,stroke:#7C90A0,color:#fff
```

## Deployment Options

| Platform | Method |
|----------|--------|
| GitHub Actions | Automated on push |
| GitLab CI | `.gitlab-ci.yml` |
| Docker | Container deployment |
| Kubernetes | Helm chart |

## GitHub Actions

The plugin includes a workflow for automatic deployment:

```yaml
on:
  push:
    branches: [main]
```

## Environment Variables

Set these in your deployment:

| Variable | Purpose |
|----------|---------|
| `GITHUB_TOKEN` | API access |
| `GITHUB_REPO` | Content repository |
| `WP_HOME` | WordPress URL |

## Cloud Native

This plugin is designed for:

- ✅ Containerized environments
- ✅ Stateless deployments
- ✅ GitOps workflows
- ✅ Multi-environment (dev/staging/prod)

## Caching

In production, enable object caching:

```php
define('WP_CACHE', true);
```

Redis or Memcached recommended.
