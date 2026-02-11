<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use DI\Container;
use App\Services\MysqlDatabase;
use App\Services\Traccar;
use App\Services\Simbase;
use App\Services\FirebaseProvider; // New Service
use App\Middleware\FirebaseAuthMiddleware; // New Middleware


// 1. Force Debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

try {

require __DIR__ . '/vendor/autoload.php';


// 1. Load Environment Variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// 2. Initialize DI Container
$container = new Container();

$container->set('db', fn() => new MysqlDatabase());
$container->set('simbase', fn() => new Simbase());

// Firebase Service Registration
$container->set(FirebaseProvider::class, fn() => new FirebaseProvider());

// Traccar Factory for dynamic domains
$container->set('traccarFactory', function () {
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

/**
 * AUTHENTICATION LAYER
 * Uncomment the line below to protect all routes under this API.
 * Ensure you have created src/middleware/FirebaseAuthMiddleware.php first.
 */
//$app->add($container->get(FirebaseAuthMiddleware::class));


# POST /status
$app->post('/status', function (Request $request, Response $response) {
    $data = $request->getParsedBody();
    $serverUrl = $data['server_url'] ?? $_ENV['TRACCAR_TEST_URL'];
    
    $db = $this->get('db');
    $simbase = $this->get('simbase');
    $traccar = ($this->get('traccarFactory'))($serverUrl);

    // Mysql Check
    $mysqlRes = $db->fetchOne("SELECT 1 as alive");
    $mysqlStatus = isset($mysqlRes['error']) 
        ? ['status' => 'error', 'message' => $mysqlRes['message']] 
        : ['status' => 'online'];

    // Traccar Check
    $traccarRes = $traccar->getServerInfo();
    $traccarStatus = isset($traccarRes['error']) 
        ? ['status' => 'error', 'message' => $traccarRes['message']] 
        : ['status' => 'online', 'version' => $traccarRes['version'] ?? 'unknown'];

    // Simbase Check
    $simbaseRes = $simbase->getAccountBalance();
    $simbaseStatus = isset($simbaseRes['error']) 
        ? ['status' => 'error', 'message' => $simbaseRes['errors'] ?? 'Unknown'] 
        : ['status' => 'online', 'balance' => $simbaseRes['balance'] ?? 0];

    $results = [
        'timestamp' => date('c'),
        'services' => [
            'mysql'   => $mysqlStatus,
            'traccar' => $traccarStatus,
            'simbase' => $simbaseStatus
        ]
    ];

    $response->getBody()->write(json_encode($results, JSON_PRETTY_PRINT));
    return $response->withHeader('Content-Type', 'application/json');
});


# GET /status-test (Same as above but GET for easy browser testing)
$app->get('/status-test', function (Request $request, Response $response) {
    $serverUrl = $_ENV['TRACCAR_TEST_URL'];
    
    $db = $this->get('db');
    $simbase = $this->get('simbase');
    $traccar = ($this->get('traccarFactory'))($serverUrl);

    // Mysql Check
    $mysqlRes = $db->fetchOne("SELECT 1 as alive");
    $mysqlStatus = isset($mysqlRes['error']) 
        ? ['status' => 'error', 'message' => $mysqlRes['message']] 
        : ['status' => 'online'];

    // Traccar Check
    $traccarRes = $traccar->getServerInfo();
    $traccarStatus = isset($traccarRes['error']) 
        ? ['status' => 'error', 'message' => $traccarRes['message']] 
        : ['status' => 'online', 'version' => $traccarRes['version'] ?? 'unknown'];

    // Simbase Check
    $simbaseRes = $simbase->getAccountBalance();
    $simbaseStatus = isset($simbaseRes['error']) 
        ? ['status' => 'error', 'message' => $simbaseRes['errors'] ?? 'Unknown'] 
        : ['status' => 'online', 'balance' => $simbaseRes['balance'] ?? 0];

    $results = [
        'timestamp' => date('c'),
        'services' => [
            'mysql'   => $mysqlStatus,
            'traccar' => $traccarStatus,
            'simbase' => $simbaseStatus
        ]
    ];

    $response->getBody()->write(json_encode($results, JSON_PRETTY_PRINT));
    return $response->withHeader('Content-Type', 'application/json');
});


# POST /users/sync
$app->post('/users/sync', function (Request $request, Response $response) {
    $data = $request->getParsedBody();
    $serverUrl = $data['server_url'] ?? null;

    if (!$serverUrl) {
        $response->getBody()->write(json_encode(["error" => "server_url is required"]));
        return $response->withStatus(400);
    }

    $db = $this->get('db');
    $traccar = ($this->get('traccarFactory'))($serverUrl);

    try {
        // PHP 8.5 string manipulation
        $encodedPassword = rtrim(strtr(base64_encode($data['email']), '+/', '-_'), '=');
        
        $traccarUser = $traccar->createUser([
            'name' => $data['email'],
            'email' => $data['email'],
            'password' => $encodedPassword,
        ]);

        if (isset($traccarUser['error'])) throw new Exception($traccarUser['message']);

        $db->execute("INSERT INTO users (email, firebase_uid, server_id, server_url, created_at) VALUES (?, ?, ?, ?, NOW())", [
            $data['email'], $data['uid'], $traccarUser['id'], $serverUrl
        ]);

        $response->getBody()->write(json_encode(["status" => "success", "traccar_id" => $traccarUser['id']]));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    } catch (Exception $e) {
        $response->getBody()->write(json_encode(["error" => $e->getMessage()]));
        return $response->withStatus(500);
    }
});


# POST /users/update
$app->post('/users/update', function (Request $request, Response $response) {
    $data = $request->getParsedBody();
    $db = $this->get('db');
    
    try {
        $localUser = $db->fetchOne("SELECT email FROM users WHERE id = ?", [$data['id']]);
        if (!$localUser) throw new Exception("User not found");

        $traccar = ($this->get('traccarFactory'))($data['server_url']);
        $traccarResult = $traccar->updateUser((int)$data['server_id'], [
            'name' => $data['name'] ?? $localUser['email'],
            'email' => $localUser['email'],
            'phone' => $data['mobile'] ?? null
        ]);

        if (isset($traccarResult['error'])) throw new Exception($traccarResult['message']);

        if (isset($data['name'])) $db->execute("UPDATE users SET name = ? WHERE id = ?", [$data['name'], $data['id']]);
        if (isset($data['mobile'])) $db->execute("UPDATE users SET mobile = ? WHERE id = ?", [$data['mobile'], $data['id']]);
        
        $response->getBody()->write(json_encode(["status" => "success"]));
        return $response->withHeader('Content-Type', 'application/json');
    } catch (Exception $e) {
        $response->getBody()->write(json_encode(["error" => $e->getMessage()]));
        return $response->withStatus(500);
    }
});


# POST /users/delete
$app->post('/users/delete', function (Request $request, Response $response) {
    $data = $request->getParsedBody();
    $db = $this->get('db');
    
    try {
        $traccar = ($this->get('traccarFactory'))($data['server_url']);
        $res = $traccar->deleteUser((int)$data['server_id']);
        if (isset($res['error'])) throw new Exception($res['message']);

        $db->execute("DELETE FROM users WHERE id = ?", [$data['id']]);
        
        $response->getBody()->write(json_encode(["status" => "success"]));
        return $response->withHeader('Content-Type', 'application/json');
    } catch (Exception $e) {
        $response->getBody()->write(json_encode(["error" => $e->getMessage()]));
        return $response->withStatus(500);
    }
});


# POST /device/add
$app->post('/device/add', function (Request $request, Response $response) {
    $data = $request->getParsedBody();
    $db = $this->get('db');
    $simbase = $this->get('simbase');
    $traccar = ($this->get('traccarFactory'))($data['server_url']);

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


# POST /server/token
/* 
$app->post('/server/token', function (Request $request, Response $response) {
    // 1. Identify User (from Firebase Middleware)
    $firebaseUser = $request->getAttribute('firebase_user');
    $firebaseemail = $firebaseUser['email'];
    $data = $request->getParsedBody();

    if($data["email"] !== $firebaseemail) {
        $response->getBody()->write(json_encode([
            'error' => 'Email mismatch',
            'message' => 'The email provided does not match the authenticated Firebase user.'
        ]));
        return $response->withStatus(403);
    }
    
    $serverUrl = $data['server_url'] ?? $_ENV['TRACCAR_DEFAULT_URL'];

    // 2. Re-calculate the "Encoded Password" (Fixed: No pipe operator)
    $password = rtrim(strtr(base64_encode($firebaseemail), '+/', '-_'), '=');

    // 3. Get Traccar Token
    $traccar = ($this->get('traccarFactory'))($serverUrl);
    
    // Fixed: used $firebaseemail instead of undefined $email
    $token = $traccar->createUserToken($firebaseemail, $password);

    if (is_array($token) && isset($token['error'])) {
        $response->getBody()->write(json_encode($token));
        return $response->withStatus(500);
    }

    // 4. Return Token to Frontend
    $response->getBody()->write(json_encode([
        'status' => 'success',
        'traccar_token' => $token
    ]));
    return $response->withHeader('Content-Type', 'application/json');
})->add($container->get(FirebaseAuthMiddleware::class));
*/

$app->run();


} catch (Exception $e) {
    // Catch any initialization errors
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'Initialization failed',
        'message' => $e->getMessage()
    ]);
    exit(1);
}
?>