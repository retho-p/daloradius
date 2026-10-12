(() => {
    'use strict';

    const rowSelector = 'tbody input[type="checkbox"][name="username[]"]';
    const selectedRows = form => Array.from(form.querySelectorAll(rowSelector))
        .filter(input => !input.disabled && input.checked);

    // The legacy delete helper counts every checkbox, including a header toggle.
    // Count only actual user rows without changing shared behavior elsewhere.
    window.usersListRemoveSelected = () => {
        const form = document.getElementById('listall');
        if (!form) return false;
        const count = selectedRows(form).length;
        if (!count) {
            alert('No items selected');
            return false;
        }
        if (confirm(`You are about to remove ${count} records from database\nDo you want to continue?`)) {
            form.action = 'mng-del.php';
            form.submit();
        }
        return false;
    };

    const initSelection = () => {
        const form = document.getElementById('listall');
        const master = document.getElementById('users-list-select-all');
        if (!form || !master) return;
        const rows = () => Array.from(form.querySelectorAll(rowSelector))
            .filter(input => !input.disabled);
        const sync = () => {
            const inputs = rows();
            const count = inputs.filter(input => input.checked).length;
            master.checked = inputs.length > 0 && count === inputs.length;
            master.indeterminate = count > 0 && count < inputs.length;
            master.disabled = inputs.length === 0;
        };
        master.addEventListener('change', () => {
            rows().forEach(input => { input.checked = master.checked; });
            sync();
        });
        form.addEventListener('change', event => {
            if (event.target.matches(rowSelector)) sync();
        });
        window.addEventListener('pageshow', sync);
        sync();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSelection, { once: true });
    } else {
        initSelection();
    }
})();
