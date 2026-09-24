# UNIT-012 — POS user edit and profile replacement

The POST mutation in `bill-pos-edit.php` updates `userinfo`, `userbillinfo`, and `radusergroup` with a single PDO handle and transaction (`library/pos_update.php`). Display, authorization, CSRF and the other POS/RADIUS controls keep their existing PEAR paths. The write connection rechecks and locks the user; the selected plan is checked when changed, and manual profiles are checked against the group sources shown by the form. A later insert failure rolls back earlier field changes, billing changes, and profile deletion/insertion. The portal password is hashed only when changed; blank input preserves its old hash.

Unlike the PEAR workflow, unknown plans/profiles and malformed priorities cannot silently persist or replace groups. The edit link preserves `%` in usernames; plan names with `%` and apostrophes are bound parameters. Quoted names and later-insert rollback were tested separately from parity. The normal user/billing/profile edit was compared to the prior PEAR page with equivalent disposable MariaDB data. The test command is:

```sh
POS_EDIT_BASELINE=1 python3 tests/pos_update_http.py > /path/to/isolated-baseline.json
POS_EDIT_BASELINE_JSON=/path/to/isolated-baseline.json python3 tests/pos_update_http.py
```

The baseline mode temporarily mounts `bill-pos-edit.php` from the pre-UNIT-012 commit `f467c162c` in the disposable HTTP fixture; it does not modify the worktree. The candidate command without `POS_EDIT_BASELINE_JSON` still runs the security, validation and rollback checks. Containers and their temporary database are destroyed after the run. No production or concurrency test is claimed.

Existing `lastbill`/`nextbill`, hotspot and batch fields remain untouched on updates, matching the old edit. In the missing-billing-row branch, optional metadata not previously inserted is now populated when submitted. Application-side locks and checks are not a substitute for schema uniqueness/foreign-key constraints when other writers race or ignore the locks. PDO driver support is required for this write path; the legacy display reads remain PEAR.
