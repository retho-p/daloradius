# UNIT-013 — POS user deletion

`bill-pos-del.php` now calls `library/pos_delete.php` for the POST write. A single PDO transaction locks the RADIUS account and all billing IDs for the username, discovers and locks their invoice IDs, deletes payments and invoice items before invoices, and then deletes user-group, reply, user-info, billing-info and check records. `radacct` is included only when the operator chooses **yes**. Configured table names are selected from fixed key allowlists and validated as identifiers; usernames and numeric IDs are bound values. Login, ACL, CSRF, option-list/display reads and logging retain their existing non-write paths.

The previous PEAR page deleted `userbillinfo` **before** looking up the billing ID. Consequently its invoice lookup normally saw no ID and left invoices, items and payments orphaned. The disposable baseline confirms that behavior for two billing IDs belonging to one user. The PDO path deliberately removes both sets of dependents; its account/RADIUS/billing/accounting projection matches PEAR for the simple deletion. A late foreign-key failure against `radcheck` proves rollback of earlier invoice, user and optional accounting deletions. Invalid/stale usernames or malformed accounting choices fail without modifying rows. The `%` and apostrophe in a username are no longer stripped or interpolated.

Run from the worktree:

```sh
POS_DELETE_BASELINE=1 python3 tests/pos_delete_http.py > /path/to/isolated-baseline.json
POS_DELETE_BASELINE_JSON=/path/to/isolated-baseline.json python3 tests/pos_delete_http.py
```

The baseline fixture reads the original page at `c9c8ae386`; neither test changes the worktree or the persistent lab stack. Both create and tear down isolated HTTP/PHP/MariaDB containers. The candidate command without a baseline JSON still runs rollback and security assertions. No production/concurrent-writer test is claimed. Transactional guarantees require transactional table engines. There are no schema foreign keys enforcing every relationship; another writer ignoring the account/billing locks may race with deletion and create new dependents. Invoice rows whose billing parent was already deleted before this operation cannot be safely attributed to a username and are outside its reach.
