# UNIT-015 — batch deletion

`mng-batch-del.php` now calls `library/batch_delete.php` after login, ACL and CSRF checks. The entire ID/name selection is validated and deduplicated before deletion. On one PDO transaction it locks every selected batch, its associated billing rows and their invoice IDs, then deletes invoice payments/items, invoice rows, RADIUS postauth/accounting/groups/check/reply rows and user/billing records before finally deleting the batch history rows. Table and postauth-column identifiers come from explicit allowlists; batch names, usernames and IDs use parameters. The independent option-list display remains on PEAR.

The original PEAR path deleted batch history first and then deleted username-keyed data without a transaction. It left invoices, items and payments orphaned, and could partially delete a multi-selection. The disposable A/B fixture compares the unchanged RADIUS/account/history state for one batch and separately asserts that the candidate removes invoice dependents. A late foreign-key error on the **second** batch after earlier rows have been deleted demonstrates rollback of all selected batches and users. The candidate rejects malformed/stale IDs, an ambiguous name, a batch username also associated with an unselected billing record, and absent or malformed selections. `%` and apostrophes in a batch name are preserved for the prepared lookup. Empty batches are supported.

Run from the worktree:

```sh
BATCH_DELETE_BASELINE=1 python3 tests/batch_delete_http.py > /path/to/batch-delete-baseline.json
BATCH_DELETE_BASELINE_JSON=/path/to/batch-delete-baseline.json python3 tests/batch_delete_http.py
```

The baseline uses the original page at commit `40902bd30`; containers/databases are temporary. The candidate test runs security, validation and rollback checks even without the baseline JSON. No production or concurrent-writer test is claimed. The transaction requires transactional table engines, and username-keyed data can still race with writers not coordinating on the locked batch/billing rows. Orphan invoice rows whose billing parent was already lost before deletion cannot be safely attributed to a batch. Unrelated shared PEAR functions and report reads are unchanged.
