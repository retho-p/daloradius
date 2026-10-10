# Session Explorer

An additive, read-only operator report at `rep-sessions.php`. The legacy reports remain available.

## Permissions and rollout

HTML, JSON (`format=json`), detail and CSV (`format=csv`) all require a logged-in operator and the existing **rep_online** permission, against the selected database location. This initial report intentionally inherits the existing online-report ACL; it does not introduce an independent permission or database migration. Do not grant it to an operator who must not see accounting records. Removing the new menu links/files rolls back the feature without changing accounting data.

## What the report means

- **Ended:** an accounting Stop is recorded.
- **Open · recent accounting:** no Stop, a usable update at or after session start and no later than the observation time, and its age is at most the configured expected interval times tolerance.
- **Open · old accounting:** the same conditions, but the update is older. This is only *potentially* orphaned, never proof of disconnection.
- **Open · freshness unknown:** no expected interval, missing/unusable update, missing start, update before start, or future update.

`NULL` and historical zero Stop timestamps both mean open. The report does not use a Start timestamp as a substitute heartbeat. Interval zero explicitly disables freshness classification for that NAS. No interval is inferred from `acctinterval` or a sampled packet: configured expectations must reflect actual NAS behavior.

The status counts respect all search filters **except the selected status**, so the status links stay useful. Matching total, rows, details and export respect the selected status. Details are restricted to the current search scope. Sorts use `radacctid` as a deterministic tiebreaker.

Dates mean **sessions started during inclusive local calendar dates**, not sessions overlapping the period and not traffic exchanged during it. Boundaries are converted to the configured database timezone with an exclusive next-day upper bound (including DST transitions).

Input bytes are received by the NAS from the client; output bytes are sent by the NAS to the client, subject to the NAS's implementation. They are cumulative whole-session counters, not throughput or exact period consumption. Missing values remain unavailable, not zero. Sessions and unique users are not interchangeable.

## Configuration

Optional PHP settings in `app/common/includes/daloradius.conf.php`:

```php
// Must describe the timezone of stored radacct DATETIME values.
$configValues['CONFIG_REPORTS_DB_TIMEZONE'] = 'Europe/Paris';
// Unknown by default: enabling a global expectation requires operator knowledge.
$configValues['CONFIG_REPORTS_INTERIM_INTERVAL'] = 0;
$configValues['CONFIG_REPORTS_FRESHNESS_TOLERANCE'] = 2;
$configValues['CONFIG_REPORTS_NAS_INTERVALS'] = [
    '192.0.2.10' => 300,
    '192.0.2.20' => 60,
    '192.0.2.30' => 0,
];
```

Without an explicit database timezone, PHP's configured default is assumed. Confirm it before interpreting dates; timezone-free DATETIME values cannot reveal their original timezone. Display and date-filter timezone can be selected in the URL. UTC or named IANA zones are supported.

The initial provider supports **MySQL/MariaDB**, detects optional columns and NAS name availability, and fails explicitly if required accounting columns are absent. IPv6 and Calling-Station-ID filters report a capability error when that data is unavailable. No external service, JavaScript framework or schema migration is required. BIGINT session identifiers and counters are serialized as strings in JSON to preserve their precision.

For an isolated synthetic demonstration only, `$configValues['CONFIG_REPORTS_DEMO'] = true;` displays a conspicuous fixture-data warning. It does not generate or refresh any records; fixture tooling belongs to the isolated deployment, not production.

## Exports and security

CSV uses explicit filters and sort, not the session-global last-report descriptor. Parallel tabs cannot overwrite its search context. Pagination and detail selection do not limit the export. Exports refuse more than 10,000 matches rather than silently truncate. Formula-leading cells are neutralized for spreadsheets. Exports include raw database timestamps and the database/display timezone metadata.

No passwords, NAS shared secrets, SQL fragments, or auth credentials enter the read model. HTML is escaped. All filtering values are bound; sort columns and identifiers are allowlisted. Responses are not cacheable. The page accepts GET only and exposes no disconnect/cleanup action.

## Tests

```sh
php tests/reports/session_contract_test.php
# Against an isolated, initialized test database with app configuration:
php tests/reports/session_database_test.php
```

The database test uses temporary tables on its own connection, not real accounting tables, and validates NULL/zero Stops, freshness boundaries, missing/future updates, wildcard escaping, NAS duplicate configurations, pagination, scoped details and optional-column behavior. Tests must be run with PHP's PDO MySQL extension and a database account allowed to create temporary tables.

HTTP checks should cover anonymous and denied operators for HTML/JSON/CSV, malformed filters, empty results, details, independent exports and the legacy report. Synthetic database fixtures validate reporting behavior; they do **not** validate real NAS presence, Interim-Updates or RADIUS disconnection.
