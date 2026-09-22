# UNIT-003 — operator CSV exports (PEAR DB → PDO)

The operator pages still use PEAR DB for their on-screen queries. Their CSV controls now store a structured session descriptor (`source`, `type`, validated filters), not SQL fragments or table names. `fileExport.php` authorizes the source page again, resolves allowlisted identifiers, binds data values with PDO, and generates the existing CSV formats. No transaction spans the two clients.

`rep-history.php` no longer advertises a CSV control: it never had a matching export implementation. It clears an earlier page's export state instead of offering the previous report. PDF remains unimplemented, as before. The CSV output for other reports retains the legacy comma-joined formatting; CSV quoting/formula hardening outside the user-list importer format is a separate change.

## Verification on disposable data

Run from the candidate worktree on a host with Docker and Python 3, using a separate checkout at the pre-migration base as the reference:

```sh
php -l app/operators/include/management/fileExport.php
php tests/report_export_descriptor.test.php
REPORT_EXPORT_EXPECT_PDO=1 python3 tests/report_export_http.py
DALO_SOURCE_ROOT=/path/to/baseline python3 tests/report_export_differential.py > /path/to/baseline.json
DALO_SOURCE_ROOT="$PWD" DALO_BASELINE_JSON=/path/to/baseline.json python3 tests/report_export_differential.py > /path/to/candidate.json
```

The HTTP tests use temporary containers and databases; they do not authenticate to or mutate the running lab. The differential test exercises 18 source/type combinations with equivalent seeded data. Sixteen CSV results match the PEAR reference exactly. Two known pre-existing defects are not copied into the new exporter:

- `acct-plans-usage`: the PEAR export fails with an ambiguous `username` column (HTTP 500); the PDO query qualifies the column and returns rows.
- `rep-batch-details`: with the seeded search filter, the PEAR export has only a header because the source query/formatter disagree; the PDO export returns the matching user with batch name and start time.

The `mng-batch-list` export continues to show an empty Active Users field and zero Batch Cost because that page's legacy query has no accounting join; `rep-batch-list` keeps its separate accounting-join semantics. Changing those calculations is outside this migration. These checks do not establish production authentication, live dataset parity, or performance improvement.
