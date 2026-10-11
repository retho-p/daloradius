/* Read-only hotspot inventory, synchronized with the Leaflet map. */
(function () {
    'use strict';
    var dataElement = document.getElementById('gis-data');
    if (!dataElement) {
        return;
    }
    var data = JSON.parse(dataElement.textContent);
    var labels = data.labels;
    var items = data.items;
    var byId = new Map();
    var markers = new Map();
    var filtered = [];
    var page = 0;
    var pageSize = 30;
    var selectedId = null;
    var map = null;
    var group = null;
    var timer = null;
    var list = document.getElementById('gis-list');
    var search = document.getElementById('gis-search');
    var filter = document.getElementById('gis-filter');
    var fit = document.getElementById('gis-fit');
    var previous = document.getElementById('gis-prev');
    var next = document.getElementById('gis-next');
    var warning = document.getElementById('gis-map-error');

    function name(item) {
        return item.name || labels.unnamed + ' #' + item.id;
    }

    function mapWarning(message) {
        warning.textContent = message;
        warning.hidden = false;
    }

    function clearSelection() {
        if (selectedId !== null && markers.has(selectedId)) {
            markers.get(selectedId).setZIndexOffset(0).setOpacity(1);
        }
        selectedId = null;
        document.getElementById('gis-selection-empty').hidden = false;
        document.getElementById('gis-selection').hidden = true;
    }

    function renderList() {
        var focusedId = list.contains(document.activeElement) ? document.activeElement.dataset.id : null;
        list.replaceChildren();
        var fragment = document.createDocumentFragment();
        filtered.slice(page * pageSize, (page + 1) * pageSize).forEach(function (item) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'gis-hotspot-item';
            button.dataset.id = item.id;
            button.setAttribute('aria-pressed', item.id === selectedId ? 'true' : 'false');
            var title = document.createElement('span');
            title.className = 'gis-hotspot-name';
            title.textContent = name(item);
            var identity = document.createElement('span');
            identity.className = 'gis-hotspot-meta';
            identity.textContent = item.mac || '\u2014';
            var state = document.createElement('span');
            state.className = 'gis-hotspot-state gis-state-' + item.state;
            state.textContent = labels[item.state];
            button.append(title, identity, state);
            fragment.append(button);
        });
        list.append(fragment);
        if (focusedId) {
            Array.from(list.children).some(function (button) {
                if (button.dataset.id === focusedId) {
                    button.focus({preventScroll: true});
                    return true;
                }
                return false;
            });
        }
        var empty = document.getElementById('gis-empty');
        empty.hidden = filtered.length !== 0;
        empty.textContent = items.length === 0 ? labels.empty : labels.noResults;
        previous.disabled = page === 0;
        next.disabled = (page + 1) * pageSize >= filtered.length;
        document.getElementById('gis-page').textContent = filtered.length ? (page + 1) + ' / ' + Math.ceil(filtered.length / pageSize) : '';
    }

    function select(id, fromMarker) {
        var item = byId.get(id);
        if (!item) {
            return;
        }
        clearSelection();
        selectedId = id;
        document.getElementById('gis-selection-empty').hidden = true;
        document.getElementById('gis-selection').hidden = false;
        document.getElementById('gis-name').textContent = name(item);
        document.getElementById('gis-mac').textContent = item.mac || '\u2014';
        document.getElementById('gis-position').textContent = item.coordinates ? item.coordinates.join(', ') : labels[item.state];
        var note = document.getElementById('gis-location-note');
        note.hidden = item.state === 'located';
        note.textContent = item.state === 'invalid' ? labels.invalidPosition : labels.noPosition;
        var accounting = document.getElementById('gis-accounting');
        accounting.hidden = !data.permissions.accounting || !item.name;
        accounting.href = 'acct-hotspot-accounting.php?hotspot[]=' + encodeURIComponent(item.name);
        document.getElementById('gis-compare').hidden = !data.permissions.compare;
        if (map && item.coordinates) {
            markers.get(id).setZIndexOffset(1000).setOpacity(0.8);
            if (!fromMarker) {
                map.panTo(item.coordinates, {animate: false});
            }
        }
        var index = filtered.findIndex(function (candidate) { return candidate.id === id; });
        if (index >= 0) {
            page = Math.floor(index / pageSize);
        }
        renderList();
    }

    function update() {
        var query = search.value.trim().toLocaleLowerCase();
        filtered = items.filter(function (item) {
            return (filter.value === 'all' || item.state === filter.value) &&
                (!query || item.name.toLocaleLowerCase().includes(query) || item.mac.toLocaleLowerCase().includes(query));
        });
        page = 0;
        if (selectedId !== null && !filtered.some(function (item) { return item.id === selectedId; })) {
            clearSelection();
        }
        if (group) {
            group.clearLayers();
            filtered.forEach(function (item) {
                if (markers.has(item.id)) {
                    group.addLayer(markers.get(item.id));
                }
            });
        }
        fit.disabled = !map || !filtered.some(function (item) { return item.coordinates; });
        var located = filtered.filter(function (item) { return item.state === 'located'; }).length;
        document.getElementById('gis-count').textContent = labels.results
            .replace('{count}', filtered.length).replace('{total}', items.length).replace('{located}', located);
        renderList();
    }

    function fitResults() {
        if (!map || !group || !group.getLayers().length) {
            return;
        }
        map.fitBounds(group.getBounds(), {padding: [24, 24], maxZoom: 16, animate: false});
    }

    items.forEach(function (item) { byId.set(item.id, item); });
    try {
        if (!window.L || typeof window.daloObserveMapResize !== 'function') {
            throw new Error('Map library unavailable');
        }
        map = L.map('map', {trackResize: false}).setView([20, 0], 2);
        group = L.featureGroup().addTo(map);
        var tiles = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
            subdomains: 'abc',
            maxZoom: 19
        }).addTo(map);
        tiles.on('tileerror', function () { mapWarning(labels.tileError); });
        items.forEach(function (item) {
            if (!item.coordinates) {
                return;
            }
            var tooltip = document.createElement('span');
            tooltip.textContent = name(item);
            var marker = L.marker(item.coordinates, {title: name(item)}).bindTooltip(tooltip);
            marker.on('click', function () { select(item.id, true); });
            markers.set(item.id, marker);
        });
        daloObserveMapResize(map);
    } catch (error) {
        if (map) {
            map.remove();
        }
        map = null;
        group = null;
        mapWarning(labels.mapError);
        document.getElementById('map').hidden = true;
    }

    search.disabled = false;
    filter.disabled = false;
    search.addEventListener('input', function () {
        window.clearTimeout(timer);
        timer = window.setTimeout(update, 120);
    });
    filter.addEventListener('change', update);
    list.addEventListener('click', function (event) {
        var button = event.target.closest('button[data-id]');
        if (button && list.contains(button)) {
            select(button.dataset.id, false);
        }
    });
    previous.addEventListener('click', function () { page--; renderList(); });
    next.addEventListener('click', function () { page++; renderList(); });
    fit.addEventListener('click', fitResults);
    update();
    fitResults();
}());
