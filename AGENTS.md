# AGENTS.md — IntraVox

The suite's intranet: a fork of VoxCloud's IntraVox (`nextcloud/IntraVox`) that APS Conecta ships
as its own app. Pages are folders and files in a group folder; communications teams publish, staff
read. What this fork changed and why is
[docs/adr/0001](docs/adr/0001-fork-with-local-features.md).

## How we work

- **Fork of our own app:** upstream is the engine; this repository is the suite's install of it.
  Pull upstream in. Keep local features few, and record every one in
  [docs/adr/0001](docs/adr/0001-fork-with-local-features.md) — a pull that touches a fork delta
  (language defaults, the storage root, walls, the managed seed) re-reads that ADR before it merges.
- **Language reality:** the engine's content languages are `['es', 'en']` with `es` primary
  ([gestion ADR-0018](https://github.com/APS-Conecta/gestion/blob/main/docs/adr/0018-language-reality-is-the-engines-es-only-default.md)).
  This repository's code, docs and commits are English.
- **Coordination:** Conventional Commits; `main` is the trunk. This GitHub repository is a mirror
  that receives `main` and release tags — day-to-day branches are developed and gated upstream of
  the mirror (`.github/workflows/ci.yml` records this).
- **Never edit generated artifacts:** `docs/route-table.md` and `docs/route-table.nl.md`
  (`npm run route-table`), and the committed `js/` bundles (`npm run build`).

## Invariants

- **Walls are load-bearing.** A page whose JSON carries `"protected": true` refuses delete and
  move on the server (`PAGE_PROTECTED`), a client can never raise the flag, and the wall set comes
  from the managed seed or from `occ intravox:protect`
  (`lib/Command/ProtectPageCommand.php`, `lib/Service/Write/PageProtectionService.php`).
  A refactor never removes the server-side guard.
- **The storage folder is one configured name** (`groupfolder_name`,
  `lib/Service/Folder/MountName.php`), never a string literal — every resolver, the path stripper
  and the Files links go through it.
- **Gate before opening a PR** — what `ci.yml` runs: `php -l` over `lib/` and `tests/`,
  `composer test:unit`, `composer lint:phpstan` (its baseline only shrinks), and the
  packaging guard's self-test; plus `npm run route-table` leaving a clean tree, and
  `python3 .github/repo-docs.py check . --offline`. A step you could not run is named in the PR,
  never reported as passed.
