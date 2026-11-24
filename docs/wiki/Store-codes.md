Written against v0.4.1.

A listing's store code is the key the sync finds it by. The add-on has to produce the codes the existing listings already carry, or it creates a second listing beside each one it fails to recognize.

## How a code is made

| Listing | Store code |
| --- | --- |
| Location | Its slug. |
| Child location | `<parent slug>_<child slug>`. |
| Provider at a location | `<person slug>_<location slug>`, using the location's own slug even when it is a child. |

The rules are the WordPress sync's, so listings it keeps today are found.

## Limits

Google takes a store code of at most 64 characters. A longer code goes to review with the reason `Store code <code> is <n> characters, over the 64 Google allows`, and nothing is sent, so a person shortens it first. Since v0.4.0.

## How a code is looked up

Each item's code is looked up in an index of every listing the credentials can see, across every account group. A code anywhere is found before anything is created for it. A listing with no store code cannot be matched and is left out of the index. See [Outcomes and actions](Outcomes-and-actions.md) for what each result does.

## Comparing before a silo goes live

`StoreCodeComparison::compare()` takes the codes the content generates and the codes the existing listings carry, and returns every code on one side only, generated codes first, both sorted. A code on both sides is a match and is not listed.

Each difference is an array with `store_code`, `side` (`generated` or `existing`) and `cause`:

| Cause | Meaning |
| --- | --- |
| `differs only in letter case from <code>` | The same code in another letter case. |
| `a slug is cut short against <code>` | The other side carries a slug in full where this one is cut short. |
| `no listing carries it, so the sync would create one` | A generated code with no counterpart. |
| `no content generates it, so the sync never updates that listing` | An existing code with no counterpart. |

Read the list before a silo goes live. See [Going live for a brand](Going-live-for-a-brand.md).
