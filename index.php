<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use DI\Container;
use App\Services\MysqlDatabase;
use App\Services\Traccar;
use App\Services\Simbase;
use App\Services\Firebase;
use App\Services\Xendit;
use App\Middleware\FirebaseAuthMiddleware;
use App\Controllers\Server;
use App\Controllers\User;
use App\Controllers\Device;
use App\Controllers\Notification;

require __DIR__ . '/vendor/autoload.php';

// 1. Load Environment Variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// 2. Initialize DI Container
$container = new Container();

$container->set('db', fn() => new MysqlDatabase());
$container->set('simbase', fn() => new Simbase());
$container-set('xendit', fn() => new Xendit());
$container->set(Notification::class, fn($c) => new Notification($c));

// Firebase Service Registration
$container->set(Firebase::class, fn() => new Firebase());

// Traccar Factory for dynamic domains
$container->set('traccar', function () {
    return function (string $domain) {
        return new Traccar($domain);
    };
});

// 3. Initialize Slim App
AppFactory::setContainer($container);
$app = AppFactory::create();

$app->setBasePath('/v1');

// --- MIDDLEWARE STACK START ---
// Note: Slim executes middleware in LIFO (Last In, First Out) order.

// 1. Body Parsing (Executes 3rd)
$app->addBodyParsingMiddleware();

// 2. Error Handling (Executes 2nd)
$app->addErrorMiddleware(true, true, true);

// 3. AUTHENTICATION LAYER (Executes 1st - AFTER CORS)
$app->add($container->get(FirebaseAuthMiddleware::class));

// --- MIDDLEWARE STACK END ---
$container->set(Server::class, fn($c) => new Server($c));

//Route Lists
$app->get('/', function (Request $request, Response $response) {
    $response->getBody()->write("Navitag Consolidated API v1.");
    return $response;
});

$app->get('/authcheck', function (Request $request, Response $response) {
    $firebaseUser = $request->getAttribute('firebase_user');
    $response->getBody()->write(json_encode($firebaseUser));
    return $response;
});

# POST /server/status
$app->post('/server/status', [Server::class, 'serverInfo']);

# POST /server/token
$app->post('/server/token', [Server::class, 'generateToken']);

# POST to enable linked device and set expiration
$app->post('/device/enable', [Device::class, 'enable']);

# POST to disable any device in simbase
$app->post('/device/disable', [Device::class, 'disable']);

# GET /server/test
$app->get('/server/test', [Server::class, 'testServer']);

# POST /users/sync
$app->post('/user/sync', [User::class, 'sync']);

# POST /users/update
$app->post('/user/update', [User::class, 'update']);

# POST /users/delete
$app->post('/user/delete', [User::class, 'delete']);

# POST /user/fcm-token
$app->post('/user/fcm-token', [User::class, 'saveFcmToken']);

# POST /user/link-device
// FIX: Added 'use ($container)' to access services inside closure
$app->post('/user/link-device', function (Request $request, Response $response) use ($container) {
    $firebaseUser = $request->getAttribute('firebase_user');
    $data = $request->getParsedBody();
    
    // FIX: Use container instead of $this->get()
    $db = $container->get('db'); 

    $user = $db->fetchOne("SELECT server_url, server_id from users WHERE auth_uid = ?", [$firebaseUser['sub']]);
    $device = $db->fetchOne("SELECT server_url, server_ref, sim_iccid, server_user_id FROM device_inventory WHERE imei = ?", [$data['imei']]);

    if(isset($user['error']) || empty($user) ){
        $response->getBody()->write(json_encode(["error" => $user]));
        return $response->withStatus(400);
    }

    if($user['server_url'] != $device['server_url']){
        $response->getBody()->write(json_encode(["error" => "User and Device mismatch"]));
        return $response->withStatus(400);
    }

    // FIX: Use container factory
    $traccar = ($container->get('traccar'))($user['server_url']);

    if (!$device || !empty($device['server_user_id'])) {
        $response->getBody()->write(json_encode(["error" => "Device unavailable or already assigned"]));
        return $response->withStatus(400);
    }

    try {
        // Link Traccar
        $traccar->updateDevice((int)$device['server_ref'], ['name' => $data['name']]);
        $traccar->linkUserToDevice((int)$user['server_id'], (int)$device['server_ref']);

        // Update MySQL
        $db->execute("UPDATE device_inventory SET server_user_id = ? WHERE imei = ?", [$user['server_id'], $data['imei']]);

        $response->getBody()->write(json_encode(["status" => "success"]));
        return $response->withHeader('Content-Type', 'application/json');
    } catch (Exception $e) {
        $response->getBody()->write(json_encode(["error" => $e->getMessage()]));
        return $response->withStatus(500);
    }
});

# GET /user/device-expirations
$app->get('/user/device-expiration', [User::class, 'deviceExp']);


