<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Exception;


class Server {
  protected $container;

  public function __construct($container) {
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
}