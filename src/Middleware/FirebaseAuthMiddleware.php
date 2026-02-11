<?php

namespace App\Middleware;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response;
use App\Services\FirebaseProvider;

class FirebaseAuthMiddleware {
    public function __construct(private readonly FirebaseProvider $firebase) {}

    public function __invoke(Request $request, Handler $handler): Response {
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