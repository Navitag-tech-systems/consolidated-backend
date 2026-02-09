<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class Traccar {
    private $client;

    public function __construct($baseUrl) {
        // Retrieve configuration directly from environment variables
        $username = $_ENV['TRACCAR_ADMIN_USER'] ?? ''; // This is usually the admin email
        $password = $_ENV['TRACCAR_ADMIN_PASS'] ?? '';

        $this->client = new Client([
            'base_uri' => rtrim($baseUrl, '/') . '/api/',
            'timeout'  => 5.0,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            // Basic Authentication using the credentials from .env
            'auth' => [$username, $password], 
        ]);
    }

    /**
     * Get the latest known position for a device
     */
    public function getLatestPosition($deviceId) {
        try {
            $response = $this->client->request('GET', 'positions', [
                'query' => ['deviceId' => $deviceId]
            ]);
            $positions = json_decode($response->getBody()->getContents(), true);
            return !empty($positions) ? $positions[0] : [];
        } catch (GuzzleException $e) {
            return [];
        }
    }

    /**
     * Create a new User
     * @param array $userData ['name' => 'John', 'email' => 'john@doe.com', 'password' => 'secret']
     */
    public function createUser(array $userData) {
        try {
            // Default settings for new users
            $payload = array_merge([
                'id' => -1,
                'disabled' => false,
                'admin' => false,
                'map' => 'osm',
                'deviceLimit' => -1,
                'expirationTime' => null,
            ], $userData);

            $response = $this->client->request('POST', 'users', [
                'json' => $payload
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            return ['error' => 'Failed to create user', 'message' => $e->getMessage()];
        }
    }

    /**
     * Update an existing User
     * @param int $id The Traccar User ID
     * @param array $userData Fields to update
     */
    public function updateUser(int $id, array $userData) {
        try {
            $userData['id'] = $id; // ID is required in the body for PUT requests
            
            $response = $this->client->request('PUT', "users/{$id}", [
                'json' => $userData
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            return ['error' => 'Failed to update user', 'message' => $e->getMessage()];
        }
    }

    /**
     * Link a User to a Device
     * @param int $userId
     * @param int $deviceId
     */
    public function linkUserToDevice(int $userId, int $deviceId) {
        try {
            $payload = [
                'userId' => $userId,
                'deviceId' => $deviceId
            ];

            // Traccar uses /permissions to link entities
            $response = $this->client->request('POST', 'permissions', [
                'json' => $payload
            ]);

            if ($response->getStatusCode() === 204 || $response->getStatusCode() === 200) {
                return ['status' => 'success', 'message' => 'Device linked to user'];
            }
            
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            return ['error' => 'Failed to link device', 'message' => $e->getMessage()];
        }
    }

    /**
     * Get Traccar Server Information
     * Returns version, map settings, and global server config.
     */
    public function getServerInfo() {
        try {
            $response = $this->client->request('GET', 'server');
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            return ['error' => 'Failed to fetch server info', 'message' => $e->getMessage()];
        }
    }
}