# Collaborative Editing

Enable collaborative content editing with GitHub PR workflows.

## Overview

PraisonPressGit enables a Wikipedia-style collaborative editing experience where users can suggest edits via pull requests.

## Frontend Features

### Report Error Button

Allow logged-in users to suggest edits on any post:

1. User clicks "Report Error" button
2. Beautiful WordPress-style modal opens
3. User makes suggested changes
4. PR is created automatically

### My Submissions Page

Users can view their submitted edits:

- Pagination (5 submissions per page)
- Status badges (Open/Merged/Closed)
- View Diff links to GitHub
- View Page links to WordPress

## Admin Features

### View All Submissions

Admins can view ALL users' pull requests:

- Toggle between "My Submissions" and "All Users"
- Filter buttons for easy navigation
- Full PR review interface with diffs

### One-Click Approve

Merge pull requests directly from WordPress:

1. Click "Approve & Merge" button
2. PR is merged on GitHub
3. Content auto-syncs to WordPress

## Workflow

```
┌─────────────────┐
│  User Edit      │  Clicks "Report Error"
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  Create PR      │  Automatic PR on GitHub
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  Admin Review   │  Reviews in WordPress
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  Merge          │  One-click approve
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  Auto-Sync      │  Content updated
└─────────────────┘
```

## GitHub Integration

Connect your repository:

1. Go to **PraisonPress → Settings**
2. Click "Connect to GitHub"
3. Authorize OAuth
4. Select repository
