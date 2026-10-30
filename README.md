# Banner Studio

A lightweight WordPress plugin for your own image banners. No ad platform, tracking pixel, frontend framework, or build step is required.

## Install

Requires WordPress 6.5+ and PHP 8.1+. PHP DOM is required for paragraph/heading insertion; other placements work without it. Use a current, supported WordPress release in production.

1. Build the installable ZIP with `python3 scripts/package.py`.
2. In WordPress, open **Plugins → Add Plugin → Upload Plugin**, select `dist/banner-studio-1.1.0.zip`, and activate **Banner Studio**.
3. Open **Banner Studio → Banners → Create banner**.
4. Choose your artwork, add a link and alternative text, select placement and devices, preview, and save.
5. Clear your page cache and review the published page on desktop and mobile.

You can also copy the plugin's production files to `wp-content/plugins/banner-studio` and activate it. Node and Docker are development tools only; they are not needed on your website.

## Included in version 1.1

- Dashboard, searchable/filterable banner library, placement guide, and settings.
- Up to four creatives per campaign, each with its own link and desktop/mobile artwork.
- Optional random selection on each post/page visit, including cached pages.
- WordPress Media Library integration; separate desktop/mobile images with single-image fallback.
- Before/after content and after a selected top-level paragraph or H2–H6 heading.
- Shortcode: `[banner_studio id="123"]` and the **Banner Studio** Gutenberg block.
- Responsive maximum widths, full-width stretch, alignment, margins, padding, and advertisement labels.
- Desktop/mobile visibility, public post type targeting, category/tag IDs, and post/page exclusions.
- Enable/pause, site-timezone schedules, and active/scheduled/expired status badges.
- Minimum article word count, automatic in-content spacing, duplicate prevention, and page-wide limits.
- Optional mobile bottom-sticky banner, close button, dismissal memory, safe-area support, and configurable bottom offset.
- Sponsored link attributes and safe new-tab links.
- No analytics or third-party advertising requests. Banner links only navigate when clicked. Media hosted on your configured media/CDN origin is loaded as normal.
- Opt-in data removal on uninstall; uploaded images are retained.

## Artwork recommendations

| Use | Suggested dimensions |
| --- | --- |
| Wide desktop banner | 970 × 250 or 970 × 90 |
| Desktop content banner | 728 × 90 |
| Mobile content banner | 320 × 100 or 375 × 100 |
| Mobile sticky | 320 × 50 or 320 × 100 |

These are practical design suggestions, not Google requirements. JPEG, PNG, WebP, and AVIF attachments are accepted. AVIF upload/thumbnail support depends on the host's WordPress image libraries. SVG, animated GIF, HTML, scripts, and arbitrary ad code are deliberately excluded. Aim for under 200 KB per image; this is a recommendation, not a hard upload limit.

Sizes are responsive **maximum widths**, not forced width/height pairs. Aspect ratios come from the image, so images are never stretched out of proportion. To change the banner's shape, upload artwork with the desired proportions. Stretch mode fills the available content width and overrides the maximum-width settings. Banners cannot escape the theme's content container.

## Display and placement rules

The breakpoint is 767px: mobile is 767px and below, desktop begins at 768px. A native `<picture>` selects the matching image, avoiding two normal image downloads per placement. Visibility is viewport-based, so page caches do not need device-specific variants.

Automatic placement runs only in the main article on singular pages. Search results, archives, feeds, previews, password-protected pages, and REST responses are excluded. Auto-insertion only uses top-level text paragraphs and H2–H6 headings; image paragraphs and nested layouts are skipped. Use manual placement for page builders, columns, groups, code, and tables. Existing content and scripts are not executed or modified by the plugin's admin UI.

Manual placement still obeys banner status, schedule, content targeting, exclusions, device visibility, deduplication, and the page cap. One banner is displayed at most once per server request; use separate banner records if you need identical artwork in separate places. Manual banners normally render before automatic placements because WordPress expands blocks/shortcodes earlier. Before/after placements are considered before in-content placements; the footer sticky slot uses any remaining page capacity. Lower priority numbers win within each processing stage; equal priorities use the older banner first.

