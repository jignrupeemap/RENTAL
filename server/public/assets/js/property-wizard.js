// Posting form helpers. Every step also works without JavaScript; this only
// filters choices, keeps the map pin and the coordinates box in sync, and
// asks before deleting a draft.
(function () {
    'use strict';

    // Confirming destructive buttons (data-confirm) lives in app.js.

    // ---- Step 1: show only the sub-types that fit purpose + category ------
    var typeForm = document.getElementById('typeForm');
    if (typeForm) {
        var catGroup = typeForm.querySelector('[data-category-group]');
        var pgNote = typeForm.querySelector('[data-pg-note]');
        var syncTypes = function () {
            var purpose = (typeForm.querySelector('input[name="purpose"]:checked') || {}).value;
            var isPg = purpose === 'pg';
            var cat = typeForm.querySelector('input[name="category_id"]:checked');
            if (isPg) {
                // PG: Flat or Bungalow only, which sit under Residential
                var res = typeForm.querySelector('input[name="category_id"][data-code="residential"]');
                if (res) { res.checked = true; cat = res; }
            }
            catGroup.hidden = isPg;
            if (pgNote) pgNote.hidden = !isPg;
            typeForm.querySelectorAll('.type-group').forEach(function (g) {
                var show = cat && g.getAttribute('data-category') === cat.value;
                g.hidden = !show;
                g.querySelector('.type-group-title').hidden = true;
                g.querySelectorAll('input[name="type_id"]').forEach(function (r) {
                    var ok = show && (!isPg || r.getAttribute('data-pg') === '1');
                    r.disabled = !ok;
                    r.nextElementSibling.hidden = !ok;
                    if (!ok && r.checked) r.checked = false;
                });
            });
        };
        typeForm.addEventListener('change', function (e) {
            if (e.target.name === 'purpose' || e.target.name === 'category_id') syncTypes();
        });
        syncTypes();
    }

    // ---- Step 3: localities follow the city; "not listed" shows a text box --
    var city = document.getElementById('f_city_id');
    var locality = document.getElementById('f_locality_id');
    if (city && locality) {
        var otherBox = document.querySelector('[data-locality-other]');
        var pincode = document.getElementById('f_pincode');
        var syncLocalities = function () {
            locality.querySelectorAll('optgroup').forEach(function (g) {
                var show = g.getAttribute('data-city') === city.value;
                g.hidden = !show;
                g.disabled = !show;
                if (!show && g.contains(locality.selectedOptions[0])) locality.value = '';
            });
            otherBox.hidden = locality.value !== 'other';
        };
        city.addEventListener('change', syncLocalities);
        locality.addEventListener('change', function () {
            syncLocalities();
            var opt = locality.selectedOptions[0];
            if (pincode && pincode.value === '' && opt && opt.dataset.pincode) pincode.value = opt.dataset.pincode;
            if (locality.value === 'other') document.getElementById('f_locality_name').focus();
        });
        syncLocalities();
    }

    // ---- Step 6: uploads with progress; photos are shrunk before sending --
    var mb = function (b) { return (Math.round(b / 104857.6) / 10) + ' MB'; };
    var shrink = function (file, maxEdge) {
        if (!maxEdge || !window.createImageBitmap || !/^image\/(jpeg|png|webp)$/.test(file.type)) return Promise.resolve(file);
        return createImageBitmap(file, { imageOrientation: 'from-image' }).then(function (bmp) {
            var scale = Math.min(1, maxEdge / Math.max(bmp.width, bmp.height));
            if (scale === 1 && file.size < 1572864) { bmp.close && bmp.close(); return file; }
            var c = document.createElement('canvas');
            c.width = Math.round(bmp.width * scale);
            c.height = Math.round(bmp.height * scale);
            var ctx = c.getContext('2d');
            ctx.fillStyle = '#fff';
            ctx.fillRect(0, 0, c.width, c.height);
            ctx.drawImage(bmp, 0, 0, c.width, c.height);
            bmp.close && bmp.close();
            return new Promise(function (res) {
                c.toBlob(function (blob) { res(blob && blob.size < file.size ? blob : file); }, 'image/jpeg', 0.88);
            });
        }).catch(function () { return file; });
    };
    document.querySelectorAll('form[data-upload]').forEach(function (form) {
        var kind = form.getAttribute('data-upload');
        var max = parseInt(form.getAttribute('data-max-bytes'), 10) || Infinity;
        var resize = parseInt(form.getAttribute('data-resize'), 10) || 0;
        var input = form.querySelector('input[type=file]');
        var list = form.querySelector('.upload-progress');
        var token = form.querySelector('input[name=_token]').value;
        form.addEventListener('submit', function (e) {
            if (!window.FormData || !window.XMLHttpRequest || !input.files.length) return;
            e.preventDefault();
            e.stopImmediatePropagation();
            var files = Array.prototype.slice.call(input.files);
            var room = parseInt(form.getAttribute('data-remaining'), 10);
            list.innerHTML = '';
            if (!isNaN(room) && files.length > room) {
                var note = document.createElement('li');
                note.className = 'text-warning-emphasis';
                note.textContent = 'Only ' + room + ' more photo(s) fit in this listing; the rest were skipped.';
                list.appendChild(note);
                files = files.slice(0, room);
            }
            var btn = form.querySelector('button[type=submit]');
            btn.disabled = true; input.disabled = true;
            var ok = 0, failed = 0;
            var row = function (name) {
                var li = document.createElement('li');
                li.innerHTML = '<span class="name"></span><span class="bar"><span></span></span><span class="state text-muted">Waiting</span>';
                li.querySelector('.name').textContent = name;
                list.appendChild(li);
                return {
                    progress: function (p) { li.querySelector('.bar > span').style.width = p + '%'; li.querySelector('.state').textContent = p < 100 ? p + '%' : 'Processing…'; },
                    done: function () { li.querySelector('.bar > span').style.width = '100%'; var s = li.querySelector('.state'); s.textContent = 'Uploaded'; s.className = 'state text-success'; },
                    fail: function (msg) { var s = li.querySelector('.state'); s.textContent = msg; s.className = 'state text-danger'; li.querySelector('.bar').hidden = true; }
                };
            };
            var send = function (blob, name, ui) {
                return new Promise(function (resolve) {
                    var fd = new FormData();
                    fd.append('_token', token);
                    fd.append(kind === 'photos' ? 'photos[]' : 'video', blob, name);
                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', form.action);
                    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                    xhr.setRequestHeader('Accept', 'application/json');
                    xhr.upload.onprogress = function (ev) { if (ev.lengthComputable) ui.progress(Math.round(ev.loaded / ev.total * 100)); };
                    xhr.onload = function () {
                        var r = null;
                        try { r = JSON.parse(xhr.responseText); } catch (err) { /* not JSON */ }
                        resolve(r || { ok: false, errors: [xhr.status === 413 ? 'Too big for the server.' : 'Upload failed (' + xhr.status + ').'] });
                    };
                    xhr.onerror = function () { resolve({ ok: false, errors: ['Network error. Please try again.'] }); };
                    xhr.send(fd);
                });
            };
            var finish = function () {
                if (ok && !failed) { window.location.reload(); return; }
                btn.disabled = false; input.disabled = false; input.value = '';
                if (ok) {
                    var li = document.createElement('li');
                    li.innerHTML = '<button type="button" class="btn btn-sm btn-primary mt-1">Show uploaded</button>';
                    li.querySelector('button').addEventListener('click', function () { window.location.reload(); });
                    list.appendChild(li);
                }
            };
            var next = function (i) {
                if (i >= files.length) { finish(); return; }
                var f = files[i];
                var ui = row(f.name);
                (kind === 'photos' ? shrink(f, resize) : Promise.resolve(f)).then(function (blob) {
                    if (blob.size > max) { ui.fail('Too big (limit ' + mb(max) + ')'); failed++; next(i + 1); return; }
                    var name = blob === f ? f.name : f.name.replace(/\.[^.]+$/, '') + '.jpg';
                    send(blob, name, ui).then(function (r) {
                        if (r.ok) { ok++; ui.done(); } else { failed++; ui.fail((r.errors || []).join(' ') || 'Upload failed.'); }
                        next(i + 1);
                    });
                });
            };
            next(0);
        });
    });

    // ---- Step 3: map pin -------------------------------------------------
    // Search a place (fills the address boxes), click the map or drag the pin.
    // The "coordinates" box is what the server reads; the map only keeps it
    // in sync, so the step still works if Google Maps can't load.
    var block = document.querySelector('.map-block');
    if (!block) return;
    var coords = document.getElementById('f_coordinates');
    var addr = block.querySelector('[data-formatted-address]');
    var placeId = block.querySelector('[data-place-id]');
    var map = null, pin = null, markerLib = null;

    var parse = function (v) {
        var m = /^\s*(-?\d{1,2}(?:\.\d+)?)\s*[, ]\s*(-?\d{1,3}(?:\.\d+)?)\s*$/.exec(v || '');
        return m ? { lat: parseFloat(m[1]), lng: parseFloat(m[2]) } : null;
    };
    var setCoords = function (lat, lng) {
        coords.value = lat.toFixed(7).replace(/0+$/, '').replace(/\.$/, '') + ', ' + lng.toFixed(7).replace(/0+$/, '').replace(/\.$/, '');
        coords.classList.remove('is-invalid');
    };
    // A pin moved by hand no longer matches the searched place.
    var pinMoved = function (lat, lng) { setCoords(lat, lng); addr.value = ''; placeId.value = ''; };
    var moveTo = function (ll, zoom) {
        if (!map) return;
        if (pin) pin.set(ll); else pin = makePin(markerLib, ll, pinMoved);
        map.panTo(ll);
        if (zoom) map.setZoom(zoom);
    };

    block.querySelector('[data-use-my-location]').addEventListener('click', function (e) {
        var btn = e.currentTarget;
        if (!navigator.geolocation) { btn.textContent = 'Not available on this device'; return; }
        btn.disabled = true;
        btn.textContent = 'Finding you…';
        navigator.geolocation.getCurrentPosition(function (pos) {
            pinMoved(pos.coords.latitude, pos.coords.longitude);
            moveTo({ lat: pos.coords.latitude, lng: pos.coords.longitude }, 18);
            btn.disabled = false;
            btn.textContent = 'Use my current location';
        }, function () {
            btn.disabled = false;
            btn.textContent = 'Location blocked: please allow it or type it';
        }, { enableHighAccuracy: true, timeout: 15000 });
    });

    // Fill the empty address boxes (and pick city / locality) from a searched place.
    var norm = function (s) { return String(s || '').toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim(); };
    var sameName = function (a, b) {
        a = norm(a); b = norm(b);
        return a !== '' && b !== '' && (a === b || a.indexOf(b) === 0 || b.indexOf(a) === 0);
    };
    var fillEmpty = function (id, value) {
        var el = document.getElementById(id);
        if (el && value && el.value.trim() === '') el.value = value;
    };
    var fillAddress = function (place) {
        var parts = place.addressComponents || [];
        var get = function (type) {
            for (var i = 0; i < parts.length; i++) {
                if ((parts[i].types || []).indexOf(type) !== -1) return parts[i].longText || '';
            }
            return '';
        };
        var cityEl = document.getElementById('f_city_id');
        var locEl = document.getElementById('f_locality_id');
        var pinEl = document.getElementById('f_pincode');

        var postal = get('postal_code');
        if (pinEl && /^[1-9]\d{5}$/.test(postal)) pinEl.value = postal;

        var cityName = get('locality') || get('administrative_area_level_3') || get('administrative_area_level_2');
        var area = get('sublocality_level_1') || get('sublocality') || get('neighborhood');
        if (cityEl && cityName) {
            var cityOpt = Array.prototype.find.call(cityEl.options, function (o) { return o.value !== '' && sameName(o.text, cityName); });
            if (cityOpt && cityEl.value !== cityOpt.value) {
                cityEl.value = cityOpt.value;
                cityEl.dispatchEvent(new Event('change'));
            }
            if (cityOpt && locEl && area) {
                var group = locEl.querySelector('optgroup[data-city="' + cityOpt.value + '"]');
                var locOpt = group ? Array.prototype.find.call(group.querySelectorAll('option'), function (o) { return sameName(o.text, area); }) : null;
                if (locOpt) {
                    locEl.value = locOpt.value;
                } else if (locEl.value === '' || locEl.value === 'other') {
                    locEl.value = 'other';
                    var nameEl = document.getElementById('f_locality_name');
                    if (nameEl) nameEl.value = area;
                }
                locEl.dispatchEvent(new Event('change'));
            }
        }

        var street = [get('subpremise'), get('premise'), [get('street_number'), get('route')].join(' ').trim(),
            get('sublocality_level_3'), get('sublocality_level_2')].filter(function (s) { return s !== ''; });
        var types = place.types || [];
        var isBuilding = types.indexOf('premise') !== -1 || types.indexOf('establishment') !== -1 || types.indexOf('point_of_interest') !== -1;
        if (isBuilding && place.displayName) {
            fillEmpty('f_building_name', place.displayName);
            if (!document.getElementById('f_building_name') && street.indexOf(place.displayName) === -1) street.unshift(place.displayName);
        }
        fillEmpty('f_address_line', street.join(', '));
    };

    var key = block.getAttribute('data-maps-key');
    if (!key) return;

    var fallback = function () {
        block.querySelectorAll('#propertyMap, [data-map-search], [data-map-help]').forEach(function (el) { el.hidden = true; });
        block.querySelector('[data-coords-box]').open = true;
    };
    window.gm_authFailure = fallback; // invalid key, or key not allowed on this website

    // google.maps.Marker is deprecated; AdvancedMarkerElement replaces it but
    // needs a map ID. Use whichever this API version offers.
    var makePin = function (lib, position, onMoved) {
        if (lib.Marker) {
            var m = new lib.Marker({ map: map, position: position, draggable: true });
            m.addListener('dragend', function (ev) { onMoved(ev.latLng.lat(), ev.latLng.lng()); });
            return { set: function (ll) { m.setPosition(ll); } };
        }
        var a = new lib.AdvancedMarkerElement({ map: map, position: position, gmpDraggable: true });
        a.addListener('dragend', function () {
            var p = a.position;
            onMoved(typeof p.lat === 'function' ? p.lat() : p.lat, typeof p.lng === 'function' ? p.lng() : p.lng);
        });
        return { set: function (ll) { a.position = ll; } };
    };

    window.initPropertyMap = function () {
        Promise.all([
            google.maps.importLibrary('maps'),
            google.maps.importLibrary('marker'),
            google.maps.importLibrary('places')
        ]).then(function (libs) {
            var start = parse(coords.value);
            var el = document.getElementById('propertyMap');
            el.hidden = false;
            block.querySelector('[data-map-help]').hidden = false;
            var opts = {
                center: start || { lat: 21.1702, lng: 72.8311 },
                zoom: start ? 17 : 12,
                mapTypeControl: false, streetViewControl: false, fullscreenControl: true,
                clickableIcons: false, gestureHandling: 'greedy'
            };
            if (!libs[1].Marker) opts.mapId = 'DEMO_MAP_ID';
            map = new libs[0].Map(el, opts);
            markerLib = libs[1];
            // No pin until the owner picks a spot: a pin on the default
            // city centre would look like a chosen location.
            if (start) pin = makePin(markerLib, start, pinMoved);
            map.addListener('click', function (ev) {
                if (!ev.latLng) return;
                moveTo(ev.latLng);
                pinMoved(ev.latLng.lat(), ev.latLng.lng());
            });
            coords.addEventListener('change', function () {
                var c = parse(coords.value);
                if (c) moveTo(c, 17);
            });

            var Auto = libs[2].PlaceAutocompleteElement;
            if (Auto) {
                var ac = new Auto({ includedRegionCodes: ['in'] });
                ac.id = 'placeAutocomplete';
                ac.setAttribute('placeholder', 'e.g. Rajhans Apartments, Adajan, Surat');
                document.getElementById('placeSearch').appendChild(ac);
                var search = block.querySelector('[data-map-search]');
                search.hidden = false;
                var searchFailed = function () {
                    var note = block.querySelector('[data-search-error]');
                    if (note) note.hidden = false;
                };
                ac.addEventListener('gmp-error', searchFailed);
                ac.addEventListener('gmp-requesterror', searchFailed); // older API versions
                var apply = function (place) {
                    place.fetchFields({ fields: ['formattedAddress', 'location', 'id', 'addressComponents', 'displayName', 'types'] }).then(function () {
                        if (!place.location) return;
                        moveTo(place.location, 18);
                        setCoords(place.location.lat(), place.location.lng());
                        addr.value = place.formattedAddress || '';
                        placeId.value = place.id || '';
                        fillAddress(place);
                    }).catch(searchFailed);
                };
                ac.addEventListener('gmp-select', function (ev) { if (ev.placePrediction) apply(ev.placePrediction.toPlace()); });
                ac.addEventListener('gmp-placeselect', function (ev) { if (ev.place) apply(ev.place); }); // older API versions
            }
            block.querySelector('[data-coords-box]').open = false;
        }).catch(fallback);
    };

    var s = document.createElement('script');
    s.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(key) + '&v=weekly&loading=async&language=en&region=IN&callback=initPropertyMap';
    s.async = true;
    s.onerror = fallback;
    document.head.appendChild(s);
})();
