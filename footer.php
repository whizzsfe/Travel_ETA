    </div><!-- /.container -->

</body>

<script>
    function attachAutocomplete(inputEl) {
        var prefix = inputEl.dataset.prefix;
        console.log('[TravelETA attachAutocomplete] prefix:', prefix);

        if (!window.google || !google.maps.places || !google.maps.places.PlaceAutocompleteElement) {
            console.error('[TravelETA] PlaceAutocompleteElement not available — google.maps.places:', window.google && google.maps.places);
            return;
        }

        var pac = new google.maps.places.PlaceAutocompleteElement();
        pac.style.width = '100%';
        console.log('[TravelETA] PAC created. tagName:', pac.tagName);

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
            // New Places API (v=beta/weekly): event.placePrediction is a PlacePrediction object.
            // Call .toPlace() to get a Place, then fetchFields() to populate its properties.
            // Older API: event.place is a Place object directly.
            var prediction = event.placePrediction || null;
            var place      = event.place || null;

            if (prediction && typeof prediction.toPlace === 'function') {
                // Current API path
                place = prediction.toPlace();
            }

            if (!place) {
                console.warn('[TravelETA] No place in event. event.type:', event.type,
                    '| event.placePrediction:', event.placePrediction,
                    '| event.place:', event.place);
                return;
            }

            clearHiddenFields();

            place.fetchFields({ fields: ['id', 'displayName', 'location'] }).then(function() {
                setField(prefix, 'place_id',     place.id);
                setField(prefix, 'display_name', place.displayName);
                setField(prefix, 'lat',          place.location.lat());
                setField(prefix, 'lng',          place.location.lng());
                console.log('[TravelETA fetchFields OK]',
                    'place_id:', place.id,
                    '| display:', place.displayName,
                    '| lat:', place.location.lat(),
                    '| lng:', place.location.lng());
            }).catch(function(err) {
                setField(prefix, 'display_name', '');
                setField(prefix, 'lat',          '');
                setField(prefix, 'lng',          '');
                console.error('[TravelETA fetchFields FAILED]', err);
            });
        }

        // Current API fires 'gmp-select'; listen to both names for compatibility.
        pac.addEventListener('gmp-select',      handlePlaceSelect);
        pac.addEventListener('gmp-placeselect', handlePlaceSelect);
    }

    function setField(prefix, field, value) {
        var name;
        if (prefix.indexOf('[') === -1) {
            // origin / destination: 'display_name' → '_display' to match hidden field names
            var suffix = (field === 'display_name') ? 'display' : field;
            name = prefix + '_' + suffix;
        } else {
            // waypoints[N] — array syntax
            name = prefix + '[' + field + ']';
        }
        var el = document.querySelector('input[name="' + name + '"]');
        if (el) {
            el.value = value;
        } else {
            console.warn('[TravelETA setField] element not found for name:', name);
        }
    }

    function initPlaces() {
        var inputs = document.querySelectorAll('.autocomplete-input');
        console.log('[TravelETA initPlaces] called — inputs found:', inputs.length);

        // Document-level capture catches the event even if it doesn't bubble out of shadow DOM.
        document.addEventListener('gmp-placeselect', function(e) {
            console.log('[TravelETA DOCUMENT capture] gmp-placeselect — target tag:', e.target.tagName,
                '| place:', e.place);
        }, true);
        document.addEventListener('gmp-select', function(e) {
            console.log('[TravelETA DOCUMENT capture] gmp-select — target tag:', e.target.tagName,
                '| detail:', e.detail);
        }, true);

        inputs.forEach(function(el) { attachAutocomplete(el); });
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