The default page cap is three banner records, including manual and sticky banners. The cap counts rendered records even when a record is hidden on the current viewport. Minimum spacing applies only between automatically inserted paragraph/heading banners. Boundary/manual placement spacing needs editorial review. Minimum word count applies only to automatic content placements. Word counting uses whitespace, so adjust or disable it for languages without whitespace-separated words.

## Four creatives and random selection

Edit a banner and use **Add creative** to add up to four creatives. Each creative contains one desktop image, one mobile image, one destination URL, and alternative text. This supports up to four desktop images and four mobile images per campaign. Each creative's link is shared by its desktop/mobile variants. If one device image is missing, the other is used. Shared placement, schedule, labels, dimensions, and new-tab/sponsored settings apply to the campaign.

Enable **Randomly select a creative on each post/page visit** to display one uniformly selected creative from the pool. Select **Posts** and **Pages** under content types and leave category/tag filters and exclusions empty to include every eligible post/page. Automatic placement still requires a suitable paragraph/heading and the configured minimum article length; use before/after content placement and a zero minimum word count if you need coverage of short articles too.

Selection happens once per banner per page load. It does not animate or periodically swap images, does not remember the last selection, and can repeat the same creative on consecutive visits. Resizing changes only the selected creative's device artwork, keeping its link matched. With random selection off, only the first saved creative is used. With one creative, the campaign behaves as a single banner regardless of the random setting.

Random selection runs in the browser, so full-page caching does not freeze a server-selected banner for every visitor. All alternatives are embedded in inert HTML templates; only the selected artwork downloads. No tracking request, API call, or visitor identifier is added. Without JavaScript, the first creative is shown as a linked fallback. Space is reserved for the tallest creative on each device; use consistent aspect ratios to avoid excess empty space around shorter artwork. Excluded or expired campaigns do not bypass the normal display rules.

Existing version 1.0 banner records remain readable without migration. Their images and links appear as Creative 1 when editing. Saving upgrades only that record to the multi-creative format. Clear page/CDN caches after upgrading so version 1.1 assets and markup reach visitors together.

## Mobile sticky behavior

Only one eligible sticky record is selected per page. It starts hidden and appears after 160px of scrolling on pages at least two viewport heights long. Artwork is capped at the smaller of 100px or 15% of the viewport height. It includes an accessible close control, reserves space at the bottom of the page, hides while a text/form control is focused, and respects the device bottom safe area. JavaScript is required for sticky banners; normal banners work without it.

Dismissal is saved in `localStorage` for the configured number of hours. Zero uses tab-session storage. If storage is blocked, dismissal works for the current page. No dismissal identifier is sent to a server. Changing artwork on the same banner record does not reset dismissal; create a new record for a new campaign if desired.

Use **Settings → Bottom offset** for a fixed bottom navigation. Cookie notices and arbitrary theme overlays require explicit integration; they cannot all be detected safely:

```js
// While your cookie dialog / modal is open:
document.documentElement.dataset.bapOverlay = 'true';
// When it closes:
delete document.documentElement.dataset.bapOverlay;
```

Test any fixed UI and virtual keyboard on your actual mobile theme. This plugin does not replace a consent management platform. If several sticky banners exist, dismissal hides the selected one; it does not rotate another banner into view.

## Scheduling and caching

Schedules are entered in the WordPress site's timezone and saved as UTC timestamps. Start is inclusive and end is exclusive. Empty dates mean no boundary. No cron service is necessary.

Server rendering determines whether a scheduled record is present. **Clear your full-page/CDN cache after banner changes and at scheduled start times**, or configure cache expiry/purge automation accordingly. Banner Studio does not integrate with every cache vendor. A cached page generated before a start time cannot reveal artwork that is absent from that HTML.

The frontend additionally checks end timestamps at load, on tab visibility changes, and every 30 seconds. This limits stale expired displays in cached pages with JavaScript enabled; it is not a replacement for cache purging. A browser's clock can be incorrect. Disabling or deleting banners also requires purging cached HTML. Exclude logged-in users from full-page caching when using the administrator visibility setting.

## Google Search guidance

Official sources to review:

