# UNIT-017 — transactional backup restore

`config-backup-managebackups.php` keeps login, ACL, CSRF, download and deletion actions. The `rollback` action now delegates to `library/backup_restore.php`, using the optional PDO MySQL/MariaDB connection. Only regular, non-symlink, canonical UI backup filenames (both legacy and UNIT-016 variants) under the configured backup directory can be restored.

A streaming, strict two-pass reader accepts only the UI writer's `INSERT INTO \`table\` (\`column\`, ...) VALUES (...)` dialect: SQL strings with supported MySQL escapes, NULL and hex binary literals. It rejects comments, DDL/DML other than INSERT, extra statements, malformed rows, duplicate tables, and unknown tables. Before changing any row, the complete file is parsed and every table is checked against the configured backup-page table allowlist, current column order and InnoDB engine. A second pass restores all named tables in one PDO transaction, deleting then inserting each table with bound values; the file hash, columns and row counts must still match before commit. Any error rolls the transaction back. Errors shown to users do not include driver details or backup row contents. Values are limited to 16 MiB per cell.

**Scope and limits:** The backup is a data-only snapshot; tables omitted because they were empty at export time are not cleared. Schema, triggers, routines and auto-increment state are not restored. A failed restore is transactional for the included InnoDB tables, but it does not quiesce RADIUS/web writers or supply an automatic pre-restore backup. Concurrent writers and foreign-key ordering can interfere; keep services quiesced and take an independent verified backup before any real restoration. This is not a production disaster-recovery guarantee. Only MySQL/MariaDB is supported by this UI SQL format.

Disposable HTTP/PHP/MariaDB tests use the fixed pre-unit commit `876cca1a8` for PEAR comparison:

```sh
BACKUP_RESTORE_BASELINE=1 python3 tests/backup_restore_http.py
python3 tests/backup_restore_http.py
```

Both paths restored three seeded tables with quotes, backslashes, newlines and NULL through the real UI, matching pre-backup SQL state; login, ACL and CSRF rejections left state unchanged. Candidate-only checks reject SQL trailing after a valid statement, unknown/duplicate tables, schema mismatch, traversal names and non-InnoDB tables before mutation; a forced INSERT failure in the second table rolls back the first table's replacement and the second table's deletion. Legacy/new filenames and binary/NULL values were exercised. Lint and `git diff --check` are static checks; the HTTP/MariaDB tests are **isolated laboratory execution**, not production validation.
