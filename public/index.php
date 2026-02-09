$app->get('/api/full-status', function ($request, $response) {
    $uid = $request->getAttribute('firebase_uid');

    // 1. Get the 'Link IDs' from your MySQL
    $deviceInfo = $db->query("SELECT traccar_id, iccid FROM devices WHERE user_id = ?", [$uid])->fetch();

    // 2. Initialize Services
    $traccar = new \App\Services\Traccar(
        $_ENV['TRACCAR_URL'], 
        $_ENV['TRACCAR_USER'], 
        $_ENV['TRACCAR_PASS']
    );
    $simbase = new \App\Services\Simbase($_ENV['SIMBASE_KEY']);

    // 3. Fetch Data from both simultaneously
    $position = $traccar->getLatestPosition($deviceInfo['traccar_id']);
    $sim = $simbase->getSimDetails($deviceInfo['iccid']);

    // 4. Unified Response
    $payload = [
        'last_seen' => $position['fixTime'] ?? 'Unknown',
        'location'  => [
            'lat' => $position['latitude'] ?? 0,
            'lng' => $position['longitude'] ?? 0,
            'speed' => $position['speed'] ?? 0
        ],
        'connectivity' => [
            'status' => $sim['status'] ?? 'offline',
            'sim_id' => $deviceInfo['iccid']
        ]
    ];

    $response->getBody()->write(json_encode($payload));
    return $response->withHeader('Content-Type', 'application/json');
});