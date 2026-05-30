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
            setField(prefix, 'place_id', '');
            setField(prefix, 'display_name', '');
            setField(prefix, 'lat', '');
            setField(prefix, 'lng', '');
        }

        // Clear stale place_id if user edits the autocomplete text without re-selecting.
        pac.addEventListener('input', clearHiddenFields);

        pac.addEventListener('gmp-placeselect', function(event) {
            var place = event.place;

            // Fetch geometry + display name
            place.fetchFields({ fields: ['displayName', 'location', 'id'] }).then(function() {
                setField(prefix, 'place_id',     place.id);
                setField(prefix, 'display_name', place.displayName);
                setField(prefix, 'lat',          place.location.lat());
                setField(prefix, 'lng',          place.location.lng());
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
        var name;
        if (prefix.indexOf('[') === -1) {
            // origin / destination
            name = prefix + '_' + field;
        } else {
            // waypoints[N]
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
