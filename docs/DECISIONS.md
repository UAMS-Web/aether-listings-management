# Decision log

The canonical record of **why** this repository is the way it is: the reasoning and the **rejected alternatives** behind decisions that have closed. It is the guard against silently relitigating a settled decision.

**Consult it before acting.** Before proposing a change, reopening a question, or picking an approach, check here first: the path under consideration may already be a decided-and-recorded one, or an explicitly rejected one. If it is, follow the record (or make the case to revisit it); do not quietly re-decide.

**When a seam closes, record the verdict in both places.** Capture the *what*, *why* and rejected alternatives **here** (the cross-ticket index) **and** in the GitHub issue's `## Decision` section (the per-ticket record), keeping the two in sync. This file does not replace the issue tracker, which stays the system of record for a single ticket's status.

See the [`design-decision-forks`](../.claude/rules/design-decision-forks.md) rule for how a fork is surfaced and driven to resolution before its verdict lands here.

## How to add an entry

Oldest first, one entry per closed decision, so **a new entry is appended to the end of the file**. Keep it terse; the issue carries the depth. Each entry is preceded by exactly one blank line, which `scripts/check-decision-log-order.mjs` enforces along with the date order. The file is marked `merge=union` in `.gitattributes`, so two branches that each append an entry merge without a conflict.

```markdown
## YYYY-MM-DD: <short decision title> (<#issue or area>)

**Decision.** <what was decided, in a sentence or two>

**Why.** <the reasoning that settled it>

**Rejected.** <the alternatives considered, and why each lost>

**Companion.** <link to the GitHub issue holding the full reasoning>
```

---

## 2026-10-14: Started from the add-on template (repository setup)

**Decision.** This repository started from `UAMS-Web/statamic-addon-template` at `197c764`, the template's `main` tip when it was created. It carries no synced files (`.agents/`, `.claude/`, `.cursor/`) and no `LICENSE`, and `composer.json` has no `license` field.

**Why.** The shared rules and skills reach a repository by onboarding it to the sync in `UAMS-Web/uams-claude-skills`, not by copying a snapshot that goes stale. The license waits on the general decision from UAMS legal in `UAMS-Web/uams-statamic#261`.

**Rejected.** Snapshotting the synced files into the template: every new add-on would begin with a stale copy that conflicts with its first sync pull request. Copying a license from an existing add-on: legal has not decided which license applies.

**Companion.** `UAMS-Web/uams-statamic#261`.

## 2026-11-12: The add-on goes to Packagist on MIT, ahead of UAMS legal's decision (repository setup)

**Decision.** The operator decided on 2026-11-12 that `uams-web/aether-listings-management` is published on Packagist and licensed MIT (`LICENSE`, `"license": "MIT"`), following `UAMS-Web/aether-redirects`, ahead of `UAMS-Web/uams-statamic#261`. The package is listed and tagged (`v0.1.0`, `v0.1.1`, `v0.1.2`). The Marketplace is not part of this decision.

**Why.** A public Packagist package needs a declared license, and a host site requires the add-on by Composer name rather than by path. Matching the sibling add-ons keeps one license across them, and the file can change if UAMS legal decides otherwise.

**Rejected.** Waiting for UAMS legal before declaring a license: the package is already listed and cannot be used publicly without one. Requiring the add-on from a path repository: it ties a site to one machine's checkout.

**Companion.** `UAMS-Web/aether-listings-management#39`, `UAMS-Web/uams-statamic#261`.

## 2026-11-12: `aether-people` is suggested, and the add-on is Business Profile only (UAMS-Web/uams-statamic#418)

**Decision.** `composer.json` suggests `uams-web/aether-people` and does not require it: the add-on reads provider and location entries through collection handles set in its config, and calls no `aether-people` class. The add-on syncs to Google Business Profile only and defines no destination interface for other listing directories.

**Why.** The sync needs entries, not the package that defines them. Reading through configured handles keeps the add-on installable while the people collection still lives in the site (`UAMS-Web/uams-statamic#281` moves it), lets the two add-ons release on their own schedules, and keeps every handle unchanged. One destination exists, so an interface drawn from it would only describe Business Profile; Doximity and MyChart feeds are out of scope for the epic.

**Rejected.** Requiring `aether-people`: it would install the whole people layer for a sync that only reads entries, tie each release of this add-on to one of that add-on, and fail on a site whose people collection has not moved yet. A destination interface now: an abstraction with one implementation fixes its shape before a second destination shows what the shape must be; it can be extracted when a ticket adds one.

**Companion.** `UAMS-Web/uams-statamic#419`, `UAMS-Web/uams-statamic#418`.
