# Grounding — IntraVox home/welcome screen layout & sizing

Brief class: Tier B (symptom only — "improve layout/sizing of welcome screen"; no root cause, files, or fix named).
Grounded via 2 sequential codebase-analyzer dispatches. Repo: `/opt/aps-conecta-org/IntraVox` (Nextcloud app, Vue 3 + PHP).

## Scope correction (highest-value finding)

The brief's "welcome screen" is **not** `src/components/WelcomeScreen.vue`. That component is the
first-install empty-state card (shown only when `pages.length === 0`). The real intranet home the
brief describes (quick links, columns, news, right rail) is **`PageViewer.vue` rendering
`demo-data/<lang>/home.json`** through the `Widget.vue` dispatcher. A redesign plan must target the
home-page path; `WelcomeScreen.vue` is optional polish (it is the literal "welcome" screen).

## Module shape — home page path

- Mount: `src/main.js:49,66` → `App.vue` → `<main class="intravox-content">` (`src/App.vue:205`) →
  `PageViewer` at `src/App.vue:252-257` (normal path) / `<WelcomeScreen>` at `src/App.vue:176`.
- Home detection: `isCurrentPageHome` (`src/App.vue:782-800`).
- Layout scaffold: header row `src/components/PageViewer.vue:3-19`; left column `:22-35`;
  rows grid `:60-69`; right column `:93-106`. Data model: `layout.rows[]` of 1–3 columns
  (+ per-row `backgroundColor`, `collapsible`, `sectionTitle`), `layout.sideColumns.left/right`
  (right rail enabled in demo) — `demo-data/en/home.json` (right rail `:317-371`).
- Widget dispatch: `v-else-if` chain in `src/components/Widget.vue` — text `:7`, heading `:22`,
  image `:39`, links `:75`, divider `:84`, news `:89`, people `:99`, calendar `:108`, feed `:117`,
  photo-story `:126`, file-story `:134`, video `:144`; async-registered `:207-214`.
- Quick links: `src/components/LinksWidget.vue` — grid via `getGridStyle()` `:318-324`,
  `.link-item` variants, tile mode `:229`; internal nav `handleLinkClick` `:357-365`.
- Widths/spacing backbone lives in scoped styles: `.intravox-content` max-width
  `min(1600px, 95vw)` (`src/App.vue:2529-2535`); `.page-viewer-container` flex
  (`PageViewer.vue:411-424`); `.side-column` 250px / 200–300px rail (`:438-453`);
  `.page-grid` 12px gap (`:536-545`); `.page-column` (`:548-556`).
- Responsiveness: `@media (max-width: 768px)` stacking `PageViewer.vue:564-628`
  (side-rail `order:1`, grid `1fr !important`), `LinksWidget.vue:433-455`, `App.vue:2642-2668`;
  1200px `.page-grid` breakpoint `css/main.css:87-92`.
- Accessibility already present: skip-link `css/main.css:4-20`, `.visually-hidden` `:22-33`,
  global `:focus-visible` `:75-78`, aria-labels `App.vue:234-250`, heading anchors `Widget.vue:27-36`.

## Integration points (what a change must wire into)

- Demo seeding: `DemoDataService::importBundledDemoData()` `lib/Service/DemoDataService.php:558`
  (recursive `:638`, marker `:610`). Import paths: `occ intravox:setup` / `intravox:import-demo`
  (`lib/Command/SetupCommand.php:64-72`, registered `appinfo/info.xml:171`) or admin API
  `POST /api/demo-data/import` (`appinfo/routes.php:80`). **Editing `home.json` requires
  re-import** (overwrite deletes the language folder first, `DemoDataService.php:599-602`);
  install/migration does NOT auto-import (`lib/Migration/SetupDemoData.php:49-54`).
- Write-path validation: `PageShapeSanitizer` whitelist `lib/Service/Sanitize/PageShapeSanitizer.php:30-31`
  (`ALLOWED_WIDGET_TYPES`, `MAX_COLUMNS=5`), enforced on read `lib/Service/Read/PageReadService.php:48`
  and write `lib/Service/Write/PageWriteService.php:49`. Unknown keys are dropped silently on save.
