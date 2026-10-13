/* Operator-only appearance preference. Runs before CSS to avoid a light flash. */
(function () {
    'use strict';
    var key = 'daloradius.operator.theme';
    var choices = ['light', 'night', 'auto'];
    var root = document.documentElement;
    var system = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
    var preference = 'light';

    function valid(value) {
        return choices.indexOf(value) !== -1;
    }

    try {
        var saved = window.localStorage.getItem(key);
        if (valid(saved)) {
            preference = saved;
        }
    } catch (error) {
        // Restricted storage must not prevent switching themes in this page.
    }

    function updateChoices() {
        document.querySelectorAll('button[data-operator-theme]').forEach(function (button) {
            button.setAttribute('aria-pressed', String(button.dataset.operatorTheme === preference));
        });
    }

    function apply() {
        var night = preference === 'night' || (preference === 'auto' && system && system.matches);
        root.setAttribute('data-bs-theme', night ? 'dark' : 'light');
        root.setAttribute('data-operator-theme', preference);
        updateChoices();
        document.dispatchEvent(new CustomEvent('daloradius:themechange', {
            detail: { preference: preference, theme: night ? 'dark' : 'light' }
        }));
    }

    apply();
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', updateChoices);
    } else {
        updateChoices();
    }

    document.addEventListener('click', function (event) {
        var button = event.target instanceof Element ? event.target.closest('button[data-operator-theme]') : null;
        if (!button || !valid(button.dataset.operatorTheme)) {
            return;
        }
        preference = button.dataset.operatorTheme;
        try {
            window.localStorage.setItem(key, preference);
        } catch (error) {
            // Keep the in-memory choice even if persistence is unavailable.
        }
        apply();
    });

    function systemChanged() {
        if (preference === 'auto') {
            apply();
        }
    }
    if (system && system.addEventListener) {
        system.addEventListener('change', systemChanged);
    } else if (system && system.addListener) {
        system.addListener(systemChanged);
    }

    window.addEventListener('storage', function (event) {
        if (event.key === key || event.key === null) {
            preference = valid(event.newValue) ? event.newValue : 'light';
            apply();
        }
    });
}());
