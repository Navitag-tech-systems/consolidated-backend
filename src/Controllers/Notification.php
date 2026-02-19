<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Container\ContainerInterface;
use App\Services\Firebase;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FirebaseNotification;
use Exception;

class Notification
{
    protected $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    /**
     * POST /notification/send
     * Body: { "token": "FCM_TOKEN", "title": "Hello", "body": "World", "data": {} }
     */
    public function send(Request $request, Response $response): Response {
        $firebaseUser = $request->getAttribute('firebase_user'); // Check if admin
        if($firebaseUser['email'] != 'superadmin@navitag.com'){
          return $this->jsonResponse($response, ['error' => 'Unauthorized'], 400);
        }


        $data = $request->getParsedBody();
        
        if (empty($data['token']) || empty($data['title']) || empty($data['body'])) {
            return $this->jsonResponse($response, ['error' => 'Missing token, title, or body'], 400);
        }

        try {
            $firebase = $this->container->get(Firebase::class);
            $messaging = $firebase->getMessaging();

            $message = CloudMessage::withTarget('token', $data['token'])
                ->withNotification(FirebaseNotification::create($data['title'], $data['body']))
                ->withData($data['data'] ?? []);

            $messaging->send($message);

            return $this->jsonResponse($response, ['status' => 'success', 'message' => 'Notification sent']);
        } catch (Exception $e) {
            return $this->jsonResponse($response, ['error' => $e->getMessage()], 500);
        }
    }

    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}