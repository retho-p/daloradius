# UNIT-002 — Open-session maintenance under PDO

Scope: `DB-ACCT-008`, `DB-ACCT-013`–`016` (page + filter, preview, query, and apply helpers). The separate legacy `acct-maintenance-delete.php` endpoint is outside UNIT-002 and remains PEAR-based.

The cleanup page uses a PDO connection directly after the existing ACL gate. The gate itself still reads permissions through PEAR, closes that handle, and never participates in the maintenance writes. A PDO transaction cannot span PEAR and PDO; this workflow deliberately keeps independent, atomic per-row UPDATE/DELETE statements, preserving the old partial-success semantics rather than wrapping 100 records in a new all-or-nothing transaction.

- Whitelisted table identifiers (configuration) and live-schema-validated snapshot column identifiers are the only dynamic SQL identifiers. Unknown, added or missing snapshot columns invalidate confirmation before any write.
- Username, midnight date boundary, and every non-NULL previewed column are bound values. `LIMIT` is an integer binding. Preview uses `fetchColumn()`/`fetchAll()`; `rowCount()` is used only for UPDATE/DELETE.
- The complete snapshot is checked byte-for-byte using the original MariaDB `BINARY <=> BINARY` predicate. NULL remains `IS NULL`; concurrently changed/stopped rows are skipped and rows created after preview are never included.
- Confirmation retains operator, location/database/table context, CSRF, one-use token, 10-minute TTL, and 100-row maximum. Driver exceptions are redacted and SQL debug logging prints placeholders rather than bound values.
- If the accounting table becomes unavailable after a preview, every previewed row is reported as failed, matching the old page's failure accounting. An individual failed statement does not roll back prior successful rows.

Validation command (disposable internal Docker network and tmpfs MariaDB; never uses the live accounting database):

```sh
TMPDIR=/home/kevin/.hermes/cache/scratch MAINTENANCE_EXPECT_PDO=1 \
  python3 tests/acct_maintenance_http.py
```

Run the same suite on a PEAR baseline seeded with the same schema and fixture, writing normalized post-action SQL snapshots with `MAINTENANCE_AB_TRACE=/path/to/trace.json`. Compare trace JSON and HTTP assertions. Random tokens and `NOW()` timestamps are omitted from the normalized SQL snapshots. This differential comparison covers ten named checkpoints (including a named location) but is not a complete byte-for-byte equivalence proof for all application screens. The dedicated PDO-only tampered-snapshot assertion checks stricter identifier validation.

Do not deploy this branch or run a destructive cleanup against live accounting as a test. Verify actual operator credentials and production-specific extensions separately in a staged database before release.
