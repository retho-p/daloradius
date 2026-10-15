'use strict';
document.addEventListener('DOMContentLoaded', function () {
    const wrap = document.querySelector('#log-wrap');
    const copy = document.querySelector('#log-copy');
    const status = document.querySelector('#log-copy-status');
    const refresh = document.querySelector('#log-refresh');
    const download = document.querySelector('#log-download');
    if (!wrap || !copy || !status || !refresh || !download) return;
    function updateControls() {
        const log = document.querySelector('#log-content');
        wrap.disabled = !log;
        copy.hidden = !log;
        download.hidden = !log;
        if (log) log.classList.toggle('no-wrap', !wrap.checked);
    }
    wrap.addEventListener('change', updateControls);
    updateControls();
    let refreshing = false;
    refresh.addEventListener('click', async function (event) {
        event.preventDefault();
        if (refreshing) return;
        refreshing = true;
        refresh.setAttribute('aria-busy', 'true');
        const controller = new AbortController();
        const timeout = window.setTimeout(function () { controller.abort(); }, 15000);
        status.textContent = 'Refreshing…';
        try {
            const response = await fetch(refresh.href, {cache: 'no-store', credentials: 'same-origin', signal: controller.signal});
            if (!response.ok) throw new Error('Refresh failed');
            const next = new DOMParser().parseFromString(await response.text(), 'text/html');
            const output = next.querySelector('#log-output');
            if (!output) throw new Error('Session expired or log unavailable');
            // Save the current position immediately before replacing the excerpt, not when the request started.
            const oldLog = document.querySelector('#log-content');
            const position = oldLog ? {top: oldLog.scrollTop, left: oldLog.scrollLeft} : null;
            const pagePosition = {top: window.scrollY, left: window.scrollX};
            document.querySelector('#log-output').replaceWith(output);
            updateControls();
            const newLog = document.querySelector('#log-content');
            if (newLog && position) { newLog.scrollTop = position.top; newLog.scrollLeft = position.left; }
            window.scrollTo(pagePosition.left, pagePosition.top);
            status.textContent = 'Log refreshed.';
        } catch (_) {
            status.textContent = 'Refresh failed. Reload the page to sign in or try again.';
        } finally {
            window.clearTimeout(timeout);
            refreshing = false;
            refresh.removeAttribute('aria-busy');
        }
    });
    copy.addEventListener('click', async function () {
        const log = document.querySelector('#log-content');
        if (!log) return;
        const text = Array.from(log.querySelectorAll('.log-line')).map(function (line) { return line.textContent; }).join('\n') + '\n';
        let copied = false;
        if (navigator.clipboard && window.isSecureContext) {
            try { await navigator.clipboard.writeText(text); copied = true; } catch (_) { /* Use the selection fallback below. */ }
        }
        if (!copied) {
            const input = document.createElement('textarea');
            input.value = text;
            input.style.position = 'fixed';
            input.style.left = '-9999px';
            document.body.appendChild(input);
            input.select();
            try { copied = document.execCommand('copy'); } catch (_) { copied = false; }
            input.remove();
            copy.focus({preventScroll: true});
        }
        status.textContent = copied ? 'Excerpt copied.' : 'Copy is unavailable. Select the text or download the excerpt instead.';
    });
});
