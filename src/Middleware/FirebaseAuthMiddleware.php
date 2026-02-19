<?php

namespace App\Middleware;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response;
use App\Services\Firebase; // Ensure this matches your actual service class name (e.g., FirebaseProvider)
use Kreait\Firebase\Exception\Auth\FailedToVerifyToken;
use Kreait\Firebase\Exception\Auth\RevokedIdToken;
use Throwable;

class FirebaseAuthMiddleware {
    public function __construct(private readonly Firebase $firebase) {}

    private $excludedRoutes = [
        '/v1/server/country-code'
    ];

    public function __invoke(Request $request, Handler $handler): Response {
        $path = $request->getUri()->getPath();

        // Check if current path is in the excluded list
        if (in_array($path, $this->excludedRoutes)) {
            return $handler->handle($request);
        }


        // 1. Check for Admin Key Bypass
        $adminKey = $request->getHeaderLine('X-Admin-Key');
        $expectedKey = $_ENV['ADMIN_KEY'] ?? null;

        if ($expectedKey && $adminKey === $expectedKey) {
            // Bypass: Attach a mock admin user
            $request = $request->withAttribute('firebase_user', [
                'email' => 'superadmin@navitag.com',
                'uid' => 'QP6@hTbKE$mBK2!',
                'admin' => true
            ]);
            return $handler->handle($request);
        }

        // 2. Extract Bearer Token safely
        $authHeader = $request->getHeaderLine('Authorization');
        if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            return $this->errorResponse('Missing or malformed Bearer token', 401);
        }
        
        $idToken = $matches[1];

        try {
            // 3. Verify Token
            // This will throw specific exceptions if the token is expired, invalid, etc.
            $verifiedToken = $this->firebase->getAuth()->verifyIdToken($idToken);
            
            // 4. Attach User to Request
            $request = $request->withAttribute('firebase_user', $verifiedToken->claims()->all());
            
            return $handler->handle($request);

        } catch (RevokedIdToken $e) {
            return $this->errorResponse('Token revoked: ' . $e->getMessage(), 401);
            
        } catch (FailedToVerifyToken $e) {
            // This catches "The token is expired", "The token is issued in the future", etc.
            // We pass the specific Firebase message directly to the client.
            return $this->errorResponse($e->getMessage(), 401);
            
        } catch (Throwable $e) {
            // Catch-all for any other system errors (DB connection, etc)
            return $this->errorResponse('Authentication Error: ' . $e->getMessage(), 401);
        }
    }

    private function errorResponse(string $message, int $status): Response {
        $response = new Response();
        // Returns a clean JSON error without the generic "Unauthorized:" prefix if not desired
        $response->getBody()->write(json_encode([
            'status' => 'error',
            'message' => $message
        ]));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}