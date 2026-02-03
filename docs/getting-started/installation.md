# Installation

Get PraisonPressGit running in minutes.

```mermaid
graph TB
    A[📥 Download] --> B[📤 Upload]
    B --> C[✅ Activate]
    C --> D[📁 Create Content]
    D --> E[🎉 Done!]
    
    style A fill:#6366F1,stroke:#7C90A0,color:#fff
    style B fill:#F59E0B,stroke:#7C90A0,color:#fff
    style C fill:#189AB4,stroke:#7C90A0,color:#fff
    style D fill:#14B8A6,stroke:#7C90A0,color:#fff
    style E fill:#10B981,stroke:#7C90A0,color:#fff
```

## Option 1: WordPress Admin

1. Go to **Plugins → Add New**
2. Search for "PraisonPressGit"
3. Click **Install Now**
4. Click **Activate**

## Option 2: Manual Upload

1. Download the plugin ZIP
2. Go to **Plugins → Add New → Upload Plugin**
3. Choose the ZIP file
4. Click **Install Now**
5. Click **Activate**

## What Happens on Activation

The plugin automatically:

- ✅ Creates content directories
- ✅ Sets up submissions page
- ✅ Creates database table for tracking

## Content Directory

Default: `wp-content/uploads/praison-content/`

```
praison-content/
├── posts/
├── pages/
├── lyrics/
├── recipes/
└── config/
```

## Next Step

→ [Configure the plugin](configuration.md)
