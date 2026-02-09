<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use App\Services\MysqlDatabase;
use App\Services\Traccar;
use App\Services\Simbase;

require __DIR__ . '/../vendor/autoload.php';

// 1. Load Environment Variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();



// 2. Initialize Slim App
$app = AppFactory::create();

// Add Middleware (Body Parsing for JSON inputs, Error Handling)
$app->addBodyParsingMiddleware();
$app->addErrorMiddleware(true, true, true);

/**
 * Route: System Status Check
 * Verifies connections to MySQL, Traccar, and Simbase
 */
$app->get('/status', function (Request $request, Response $response) {
    $results = [
        'timestamp' => date('c'),
        'services'  => []
    ];

    // --- 1. Test MySQL Connection ---
    try {
        $db = new MysqlDatabase();
        // Run a simple query to ensure the connection is actually alive
        $check = $db->fetchOne("SELECT 1 as alive");
        
        if (isset($check['error'])) {
            $results['services']['mysql'] = [
                'status' => 'error', 
                'message' => $check['message']
            ];
        } else {
            $results['services']['mysql'] = [
                'status' => 'online', 
                'details' => 'Connection successful'
            ];
        }
    } catch (Exception $e) {
        $results['services']['mysql'] = ['status' => 'error', 'message' => $e->getMessage()];
    }

    // --- 2. Test Traccar Connection ---
    try {
        $traccar_url =  $_ENV['TRACCAR_TEST_URL'];
        $traccar = new Traccar($traccar_url);
        $serverInfo = $traccar->getServerInfo();

        if (isset($serverInfo['error'])) {
            $results['services']['traccar'] = [
                'status' => 'error', 
                'message' => $serverInfo['message'] ?? 'Unknown error'
            ];
        } else {
            $results['services']['traccar'] = [
                'status' => 'online',
                'version' => $serverInfo['version'] ?? 'unknown',
                'api_check' => 'getServerInfo'
            ];
        }
    } catch (Exception $e) {
        $results['services']['traccar'] = ['status' => 'error', 'message' => $e->getMessage()];
    }

    // --- 3. Test Simbase Connection ---
    try {
        $simbase = new Simbase();
        $message = $simbase->getAccountBalance();
    
        if (isset($message['error'])) {
            $results['services']['simbase'] = [
                'status' => 'error',
                'message' => $message['errors'] ?? 'Unknown error'
            ];
        } else {
            $results['services']['simbase'] = [
                'status' => 'online',
                'currency' => $message['currency'] ?? 'USD',
                'balance' => $message['balance'] ?? 0,
                'api_check' => 'getAccountBalance'
            ];
        }
    } catch (Exception $e) {
        $results['services']['simbase'] = ['status' => 'error', 'message' => $e->getMessage()];
    }

    // Determine Overall System Health
    $allOnline = true;
    foreach ($results['services'] as $service) {
        if ($service['status'] !== 'online') {
            $allOnline = false;
            break;
        }
    }
    $results['overall_status'] = $allOnline ? 'healthy' : 'degraded';

    // Return JSON Response
    $response->getBody()->write(json_encode($results, JSON_PRETTY_PRINT));
    return $response->withHeader('Content-Type', 'application/json');
});

// Run the application
$app->run();