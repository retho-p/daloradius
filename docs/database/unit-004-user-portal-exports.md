# UNIT-004 — user portal report exports

`app/users/acct-date.php` and `app/users/bill-invoice-report.php` still use PEAR for their on-screen queries. They no longer put SQL or CSV columns in the session. When the page has rows, it stores a short-lived descriptor with a fixed source and validated filters. The CSV endpoint consumes it once, derives the username from the current authenticated session, and rebuilds an unpaginated PDO query with bound values and allowlisted table identifiers. Both pages clear stale descriptors on entry, including zero-row views.

The accounting report keeps its **exclusive** start and end dates; the invoice report keeps its **inclusive** `>=` and `<=` predicates against its datetime column. The new endpoint writes RFC-style quoted CSV via `fputcsv()` and prefixes spreadsheet-formula text cells with an apostrophe; simple data rows match the PEAR baseline after parsing, while header spaces and unsafe unquoted commas/formulas intentionally differ. A report without rows still sends no CSV attachment. The old `export_title` state had no producers and is no longer rendered.

## Isolated verification

On a host with Docker and the project PHP image, with a separate pre-migration worktree:

```sh
php tests/user_report_export.test.php
USER_REPORT_ROOT=/path/to/baseline python3 tests/user_report_export_http.py > /path/to/baseline.json
USER_REPORT_ROOT="$PWD" USER_REPORT_BASELINE_JSON=/path/to/baseline.json python3 tests/user_report_export_http.py > /path/to/candidate.json
```

The HTTP harness creates temporary MariaDB/PHP containers and seeded data. It checks both report families, date boundaries, cross-user isolation (including a stale descriptor after a principal switch), raw session SQL rejection, invalid filters, one-use state, unauthenticated redirect, CSV quoting and formula neutralization. It neither uses live operator/user accounts nor modifies the lab database. PHP logs on both baseline and candidate may contain pre-existing duplicate-constant warnings from `validation.php`; the harness rejects other warnings and fatal errors.
