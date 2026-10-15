'use strict';
document.addEventListener('DOMContentLoaded', function () {
    const form = document.querySelector('.activity-search');
    if (!form) return;
    const preset = form.querySelector('#activity-period');
    const start = form.querySelector('#activity-start');
    const end = form.querySelector('#activity-end');
    if (!preset || !start || !end) return;
    let ranges;
    try { ranges = JSON.parse(form.dataset.activityRanges); } catch (_) { return; }
    preset.parentElement.hidden = false;
    preset.addEventListener('change', function () {
        const dates = ranges[preset.value];
        if (!Array.isArray(dates) || dates.length !== 2) return;
        start.value = dates[0];
        end.value = dates[1];
    });
    [start, end].forEach(function (input) {
        input.addEventListener('change', function () { preset.value = 'custom'; });
    });
});
