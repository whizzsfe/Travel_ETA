    </div><!-- /.container -->

</body>

<script>
    function attachAutocomplete(inputEl) {
        var prefix = inputEl.dataset.prefix;

        if (!window.google || !google.maps.places || !google.maps.places.PlaceAutocompleteElement) {
            return;
        }

        var pac = new google.maps.places.PlaceAutocompleteElement();
        pac.style.width = '100%';

        var wrapper = inputEl.closest('.autocomplete-wrapper') || inputEl.parentNode;
        wrapper.appendChild(pac);
        inputEl.style.display = 'none';

        function clearHiddenFields() {
            setField(prefix, 'place_id',     '');
            setField(prefix, 'display_name', '');
            setField(prefix, 'lat',          '');
            setField(prefix, 'lng',          '');
        }

        function handlePlaceSelect(event) {
            // The current Places API (v=beta) fires 'gmp-select' with event.placePrediction.
            // Call .toPlace() to get a Place object, then fetchFields() to load its data.
            // Older builds used 'gmp-placeselect' with event.place directly — kept as fallback.
            var prediction = event.placePrediction || null;
            var place      = event.place || null;

            if (prediction && typeof prediction.toPlace === 'function') {
                place = prediction.toPlace();
            }

            if (!place) { return; }

            clearHiddenFields();

            place.fetchFields({ fields: ['id', 'displayName', 'location'] }).then(function() {
                setField(prefix, 'place_id',     place.id);
                setField(prefix, 'display_name', place.displayName);
                setField(prefix, 'lat',          place.location.lat());
                setField(prefix, 'lng',          place.location.lng());
            }).catch(function() {
                clearHiddenFields();
            });
        }

        pac.addEventListener('gmp-select',      handlePlaceSelect);
        pac.addEventListener('gmp-placeselect', handlePlaceSelect);
    }

    function setField(prefix, field, value) {
        var name;
        if (prefix.indexOf('[') === -1) {
            // origin / destination: 'display_name' maps to '_display' to match hidden field names
            var suffix = (field === 'display_name') ? 'display' : field;
            name = prefix + '_' + suffix;
        } else {
            // waypoints[N] — array syntax: waypoints[0][place_id] etc.
            name = prefix + '[' + field + ']';
        }
        var el = document.querySelector('input[name="' + name + '"]');
        if (el) { el.value = value; }
    }

    function initPlaces() {
        document.querySelectorAll('.autocomplete-input').forEach(function(el) {
            attachAutocomplete(el);
        });
    }
</script>

<!-- Google Maps JS — loading=async (URL param) + callback=initPlaces.
     loading=async is required by the new Maps API loader; the HTML async attribute
     alone is not sufficient and produces a console warning. -->
<script
    src="https://maps.googleapis.com/maps/api/js?key=<?= h(PLACES_API_KEY) ?>&loading=async&libraries=places&v=beta&callback=initPlaces"
    async>
</script>

</html>