# POST /inventory/createRecord
$app->post('/inventory/createRecord', function (Request $request, Response $response) use ($container) {
    $brand = 'istartek';
    $model = 'VT100';

    $data = $request->getParsedBody();
    
    $serverUrl = $data['server_url'] ?? null;
    $imei = $data['imei'] ?? null;
    $iccid = $data['iccid'] ?? null;

    if (!$serverUrl || !$imei || !$iccid) {
        $response->getBody()->write(json_encode(['error' => 'Missing required fields']));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
    }

    $simbase = $container->get('simbase'); // Use container for Simbase
    $traccar = new Traccar($serverUrl);    // Helper classes can still be new'd if preferred

    try {
        $simNameUpdated = false;
        $createdTraccarId = false;
        
        $simDetails = $simbase->getSimDetails($iccid);

        $isSimValid = ($simDetails && $simDetails['state'] === 'disabled') && 
                      (empty($simDetails['name']) || $simDetails['name'] == '@@');

        if (!$isSimValid) {
            $response->getBody()->write(json_encode(['error' => 'SIM is either not found, already named, or enabled.']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $generatedName = "@@ " . substr($imei, -4) . "/" . substr($iccid, -4);
        $traccarDevice = $traccar->createDevice(["name" => $generatedName, "uniqueId" => $imei]);
        
        if (!isset($traccarDevice['id'])) {
            throw new Exception("Failed to create device on Traccar");
        } else {
            $createdTraccarId = $traccarDevice['id'];
        }

        $setSimName = $simbase->setSimName($iccid, "@@ " . $imei);
        if (isset($setSimName['error'])) {
            throw new Exception("Failed to set SIM name: " . $setSimName['message']);
        } else {
            $simNameUpdated = true;
        }

        $traccarId = $traccarDevice['id'];

        // FIX: Use shared DB connection
        $db = $container->get('db');
        $sql = "INSERT INTO device_inventory (imei, sim_iccid, server_ref, ref1, server_url, brand, model) 
                VALUES (:imei, :iccid, :traccar_id, :name, :url, :brand, :model)";

        $dbres = $db->execute($sql, [
            'imei' => $imei,
            'iccid' => $iccid,
            'traccar_id' => $traccarId,
            'name' => $generatedName,
            'url' => $serverUrl,
            'brand' => $brand,
            'model' => $model
        ]);

        if($dbres['status'] !== 'success') {
            throw new Exception("Failed to insert device into database: " . $dbres['message']);
        }

        $response->getBody()->write(json_encode([
            'status' => 'success',
            'traccar_id' => $traccarId,
            'generated_name' => $generatedName,
            'mysql_id' => $dbres['last_insert_id']
        ]));
        
        return $response->withHeader('Content-Type', 'application/json')->withStatus(201);

    } catch (Exception $e) {
        if ($simNameUpdated) {
            $simbase->setSimName($iccid, "@@"); 
        }
        if ($createdTraccarId) {
            $traccar->deleteDevice($createdTraccarId);
        }
        $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
    }
});

# POST /history/positions
// FIX: Added 'use ($container)' to fix the Fatal Error causing CORS failure
$app->post('/history/positions', function (Request $request, Response $response) use ($container) {
    $data = $request->getParsedBody();
    $firebaseUser = $request->getAttribute('firebase_user');

    // FIX: Use $container->get(), not $this->get()
    $db = $container->get('db');

    // 1. Get User Country for Timezone Logic
    $user = $db->fetchOne("SELECT id, server_id, country FROM users WHERE auth_uid = ?", [$firebaseUser['sub']]);
    
    // 2. Auto-Detect Timezone if missing
    $timezone = $data['timezone'] ?? null;
    if (empty($timezone) && !empty($user['country'])) {
        // Feature: Get primary timezone for the country code (e.g., 'PH' -> 'Asia/Manila')
        $zones = DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, $user['country']);
        $timezone = $zones[0] ?? 'UTC'; 
    }
    if (empty($timezone)) {
        $response->getBody()->write(json_encode(['error' => 'Timezone is required and could not be detected from user profile.']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    if (empty($data['imei']) || empty($data['date'])) {
        $response->getBody()->write(json_encode(['error' => 'Missing required fields: imei, date']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    // 3. Check Device
    $device = $db->fetchOne("SELECT server_ref, server_url, server_user_id FROM device_inventory WHERE imei = ?", [$data['imei']]);

    if (!$device) {
        $response->getBody()->write(json_encode(['error' => 'Device not found']));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }
    
    // 4. Admin / Ownership Check
    if($firebaseUser['email'] != 'superadmin@navitag.com'){
        if (!$user) {
            $response->getBody()->write(json_encode(['error' => 'User not found in database. Please sync app.']));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }

        if ($device['server_user_id'] != $user['server_id']) {
            $response->getBody()->write(json_encode(['error' => 'Unauthorized: Device not assigned to you']));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }
    } 

    try {
        $localTimeZone = new DateTimeZone($timezone);
        $utcTimeZone = new DateTimeZone('UTC');

        $startDate = new DateTime($data['date'] . ' 00:00:00', $localTimeZone);
        $endDate   = new DateTime($data['date'] . ' 23:59:59', $localTimeZone);

        $startUtc = $startDate->setTimezone($utcTimeZone)->format('Y-m-d\TH:i:s\Z');
        $endUtc   = $endDate->setTimezone($utcTimeZone)->format('Y-m-d\TH:i:s\Z');

        // FIX: Use container for Traccar factory
        $traccar = ($container->get('traccar'))($device['server_url']);
        $positions = $traccar->getPositions((int)$device['server_ref'], $startUtc, $endUtc);

        $response->getBody()->write(json_encode($positions));
        return $response->withHeader('Content-Type', 'application/json');

    } catch (Exception $e) {
        $response->getBody()->write(json_encode(['error' => 'Processing failed', 'message' => $e->getMessage()]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
});

# POST /notification/send
$app->post('/notification/send', [Notification::class, 'send']);

# POST create session xendit component
$app->post('/transaction/create', function (Request $request, Response $response) {
    // Get payload from the frontend request
    $firebaseUser = $request->getAttribute('firebase_user');
    $data = $request->getParsedBody();

    $db = $container->get('db');
    $xendit = $container->get('xendit');

    // 1. Get User Country for Timezone Logic
    $user = $db->fetchOne("SELECT * FROM users WHERE auth_uid = ?", [$firebaseUser['sub']]);

    $transId = guidv4();
    $name = $user["name"];
    $userId = $firebaseUser['sub'];
    $amount = $data['amount'] ?? 0;
    $currency = $data['currency'] ?? 'USD';
    $countryCode = $data['country'] ?? 'PH';
    $origins = $request->getHeaderLine('Origin') ?? 'https://track.navitag.com'; // Default to your domain

    // Basic Validation
    if (empty($userId) || $amount <= 0) {
        $response->getBody()->write(json_encode([
            'success' => false, 
            'error' => 'Missing or invalid user_id / amount'
        ]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
    }

    try {
        $xenditResponse = $xendit->createPaymentSession(
            $transId,
            $amount,
            $currency,
            $countryCode,
            $userId,
            $name,
            $origins
        );

        // Check if Xendit request was successful
        if ($xenditResponse['success']) {
            // save transaction record into db
            $db->execute(
                'INSERT INTO transactions(id, user_ref, type, data, amount, currency, xendit_ref) VALUES (?, ?, ?, ?, ?, ?, ?)', 
                [$transId, $userId, $data["type"] , json_encode($data["data"]), $amount, $currency, $xenditResponse["payment_session_id"]]
            );

            $response->getBody()->write(json_encode($xenditResponse));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        } else {
            // Xendit API rejected the payload (e.g., bad currency, invalid domain)
            $response->getBody()->write(json_encode($xenditResponse));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

    } catch (\Exception $e) {
        // Catch any fatal errors (e.g. database goes down)
        $response->getBody()->write(json_encode([
            'success' => false,
            'message' => 'Internal Server Error',
            'error' => $e->getMessage()
        ]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
    }
});


$app->post('/api/auth/generate-web-token', function (Request $request, Response $response) use ($container) {
    // 1. Get the authenticated user's UID from the middleware
    $firebaseUser = $request->getAttribute('firebase_user');
    $uid = $firebaseUser['sub'] ?? null;

    if (!$uid) {
        $response->getBody()->write(json_encode(['error' => 'Unauthorized']));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
    }

    // 2. Generate the Custom Token
    $firebaseService = new \App\Services\Firebase();
    $customToken = $firebaseService->createCustomToken($uid);

    if (!$customToken) {
        $response->getBody()->write(json_encode(['error' => 'Failed to generate token']));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
    }

    // 3. Return the token and the target URL to the mobile app
    $responsePayload = [
        'success' => true,
        'custom_token' => $customToken,
        'redirect_url' => "https://track.navitag.com/auto-login?token=" . $customToken
    ];

    $response->getBody()->write(json_encode($responsePayload));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
});

//Helper Function
function guidv4($data = null) {
    // Generate 16 bytes (128 bits) of random data
    $data = $data ?? random_bytes(16);
    assert(strlen($data) == 16);

    // Set version to 0100 (version 4)
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    // Set bits 6-7 to 10 (RFC 4122 variant)
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

    // Output the 36 character UUID
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}



// ==========================================
// CORS IMPLEMENTATION
// ==========================================

// 1. Preflight Route (OPTIONS)
$app->options('/{routes:.+}', function (Request $request, Response $response, $args) {
    return $response;
});

// 2. CORS Middleware (Added LAST -> Executes FIRST)
$app->add(function (Request $request, $handler) use ($app) {
    if ($request->getMethod() === 'OPTIONS') {
        $response = $app->getResponseFactory()->createResponse();
    } else {
        $response = $handler->handle($request);
    }

    $origin = $request->getHeaderLine('Origin') ?: 'http://localhost:5173';

    return $response
        ->withHeader('Access-Control-Allow-Origin', $origin)
        ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS')
        ->withHeader('Access-Control-Allow-Credentials', 'true');
});

$app->run();
?>