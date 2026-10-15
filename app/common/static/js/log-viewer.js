'use strict';
document.addEventListener('DOMContentLoaded', function () {
    const log = document.querySelector('#log-content');
    const wrap = document.querySelector('#log-wrap');
    const copy = document.querySelector('#log-copy');
    const status = document.querySelector('#log-copy-status');
    if (!log || !wrap || !copy || !status) return;
    wrap.addEventListener('change', function () { log.classList.toggle('no-wrap', !wrap.checked); });
    copy.hidden = false;
    copy.addEventListener('click', async function () {
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
