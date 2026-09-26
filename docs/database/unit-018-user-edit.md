# UNIT-018 — Operator user edit

## Scope

`app/operators/mng-edit.php` now uses `app/operators/library/user_edit.php` for the edit-page reads and for its entire dependent write sequence: RADIUS check/reply attributes, `userinfo`, `userbillinfo`, group mappings and plan-derived profiles. The writer parses the form first, then locks/rechecks the user and existing billing plan and writes on **one PDO handle and one InnoDB transaction**. The page no longer invokes the shared PEAR `handleAttributes()` or its local PEAR `addPlanProfile()`.

The shared `library/attributes.php` still supplies `hashPasswordAttribute()` and remains PEAR-based for **other** unmigrated creation/profile pages. The independent authorization/existence/portal-presence and shared widget reads on this page still use PEAR; they finish outside the PDO write transaction. This unit does not migrate those shared consumers or imply that the entire page has no PEAR connection.

Configured table names are checked against the fixed application key allowlist and identifier syntax; SQL values are bound. Attribute controls require four scalar slots, an allowed operator and a check/reply table. An existing attribute ID must belong to the selected username **and** its submitted table, with its stored attribute unchanged. Manual groups and priorities and changed active plans are validated before any mutation; the posted old-plan field must match the locked billing row. Replacing groups and changing plans replaces only the old plan's mapped profiles with the new plan's profiles, without inserting duplicate memberships.

A blank replacement portal password preserves the existing hash; a supplied replacement is hashed. Creation metadata is preserved on existing user/billing rows. Billing rows created by an edit include the selected plan. A submitted attribute value of `0` is stored rather than treated as empty.

## Isolated verification

`tests/user_edit_http.py` launches disposable PHP HTTP and MariaDB containers and imports the actual schemas. Run the pinned PEAR page from commit `e3075ac0c` first, then the candidate with the same temporary reference path:

```sh
USER_EDIT_BASELINE=1 USER_EDIT_REFERENCE=/path/to/isolated-reference.json python3 tests/user_edit_http.py
USER_EDIT_REFERENCE=/path/to/isolated-reference.json python3 tests/user_edit_http.py
```

The baseline and candidate compare normalized database projections after a regular edit and a plan switch. The candidate separately checks login, ACL and CSRF, stale-plan and foreign-attribute rejection, malformed groups/checkboxes, a late group-insert failure after earlier updates (all prior rows restored), password-attribute hashing/duplicate suppression, literal `0`, quoted/percent-bearing names, creation of absent user/billing rows, portal-password hashing and preservation, and creation metadata. The baseline's non-atomic partial writes, `%` stripping, billing creation-metadata overwrite, omitted initial billing plan and discarded literal `0` are **intentional corrections**, not claimed as PEAR/PDO parity.

This is disposable HTTP/PHP/MariaDB validation, not production or live-service testing. The test removes its containers/network and fixture on exit. It does not retain real credentials or password material.

## Limits

Legacy PEAR pages can still write the same tables without cooperating with this account lock; without universal uniqueness/foreign-key constraints, concurrent independent writers are not fully serialized. The independent preflight PEAR reads cannot protect the transaction; the account, plan and selected attribute IDs are therefore rechecked on PDO. A plan's mappings could still be changed concurrently by an unrelated legacy writer. No service deployment or database schema change is part of this unit.
