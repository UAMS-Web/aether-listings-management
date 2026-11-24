Written against v0.4.1.

`FieldMapper` turns a `people` or `clinical_locations` entry into the listing it asks for. Every value is read through a handle in [`mapping`](Configuration.md#mapping), so the mapper depends on no class of the site's. Its rules follow the WordPress sync's, so listings that sync already keeps up to date get the same store codes and fields.

The caller hands the mapper an `EntryData`: the entry's slug, title, absolute URL and field values, with a child location's parent attached. Relationship values arrive already resolved: a brand organization as its slug, a category term as an array with its `gcid` and `title`, an image as its public address.

## Locations

A location gives one listing.

| Part | How it is read |
| --- | --- |
| Store code | The location's slug, or `<parent slug>_<child slug>` for a child. See [Store codes](Store-codes.md). |
| Title | The entry's title with HTML entities decoded (`&amp;` is sent as `&`). It is prefixed with `UAMS Health - ` when the entry, or its parent for a child, has the prefix toggle on. |
| Address | The street, building, floor and suite, in that order, as address lines, with city, state, postal code, region `US` and language `en`. It is sent only when there is at least one line, a city, a state and a postal code. A child inherits its parent's address through the resolved handles unless it overrides it. |
| Coordinates | Both `lat` and `lng`, or neither. |
| Phone | The location's own phone, never its parent's. |
| Description | The first description handle with text, read from the parent for a child. |
| Categories | See [Categories](#categories). |
| Website | See [Website](#website). |
| Attributes | See [Attributes](#attributes). |
| Hours | See [Hours](Hours.md). |
| Photos | See [Photos](Photos.md). |

A location is excluded when its exclude toggle is on, or when its parent's is.

## Providers

A provider gets one listing per location it practices at.

| Part | How it is read |
| --- | --- |
| Store code | `<person slug>_<location slug>`, using the location's own slug even when it is a child. |
| Title | `UAMS Health - <name> - <location>`. The location is left off when it has no title. A provider at several locations has one listing per location, so the title names the location. |
| Address | The location's street and building, then the location's name without the place it is "in", "at" or "on", then the city, state and postal code. For a child location the parent's name comes before the child's. |
| Description | The first description handle with text. With none, `<short name> is a <clinical title> at <location> employed by UAMS Health.`, or `a health care provider` when there is no clinical title. |
| Categories | See [Categories](#categories), read from the provider's own field. |
| Website | See [Website](#website). |
| Hours | The hours of the location the listing is at. See [Hours](Hours.md). |
| Photos | See [Photos](Photos.md). |

A provider listing is excluded when the provider's exclude toggle is on, or when the location's `exclude_providers` toggle is on. A provider's listing has no attributes.

## Brand and silo

A location's brand picks its silo. With the brand toggle off, the location carries the default brand (`null` in `silos`). With it on and no brand chosen, the location has no brand and is never synced. A provider's listing takes the silo of the location it is at. The brand is read from the location itself, never from its parent.

## Categories

- The first category is the primary one. The next nine are additional categories. Repeats and a repeat of the primary are dropped.
- A category is sent by its Google ID, with or without the `gcid:` prefix: `doctor` and `gcid:doctor` both become `categories/gcid:doctor`.
- A category with no Google ID cannot be sent. Its name appears in `unresolved_categories` of the outcome. When the one that has none is the primary, no categories are sent at all, as the WordPress sync did.
- With no categories chosen, the kind's default from `default_categories` is sent.

## Description

The text is stripped of markup and line breaks, decoded, and cut to 747 characters with an ellipsis. A rich-text (Bard) value is read as the text of its nodes, one space between nodes.

## Website

The entry's absolute URL, with a trailing `/`, and the `tracking` query. `{kind}` becomes `provider` or `location` and `{store_code}` the listing's store code. An entry with no URL sends no website.

## Attributes

A location's attributes come from its radio fields, which answer true, false or nothing, and from the toggles in `attributes_when_on`, where on is true and off gives no answer. They are read from the parent for a child. An answer the content does not give is left out, so the sync never turns an attribute off for want of one.
