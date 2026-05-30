    </div><!-- /.container -->

</body>

<script>
    /**
     * attachAutocomplete — binds a PlaceAutocompleteElement to one input.
     * Called at load for existing inputs and by the waypoint-add button for new ones.
     * Never re-calls initPlaces() (would double-bind existing elements).
     */
    function attachAutocomplete(inputEl) {
        // Determine the field prefix from a data attribute on the input.
        // e.g. data-prefix="origin" → hidden fields: origin_place_id, origin_display, origin_lat, origin_lng
        //      data-prefix="waypoints[0]" → waypoints[0][place_id] etc.
        var prefix = inputEl.dataset.prefix;

        var pac = new google.maps.places.PlaceAutocompleteElement();
        pac.style.width = '100%';

        // Insert the PAC element immediately after the visible input wrapper.
        var wrapper = inputEl.closest('.autocomplete-wrapper') || inputEl.parentNode;
        wrapper.appendChild(pac);

        // Hide the raw text input — PAC element provides its own UI.
        inputEl.style.display = 'none';

        function clearHiddenFields() {
            setField(prefix, 'place_id',     '');
            setField(prefix, 'display_name', '');
            setField(prefix, 'lat',          '');
            setField(prefix, 'lng',          '');
        }

        // Note: do NOT bind clearHiddenFields to the 'input' event on the PAC element.
        // PlaceAutocompleteElement fires 'input' when it updates its own text after a
        // gmp-placeselect, which would clear the hidden fields we just set.

        pac.addEventListener('gmp-placeselect', function(event) {
            var place = event.place;

            // Clear any stale values from a previous selection first.
            clearHiddenFields();

            // place.id is populated immediately on the Place object — set it without
            // waiting for fetchFields so the value is available even if fetchFields is slow.
            if (place.id) {
                setField(prefix, 'place_id', place.id);
            }

            // Fetch display name + coordinates (not available before fetchFields).
            place.fetchFields({ fields: ['displayName', 'location'] }).then(function() {
                setField(prefix, 'place_id',     place.id);
                setField(prefix, 'display_name', place.displayName);
                setField(prefix, 'lat',          place.location.lat());
                setField(prefix, 'lng',          place.location.lng());
            }).catch(function(err) {
                // fetchFields failed — clear fields so stale/partial data isn't submitted.
                clearHiddenFields();
                console.error('PlaceAutocomplete fetchFields error:', err);
            });
        });
    }

    /**
     * setField — sets a hidden input value by field prefix + field name.
     * Prefix "origin"        → name="origin_place_id"
     * Prefix "waypoints[0]"  → name="waypoints[0][place_id]"
     */
    function setField(prefix, field, value) {
        // Build the name attribute: flat for origin/destination, array syntax for waypoints.
        // For flat prefixes, 'display_name' maps to the '_display' suffix used in the HTML
        // (origin_display, destination_display) to match the DB column names.
        var name;
        if (prefix.indexOf('[') === -1) {
            // origin / destination
            var suffix = (field === 'display_name') ? 'display' : field;
            name = prefix + '_' + suffix;
        } else {
            // waypoints[N] — array syntax, field name used as-is (waypoints[0][display_name])
            name = prefix + '[' + field + ']';
        }
        var el = document.querySelector('input[name="' + name + '"]');
        if (el) el.value = value;
    }

    /**
     * initPlaces — fired by Google as callback=initPlaces once the library loads.
     * Attaches autocomplete to every .autocomplete-input already in the DOM.
     * Must NOT be called again after load.
     */
    function initPlaces() {
        document.querySelectorAll('.autocomplete-input').forEach(function(el) {
            attachAutocomplete(el);
        });
    }
</script>

<!-- Google Maps JS — async + callback=initPlaces. Do NOT use defer (deferred scripts
     are not ready when inline footer JS above has already run). -->
<script async
    src="https://maps.googleapis.com/maps/api/js?key=<?= h(PLACES_API_KEY) ?>&libraries=places&v=beta&callback=initPlaces">
</script>

</html>
