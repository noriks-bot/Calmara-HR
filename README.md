# Calmara-HR

WordPress theme + site code for **Calmara** brand, market **HR**.

- **Subdomain:** `hr_calmara.noriks.com` → `18.197.40.171` (brand-machine)
- **Theme:** `calmara/` (forked from Noriks WP theme, Storefront-based)
- **Source:** copied from `wp-noriks-hr{-pravi?}` on 2026-06-09
- **Excluded from copy:** `lander2/`, `videos/`, `auto_reviews/`, `img/`, `images/` (brand-specific heavy assets)

## Deploy
Production server: `brand-machine` (`18.197.40.171`, ubuntu user).
Pull via git on the server, then symlink/copy into `wp-content/themes/calmara/`.

## Status
- [x] Theme code copied from Noriks
- [x] Theme renamed to "Calmara" (style.css header)
- [ ] WP core install on brand-machine
- [ ] DB + uploads import
- [ ] Brand assets (images, logos, hero videos) — to be generated via Higgsfield
- [ ] Localization for market-specific copy
