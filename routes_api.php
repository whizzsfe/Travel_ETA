<?php

/**
 * Call the Google Maps Routes API v2 for a single departure slot.
 *
 * @param string $originPlaceId      Google Place ID of the origin
 * @param string $destPlaceId        Google Place ID of the destination
 * @param array  $intermediates      Array of ['placeId' => '...'] — empty for direct trips
 * @param string $departureLondon    Departure time as 'Y-m-d H:i:s' in Europe/London timezone
 *
 * @return array ['duration' => int, 'static_duration' => int] — both in seconds
 *
 * @throws Exception on cURL error, non-200 HTTP response, or missing/malformed API response
 */
function call_routes_api(
    string $originPlaceId,
    string $destPlaceId,
    array  $intermediates,
    string $departureLondon
): array {
    // Convert Europe/London departure time to UTC ISO 8601 for the API.
    // Critical: Google uses this to pick the correct real-world traffic snapshot.
    $utcDeparture = (new DateTime($departureLondon, new DateTimeZone('Europe/London')))
        ->setTimezone(new DateTimeZone('UTC'))
        ->format('Y-m-d\TH:i:s\Z');

    // Build request payload
    $payload = [
        'origin'            => ['placeId' => $originPlaceId],
        'destination'       => ['placeId' => $destPlaceId],
        'travelMode'        => 'DRIVE',
        'routingPreference' => 'TRAFFIC_AWARE_OPTIMAL',
        'departureTime'     => $utcDeparture,
    ];

    // Only include intermediates key when waypoints exist
    if (!empty($intermediates)) {
        $payload['intermediates'] = $intermediates;
    }

    $url = 'https://routes.googleapis.com/directions/v2:computeRoutes';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-Goog-Api-Key: ' . ROUTES_API_KEY,
            'X-Goog-FieldMask: routes.duration,routes.staticDuration',
        ],
    ]);

    $body     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr !== '') {
        throw new Exception('cURL error: ' . $curlErr);
    }

    if ($httpCode !== 200) {
        throw new Exception('Routes API returned HTTP ' . $httpCode);
    }

    $data = json_decode($body, true);

    if (empty($data['routes'][0]['duration']) || empty($data['routes'][0]['staticDuration'])) {
        throw new Exception('Routes API response missing duration data');
    }

    // Duration is returned as a string with an 's' suffix e.g. "1234s"
    $duration       = (int) rtrim($data['routes'][0]['duration'],       's');
    $staticDuration = (int) rtrim($data['routes'][0]['staticDuration'], 's');

    return [
        'duration'        => $duration,
        'static_duration' => $staticDuration,
    ];
}
