# Changelog

## 0.7.4 — Visual Composer Direct Manipulation & Asset Repair

### Visual Composer

- Added smooth direct photo dragging on the canvas.
- Added four corner resize handles with a supported range of `0.5%` through `400%`.
- Added Tiny, Small, Medium, Large, and Huge size presets.
- Converted the live text/button safe area into a movable and resizable canvas object.
- Added separate desktop, tablet, and mobile content-box arrangements.
- Added direct background panning.
- Kept layer handles reachable by constraining normal movement within the canvas.
- Integrated movement and resizing with undo/redo and draft/publish state.

### Asset repair

- Corrected bundled-root versus uploaded-image path resolution.
- Corrected `/uploads/images/...`, model, media, thumbnail, export, audio, video, and caption URL handling.
- Limited the Visual Composer Media Tray to image-compatible assets.
- Added safe missing-image placeholders and status feedback.
- Made the public Hero and Footer honor the saved content-box geometry.

### Verification

- Passed 113 PHP runtime syntax checks and 6 public JavaScript syntax checks.
- Passed extracted-package Chromium drag, resize, content-box, background-pan, and asset-loading tests.
- Rendered 9 Media Tray cards with 0 broken images.
- Verified 0.5% and 300% image sizes.
- Runtime-only ZIP: 186 files.
- SHA-256: `860447db31eba4dfb6ee7acd686021cb63eac17d3fd161b1881160e4676c77e3`.

## 0.7.3 — Installer Verification & Simplified Setup Hotfix

- Added cache-safe final configuration verification after atomic activation.
- Invalidated PHP stat and OPcache entries.
- Added component-specific diagnostics.
- Simplified the installer to Database, Owner and Website, and Install.

## 0.7.2 — Installer Email Validation Hotfix

- Normalized pasted/autofilled email addresses before validation.
- Stopped validating hidden mail fields when outgoing email is configured later.

## 0.7.1 — Shared-Hosting Ownership Repair

- Added ownership-safe replacement of panel-owned runtime folders without `0777`.
- Added required upload/add-on folders and safer `/install` retirement.

## 0.7.0 — Messaging, Booking & Member Requests

- Added private messages, protected attachments, content requests, booking inquiries, notifications, saved replies, blocks, and owner queues.
- Added the eleventh migration for 64 declared tables.
- Switched the public installation package to runtime-only contents.

## 0.6.1 — Commercial Add-on Licensing & Remote Updates

- Added commercial license products, activation, site limits, encrypted local keys, signed update feeds, protected downloads, review, and rollback.

## 0.6.0 — Add-on Framework

- Added validated add-on ZIP inspection, manifests, permissions, dependencies, checksums/signatures, migrations, backups, rollback, Safe Mode, routes, widgets, events, services, settings, and tasks.

## 0.5.0 — Subscriptions & PPV Commerce

- Added Store, cart, sandbox checkout, subscriptions, PPV galleries/videos, rentals, bundles, coupons, receipts, billing, refunds, and signed webhooks.

## 0.4.0 — Members & Entitlements

- Added member authentication, dashboard, plans, subscriptions, My Library, device sessions, direct entitlements, and unified access resolution.

## 0.3.0 — Media Center

- Added persistent music, albums/playlists, galleries/lightbox, video library/player, protected media, scheduling, and media administration.

## 0.2.2 — One-Page Browser Installer

- Replaced the wizard with one responsive installer page while preserving no install key, 12-character minimum, safe permissions, migrations, rollback, lock, and cleanup.

## 0.2.0–0.2.1 — Design, Composer, Canva & Installer Foundation

- Added the approved homepage, initial Hero/Footer composer, Canva templates/import, owner login, age gate, browser installer, organized folders, and migration foundation.
