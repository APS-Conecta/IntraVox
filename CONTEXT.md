# IntraVox

The context of the suite's intranet: one app that turns Nextcloud Files into a publishing
platform — pages as folders, widgets as the building blocks, a wall that cannot move, one
content language per install. The terms are English because the code is; each entry cites the
code that makes it true.

## The app

**Fork** — this repository is APS Conecta's fork of VoxCloud's IntraVox; `appinfo/info.xml` names
upstream in `<repository>` and `<bugs>` and this fork in `<documentation>`. The fork's own deltas
are recorded in [docs/adr/0001](docs/adr/0001-fork-with-local-features.md).
_Avoid_: "the upstream app" for what ships here — what ships is the fork.

**Page** — a folder `<pageId>/` holding `<pageId>.json` (widgets, layout) and `_media/`, created
by `lib/Service/Write/PageWriteService.php` (`createPageAtPath()`). Everything Nextcloud gives a
folder — sharing, ACL, versions, trash — the page inherits; IntraVox reimplements none of it.

**Widget** — the block a page is built from. The set: text, heading, image, links, divider, video,
news, people, calendar, feed, photo story, file story (`ALLOWED_WIDGET_TYPES` in
`lib/Service/Sanitize/PageShapeSanitizer.php`; rendered by `src/components/Widget.vue`).

**Wall (protected page)** — a page whose JSON carries `"protected": true`: editable, never
deletable or movable — the server refuses both (`PAGE_PROTECTED`,
`lib/Service/Write/PageProtectionService.php`), a client can never raise the flag, and
`occ intravox:protect` (`lib/Command/ProtectPageCommand.php`) is the deliberate two-step that
lowers one. Walls come from the managed seed for the sections a site declares as wall.
_Avoid_: "locked" — a lock is the short-lived edit guard (`lib/Service/PageLockService.php`).

**Storage root** — the group folder every page lives under, read from one app value,
`groupfolder_name`, default «IntraVox» (`lib/Service/Folder/MountName.php:22`). An install names
it what its staff recognise — «Intranet» — per
[gestion ADR-0020](https://github.com/APS-Conecta/gestion/blob/main/docs/adr/0020-the-storage-root-is-named-for-staff.md).

**Managed seed** — the site's declared page tree, imported by `occ intravox:import` through
`lib/Service/Import/ManagedTreeImporter.php`; with `--skip-existing` it never overwrites a page,
file or image that a staff edit touched.

## Content and metadata

**Language reality** — the engine's enabled-languages default is `['es', 'en']` with `es`
primary (`lib/Service/LanguageService.php:44`); the instance's content language is Spanish, per
[gestion ADR-0018](https://github.com/APS-Conecta/gestion/blob/main/docs/adr/0018-language-reality-is-the-engines-es-only-default.md),
and the admin UI no longer writes the key. Documentation is English, written once for this fork.

**MetaVox** — upstream's companion app that adds structured metadata to group folders; detected
at runtime (`lib/Service/MetaVoxImportService.php`), and every feature that uses it — import,
photo-story filtering — degrades gracefully when it is absent.
