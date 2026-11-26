/* Unified hotspot map browser: live RADIUS activity, search/filtering, and inline location editing. */
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
    var onlineRings = new Map();
    var filtered = [];
    var page = 0;
    var pageSize = 30;
    var selectedId = null;
    var activeFilter = 'all';
    var activeSort = 'name';
    var validFilters = ['all', 'online', 'located', 'missing', 'invalid'];
    var stateOrder = {invalid: 0, missing: 1, located: 2};
    var map = null;
    var group = null;
    var selectionRing = null;
    var editMarker = null;
    var editingId = null;
    var timer = null;

    var list = document.getElementById('gis-list');
    var search = document.getElementById('gis-search');
    var filters = document.getElementById('gis-filters');
    var filterButtons = filters ? Array.from(filters.querySelectorAll('button[data-filter]')) : [];
    var sortSelect = document.getElementById('gis-sort');
    var fit = document.getElementById('gis-fit');
    var previous = document.getElementById('gis-prev');
    var next = document.getElementById('gis-next');
    var clearButton = document.getElementById('gis-clear-selection');
    var refreshLink = document.getElementById('gis-refresh');
    var toast = document.getElementById('gis-toast');
    var toastClose = document.getElementById('gis-toast-close');
    var warning = document.getElementById('gis-map-error');
    var mapElement = document.getElementById('map');

    var copyMacButton = document.getElementById('gis-copy-mac');
    var copyPositionButton = document.getElementById('gis-copy-position');

    var editBanner = document.getElementById('gis-edit-banner');
    var editBannerText = document.getElementById('gis-edit-banner-text');
    var editBannerCancel = document.getElementById('gis-edit-banner-cancel');
    var editLocationButton = document.getElementById('gis-edit-location-btn');
    var editLocationLabel = document.getElementById('gis-edit-location-label');
    var locationForm = document.getElementById('gis-location-editor');
    var formAction = document.getElementById('gis-form-action');
    var formHotspotId = document.getElementById('gis-form-hotspot-id');
    var geocodeInput = document.getElementById('gis-geocode-input');
    var geocodeError = document.getElementById('gis-geocode-error');
    var cancelLocationButton = document.getElementById('gis-cancel-location');
    var clearLocationButton = document.getElementById('gis-clear-location');

    function name(item) {
        return item.name || labels.unnamed + ' #' + item.id;
    }

    function mapWarning(message) {
        warning.textContent = message;
        warning.hidden = false;
    }

    function formatCoordinate(value) {
        var str = Number(value).toFixed(6).replace(/\.?0+$/, '');
        return str === '-0' ? '0' : str;
    }

    function formatCoordinates(lat, lng) {
        return formatCoordinate(lat) + ', ' + formatCoordinate(lng);
    }

    function parseCoordinates(value) {
        if (typeof value !== 'string') {
            return null;
        }
        var match = value.trim().match(/^(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)$/);
        if (!match) {
            return null;
        }
        var lat = parseFloat(match[1]);
        var lng = parseFloat(match[2]);
        if (!Number.isFinite(lat) || !Number.isFinite(lng) || Math.abs(lat) > 90 || Math.abs(lng) > 180) {
            return null;
        }
        return [lat, lng];
    }

    function matchesQuery(item, query) {
        if (!query) {
            return true;
        }
        return [item.name, item.mac, item.address, item.company, item.type, item.owner].some(function (field) {
            return field && field.toLocaleLowerCase().includes(query);
        });
    }

    function compareItems(a, b) {
        if (activeSort === 'online') {
            var diff = (b.activeSessions || 0) - (a.activeSessions || 0);
            if (diff !== 0) {
                return diff;
            }
        } else if (activeSort === 'state') {
            var orderA = Object.prototype.hasOwnProperty.call(stateOrder, a.state) ? stateOrder[a.state] : 9;
            var orderB = Object.prototype.hasOwnProperty.call(stateOrder, b.state) ? stateOrder[b.state] : 9;
            var orderDiff = orderA - orderB;
            if (orderDiff !== 0) {
                return orderDiff;
            }
        }
        var nameDiff = name(a).localeCompare(name(b), undefined, {sensitivity: 'base'});
        if (nameDiff !== 0) {
            return nameDiff;
        }
        return a.id.localeCompare(b.id);
    }

    function updateMarkerHighlight() {
        if (selectionRing && map) {
            map.removeLayer(selectionRing);
            selectionRing = null;
        }
        markers.forEach(function (marker, id) {
            var isSelected = selectedId !== null && id === selectedId;
            var isBeingEdited = editingId !== null && id === editingId;
            marker.setZIndexOffset(isSelected ? 1000 : 0);
            marker.setOpacity(isBeingEdited ? 0 : (selectedId === null || isSelected ? 1 : 0.55));
            if (!isSelected || isBeingEdited) {
                marker.closeTooltip();
            }
        });
        if (map && selectedId !== null && editingId !== selectedId && markers.has(selectedId)) {
            var selectedItem = byId.get(selectedId);
            if (selectedItem && selectedItem.coordinates) {
                selectionRing = L.circleMarker(selectedItem.coordinates, {
                    radius: 15,
                    color: '#0d6efd',
                    weight: 2,
                    fillColor: '#0d6efd',
                    fillOpacity: 0.16,
                    interactive: false
                }).addTo(map);
            }
            markers.get(selectedId).openTooltip();
        }
    }

    function syncUrl() {
        if (typeof window.URLSearchParams !== 'function') {
            return;
        }
        var params = new URLSearchParams(window.location.search);
        if (activeFilter === 'all') {
            params.delete('filter');
        } else {
            params.set('filter', activeFilter);
        }
        if (selectedId === null) {
            params.delete('hotspot');
        } else {
            params.set('hotspot', selectedId);
        }
        var queryString = params.toString();
        var nextHref = window.location.pathname + (queryString ? '?' + queryString : '');
        if (refreshLink) {
            refreshLink.href = nextHref;
        }
        if (window.history && typeof window.history.replaceState === 'function') {
            try {
                window.history.replaceState(null, '', nextHref + window.location.hash);
            } catch (error) {
                // Ignore history restrictions in sandboxed/non-standard contexts.
            }
        }
    }

    function setOptionalRow(rowId, valueId, value) {
        var row = document.getElementById(rowId);
        var field = document.getElementById(valueId);
        if (!row || !field) {
            return;
        }
        row.hidden = !value;
        field.textContent = value || '';
    }

    function setCopyButton(button, value) {
        if (!button) {
            return;
        }
        button.hidden = !value;
        button.dataset.copyValue = value || '';
    }

    function handleCopyClick(button) {
        var value = button.dataset.copyValue || '';
        if (!value) {
            return;
        }
        function showCopied() {
            var icon = button.querySelector('i');
            if (icon) {
                icon.className = 'bi bi-check-lg text-success';
            }
            button.title = labels.copied;
            button.setAttribute('aria-label', labels.copied);
            window.setTimeout(function () {
                if (icon) {
                    icon.className = 'bi bi-clipboard';
                }
                button.title = labels.copy;
                button.setAttribute('aria-label', labels.copy);
            }, 1500);
        }
        if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
            navigator.clipboard.writeText(value).then(showCopied).catch(function () {});
        } else {
            var helper = document.createElement('textarea');
            helper.value = value;
            helper.setAttribute('readonly', '');
            helper.style.position = 'fixed';
            helper.style.opacity = '0';
            document.body.appendChild(helper);
            helper.select();
            try {
                if (document.execCommand('copy')) {
                    showCopied();
                }
            } catch (error) {
                // Ignore clipboard failures.
            }
            document.body.removeChild(helper);
        }
    }

    function placeEditMarker(coords) {
        if (!map || !coords) {
            return;
        }
        if (editMarker) {
            editMarker.setLatLng(coords);
        } else {
            editMarker = L.marker(coords, {draggable: true, zIndexOffset: 1200}).addTo(map);
            editMarker.on('dragend', function () {
                var pos = editMarker.getLatLng();
                if (geocodeInput) {
                    geocodeInput.value = formatCoordinates(pos.lat, pos.lng);
                }
                if (geocodeError) {
                    geocodeError.hidden = true;
                }
            });
        }
    }

    function exitEditMode() {
        if (editingId === null) {
            return;
        }
        editingId = null;
        if (editMarker && map) {
            map.removeLayer(editMarker);
            editMarker = null;
        }
        if (mapElement) {
            mapElement.classList.remove('gis-map-picking');
        }
        if (editBanner) {
            editBanner.hidden = true;
        }
        if (locationForm) {
            locationForm.hidden = true;
        }
        if (geocodeError) {
            geocodeError.hidden = true;
        }
        if (editLocationButton) {
            editLocationButton.hidden = selectedId === null;
        }
        updateMarkerHighlight();
    }

    function enterEditMode() {
        if (!data.permissions.edit || selectedId === null || !locationForm) {
            return;
        }
        var item = byId.get(selectedId);
        if (!item) {
            return;
        }
        editingId = item.id;
        if (formAction) {
            formAction.value = 'set_location';
        }
        if (formHotspotId) {
            formHotspotId.value = item.id;
        }
        if (geocodeInput) {
            geocodeInput.value = item.coordinates ? formatCoordinates(item.coordinates[0], item.coordinates[1]) : (item.geocode ? item.geocode.trim() : '');
        }
        if (geocodeError) {
            geocodeError.hidden = true;
        }
        if (clearLocationButton) {
            clearLocationButton.hidden = item.state === 'missing';
        }
        if (editBanner && editBannerText) {
            editBannerText.textContent = labels.editBanner.replace('{name}', name(item));
            editBanner.hidden = false;
        }
        locationForm.hidden = false;
        if (editLocationButton) {
            editLocationButton.hidden = true;
        }
        if (mapElement) {
            mapElement.classList.add('gis-map-picking');
        }
        updateMarkerHighlight();
        if (map && item.coordinates) {
            placeEditMarker(item.coordinates);
            map.setView(item.coordinates, Math.max(map.getZoom(), 15), {animate: false});
        }
        if (geocodeInput && typeof geocodeInput.focus === 'function') {
            geocodeInput.focus();
        }
    }

    function clearSelection() {
        exitEditMode();
        selectedId = null;
        updateMarkerHighlight();
        document.getElementById('gis-selection-empty').hidden = false;
        document.getElementById('gis-selection').hidden = true;
        if (clearButton) {
            clearButton.hidden = true;
        }
    }

    function renderList(scrollSelected) {
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
            var badges = document.createElement('span');
            badges.className = 'gis-hotspot-badges';
            if (item.activeSessions > 0) {
                var onlineBadge = document.createElement('span');
                onlineBadge.className = 'badge text-bg-success gis-hotspot-online';
                onlineBadge.textContent = labels.onlineBadge.replace('{sessions}', item.activeSessions);
                badges.append(onlineBadge);
            }
            var state = document.createElement('span');
            state.className = 'gis-hotspot-state gis-state-' + item.state;
            state.textContent = labels[item.state];
            badges.append(state);
            button.append(title, identity, badges);
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
        } else if (scrollSelected && selectedId !== null) {
            Array.from(list.children).some(function (button) {
                if (button.dataset.id === selectedId) {
                    if (typeof button.scrollIntoView === 'function') {
                        button.scrollIntoView({block: 'nearest'});
                    }
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
        if (editingId !== null && editingId !== id) {
            exitEditMode();
        }
        selectedId = id;
        document.getElementById('gis-selection-empty').hidden = true;
        document.getElementById('gis-selection').hidden = false;
        if (clearButton) {
            clearButton.hidden = false;
        }
        var stateBadge = document.getElementById('gis-state-badge');
        if (stateBadge) {
            stateBadge.textContent = labels[item.state];
            stateBadge.className = 'badge ' + (item.state === 'located' ? 'text-bg-success' : (item.state === 'invalid' ? 'text-bg-danger' : 'text-bg-secondary'));
        }
        var onlineBadge = document.getElementById('gis-online-badge');
        if (onlineBadge) {
            onlineBadge.hidden = !(item.activeSessions > 0);
            onlineBadge.textContent = item.activeSessions > 0 ? labels.onlineBadge.replace('{sessions}', item.activeSessions) : '';
        }
        document.getElementById('gis-name').textContent = name(item);
        document.getElementById('gis-mac').textContent = item.mac || '\u2014';
        setCopyButton(copyMacButton, item.mac);

        var rawGeocode = item.geocode ? item.geocode.trim() : '';
        var formattedCoords = item.coordinates ? formatCoordinates(item.coordinates[0], item.coordinates[1]) : '';
        var positionText = formattedCoords
            ? formattedCoords
            : (item.state === 'invalid' && rawGeocode ? labels.invalid + ' (' + rawGeocode + ')' : labels[item.state]);
        document.getElementById('gis-position').textContent = positionText;
        setCopyButton(copyPositionButton, formattedCoords || (item.state === 'invalid' ? rawGeocode : ''));

        var activityText = item.activeSessions > 0
            ? labels.onlineSummary.replace('{sessions}', item.activeSessions).replace('{users}', item.activeUsers)
            : '';
        setOptionalRow('gis-activity-row', 'gis-activity', activityText);

        var heartbeatText = '';
        if (item.heartbeat) {
            heartbeatText = [item.heartbeat.time, item.heartbeat.wanIp, item.heartbeat.uptime].filter(Boolean).join(' · ');
        }
        setOptionalRow('gis-heartbeat-row', 'gis-heartbeat', heartbeatText);
        setOptionalRow('gis-type-row', 'gis-type', item.type);
        setOptionalRow('gis-company-row', 'gis-company', item.company);
        setOptionalRow('gis-address-row', 'gis-address', item.address);
        setOptionalRow('gis-owner-row', 'gis-owner', item.owner);

        var note = document.getElementById('gis-location-note');
        note.hidden = item.state === 'located';
        note.textContent = item.state === 'invalid' ? labels.invalidPosition : labels.noPosition;

        if (editLocationButton && editLocationLabel) {
            editLocationLabel.textContent = item.state === 'located' ? labels.moveLocation : labels.setLocation;
            editLocationButton.hidden = editingId === item.id;
        }
        var editHotspot = document.getElementById('gis-edit-hotspot');
        if (editHotspot) {
            editHotspot.hidden = !data.permissions.editHotspot || !item.name;
            editHotspot.href = 'mng-hs-edit.php?name=' + encodeURIComponent(item.name);
        }
        var onlineLink = document.getElementById('gis-online');
        if (onlineLink) {
            onlineLink.hidden = !data.permissions.online;
        }
        var accounting = document.getElementById('gis-accounting');
        accounting.hidden = !data.permissions.accounting || !item.name;
        accounting.href = 'acct-hotspot-accounting.php?hotspot[]=' + encodeURIComponent(item.name);
        document.getElementById('gis-compare').hidden = !data.permissions.compare;

        updateMarkerHighlight();
        if (map && item.coordinates && !fromMarker) {
            map.setView(item.coordinates, Math.max(map.getZoom(), 14), {animate: false});
        }
        var index = filtered.findIndex(function (candidate) { return candidate.id === id; });
        if (index >= 0) {
            page = Math.floor(index / pageSize);
        }
        renderList(fromMarker);
        syncUrl();
    }

    function updateFilterCounts(searchMatches) {
        var counts = {all: searchMatches.length, online: 0, located: 0, missing: 0, invalid: 0};
        searchMatches.forEach(function (item) {
            if (item.activeSessions > 0) {
                counts.online++;
            }
            if (Object.prototype.hasOwnProperty.call(counts, item.state)) {
                counts[item.state]++;
            }
        });
        filterButtons.forEach(function (button) {
            var key = button.dataset.filter;
            button.setAttribute('aria-pressed', key === activeFilter ? 'true' : 'false');
            var countElement = button.querySelector('.gis-filter-count');
            if (countElement && Object.prototype.hasOwnProperty.call(counts, key)) {
                countElement.textContent = '(' + counts[key] + ')';
            }
        });
    }

    function update() {
        var query = search.value.trim().toLocaleLowerCase();
        var searchMatches = items.filter(function (item) {
            return matchesQuery(item, query);
        });
        updateFilterCounts(searchMatches);
        filtered = searchMatches.filter(function (item) {
            if (activeFilter === 'all') {
                return true;
            }
            if (activeFilter === 'online') {
                return item.activeSessions > 0;
            }
            return item.state === activeFilter;
        }).sort(compareItems);
        page = 0;
        if (selectedId !== null && !filtered.some(function (item) { return item.id === selectedId; })) {
            clearSelection();
        }
        if (group) {
            group.clearLayers();
            filtered.forEach(function (item) {
                if (onlineRings.has(item.id)) {
                    group.addLayer(onlineRings.get(item.id));
                }
                if (markers.has(item.id)) {
                    group.addLayer(markers.get(item.id));
                }
            });
        }
        updateMarkerHighlight();
        fit.disabled = !map || !filtered.some(function (item) { return item.coordinates; });
        var located = filtered.filter(function (item) { return item.state === 'located'; }).length;
        var online = filtered.filter(function (item) { return item.activeSessions > 0; }).length;
        document.getElementById('gis-count').textContent = labels.results
            .replace('{count}', filtered.length)
            .replace('{total}', items.length)
            .replace('{located}', located)
            .replace('{online}', online);
        renderList(false);
        syncUrl();
    }

    function fitResults() {
        if (!map || !group || !group.getLayers().length) {
            return;
        }
        map.fitBounds(group.getBounds(), {padding: [24, 24], maxZoom: 16, animate: false});
    }

    function findInitialHotspot(target) {
        if (!target) {
            return null;
        }
        if (byId.has(target)) {
            return byId.get(target);
        }
        var lower = target.toLocaleLowerCase();
        return items.find(function (item) {
            return item.name && item.name.toLocaleLowerCase() === lower;
        }) || null;
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
        map.on('click', function (event) {
            if (editingId === null || !event.latlng) {
                return;
            }
            var coords = [event.latlng.lat, event.latlng.lng];
            placeEditMarker(coords);
            if (geocodeInput) {
                geocodeInput.value = formatCoordinates(coords[0], coords[1]);
            }
            if (geocodeError) {
                geocodeError.hidden = true;
            }
        });
        items.forEach(function (item) {
            if (!item.coordinates) {
                return;
            }
            var tooltip = document.createElement('span');
            tooltip.textContent = item.activeSessions > 0
                ? name(item) + ' · ' + labels.onlineBadge.replace('{sessions}', item.activeSessions)
                : name(item);
            var marker = L.marker(item.coordinates, {title: name(item)}).bindTooltip(tooltip);
            marker.on('click', function () {
                if (editingId !== null) {
                    return;
                }
                select(item.id, true);
            });
            markers.set(item.id, marker);
            if (item.activeSessions > 0) {
                var ring = L.circleMarker(item.coordinates, {
                    radius: 10,
                    color: '#198754',
                    weight: 2,
                    fillColor: '#198754',
                    fillOpacity: 0.22,
                    interactive: false
                });
                onlineRings.set(item.id, ring);
            }
        });
        daloObserveMapResize(map);
    } catch (error) {
        if (map) {
            map.remove();
        }
        map = null;
        group = null;
        mapWarning(labels.mapError);
        if (mapElement) {
            mapElement.hidden = true;
        }
    }

    var initialParams = typeof window.URLSearchParams === 'function' ? new URLSearchParams(window.location.search) : null;
    if (initialParams) {
        var requestedFilter = initialParams.get('filter');
        if (requestedFilter && validFilters.indexOf(requestedFilter) !== -1) {
            activeFilter = requestedFilter;
        }
    }

    search.disabled = false;
    filterButtons.forEach(function (button) { button.disabled = false; });
    if (sortSelect) {
        sortSelect.disabled = false;
        sortSelect.addEventListener('change', function () {
            activeSort = sortSelect.value;
            update();
        });
    }
    search.addEventListener('input', function () {
        window.clearTimeout(timer);
        timer = window.setTimeout(update, 120);
    });
    if (filters) {
        filters.addEventListener('click', function (event) {
            var button = event.target.closest('button[data-filter]');
            if (button && filters.contains(button) && button.dataset.filter !== activeFilter) {
                activeFilter = button.dataset.filter;
                update();
            }
        });
    }
    list.addEventListener('click', function (event) {
        var button = event.target.closest('button[data-id]');
        if (button && list.contains(button)) {
            select(button.dataset.id, false);
        }
    });
    if (copyMacButton) {
        copyMacButton.addEventListener('click', function () { handleCopyClick(copyMacButton); });
    }
    if (copyPositionButton) {
        copyPositionButton.addEventListener('click', function () { handleCopyClick(copyPositionButton); });
    }
    if (clearButton) {
        clearButton.addEventListener('click', function () {
            clearSelection();
            renderList(false);
            syncUrl();
        });
    }
    if (editLocationButton) {
        editLocationButton.addEventListener('click', enterEditMode);
    }
    if (cancelLocationButton) {
        cancelLocationButton.addEventListener('click', exitEditMode);
    }
    if (editBannerCancel) {
        editBannerCancel.addEventListener('click', exitEditMode);
    }
    if (geocodeInput) {
        geocodeInput.addEventListener('input', function () {
            var coords = parseCoordinates(geocodeInput.value);
            if (coords) {
                if (geocodeError) {
                    geocodeError.hidden = true;
                }
                if (map) {
                    placeEditMarker(coords);
                    map.panTo(coords, {animate: false});
                }
            }
        });
    }
    if (locationForm) {
        locationForm.addEventListener('submit', function (event) {
            if (formAction && formAction.value === 'clear_location') {
                return;
            }
            var coords = parseCoordinates(geocodeInput ? geocodeInput.value : '');
            if (!coords) {
                event.preventDefault();
                if (geocodeError) {
                    geocodeError.textContent = labels.invalidInput;
                    geocodeError.hidden = false;
                }
                return;
            }
            if (geocodeInput) {
                geocodeInput.value = formatCoordinates(coords[0], coords[1]);
            }
        });
    }
    if (clearLocationButton && locationForm) {
        clearLocationButton.addEventListener('click', function () {
            if (!window.confirm(labels.confirmClear)) {
                return;
            }
            if (formAction) {
                formAction.value = 'clear_location';
            }
            locationForm.submit();
        });
    }
    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') {
            return;
        }
        if (editingId !== null) {
            exitEditMode();
            return;
        }
        if (selectedId !== null && document.activeElement !== search) {
            clearSelection();
            renderList(false);
            syncUrl();
        }
    });
    previous.addEventListener('click', function () { page--; renderList(false); });
    next.addEventListener('click', function () { page++; renderList(false); });
    fit.addEventListener('click', fitResults);

    if (toast) {
        var toastTimer = null;
        var dismissToast = function () {
            if (toastTimer !== null) {
                window.clearTimeout(toastTimer);
                toastTimer = null;
            }
            toast.classList.add('gis-toast-fading');
            window.setTimeout(function () {
                toast.hidden = true;
            }, 260);
        };
        if (toastClose) {
            toastClose.addEventListener('click', dismissToast);
        }
        var dismissDelay = parseInt(toast.dataset.autoDismiss || '0', 10);
        if (dismissDelay > 0) {
            toastTimer = window.setTimeout(dismissToast, dismissDelay);
        }
    }

    update();
    var initialHotspot = initialParams ? findInitialHotspot(initialParams.get('hotspot')) : null;
    if (initialHotspot && filtered.some(function (item) { return item.id === initialHotspot.id; })) {
        select(initialHotspot.id, false);
        renderList(true);
    } else {
        fitResults();
    }
}());
