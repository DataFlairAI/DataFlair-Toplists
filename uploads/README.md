# DataFlair Uploads Directory

This directory stores locally cached assets from the DataFlair API.

## Structure

- **logos/** - Brand logo images downloaded and cached from the API
  - Images are named using the brand slug (e.g., `cybersprint-io.png`)
  - Cached for 7 days before re-downloading
  - Supported formats: JPG, PNG, GIF, WebP, SVG

## Purpose

Storing logos locally provides:
- **Better performance** - No external requests needed
- **Reliability** - Works even if external CDN is down
- **Control** - Images under our domain

## Maintenance

Logo images are automatically:
- Downloaded during brand sync
- Cached for 7 days
- Re-downloaded if older than cache period
