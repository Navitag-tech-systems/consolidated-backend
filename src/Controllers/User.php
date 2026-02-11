<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Exception;

class User {
    protected $container;

    public function __construct($container) {
        $this->container = $container;
    }

    /**
     * POST /users/sync
     */
    public function sync(Request $request, Response $response) {
        $data = $request->getParsedBody();
        $serverUrl = $data['server_url'] ?? null;

        if (!$serverUrl) {
            return $this->jsonResponse($response, ["error" => "server_url is required"], 400);
        }

        $db = $this->container->get('db');
        $traccar = ($this->container->get('traccar'))($serverUrl);

        try {
            // PHP 8.5 compatible string manipulation for password
            $encodedPassword = rtrim(strtr(base64_encode($data['email']), '+/', '-_'), '=');
            
            $traccarUser = $traccar->createUser([
                'name' => $data['email'],
                'email' => $data['email'],
                'password' => $encodedPassword,
            ]);

            if (isset($traccarUser['error'])) {
                throw new Exception($traccarUser['message']);
            }

            $db->execute("INSERT INTO users (email, firebase_uid, server_id, server_url, created_at) VALUES (?, ?, ?, ?, NOW())", [
                $data['email'], $data['uid'], $traccarUser['id'], $serverUrl
            ]);

            return $this->jsonResponse($response, [
                "status" => "success", 
                "traccar_id" => $traccarUser['id']
            ], 201);
        } catch (Exception $e) {
            return $this->jsonResponse($response, ["error" => $e->getMessage()], 500);
        }
    }

    /**
     * POST /users/update
     */
    public function update(Request $request, Response $response) {
        $data = $request->getParsedBody();
        $db = $this->container->get('db');
        
        try {
            $localUser = $db->fetchOne("SELECT email FROM users WHERE id = ?", [$data['id']]);
            if (!$localUser) {
                throw new Exception("User not found");
            }

            $traccar = ($this->container->get('traccar'))($data['server_url']);
            $traccarResult = $traccar->updateUser((int)$data['server_id'], [
                'name' => $data['name'] ?? $localUser['email'],
                'email' => $localUser['email'],
                'phone' => $data['mobile'] ?? null
            ]);

            if (isset($traccarResult['error'])) {
                throw new Exception($traccarResult['message']);
            }

            if (isset($data['name'])) {
                $db->execute("UPDATE users SET name = ? WHERE id = ?", [$data['name'], $data['id']]);
            }
            if (isset($data['mobile'])) {
                $db->execute("UPDATE users SET mobile = ? WHERE id = ?", [$data['mobile'], $data['id']]);
            }
            
            return $this->jsonResponse($response, ["status" => "success"]);
        } catch (Exception $e) {
            return $this->jsonResponse($response, ["error" => $e->getMessage()], 500);
        }
    }

    /**
     * POST /users/delete
     */
    public function delete(Request $request, Response $response) {
        $data = $request->getParsedBody();
        $db = $this->container->get('db');
        
        try {
            $traccar = ($this->container->get('traccar'))($data['server_url']);
            $res = $traccar->deleteUser((int)$data['server_id']);
            
            if (isset($res['error'])) {
                throw new Exception($res['message']);
            }

            $db->execute("DELETE FROM users WHERE id = ?", [$data['id']]);
            
            return $this->jsonResponse($response, ["status" => "success"]);
        } catch (Exception $e) {
            return $this->jsonResponse($response, ["error" => $e->getMessage()], 500);
        }
    }

    /**
     * Helper to standardize JSON responses
     */
    private function jsonResponse(Response $response, array $data, int $status = 200) {
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}