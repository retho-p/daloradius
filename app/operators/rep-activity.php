<?php
/** Unified, read-only user activity. Legacy report endpoints remain available. */
require_once __DIR__ . '/../common/includes/config_read.php';
include $configValues['OPERATORS_LIBRARY'] . '/checklogin.php';
$operator = $_SESSION['operator_user'];
require_once $configValues['COMMON_INCLUDES'] . '/pdo_connection.php';
require_once __DIR__ . '/library/user_activity_access.php';

try {
    $activityPDO = dalo_pdo_connect($configValues, $_SESSION['location_name'] ?? 'default');
    $activityViews = dalo_activity_allowed_views($activityPDO, $configValues, $_SESSION['operator_id'] ?? null);
} catch (Throwable $error) {
    error_log('User activity permission lookup failed: ' . get_class($error));
    http_response_code(503);
    exit('Unable to check report permissions.');
}
if (!$activityViews) {
    http_response_code(403);
    exit('You do not have permission to view these reports.');
}
try {
    $view = $_GET['view'] ?? array_key_first($activityViews);
    if (!is_string($view) || !array_key_exists($view, dalo_activity_views())) {
        throw new InvalidArgumentException('Invalid report view');
    }
    if (!isset($activityViews[$view])) {
        http_response_code(403);
        exit('You do not have permission to view this report.');
    }
    $format = $_GET['format'] ?? 'html';
    if (!is_string($format) || !in_array($format, array('html', 'csv'), true)) {
        throw new InvalidArgumentException('Invalid report format');
    }
    $activityInput = $_GET;
    unset($activityInput['format']);
    $activityFilters = dalo_activity_filters($activityInput, $view);
} catch (InvalidArgumentException $error) {
    http_response_code(400);
    exit('Invalid activity filters. Check the dates and search values.');
}

