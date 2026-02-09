<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use DI\Container;
use App\Services\MysqlDatabase;
use App\Services\Traccar;
use App\Services\Simbase;

require __DIR__ . '/../vendor/autoload.php';

// 1. Load Environment Variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// 2. Initialize DI Container
$container = new Container();

$container->set('db', function () {
    return new MysqlDatabase();
});

$container->set('simbase', function () {
    return new Simbase();
});

// Factory to handle dynamic Traccar domains with forced HTTPS
$container->set('traccarFactory', function () {
    return function (string $domain) {
        // Auto-insert https:// protocol
        $baseUrl = "https://" . ltrim($domain, 'htps:/'); 
        return new Traccar($baseUrl);
    };
});

// 3. Initialize Slim App with Container
AppFactory::setContainer($container);
$app = AppFactory::create();

$app->addBodyParsingMiddleware();
$app->addErrorMiddleware(true, true, true);

/**
 * POST /status
 * Expects: {"server_url": "tserver1.navitag.com"}
 */
$app->post('/status', function (Request $request, Response $response) {
    $data = $request->getParsedBody();
    $results = ['timestamp' => date('c'), 'services' => []];
    
    // Required server_url from JSON
    $serverUrl = $data['server_url'] ?? $_ENV['TRACCAR_TEST_URL'];
    
    $db = $this->get('db');
    $simbase = $this->get('simbase');
    $traccar = ($this->get('traccarFactory'))($serverUrl);

    // MySQL Check
    $check = $db->fetchOne("SELECT 1 as alive");
    $results['services']['mysql'] = isset($check['error']) 
        ? ['status' => 'error', 'message' => $check['message']] 
        : ['status' => 'online'];

    // Traccar Check
    $serverInfo = $traccar->getServerInfo();
    $results['services']['traccar'] = isset($serverInfo['error'])
        ? ['status' => 'error', 'message' => $serverInfo['message']]
        : ['status' => 'online', 'version' => $serverInfo['version'] ?? 'unknown'];

    // Simbase Check
    $balance = $simbase->getAccountBalance();
    $results['services']['simbase'] = isset($balance['error'])
        ? ['status' => 'error', 'message' => $balance['errors'] ?? 'Unknown error']
        : ['status' => 'online', 'balance' => $balance['balance'] ?? 0];

    $response->getBody()->write(json_encode($results, JSON_PRETTY_PRINT));
    return $response->withHeader('Content-Type', 'application/json');
});

/**
 * POST /users/sync
 * Expects: {"email": "...", "uid": "...", "server_url": "..."}
 */
