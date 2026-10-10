# ADR-0001 — fork with local features

- **Status:** accepted (2026-10-04)
- **Affects:** the documentation tree, the documentation links in `appinfo/info.xml`, and how this
  repository takes changes from upstream.

## Context

The suite's intranet is VoxCloud's IntraVox (`nextcloud/IntraVox`), an app this organisation ships
as its own (`APS-Conecta/IntraVox`). Two facts of the install differ from upstream's product: the
instance's content language is Spanish — the engine's enabled-languages default is `['es', 'en']`
with `es` primary, converged in
[gestion ADR-0018](https://github.com/APS-Conecta/gestion/blob/main/docs/adr/0018-language-reality-is-the-engines-es-only-default.md) —
and the storage root is a group folder named for staff, not for the app
([gestion ADR-0020](https://github.com/APS-Conecta/gestion/blob/main/docs/adr/0020-the-storage-root-is-named-for-staff.md)).
The site's structure arrives through the managed seed, and parts of it are walls.

Upstream's documentation corpus speaks for upstream's product: a Dutch manual set, sales
comparisons against SharePoint and Collectives, and upgrade notes for versions this fork never
ran. None of it describes what this fork ships, and maintaining a parallel manual the install
cannot use divides every future change by two.

## Decision

This repository is maintained as a fork with local features. Upstream remains the engine and is
pulled in; the fork's deltas are kept small and are recorded here — the language defaults, the
storage root, the managed seed and its walls, the documentation links that point at this fork,
and the Spanish strings upstream has not translated yet (amended 2026-10-10, IntraVox#11).
The documentation is this fork's own, written once, in English: upstream's Dutch corpus and sales
comparisons are retired (2026-10-04), and facts that live outside this repository are cited to
their owner, never restated.

## Consequences

- A pull from upstream re-checks this ADR's delta list against what arrived; a conflict is decided
  here, not silently in the merge.
- Upstream documentation that arrives in a pull is reconciled against this decision before it
  ships — the Dutch manual corpus and the sales comparisons stay retired.
- The generated `docs/route-table.nl.md` stays: `npm run route-table` regenerates it from this
  fork's routes, like the English table.
- This fork's agent rules (`AGENTS.md`) and vocabulary (`CONTEXT.md`) bind every change.
- Spanish strings that upstream's Transifex has not translated are added by hand to
  `l10n/es.{js,json}` and `l10n/es_CL.{js,json}` (IntraVox#11: «Pages», «On this page»,
  «Enter text …» and the two save-conflict messages), worded register-neutral because upstream's
  Spanish uses «tú». This fork has no Transifex bot, so nothing here deletes them. A pull that
  rewrites those files carries them over, and an upstream translation of the same msgid replaces
  ours.
