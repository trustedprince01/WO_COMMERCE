# Pictufy Integration Plugin

## Overview
Pictufy Integration is a WordPress plugin that syncs curated content from the Pictufy API into WooCommerce. It renders artist, collection, and artwork listings with infinite scroll, responsive four-column layouts on desktop, and detailed views tailored for the WoodMart theme.

## Key Features
- **Auto-generated pages:** On activation the plugin ensures the `colecciones`, `artistas`, and `explorar` pages exist with the relevant shortcodes.
- **Responsive grids:** Collections and artists use a four-column desktop layout that adapts smoothly down to mobile.
- **Infinite scroll:** Collections, artists, and artworks load additional content via IntersectionObserver with a “Load more” fallback button.
- **Detail templates:** Custom artist and collection templates fetch and display artwork lists inline, with AJAX-backed pagination.
- **Expired artwork cleanup:** A weekly WP-Cron job checks the Pictufy `/expired/` endpoint and removes matching WooCommerce products, media attachments, and caches.

## Installation
1. Copy the plugin folder into `wp-content/plugins/`.
2. Activate **Pictufy Integration** from the Plugins screen.
3. (Optional) Visit Settings → Permalinks and click **Save** to ensure page rewrites are refreshed.

## Cleanup Automation
- **Automatic schedule:** When the plugin activates, `pictufy_schedule_expired_cleanup()` registers the `pictufy_expired_artworks_cleanup` event to run weekly.
- **Manual trigger:** Run `wp cron event run pictufy_expired_artworks_cleanup` with WP-CLI or use WP Crontrol (Tools → Cron Events → Run Now).
- **What it removes:** Only WooCommerce products with `_pictufy_artwork_id` returned by `/expired/`. The product is trashed, associated gallery/featured images are deleted, and the stored transient is cleared.
- **Logging hook:** Attach to `pictufy_artwork_expired_removed` for audit logs or notifications if the client requires traceability.

## Development Notes
- Primary logic lives in `pictufy-integration.php`.
- `class Pictufy_API` handles authenticated requests; adjust the base URL or key as needed.
- JavaScript helpers are output via `pictufy_render_collections_script()` and `pictufy_render_artworks_script()` for front-end interactions.

## Testing Checklist
- Activate the plugin and confirm the three main pages render expected layouts.
- Scroll to verify infinite loading on collections, artists, and artworks.
- Trigger the cleanup manually in a staging environment to ensure expired items move to Trash and attachments are removed.
- After one week, confirm the cron event ran (WP Crontrol “Last run” column) and that no obsolete products remain.

## Support
For bugs or enhancements, open an issue in this repository or contact the development team.
