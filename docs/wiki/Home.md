Written against v0.4.1.

ListingsManagement syncs Google Business Profile listings for UAMS Statamic sites. It reads provider and location entries, decides per listing whether to update it, create it or send it to review, and writes only where a silo is live. It is for the developers who wire it into a site and the people who read its activity log.

This wiki holds the guides, the reference and troubleshooting. The [README](https://github.com/UAMS-Web/aether-listings-management#readme) keeps the overview, the requirements and installation.

**What the add-on ships.** A Business Profile client and sync, and one configuration file. It registers no command, route, tag, permission, Control Panel screen or fieldtype, and nothing in it runs by itself: the site calls it, reads each entry into plain data, and records the outcomes. [Classes](Classes.md) lists every class and what calls it.

## Getting started

- [Installation (README)](https://github.com/UAMS-Web/aether-listings-management#installation): add the package to a Statamic install.
- [Your first dry run](Your-first-dry-run.md): configure one silo and see what a run would write, without writing anything.

## Guides

- [Going live for a brand](Going-live-for-a-brand.md): move a silo from a dry run to writing, and the checks before it.
- [Living with the daily create limit](Living-with-the-daily-create-limit.md): the create budget, deferred creates, and keeping the count between runs.

## Troubleshooting

- [Troubleshooting](Troubleshooting.md): one entry per outcome reason, with its cause and fix.

## Reference

- [Configuration](Configuration.md): every key of `config/aether-listings-management.php`.
- [Outcomes and actions](Outcomes-and-actions.md): what a run decides for each listing and how it is logged.
- [Listing fields](Listing-fields.md): how an entry becomes a listing's title, address, categories, description, website and attributes.
- [Hours](Hours.md): regular and special hours.
- [Photos](Photos.md): the logo, cover and other photos.
- [Store codes](Store-codes.md): how a listing is recognized, and the comparison to run before a silo goes live.
- [Classes](Classes.md): every class and what it is for.

## Project

- [Project](Project.md): where the add-on is developed and how to report a problem.