$app->post('/users/sync', function (Request $request, Response $response) {
    $data = $request->getParsedBody();
    $serverUrl = $data['server_url'] ?? null;

    if (!$serverUrl) {
        $response->getBody()->write(json_encode(["error" => "server_url is required in JSON body"]));
        return $response->withStatus(400);
    }

    $db = $this->get('db');
    $traccar = ($this->get('traccarFactory'))($serverUrl);

    try {
        // Generate URL-safe Base64 Password from Email. Encode base64. Replace '+' with '-' and '/' with '_' , Trim '=' padding
        $encodedPassword = rtrim(strtr(base64_encode($data['email']), '+/', '-_'), '=');
        
        // 1. Create in Traccar first (Password = Email)
        $traccarUser = $traccar->createUser([
            'name' => $data['email'],
            'email' => $data['email'],
            'password' => $encodedPassword,
        ]);

        if (isset($traccarUser['error'])) {
            throw new Exception("Traccar Sync Failed: " . ($traccarUser['message'] ?? 'Unknown error'));
        }

        // 2. Insert to local MySQL
        $sql = "INSERT INTO users (email, firebase_uid, server_id, server_url, created_at) 
                VALUES (?, ?, ?, ?, NOW())";
        
        $db->prepare($sql)->execute([
            $data['email'], 
            $data['uid'], 
            $traccarUser['id'], 
            $serverUrl 
        ]);

        $response->getBody()->write(json_encode([
            "status" => "success", 
            "traccar_id" => $traccarUser['id']
        ]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
        
    } catch (Exception $e) {
        $response->getBody()->write(json_encode(["error" => $e->getMessage()]));
        return $response->withStatus(500);
    }
});

/**
 * POST /users/update
 * Expects: {"id": 1, "server_id": 123, "server_url": "...", "name": "...", "mobile": "..."}
 */
$app->post('/users/update', function (Request $request, Response $response) {
    $data = $request->getParsedBody();
    $db = $this->get('db');
    
    // Required fields from body
    $localId = $data['id'];
    $serverId = $data['server_id'];
    $serverUrl = $data['server_url'];

    try {
        // 1. Read local user first to get Email (needed for Traccar payload consistency)
        $localUser = $db->fetchOne("SELECT email FROM users WHERE id = ?", [$localId]);
        if (!$localUser) {
            throw new Exception("Local user not found");
        }

        // 2. Update Traccar FIRST
        $traccar = ($this->get('traccarFactory'))($serverUrl);
        
        $traccarUpdateData = [
            'name' => $data['name'] ?? $localUser['email'], // Default to email if name is empty
            'email' => $localUser['email'], // Keep email consistent
            'phone' => $data['mobile'] ?? null
        ];

        $traccarResult = $traccar->updateUser((int)$serverId, $traccarUpdateData);
        
        if (isset($traccarResult['error'])) {
            throw new Exception("Traccar Update Failed: " . $traccarResult['message']);
        }

        // 3. Update Local MySQL
        if (isset($data['name'])) {
            $db->prepare("UPDATE users SET name = ? WHERE id = ?")->execute([$data['name'], $localId]);
        }
        if (isset($data['mobile'])) {
            $db->prepare("UPDATE users SET mobile = ? WHERE id = ?")->execute([$data['mobile'], $localId]);
        }
        
        $response->getBody()->write(json_encode(["status" => "success", "message" => "User updated in Traccar then MySQL"]));
        return $response->withHeader('Content-Type', 'application/json');

    } catch (Exception $e) {
        $response->getBody()->write(json_encode(["error" => $e->getMessage()]));
        return $response->withStatus(500);
    }
});

/**
 * POST /users/delete
 * Expects: {"id": 1, "server_id": 123, "server_url": "..."}
 */
$app->post('/users/delete', function (Request $request, Response $response) {
    $data = $request->getParsedBody();
    $db = $this->get('db');
    
    // Required fields from body
    $localId = $data['id'];
    $serverId = $data['server_id'];
    $serverUrl = $data['server_url'];

    try {
        // 1. Delete from Traccar FIRST
        $traccar = ($this->get('traccarFactory'))($serverUrl);
        
        $traccarResult = $traccar->deleteUser((int)$serverId);
        
        if (isset($traccarResult['error'])) {
            // Optional: You might want to allow local delete even if Traccar fails, 
            // but strict "Traccar First" implies we stop on error.
            throw new Exception("Traccar Delete Failed: " . $traccarResult['message']);
        }

        // 2. Delete from Local MySQL
        $db->prepare("DELETE FROM users WHERE id = ?")->execute([$localId]);
        
        $response->getBody()->write(json_encode(["status" => "success", "message" => "User deleted from Traccar then MySQL"]));
        return $response->withHeader('Content-Type', 'application/json');

    } catch (Exception $e) {
        $response->getBody()->write(json_encode(["error" => $e->getMessage()]));
        return $response->withStatus(500);
    }
});

/**
 * POST /device/add
 * Assigns a device to a user across MySQL, Traccar, and Simbase.
 * Expects: {"id": 1, "server_id": 10, "server_url": "...", "imei": "...", "name": "..."}
 */
$app->post('/device/add', function (Request $request, Response $response) {
    $data = $request->getParsedBody();
    
    // Validate required fields
    if (empty($data['id']) || empty($data['server_id']) || empty($data['imei']) || empty($data['server_url'])) {
        $response->getBody()->write(json_encode(["error" => "Missing required fields"]));
        return $response->withStatus(400);
    }

    $db = $this->get('db');
    $simbase = $this->get('simbase');
    $traccar = ($this->get('traccarFactory'))($data['server_url']);

    // --- Step 1: Validation & Retrieval (MySQL) ---
    // Fetch device details including server_ref (Traccar ID) and Simbase ICCID
    $device = $db->fetchOne(
        "SELECT id, server_ref, sim_iccid, server_user_assigned FROM device_inventory WHERE imei = ?", 
        [$data['imei']]
    );

    if (!$device) {
        $response->getBody()->write(json_encode(["error" => "Device not found in inventory"]));
        return $response->withStatus(404);
    }

    if (!empty($device['server_user_assigned'])) {
        $response->getBody()->write(json_encode(["error" => "Device is already assigned to a user"]));
        return $response->withStatus(409);
    }

    $traccarDeviceId = (int)$device['server_ref'];
    $simIccid = $device['sim_iccid'];
    $userId = $data['id'];
    $traccarUserId = (int)$data['server_id'];
    $newDeviceName = $data['name'];

    // State for Rollback
    $rollback = [
        'original_name' => null,
        'traccar_linked' => false,
        'simbase_activated' => false
    ];

    try {
        // --- Step 2: Update Device Name (Traccar) ---
        // Fetch current info first to allow rollback
        $currentTraccarDevice = $traccar->getDevice($traccarDeviceId);
        if (isset($currentTraccarDevice['error'])) {
            throw new Exception("Traccar Lookup Failed: " . $currentTraccarDevice['message']);
        }
        $rollback['original_name'] = $currentTraccarDevice['name'];

        $updateResult = $traccar->updateDevice($traccarDeviceId, [
            'name' => $newDeviceName,
            'uniqueId' => $data['imei'] // Traccar often requires uniqueId in PUT
        ]);

        if (isset($updateResult['error'])) {
            throw new Exception("Traccar Name Update Failed: " . $updateResult['message']);
        }

        // --- Step 3: Link User to Device (Traccar) ---
        $linkResult = $traccar->linkUserToDevice($traccarUserId, $traccarDeviceId);
        if (isset($linkResult['error'])) {
            throw new Exception("Traccar Link Failed: " . $linkResult['message']);
        }
        $rollback['traccar_linked'] = true;

        // --- Step 4: Enable SIM (Simbase) ---
        // Using existing setSimState method from your Simbase service
        $simResult = $simbase->setSimState($simIccid, 'enabled');
        if (isset($simResult['error'])) {
            throw new Exception("Simbase Activation Failed: " . $simResult['message']);
        }
        $rollback['simbase_activated'] = true;

        // --- Step 5: Assign User (MySQL) ---
        $db->prepare("UPDATE device_inventory SET server_user_assigned = ? WHERE imei = ?")
           ->execute([$userId, $data['imei']]);

        // Success Response
        $response->getBody()->write(json_encode([
            "status" => "success", 
            "message" => "Device assigned and activated successfully"
        ]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

    } catch (Exception $e) {
        // --- Rollback Logic ---
        
        // 1. Revert MySQL (Not needed as it's the last step and hasn't happened if we are here)
        
        // 2. Revert Simbase
        if ($rollback['simbase_activated']) {
             $simbase->setSimState($simIccid, 'disabled'); // Or 'suspended'/'deactivated' depending on Simbase rules
        }

        // 3. Revert Traccar Link
        if ($rollback['traccar_linked']) {
            $traccar->unlinkUserFromDevice($traccarUserId, $traccarDeviceId);
        }

        // 4. Revert Device Name
        if ($rollback['original_name']) {
            $traccar->updateDevice($traccarDeviceId, [
                'name' => $rollback['original_name'],
                'uniqueId' => $data['imei']
            ]);
        }

        $response->getBody()->write(json_encode([
            "status" => "error", 
            "message" => $e->getMessage(),
            "details" => "Changes have been rolled back."
        ]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
    }
});


$app->run();