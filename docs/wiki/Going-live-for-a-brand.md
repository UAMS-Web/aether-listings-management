Written against v0.4.1.

A silo that has been running as a dry run starts writing to Google when its mode is `live` and the run is on the production host. Writing is the one step the sync cannot take back: it creates listings and overwrites listing fields. Work through these checks first.

## Steps

1. **Compare store codes.** Run `StoreCodeComparison::compare()` over the codes the content generates and the codes on the existing listings. Fix every difference whose cause is letter case or a cut-short slug, since each one would otherwise create a second listing beside an existing one. See [Store codes](Store-codes.md).
2. **Read a full dry run of the silo.** Count the actions. Every `create` is a new listing, so a large number means store codes are not being recognized. Every `review` needs a person: a store code on two listings, one in the wrong account group, or one longer than 64 characters.
3. **Read the reasons for hand-kept hours.** A reason ending `; replaces the listing's special hours on <dates>` names dates where a live write replaces special hours someone entered by hand on the listing.
4. **Read `unresolved_categories`.** Each name listed has no Google ID and is dropped from its listing. When the primary category is the one missing, the listing gets no categories at all, and a create of it is skipped with `Cannot create, missing categories`.
5. **Check the create budget.** A backlog of creates drains over several days at `create_budget` a day. See [Living with the daily create limit](Living-with-the-daily-create-limit.md). Make sure the site supplies a quota store that outlives a request.
6. **Set `production_host`** to the production site's host (`BUSINESS_PROFILE_PRODUCTION_HOST`), compared without letter case. A staging site with the same configuration keeps running the silo as a dry run.
7. **Set the silo's mode to `live`** in `modes`. Leave every other silo as it is.

## After the first live run

Read the log for `error` outcomes. A rate limit is marked `retryable`, and the next save of the entry runs it again. Anything else carries Google's own message. See [Troubleshooting](Troubleshooting.md).

**Warning:** a silo is the only writer to its two account groups, and a listing created by the sync is never deleted by it. Remove a listing created by mistake in Google, not through the add-on.

## Reference

- [Configuration](Configuration.md): `production_host`, `silos`, `modes` and `create_budget`.
- [Outcomes and actions](Outcomes-and-actions.md): what each action and reason means.
- [Store codes](Store-codes.md): the comparison and the 64-character limit.
