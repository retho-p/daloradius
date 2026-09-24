# UNIT-014 — batch user creation

`mng-batch-add.php` now generates the candidate usernames/passwords before opening one PDO transaction for the batch history row and **all** generated account check/reply attributes, manual group mappings, user info and billing info. A failure on any generated user rolls the whole batch back; the printable/CSV export form is constructed only after commit. All data values are bound and configured table names selected from fixed allowlists. Existing shared PEAR attribute/user/group helpers are unchanged for other pages. The form's hotspot, group and active-plan lists remain read through PEAR, but selections are rechecked on the write connection. The generated per-user portal password hashes use independent salts.

This deliberately chooses **all-or-nothing**, not the legacy partial-success policy. The old page could leave a `batch_history` row behind after a duplicate-name failure, skip a username collision while reporting a smaller batch, and strand a partly created user after a later write error. The new path rejects duplicate generated usernames and existing accounts before the first insert, then rolls back all dependent rows if a later insert fails. Invalid selected group/plan/hotspot/type and malformed scalar or four-slot attribute fields fail without provisioning. The generation count is capped at 1,000; this is a deliberate operational limit, not a PEAR behavior match. Name validation now checks the actual string (rather than the old boolean expression).

Isolated real HTTP/PHP/MariaDB differential test:

```sh
BATCH_CREATE_BASELINE=1 python3 tests/batch_create_http.py > /path/to/batch-baseline.json
BATCH_CREATE_BASELINE_JSON=/path/to/batch-baseline.json python3 tests/batch_create_http.py
```

The baseline fixture mounts `mng-batch-add.php` at pre-UNIT-014 commit `28154153c` in disposable containers. The candidate command also runs permissions/CSRF checks, duplicate rejection, a database trigger on the **second** generated user to verify complete rollback, malformed-field and quoted-plan tests, PIN mode and salted portal hashes. Generated random passwords are not byte-compared; stable account/billing/RADIUS projections are compared. No production or concurrent-writer test is claimed. Application prechecks cannot guarantee batch-name or username uniqueness under concurrent writes without appropriate database constraints. This write path requires supported PDO and transactional table engines; independent reads and unrelated workflows remain on PEAR.
