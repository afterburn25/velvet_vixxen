# Architecture and Product Decisions

## Locked decisions

### Visual direction
The approved direction is the full-width neon-pink, magenta, black, and white Velvet Vixxen design with rendered bedroom/studio backgrounds and open composition space for transparent Nikki cutouts.

### Visual editing
Hero and visual-footer scenes use a layered, non-destructive composer with separate desktop, tablet, and mobile arrangements. Canva is an optional template workflow; live navigation, text, forms, paywalls, and controls remain HTML.

### Installer
Installation is a single responsive browser page. It requires no installation key, accepts owner passwords of 12 or more characters, creates and migrates database tables, performs real filesystem probes, attempts only safe permissions, never relies on chmod 0777, creates an install lock, and removes or securely disables `/install` after success.

### Project organization
Application files remain in organized folders including `/admin`, `/app`, `/config`, `/database`, `/docs`, `/images`, `/includes`, `/install`, `/public`, `/storage`, `/templates`, `/tests`, and `/uploads`. The root contains only necessary entry and project files.

### Media Center
The platform will provide persistent music playback, albums and playlists, photo galleries with a full-screen viewer, videos with trailers and protected playback, public previews, access tiers, scheduling, and media administration.

### Commerce
Photo galleries and videos can be public, subscription-included, PPV, rented, bundled, discounted for subscribers, gifted, complimentary, scheduled, or unlisted. Payment providers are adapters and must support the business category.

### Add-ons
New functions install through validated add-on ZIP packages without editing core files. Add-ons use versioned extension points, declared permissions, dependencies, migrations, licensing, safe updates, backup, rollback, and recovery.

### Repository and testing
`afterburn25/velvet_vixxen` is the communication and source-of-truth repository. `main` is last-known-good; active work uses feature branches. Tests, roadmap, status, decisions, and handoffs must remain current. Automated tests are required for installer, migrations, security boundaries, media access, commerce entitlements, and add-on lifecycle behavior.

### Public repository safety
Production credentials, identity documents, private customer information, payment secrets, private messages, runtime logs, and private or paid media must never be committed.
