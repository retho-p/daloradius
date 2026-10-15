<?php
/** Unified log reader. Legacy source-specific URLs remain available during the trial. */
require_once __DIR__ . '/../common/includes/config_read.php';
include $configValues['OPERATORS_LIBRARY'] . '/checklogin.php';
$operator = $_SESSION['operator_user'];
require_once $configValues['COMMON_INCLUDES'] . '/pdo_connection.php';
require_once __DIR__ . '/library/log_viewer_read.php';
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
try {
    $logPDO = dalo_pdo_connect($configValues, $_SESSION['location_name'] ?? 'default');
    $logSources = dalo_log_allowed_sources($logPDO, $configValues, $_SESSION['operator_id'] ?? null);
} catch (Throwable $error) {
    error_log('Log viewer permission lookup failed: ' . get_class($error));
    http_response_code(503); exit('Unable to check log permissions.');
}
if (!$logSources) { http_response_code(403); exit('You do not have permission to view these logs.'); }
try {
    $source = $_GET['source'] ?? array_key_first($logSources);
    if (!is_string($source) || !isset(dalo_log_sources()[$source])) { throw new InvalidArgumentException('Invalid source'); }
    if (!isset($logSources[$source])) { http_response_code(403); exit('You do not have permission to view this log.'); }
    $logFilters = dalo_log_filters($_GET, $source);
} catch (InvalidArgumentException $error) {
    http_response_code(400); exit('Invalid log filters.');
}
try { $logResult = dalo_log_read($configValues, $logFilters); }
catch (Throwable $error) {
    error_log('Log viewer read failed: ' . get_class($error));
    http_response_code(503); exit('Unable to read this log.');
}
$logMessages = array(
    'unconfigured' => 'This log source is not configured.',
    'missing' => 'No log file for this source was found in the web server filesystem. It may be stored on another host or container, or only in a system journal.',
    'unreadable' => 'The log file exists, but the web server cannot read it. No file permissions have been changed.',
    'empty' => 'The log file is empty.',
    'no_matches' => 'No matching lines were found in the inspected log window.',
    'error' => 'The file could not be read. Please refresh and try again.',
);
if ($logFilters['format'] === 'text') {
    if ($logResult['state'] !== 'ok') { http_response_code(409); exit($logMessages[$logResult['state']] ?? 'Log unavailable.'); }
    header('Content-Type: text/plain; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $source . '-filtered.log"');
    foreach ($logResult['lines'] as $line) { echo $line['text'] . "\n"; }
    exit;
}
require_once $configValues['OPERATORS_LANG'] . '/main.php';
require_once $configValues['COMMON_INCLUDES'] . '/layout.php';
function log_view_escape($value) { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function log_view_url(array $filters, array $overrides = array()) {
    return 'rep-logs.php?' . http_build_query(array_merge($filters, $overrides), '', '&', PHP_QUERY_RFC3986);
}
function log_view_line($text, $query) {
    if ($query === '') { return log_view_escape($text); }
    $out = ''; $start = 0; $highlights = 0;
    while ($highlights++ < 100 && ($position = stripos($text, $query, $start)) !== false) {
        $out .= log_view_escape(substr($text, $start, $position - $start));
        $out .= '<mark>' . log_view_escape(substr($text, $position, strlen($query))) . '</mark>';
        $start = $position + strlen($query);
    }
    return $out . log_view_escape(substr($text, $start));
}
$title = 'Logs';
print_html_prologue($title, $langCode, array('static/css/log-viewer.css?v=1'), array('static/js/log-viewer.js?v=2'));
print_title_and_help($title, '');
?>
<section class="log-viewer" aria-label="Log reader">
    <nav class="log-sources" aria-label="Log source">
    <?php foreach ($logSources as $key => $definition): ?>
        <a href="<?= log_view_escape(log_view_url(array('source' => $key, 'q' => $logFilters['q'], 'count' => $logFilters['count']))) ?>"<?= $key === $source ? ' class="active" aria-current="page"' : '' ?>><?= log_view_escape($definition['label']) ?></a>
    <?php endforeach; ?>
    </nav>
    <form class="log-search card" action="rep-logs.php" method="get">
        <input type="hidden" name="source" value="<?= log_view_escape($source) ?>">
        <div class="log-search-field"><label for="log-query">Search text</label><input id="log-query" class="form-control form-control-sm" type="search" name="q" value="<?= log_view_escape($logFilters['q']) ?>" maxlength="200" placeholder="Username, address or message"></div>
        <?php if ($logSources[$source]['types']): ?>
        <div><label for="log-type">Type</label><select id="log-type" class="form-select form-select-sm" name="type"><option value="">All types</option><?php foreach ($logSources[$source]['types'] as $type): ?><option value="<?= log_view_escape($type) ?>"<?= $logFilters['type'] === $type ? ' selected' : '' ?>><?= log_view_escape($type) ?></option><?php endforeach; ?></select></div>
        <?php endif; ?>
        <div><label for="log-count">Matching lines</label><select id="log-count" class="form-select form-select-sm" name="count"><?php $counts = array_unique(array(20, 50, 100, 500, 1000, (int) $logFilters['count'])); sort($counts); foreach ($counts as $count): ?><option value="<?= $count ?>"<?= (int) $logFilters['count'] === $count ? ' selected' : '' ?>><?= $count ?></option><?php endforeach; ?></select></div>
        <div class="log-search-actions"><button class="btn btn-secondary btn-sm" type="submit">Search</button><a class="btn btn-outline-secondary btn-sm" href="<?= log_view_escape(log_view_url(array('source' => $source))) ?>">Reset</a></div>
    </form>
    <div class="log-toolbar">
        <div class="log-toolbar-actions"><a id="log-refresh" class="btn btn-outline-secondary btn-sm" href="<?= log_view_escape(log_view_url($logFilters)) ?>">Refresh</a>
            <label class="log-wrap-control"><input id="log-wrap" type="checkbox" checked> Wrap lines</label>
            <button class="btn btn-outline-secondary btn-sm" id="log-copy" type="button" hidden>Copy excerpt</button>
        </div>
        <a id="log-download" class="btn btn-outline-secondary btn-sm" href="<?= log_view_escape(log_view_url($logFilters, array('format' => 'text'))) ?>"<?= $logResult['state'] !== 'ok' ? ' hidden' : '' ?>>Download filtered log</a>
    </div>
    <div id="log-output">
    <div class="log-metadata">
        <span>Read: <?= log_view_escape($logResult['read_at']) ?></span>
        <?php if ($logResult['path'] !== ''): ?><span>File: <code><?= log_view_escape($logResult['path']) ?></code></span><?php endif; ?>
        <?php if ($logResult['modified'] !== null): ?><span>File modified: <?= log_view_escape($logResult['modified']) ?></span><?php endif; ?>
    </div>
    <?php if ($logResult['state'] === 'ok'): ?>
        <p class="log-note"><?= (int) $logResult['matches'] ?> matching <?= $logResult['matches'] === 1 ? 'line' : 'lines' ?><?= $logResult['limited'] ? ' (latest selected matches)' : '' ?>. Displayed in file order, oldest to newest.<?= $logFilters['q'] !== '' || $logFilters['type'] !== '' ? ' Up to two surrounding lines are included for context.' : '' ?></p>
        <pre id="log-content" class="log-content" tabindex="0" aria-label="Filtered log excerpt"><?php foreach ($logResult['lines'] as $line): ?><span class="log-line<?= $line['context'] ? ' log-context' : '' ?>"><?= log_view_line($line['text'], $logFilters['q']) ?></span><?php endforeach; ?></pre>
    <?php else: ?>
        <div class="log-state" role="status"><?= log_view_escape($logMessages[$logResult['state']] ?? 'Log unavailable.') ?></div>
    <?php endif; ?>
    <?php if ($logResult['bounded']): ?><p class="log-note">Search is bounded to the last 2 MiB and at most 20,000 lines. Older content was not inspected; a partial first line is omitted.</p><?php endif; ?>
    <p class="log-note">Local files visible to the web server only. No journal or remote-container access is added by this reader.</p>
    </div>
    <span id="log-copy-status" class="log-note" role="status" aria-live="polite"></span>
</section>
<?php print_footer_and_html_epilogue();
