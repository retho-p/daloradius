# UNIT-019 — Operator account and attribute deletion

## Scope

`app/operators/mng-del.php` delegates its three POST operations to `app/operators/library/user_delete.php`: one user attribute, multiple selected accounts, or selected open accounting sessions. Every mutation uses a caller-owned PDO transaction; the account list is read independently through PDO. The page still relies on the existing session, ACL, CSRF and logging includes. Other pages and shared PEAR helpers are not migrated by this unit.

A whole-account deletion validates and deduplicates the entire selection, then locks all selected `radcheck` and `userbillinfo` rows and linked invoice IDs before changing anything. It deletes payments and invoice items before invoices; deletes `radpostauth`, group memberships, replies, user/billing information and check attributes; and deletes `radacct` only if the operator explicitly chose **yes**. Missing selected users or any late SQL error roll back the entire selection. The invoice cleanup is an intentional correction: the PEAR page left those invoices and their children orphaned. Already-orphaned invoices without a retained billing ID cannot be associated with an account by this procedure.

Attribute deletion accepts only the check/reply tables emitted by the edit page. The ID, attribute and username must match a locked row. Removing the last check row or last password/Auth-Type check attribute is rejected. This corrects the old password count's unparenthesized `AND ... OR`, which could count passwords of unrelated users. Session cleaning parses the report's `username||start time` controls, validates and locks all selected open sessions before removing any. Stale or malformed later entries reject the entire request rather than silently succeeding.

Table keys, configured identifiers and the FreeRADIUS postauth username column are allowlisted; all user data is bound. Exact usernames retain `%`, `+`, apostrophes and `||` rather than being URL-decoded a second time or having `%` stripped. Deletion refuses missing or non-InnoDB participating tables instead of claiming a rollback that the engine cannot provide. Driver errors and submitted values are excluded from failure messages and logs.

## Disposable validation

`tests/user_delete_http.py` runs real HTTP/PHP against isolated MariaDB with the project schemas. The PEAR page is pinned to `df1b6b3b7`; run baseline first and candidate against an isolated temporary reference file:

```sh
USER_DELETE_BASELINE=1 USER_DELETE_REFERENCE=/path/to/isolated-reference.json python3 tests/user_delete_http.py
USER_DELETE_REFERENCE=/path/to/isolated-reference.json python3 tests/user_delete_http.py
USER_DELETE_FR1=1 python3 tests/user_delete_http.py
```

The last command exercises the FreeRADIUS 1 postauth `user` column on an isolated schema variant; it does not emulate an entire FreeRADIUS 1 installation. Normalized persisted account/attribute/session/accounting state is compared A/B. Candidate-only checks cover ACL/CSRF, missing/malformed selections, foreign or disallowed attribute IDs/tables, last-auth guard, a database-trigger failure **after** earlier dependent deletes, stale later session selection, duplicate requests, invoice and payment cleanup, optional accounting, empty-billing accounts, refusal of a MyISAM table and percent/quote/plus/delimiter-bearing identities. The fixture inspects PHP logs for fatal errors and removes its containers and network.

This validation is an isolated application run, not a live deployment or production-data verification. The existing POS deletion and user edit paths have separate regression tests. No actual credentials are retained.

## Residual limits

Independent PEAR writers do not all take the same locks. Without universal foreign keys or coordinated writes, an invoice child, group record or postauth record could be inserted concurrently after this transaction's discovery/deletion; account locking alone does not provide universal referential integrity. External services are not quiesced, and non-transactional installations must be migrated to InnoDB before this deletion flow can operate. No schema migration, push or deployment is part of this unit.
