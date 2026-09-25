# IntraVox — org fence (L0-03). aps-common is the stated-once SSOT for shared
# rules; this repo vendors its src/ as a committed subtree, exactly the way
# farmacia does. composer still resolves aps/common from the path repository
# (../aps-common) for autoload correctness, but vendor/aps/common/src has ONE
# writer: 'make aps-sync'. The sibling checkout must exist beside this repo
# (org layout).
APS ?= ../aps-common

.PHONY: aps-sync aps-drift

aps-sync:
	@test -d "$(APS)/src" || { echo "aps-common not found at $(APS) — clone the sibling first"; exit 1; }
	rm -rf vendor/aps/common src/aps
	mkdir -p vendor/aps/common src/aps
	git -C "$(APS)" archive HEAD src | tar -x -C vendor/aps/common
	git -C "$(APS)" archive HEAD js | tar -x -C src/aps --strip-components=1
	rm -f src/aps/*.test.js

# vendor/aps/common/src is committed, so it can drift from the package the
# way js/ can drift from src/. Until CI lands here, this target IS the gate
# (same posture farmacia's Makefile records).
aps-drift: aps-sync
	@drift="$$(git status --porcelain -- vendor/aps/common src/aps)"; \
	if [ -n "$$drift" ]; then \
		printf '%s\n' "$$drift"; \
		echo "::error::aps-common artifacts are behind the package. Run 'make aps-sync' and commit the result."; \
		exit 1; \
	fi

# JS arm landed 2026-09-25: headingAnchors consumes fold (L0-03) — the first
# real shared-rule consumer. Prebuild's check-aps-parity.js holds the anchor
# slugs byte-stable against the golden corpus.
