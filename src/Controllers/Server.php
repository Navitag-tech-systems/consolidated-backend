<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Container\ContainerInterface;
use Exception;


class Server {
  protected $container;

  public function __construct(ContainerInterface $container) {
    $this->container = $container;
  }

  public function testServer(Request $request, Response $response) {
    $serverUrl = $_ENV['TRACCAR_TEST_URL'];
    
    $db = $this->container->get('db');
    $simbase = $this->container->get('simbase');
    $traccar = ($this->container->get('traccar'))($serverUrl);

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
  }

  public function serverInfo(Request $request, Response $response) {
    $data = $request->getParsedBody();
    $serverUrl = $data['server_url'] ?? $_ENV['TRACCAR_TEST_URL'];
    
    $db = $this->container->get('db');
    $simbase = $this->container->get('simbase');
    $traccar = ($this->container->get('traccar'))($serverUrl);

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
  }

  public function generateToken(Request $request, Response $response){
    // 1. Identify User (from Firebase Middleware)
    $firebaseUser = $request->getAttribute('firebase_user');
    $data = $request->getParsedBody();

    $serverUrl = $data['server_url'] ?? $_ENV['TRACCAR_DEFAULT_URL'];

    // 2. Re-calculate the "Encoded Password" (Fixed: No pipe operator)
    $password = rtrim(strtr(base64_encode($firebaseUser['email']), '+/', '-_'), '=');

    // 3. Get Traccar Token
    $traccar = ($this->container->get('traccar'))($serverUrl);

    // Fixed: used $firebaseemail instead of undefined $email
    $token = $traccar->createUserToken($firebaseUser['email'], $password);

    if (is_array($token) && isset($token['error'])) {
        $response->getBody()->write(json_encode($token));
        return $response->withStatus(500);
    }

    $db = $this->container->get('db');
    $dbUser = $db->execute("UPDATE users SET server_token = ? WHERE auth_uid = ? ", [$token, $firebaseUser['sub']]);

    // 4. Return Token to Frontend
    $response->getBody()->write(json_encode([
        'status' => 'success',
        'server_token' => $token
    ]));
    return $response->withHeader('Content-Type', 'application/json');
  }

/**
 * Retrieves country and network information for a specific SIM/Device
 * using the Simbase Service.
 */
public function getCountryInfo(Request $request, Response $response) {
    // 1. Get query parameters
    $queryParams = $request->getQueryParams();
    $code = $queryParams['code'] ?? null;

    // Validation: Ensure code is provided
    if (empty($code)) {
        $response->getBody()->write(json_encode(['error' => 'Country code parameter is required.']));
        return $response->withStatus(400);
    }

    // 2. Get the DB instance from the container
    $db = $this->container->get('db');

    // 3. Fetch from MySQL where country_code matches the query param
    // Note: Change 'countries' to your actual table name if it is different
    $countryData = $db->fetchOne("SELECT * FROM country_servers WHERE country_code = ?", [$code]);

    // 4. Handle database errors (based on your MysqlDatabase.php structure)
    if (isset($countryData['error'])) {
        return $this->jsonResponse($response, [
            'error' => 'Database error occurred.',
            'details' => $countryData['message']
        ], 500);
    }

    // 5. Handle empty results (no matching country found)
    if (empty($countryData)) {
        $response->getBody()->write(json_encode(['error' => 'Coutry code no match.']));
        return $response->withStatus(400);
    }

    // 6. Return successful response
    return $this->jsonResponse($response, [
        'status' => 'success',
        'data' => $countryData
    ], 200);
}
}