- [Avoid intrusive interstitials and dialogs](https://developers.google.com/search/docs/appearance/avoid-intrusive-interstitials)
- [Qualify outbound links](https://developers.google.com/search/docs/crawling-indexing/qualify-outbound-links)
- [Core Web Vitals and Google Search](https://developers.google.com/search/docs/appearance/core-web-vitals)

The implementation uses sponsored-link attributes, explicit image dimensions/aspect ratios, clear labels, conservative sticky dimensions, and an accessible dismissal control. These are design choices aimed at usable pages and layout stability. Overall page quality and Core Web Vitals still depend on your theme, artwork, cache, and other plugins. No search-ranking or Google-approval guarantee is made.

**Documentation access limitation:** requests to all three official pages returned HTTP 403 in this development environment on October 30, 2026. Their current wording could not be independently verified here. Review the linked current documents before production rollout. AdSense policies do not apply to this plugin's platform-free integration scope.

## Admin design and architecture

The interface uses a teal/neutral palette, spacious cards, restrained type, status badges, responsive forms, empty states, inline browser validation, and a side-by-side creative preview. Advanced settings are collapsed. Admin assets load only on plugin screens; frontend assets load only on eligible singular pages when banners exist.

| Screen | Main controls |
| --- | --- |
| Dashboard | Banner counts, publishing workflow, create shortcut |
| Banners | Image thumbnail, status filter, search, schedule, edit/delete, shortcode |
| Editor | Creative → Placement & audience → Appearance → Publishing, device preview |
| Placements | Six placement types, safe-placement rules, overlay integration |
| Settings | Page caps, spacing, article length, sticky offset, dismissal, data removal |

Banner campaigns use a nonpublic `bap_banner` post type and one private metadata array per record, containing up to four creative entries. Global controls use the `bap_settings` option. Management requires `manage_options`; saves and deletes validate WordPress nonces. Links permit only HTTP(S), media must have an allowed raster MIME type, numeric settings are bounded, and output is escaped. Gutenberg uses WordPress's bundled editor libraries; frontend and custom admin behavior use native JavaScript.

## Local development and tests

Use the existing checkout in this isolated task. Do not create an additional Git worktree unless explicitly requested.

```sh
docker compose -p banner-studio-dev up -d --wait
docker compose -p banner-studio-dev exec -T wordpress php /var/www/html/wp-content/plugins/banner-studio/tests/setup.php > /tmp/banner-fixture.json
docker compose -p banner-studio-dev exec -T wordpress php /var/www/html/wp-content/plugins/banner-studio/tests/integration.php
npm ci
CHROMIUM_PATH=/usr/bin/chromium npm run test:browser
npm run check:js
python3 scripts/package.py
```

The browser test uses system Chromium by default. Override `CHROMIUM_PATH` with your Chromium executable, or install Playwright's matching browser and supply its path. The test-only login is `banner-tester` / `local-ui-test-only`. The setup script creates a disposable WordPress install and sample images/articles; **never run it against a production site**. Tests require CLI execution and are omitted from the installable ZIP. The browser suites test the standard workflow and four-creative random selection (including replayed cached HTML). The baseline browser suite deletes existing banner records in the disposable test site before creating its own fixtures.

The local Compose site binds port 8080 to loopback only and uses explicit development-only DB credentials. Its named volumes retain data between restarts. `docker compose -p banner-studio-dev down` stops the services and retains data. Adding `--volumes` deletes this test site's database and uploads. Local bind-mount files must be readable by Apache; the checkout/directories need traverse permission and production files need read permission.

No localhost preview service is published by this project. Test evidence and screenshots are written to `/tmp/banner-studio-results` by default.

## Future phases

1. **Current release:** Core uploaded-image workflow, clean administration, four linked creatives per campaign, uniform random selection, responsive and sticky placements, schedules/targeting, integration and browser tests.
2. **Campaign tools:** Weighted rotation, banner duplication, reusable presets, import/export, accessible category/tag pickers, and cache-vendor integrations.
3. **Optional reporting:** Define a viewable impression (for example, 50% visible for one continuous second) separately from a rendered record. Count direct-banner link activations without redirect tracking. Document bot/ad-blocker limits, deduplication, caching, consent where applicable, retention, and data deletion before adding collection. Keep analytics opt-in and separate from the core display path.

Broader theme/page-builder compatibility, a full accessibility audit, multisite network-wide management, and production performance measurement require separate validation. This release supports per-site administration; it does not provide network-wide uninstall cleanup.