$activityFailure = false;
try {
    $activityReport = dalo_activity_report($activityPDO, $configValues, $activityFilters, $format === 'csv');
} catch (Throwable $error) {
    error_log('User activity read failed: ' . get_class($error));
    http_response_code(503);
    if ($format === 'csv') {
        exit('Unable to export this report.');
    }
    $activityFailure = true;
    $activityReport = array('columns' => array(), 'rows' => array(), 'total' => 0,
                            'page' => 1, 'pages' => 1, 'note' => '');
}
header('Cache-Control: private, no-store');
if ($format === 'csv') {
    if ($activityReport['total'] > 10000 || !empty($activityReport['truncated'])) {
        http_response_code(422);
        exit('This export exceeds 10,000 rows. Narrow the search before exporting.');
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="user-activity-' . $view . '.csv"');
    header('X-Content-Type-Options: nosniff');
    $stream = fopen('php://output', 'w');
    $headers = $activityReport['columns'];
    foreach ($headers as $key => &$label) {
        if (in_array($key, array('upload', 'download'), true)) { $label .= ' (bytes)'; }
        if ($key === 'duration') { $label .= ' (seconds)'; }
    }
    unset($label);
    fputcsv($stream, array_values($headers), ',', '"', '');
    foreach ($activityReport['rows'] as $row) {
        $cells = array();
        foreach ($headers as $key => $label) {
            $value = (string) ($row[$key] ?? '');
            // Prevent spreadsheet formula execution, including leading whitespace.
            if (preg_match('/^[\x00-\x20]*[=+@-]|^[\t\r\n]/', $value)) { $value = "'" . $value; }
            $cells[] = $value;
        }
        fputcsv($stream, $cells, ',', '"', '');
    }
    fclose($stream);
    exit;
}

require_once $configValues['OPERATORS_LANG'] . '/main.php';
require_once $configValues['COMMON_INCLUDES'] . '/validation.php';
require_once $configValues['COMMON_INCLUDES'] . '/layout.php';
function activity_escape($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function activity_url(array $filters, array $overrides = array()) {
    return 'rep-activity.php?' . http_build_query(array_merge($filters, $overrides), '', '&', PHP_QUERY_RFC3986);
}
function activity_cell($key, $value) {
    if ($value === null || $value === '') { return '—'; }
    if (in_array($key, array('upload', 'download'), true)) {
        $number = (float) $value;
        $units = array('B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB');
        $index = 0;
        while ($number >= 1024 && $index < count($units) - 1) { $number /= 1024; $index++; }
        return number_format($number, $index === 0 ? 0 : 2) . ' ' . $units[$index];
    }
    if ($key === 'duration') {
        $seconds = max(0, (int) $value);
        return sprintf('%dh %02dm %02ds', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }
    return (string) $value;
}

$title = 'User activity';
print_html_prologue($title, $langCode, array('static/css/user-activity.css?v=1'), array('static/js/user-activity.js?v=1'));
print_title_and_help($title, '');
$commonFilters = array_intersect_key($activityFilters, array_flip(array('username', 'startdate', 'enddate', 'per_page')));
$hasPeriod = $view !== 'sessions' || $activityFilters['state'] === 'history';
$today = new DateTimeImmutable('today');
$ranges = array(
    'today' => array($today->format('Y-m-d'), $today->format('Y-m-d')),
    '7days' => array($today->modify('-6 days')->format('Y-m-d'), $today->format('Y-m-d')),
    '30days' => array($today->modify('-29 days')->format('Y-m-d'), $today->format('Y-m-d')),
);
$selectedRange = 'custom';
foreach ($ranges as $key => $dates) {
    if ($dates === array($activityFilters['startdate'], $activityFilters['enddate'])) { $selectedRange = $key; }
}
$sortable = $activityReport['sortable'] ?? array();
?>
<section class="activity-report" aria-label="User activity reports">
    <nav class="activity-views" aria-label="Activity view">
        <?php foreach ($activityViews as $key => $definition): ?>
        <a class="activity-view<?= $key === $view ? ' active' : '' ?>" href="<?= activity_escape(activity_url($commonFilters, array('view' => $key))) ?>"<?= $key === $view ? ' aria-current="page"' : '' ?>><?= activity_escape($definition['label']) ?></a>
        <?php endforeach; ?>
    </nav>
    <?php if ($view === 'sessions'): ?>
    <div class="activity-session-mode" aria-label="Session scope">
        <a class="btn btn-sm <?= $activityFilters['state'] === 'open' ? 'btn-secondary' : 'btn-outline-secondary' ?>" href="<?= activity_escape(activity_url($activityFilters, array('state' => 'open', 'page' => 1))) ?>"<?= $activityFilters['state'] === 'open' ? ' aria-current="true"' : '' ?>>Open now</a>
        <a class="btn btn-sm <?= $activityFilters['state'] === 'history' ? 'btn-secondary' : 'btn-outline-secondary' ?>" href="<?= activity_escape(activity_url($activityFilters, array('state' => 'history', 'page' => 1))) ?>"<?= $activityFilters['state'] === 'history' ? ' aria-current="true"' : '' ?>>History</a>
    </div>
    <?php endif; ?>
    <form class="activity-search card" method="get" action="rep-activity.php" data-activity-ranges="<?= activity_escape(json_encode($ranges)) ?>">
        <input type="hidden" name="view" value="<?= activity_escape($view) ?>">
        <input type="hidden" name="state" value="<?= activity_escape($activityFilters['state']) ?>">
        <input type="hidden" name="sort" value="<?= activity_escape($activityFilters['sort']) ?>">
        <input type="hidden" name="direction" value="<?= activity_escape($activityFilters['direction']) ?>">
        <div class="activity-search-row">
            <div class="activity-user"><label for="activity-username">Username starts with</label><input id="activity-username" class="form-control form-control-sm" type="search" name="username" maxlength="253" value="<?= activity_escape($activityFilters['username']) ?>" placeholder="All users"></div>
            <?php if ($hasPeriod): ?>
            <div class="activity-period-preset" hidden><label for="activity-period">Period</label><select id="activity-period" class="form-select form-select-sm"><?php foreach (array('today' => 'Today', '7days' => 'Last 7 days', '30days' => 'Last 30 days', 'custom' => 'Custom') as $key => $label): ?><option value="<?= $key ?>"<?= $selectedRange === $key ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></div>
            <div><label for="activity-start">From</label><input id="activity-start" class="form-control form-control-sm" type="date" name="startdate" value="<?= activity_escape($activityFilters['startdate']) ?>" required></div>
            <div><label for="activity-end">Through</label><input id="activity-end" class="form-control form-control-sm" type="date" name="enddate" value="<?= activity_escape($activityFilters['enddate']) ?>" required></div>
            <?php else: ?>
            <input type="hidden" name="startdate" value="<?= activity_escape($activityFilters['startdate']) ?>"><input type="hidden" name="enddate" value="<?= activity_escape($activityFilters['enddate']) ?>">
            <?php endif; ?>
            <?php if ($view === 'authentications'): ?>
            <div><label for="activity-reply">Result</label><select id="activity-reply" class="form-select form-select-sm" name="reply"><?php foreach (array('Any' => 'All results', 'Access-Accept' => 'Accepted', 'Access-Reject' => 'Rejected') as $key => $label): ?><option value="<?= $key ?>"<?= $activityFilters['reply'] === $key ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></div>
            <?php endif; ?>
            <div><label for="activity-size">Rows</label><select id="activity-size" class="form-select form-select-sm" name="per_page"><?php foreach (array(25, 50, 100) as $size): ?><option value="<?= $size ?>"<?= (int) $activityFilters['per_page'] === $size ? ' selected' : '' ?>><?= $size ?></option><?php endforeach; ?></select></div>
            <div class="activity-search-actions"><button type="submit" class="btn btn-secondary btn-sm">Search</button><a class="btn btn-outline-secondary btn-sm" href="<?= activity_escape(activity_url(array('view' => $view, 'state' => $activityFilters['state']))) ?>">Reset</a></div>
        </div>
        <?php if ($view === 'sessions'): $advancedOpen = $activityFilters['ip'] !== '' || $activityFilters['mac'] !== '' || $activityFilters['nas'] !== ''; ?>
        <details class="activity-advanced"<?= $advancedOpen ? ' open' : '' ?>><summary>Advanced filters</summary><div class="activity-search-row">
            <?php foreach (array('ip' => 'Client IP', 'mac' => 'Client MAC', 'nas' => 'NAS IP') as $key => $label): ?>
            <div><label for="activity-<?= $key ?>"><?= $label ?></label><input id="activity-<?= $key ?>" class="form-control form-control-sm" name="<?= $key ?>" value="<?= activity_escape($activityFilters[$key]) ?>" maxlength="253"></div>
            <?php endforeach; ?>
        </div></details>
        <?php endif; ?>
    </form>
    <?php if ($activityFailure): ?>
    <div class="alert alert-danger mt-3" role="alert">Unable to load this report. This is a technical error, not an empty result. Please try again.</div>
    <?php else: ?>
    <p class="activity-note"><?= activity_escape($activityReport['note']) ?></p>
    <div class="activity-result-bar"><span><strong><?= number_format($activityReport['total']) ?></strong> <?= $view === 'sessions' ? 'sessions' : ($view === 'authentications' ? 'attempts' : ($view === 'accounts' ? 'accounts' : 'users')) ?></span>
        <?php if ($activityReport['total'] > 0 && $activityReport['total'] <= 10000): ?><a class="btn btn-outline-secondary btn-sm" href="<?= activity_escape(activity_url($activityFilters, array('format' => 'csv'))) ?>">Export CSV</a><?php elseif ($activityReport['total'] > 10000): ?><span class="text-muted small">Narrow to 10,000 rows or fewer to export.</span><?php endif; ?>
    </div>
    <?php if ($activityReport['rows']): ?>
    <div class="activity-table-wrap"><table class="table table-sm table-hover activity-table"><caption class="visually-hidden"><?= activity_escape($activityViews[$view]['label']) ?> results</caption><thead><tr>
        <?php foreach ($activityReport['columns'] as $key => $label): $isSorted = $activityFilters['sort'] === $key; ?>
        <th scope="col"<?= $isSorted ? ' aria-sort="' . ($activityFilters['direction'] === 'asc' ? 'ascending' : 'descending') . '"' : '' ?>><?php if (in_array($key, $sortable, true)): ?><a href="<?= activity_escape(activity_url($activityFilters, array('sort' => $key, 'direction' => $isSorted && $activityFilters['direction'] === 'desc' ? 'asc' : 'desc', 'page' => 1))) ?>"><?= activity_escape($label) ?><?= $isSorted ? ($activityFilters['direction'] === 'asc' ? ' ↑' : ' ↓') : '' ?></a><?php else: ?><?= activity_escape($label) ?><?php endif; ?></th>
        <?php endforeach; ?>
    </tr></thead><tbody>
        <?php foreach ($activityReport['rows'] as $row): ?><tr>
            <?php foreach ($activityReport['columns'] as $key => $label): ?><td<?= in_array($key, array('duration', 'upload', 'download', 'sessions'), true) ? ' class="activity-numeric"' : '' ?>><?php if ($key === 'username'): ?><a href="<?= activity_escape(activity_url($activityFilters, array('username' => (string) $row[$key], 'page' => 1))) ?>"><?= activity_escape($row[$key]) ?></a><?php else: ?><?= activity_escape(activity_cell($key, $row[$key] ?? null)) ?><?php endif; ?></td><?php endforeach; ?>
        </tr><?php endforeach; ?>
    </tbody></table></div>
    <nav class="activity-pagination" aria-label="Report pagination"><span>Page <?= (int) $activityReport['page'] ?> of <?= (int) $activityReport['pages'] ?></span><div>
        <?php if ($activityReport['page'] > 1): ?><a class="btn btn-outline-secondary btn-sm" rel="prev" href="<?= activity_escape(activity_url($activityFilters, array('page' => $activityReport['page'] - 1))) ?>">Previous</a><?php endif; ?>
        <?php if ($activityReport['page'] < $activityReport['pages']): ?><a class="btn btn-outline-secondary btn-sm" rel="next" href="<?= activity_escape(activity_url($activityFilters, array('page' => $activityReport['page'] + 1))) ?>">Next</a><?php endif; ?>
    </div></nav>
    <?php else: ?><div class="activity-empty">No results match these filters. Try a different username or period.</div><?php endif; ?>
    <?php endif; ?>
</section>
<?php print_footer_and_html_epilogue();