- CSS loading: `css/main.css` loaded by `templates/main.php:4` + `Util::addStyle`
  (`lib/Controller/RendersAppShell.php:35`). **`css/confluence-panels.css` has no loader site** —
  referenced only by docs; don't assume it applies.
- Link/widget editor pairing: `LinksEditor.vue` mounted from `PageEditor.vue:416-418` (`type==='links'`,
  excluded from generic WidgetEditor `:408`).

## Conventions the implement lane must match

- **Icons**: `vue-material-design-icons` SFCs (`package.json:118`), rendered via
  `<component :is="getIconComponent(...)">` (`LinksWidget.vue:15-19`); ~66-icon map `:229-296`;
  mirrored map in `LinksEditor.vue:249+`. Adding an icon = import + map entry in **both** files.
  `@mdi/svg` (`package.json:74`) available for raw SVG paths. `src/components/icons/` holds only
  `MetaVoxIcon.vue` (app logo) — not an icon system.
- **Colors**: Nextcloud CSS custom properties only (`var(--color-primary-element)`,
  `--color-main-background/text`, `--color-background-hover`,
  `--border-radius-container-large`). Bare hex is not the norm — home-path files are clean
  (PageViewer 0 hex / 8 vars; LinksWidget 0/22; NewsWidget 0/20; WelcomeScreen 0/11);
  hex only as `var(--color-x, #hex)` fallback (e.g. `App.vue:2395-2493`; one bare `#fff`
  `Widget.vue:1137`).
- **Typography**: inherited from server — no `font-family` in home-path components; only
  `var(--font-face)`/`var(--font-monospace)` (e.g. `Widget.vue:1211`).
- **New widget type registration sites** (all required): render dispatch `Widget.vue:75-79` +
  async import `:229`; picker `WidgetPicker.vue:88`; editability `PageEditor.vue:695`;
  open-on-add `PageEditor.vue:1047,1065,1093`; default factory `PageEditor.vue:1123`;
  PHP whitelist `PageShapeSanitizer.php:30`; search `lib/Service/Search/PageSearchHelper.php:56`;
  maintenance `lib/Service/Maintenance/PageMaintenanceService.php:252`.
- **Build/checks**: `npm run build` (webpack → `js/intravox-*.js`, entries `webpack.config.js:27-30`);
  `prebuild` runs `eslint src` + 13 `scripts/check-*.js` gates (`package.json:13,31-37`);
  eslint flat config `eslint.config.js:36`. PHP: phpstan (`phpstan.neon` + baseline), phpunit.
- **Tests**: PHP unit only, no JS test runner. Home-relevant:
  `tests/Unit/Service/PageWriteServiceTest.php` (sanitizer whitelist / write path),
  `PageWalkerSkipTest.php`, `PageServiceMediaLanguageTest.php`,
  `Maintenance/PageMaintenanceServiceTest.php` (per-language home maintenance),
  `PageTreeFreshBuildRefreshGateTest.php`, `PageNewsTest.php`, `PageReordererTest.php`,
  `tests/Integration/AclCacheDiscriminatorTest.php`.
- **Content languages**: demo data exists for `de/en/fr/nl` only — no `es/`; the Spanish-language
  brief's locale has no home.json (worth flagging if Spanish content is expected).

## Fit against the brief's asks

- Above-the-fold quick links / 3 columns + right rail → already representable in
  `home.json` (`layout.rows`, `sideColumns.right`); the work is content layout + CSS sizing
  (`PageViewer.vue` scoped styles), not new machinery.
- Uniform formal SVG icons → MDI system already uniform; "adding small formal svg icons" means
  choosing/adding MDI names in the two maps, not a new icon pipeline.
- Brand palette/typography → already enforced by NC vars; keep it that way.
- "Fixing folders/setup/links" → after editing `home.json`, re-run the demo import (overwrite mode);
  page-shaped JSON must pass `PageShapeSanitizer` (≤5 columns, whitelisted widget types).
- Accessibility → extend the existing skip-link/aria/focus patterns; add headings at `Heading 2`
  level via the existing `heading` widget type (`Widget.vue:22`).
