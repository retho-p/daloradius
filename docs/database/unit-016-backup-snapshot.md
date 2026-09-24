# UNIT-016 — backup snapshot export

`config-backup-createbackups.php` uses `library/backup_snapshot.php` and the optional PDO connection to read selected tables. The selection is restricted to the page's configured table keys; resolved table and column identifiers are validated before quoting. Only MySQL/MariaDB **InnoDB** tables are accepted because the existing restore page understands MySQL-style SQL and a consistent MVCC read is required. Other engines, missing tables, malformed selections and uninstalled PDO drivers fail closed.

All selected tables are read on one repeatable-read transaction on one PDO handle. Rows are fetched unbuffered and written to a same-directory, mode-0600 staging file; the file is flushed/closed and linked atomically to a collision-resistant `backup-YYYYMMDD-HHMMSS-<random>.sql` name only after a successful export. Failures remove the staged file. The backup manager lists only published filenames, including old `backup-YYYYMMDD-HHMMSS.sql` files. Existing download/delete/rollback actions remain PEAR-backed.

The SQL format remains one `INSERT` per nonempty table separated by three newlines for the existing rollback parser. NULL is preserved as SQL `NULL`; binary values are hex-encoded. Backslashes, quotes and newlines round-trip. The count/message describes tables actually exported, not empty selected tables.

**Limitations:** This is a data-only backup, not a schema dump. Empty tables still produce no DELETE/INSERT and will not be cleared by rollback. The existing restore is not atomic and reads the whole file; a large `INSERT` can exceed the restore connection's packet or memory limits. Concurrent DDL and non-InnoDB engines are not supported. The snapshot contains secrets and personal data despite restrictive file permissions; it is not encrypted. Persistence of the configured backup directory is a separate deployment concern. UNIT-017 should address the restore path; this unit does not make restores safe for production.

Validation on disposable HTTP/PHP/MariaDB instances (baseline `2ad352af0`, same seeded schema):

```sh
BACKUP_BASELINE=1 python3 tests/backup_snapshot_http.py
python3 tests/backup_snapshot_http.py
```

Both produced three importable nonempty table statements despite a fourth empty table, passed login/ACL/CSRF checks and the actual backup-manager rollback. The baseline PEAR output turns optional NULL userinfo fields into empty strings; PDO preserves them (intentional correction). Candidate-only assertions cover malformed/no selection, failure after an earlier table leaves no published or staged artifact, staged files hidden from listing, file mode 0600, a second connection's write between table reads not leaking into the snapshot, and BLOB/NULL SQL round-tripping. Syntax checks and `git diff --check` also run. These tests are **isolated laboratory tests**, not live deployment validation.
