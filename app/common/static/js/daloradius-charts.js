(function () {
    if (window.daloRadiusCharts) {
        return;
    }
    window.daloRadiusCharts = true;
    var charts = [];

    function applyAppearance(chart) {
        var style = getComputedStyle(document.body);
        var color = style.getPropertyValue('--dalo-muted').trim() || '#666';
        var grid = style.getPropertyValue('--dalo-border').trim() || 'rgba(0, 0, 0, 0.1)';
        chart.options.color = color;
        var plugins = chart.options.plugins || {};
        if (plugins.legend && plugins.legend.labels) {
            plugins.legend.labels.color = color;
        }
        ['title', 'subtitle'].forEach(function (key) {
            if (plugins[key]) {
                plugins[key].color = color;
            }
        });
        Object.keys(chart.options.scales || {}).forEach(function (key) {
            var scale = chart.options.scales[key];
            if (scale.ticks) { scale.ticks.color = color; }
            if (scale.title) { scale.title.color = color; }
            if (scale.grid) { scale.grid.color = grid; }
            if (scale.border) { scale.border.color = grid; }
        });
        chart.update('none');
    }

    document.addEventListener('daloradius:themechange', function () {
        charts.forEach(function (chart) {
            if (chart.canvas && chart.canvas.isConnected) {
                applyAppearance(chart);
            }
        });
    });

    function showError(canvas) {
        var message = document.createElement('p');
        message.className = 'text-danger';
        message.textContent = 'Unable to load chart data.';
        canvas.parentNode.replaceChild(message, canvas);
    }

    function render(canvas) {
        fetch(canvas.dataset.chartSource, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' }
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Chart request failed');
                }
                return response.json();
            })
            .then(function (config) {
                var chart = new Chart(canvas.getContext('2d'), config);
                applyAppearance(chart);
                charts.push(chart);
            })
            .catch(function () {
                showError(canvas);
            });
    }

    function initializeCharts() {
        document.querySelectorAll('canvas[data-chart-source]').forEach(render);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeCharts);
    } else {
        initializeCharts();
    }
}());
