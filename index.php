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

# POST /server/status
$app->post('/server/status', [Server::class, 'serverInfo']);

# POST /server/token
$app->post('/server/token', [Server::class, 'generateToken'])->add($container->get(FirebaseAuthMiddleware::class));

# GET /server/test (Same as above but GET for easy browser testing)
$app->get('/server/test', [Server::class, 'testServer']);

# POST /users/sync
$app->post('/users/sync', [User::class, 'sync']);

# POST /users/update
$app->post('/users/update', [User::class, 'update']);

# POST /users/delete
$app->post('/users/delete', [User::class, 'delete']);

# POST /inventory/linkDevice -> links and activates a device to the user's account.
$app->post('/inventory/linkDevice', function (Request $request, Response $response) {
    $data = $request->getParsedBody();
    $db = $this->get('db');
    $simbase = $this->get('simbase');
    $traccar = ($this->get('traccar'))($data['server_url']);

    $device = $db->fetchOne("SELECT server_ref, sim_iccid, server_user_assigned FROM device_inventory WHERE imei = ?", [$data['imei']]);

    if (!$device || !empty($device['server_user_assigned'])) {
        $response->getBody()->write(json_encode(["error" => "Device unavailable or already assigned"]));
        return $response->withStatus(400);
    }

    try {
        // Link Traccar
        $traccar->updateDevice((int)$device['server_ref'], ['name' => $data['name'], 'uniqueId' => $data['imei']]);
        $traccar->linkUserToDevice((int)$data['server_id'], (int)$device['server_ref']);

        // Enable SIM
        $simbase->setSimState($device['sim_iccid'], 'enabled');

        // Update MySQL
        $db->execute("UPDATE device_inventory SET server_user_assigned = ? WHERE imei = ?", [$data['id'], $data['imei']]);

        $response->getBody()->write(json_encode(["status" => "success"]));
        return $response->withHeader('Content-Type', 'application/json');
    } catch (Exception $e) {
        $response->getBody()->write(json_encode(["error" => $e->getMessage()]));
        return $response->withStatus(500);
    }
});

# POST /inventory/add -> adds a new device to the inventory (admin use)
$app->post('/inventory/addDevice', function (Request $request, Response $response) {
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
})->add($container->get(FirebaseAuthMiddleware::class));

$app->run();
?>