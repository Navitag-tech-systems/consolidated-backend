<?php

namespace App\Middleware;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response;
use App\Services\Firebase;

class FirebaseAuthMiddleware {
    public function __construct(private readonly Firebase $firebase) {}

    public function __invoke(Request $request, Handler $handler): Response {
        // 1. Check for Admin Key Bypass
        $adminKey = $request->getHeaderLine('X-Admin-Key');
        $expectedKey = $_ENV['ADMIN_KEY'] ?? null;

        if ($expectedKey && $adminKey === $expectedKey) {
            // Bypass: Attach a mock admin user so downstream routes don't crash
            $request = $request->withAttribute('firebase_user', [
                'email' => 'admin@system.local',
                'admin' => true
            ]);
            return $handler->handle($request);
        }

        $authHeader = $request->getHeaderLine('Authorization');
        $idToken = str_replace('Bearer ', '', $authHeader);

        if (!$idToken) {
            return $this->errorResponse('Unauthorized: No token provided', 401);
        }

        try {
            // Verify the token using your Firebase Provider
            $verifiedToken = $this->firebase->getAuth()->verifyIdToken($idToken);
            
            // Attach the Firebase User details to the request attributes
            // This allows your routes to know WHO is making the call
            $request = $request->withAttribute('firebase_user', $verifiedToken->claims()->all());
            
            return $handler->handle($request);
        } catch (\Exception $e) {
            return $this->errorResponse('Unauthorized: ' . $e->getMessage(), 401);
        }
    }

    private function errorResponse(string $message, int $status): Response {
        $response = new Response();
        $response->getBody()->write(json_encode(['error' => $message]));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}