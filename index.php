<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use DI\Container;
use App\Services\MysqlDatabase;
use App\Services\Traccar;
use App\Services\Simbase;
use App\Services\Firebase;
use App\Middleware\FirebaseAuthMiddleware;
use App\Controllers\Server;
use App\Controllers\User;
use App\Controllers\Device;

require __DIR__ . '/vendor/autoload.php';

// 1. Load Environment Variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// 2. Initialize DI Container
$container = new Container();

$container->set('db', fn() => new MysqlDatabase());
$container->set('simbase', fn() => new Simbase());

// Firebase Service Registration
$container->set(Firebase::class, fn() => new Firebase());

// Traccar Factory for dynamic domains
$container->set('traccar', function () {
    return function (string $domain) {
        return new Traccar($domain);
    };
});

// timescale postgres database
$container->set('timescale', function () {
    return function (string $host) {
        return new PostgresDatabase($host);
    };
});


// 3. Initialize Slim App
AppFactory::setContainer($container);
$app = AppFactory::create();

$app->setBasePath('/v1');
$app->addBodyParsingMiddleware();
$app->addErrorMiddleware(true, true, true);

# AUTHENTICATION LAYER
$app->add($container->get(FirebaseAuthMiddleware::class));

$container->set(Server::class, fn($c) => new Server($c));

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

# GET /server/test (Same as above but GET for easy browser testing)
$app->get('/server/test', [Server::class, 'testServer']);

# POST /users/sync
$app->post('/user/sync', [User::class, 'sync']);

# POST /users/update
$app->post('/user/update', [User::class, 'update']);

# POST /users/delete
$app->post('/user/delete', [User::class, 'delete']);

# POST /inventory/linkDevice -> links device to the user's account must have new device name, device imei, firebase token
$app->POST('/user/link-device', function (Request $request, Response $response) {
    $firebaseUser = $request->getAttribute('firebase_user');
    $data = $request->getParsedBody();
    $db = $this->get('db');

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

    $traccar = ($this->get('traccar'))($user['server_url']);

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


# POST /inventory/add -> adds a new device to the inventory (admin use)
$app->post('/inventory/createRecord', function (Request $request, Response $response) {
    $brand = 'istartek';
    $model = 'VT100';

    $data = $request->getParsedBody();
    
    // 1. Get parameters from POST request
    $serverUrl = $data['server_url'] ?? null;
    $imei = $data['imei'] ?? null;
    $iccid = $data['iccid'] ?? null;

    if (!$serverUrl || !$imei || !$iccid) {
        $response->getBody()->write(json_encode(['error' => 'Missing required fields']));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
    }

    try {
        $simNameUpdated = false;
        $createdTraccarId = false;
        // 2. Check Simbase for status
        $simbase = new Simbase(); // Assumes API key is in .env as per project structure
        $simDetails = $simbase->getSimDetails($iccid);

        // Logic: present AND name is empty AND state is 'disabled'
        // Simbase V2 uses 'state' and 'name'. If using V1, check 'sim_state' and 'device_name'.
        $isSimValid = ($simDetails && $simDetails['state'] === 'disabled') && 
                      (empty($simDetails['name']) || $simDetails['name'] == '@@');

        if (!$isSimValid) {
            $response->getBody()->write(json_encode(['error' => 'SIM is either not found, already named, or enabled.']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // 3. Generate name string: "@@ " + last 4 imei + "/" + last 4 iccid
        $generatedName = "@@ " . substr($imei, -4) . "/" . substr($iccid, -4);

        // 4. Add to Traccar server
        $traccar = new Traccar($serverUrl);
        $traccarDevice = $traccar->createDevice(["name" => $generatedName, "uniqueId" => $imei]);
        
        if (!isset($traccarDevice['id'])) {
            throw new Exception("Failed to create device on Traccar");
        } else {
            $createdTraccarId = $traccarDevice['id']; // Store created ID for potential rollback

        }

        $setSimName = $simbase->setSimName($iccid, "@@ " . $imei);
        if (isset($setSimName['error'])) {
            throw new Exception("Failed to set SIM name: " . $setSimName['message']);
        } else {
            $simNameUpdated = true; // Flag to indicate SIM name was updated, for potential rollback
        }

        $traccarId = $traccarDevice['id'];

        // 5. Add row to MySQL device_inventory
        $db = new MysqlDatabase();
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
        // If Simbase was updated, reset it
        if ($simNameUpdated) {
            $simbase->setSimName($iccid, "@@"); 
        }

        // If Traccar device was created, delete it
        if ($createdTraccarId) {
            $traccar->deleteDevice($createdTraccarId);
        }

        $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
    }
});


# POST /history/positions
# Body (JSON): {"date": "2023-10-27", "timezone": "Asia/Manila", "imei": 5 }
$app->post('/history/positions', function (Request $request, Response $response) {
    $data = $request->getParsedBody();

    // 1. Validate Input (We no longer need server_id from input)
    if (empty($data['imei']) || empty($data['date']) || empty($data['timezone'])) {
        $response->getBody()->write(json_encode(['error' => 'Missing required fields: imei, date, timezone']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    // 2. Securely Identity User via Firebase Token
    $firebaseUser = $request->getAttribute('firebase_user');
    $db = $this->get('db');

    // 3. Check Device Ownership
    $device = $db->fetchOne("SELECT server_ref, server_url, server_user_id FROM device_inventory WHERE imei = ?", [$data['imei']]);

    if (!$device) {
        $response->getBody()->write(json_encode(['error' => 'Device not found']));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }
    
    //check for admin previlages and device ownership
    if($firebaseUser['email'] != 'superadmin@navitag.com'){
        // Fetch the user row using the Firebase UID (sub)
        $user = $db->fetchOne("SELECT id, server_id FROM users WHERE auth_uid = ?", [$firebaseUser['sub']]);

        if (!$user) {
            $response->getBody()->write(json_encode(['error' => 'User not found in database. Please sync app.']));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }

        // Strict Ownership Check:
        // Ensure this matches how you saved it in /linkDevice. 
        if ($device['server_user_id'] != $user['server_id']) {
            $response->getBody()->write(json_encode(['error' => 'Unauthorized: Device not assigned to you']));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }
    } 

    try {
        // 4. Calculate UTC Window
        $localTimeZone = new DateTimeZone($data['timezone']);
        $utcTimeZone = new DateTimeZone('UTC');

        $startDate = new DateTime($data['date'] . ' 00:00:00', $localTimeZone);
        $endDate   = new DateTime($data['date'] . ' 23:59:59', $localTimeZone);

        $startUtc = $startDate->setTimezone($utcTimeZone)->format('Y-m-d\TH:i:s\Z');
        $endUtc   = $endDate->setTimezone($utcTimeZone)->format('Y-m-d\TH:i:s\Z');

        // 5. Fetch from Traccar
        $traccar = ($this->get('traccar'))($device['server_url']);
        $positions = $traccar->getPositions((int)$device['server_ref'], $startUtc, $endUtc);

        $response->getBody()->write(json_encode($positions));
        return $response->withHeader('Content-Type', 'application/json');

    } catch (Exception $e) {
        $response->getBody()->write(json_encode(['error' => 'Processing failed', 'message' => $e->getMessage()]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
});

$app->run();
?>