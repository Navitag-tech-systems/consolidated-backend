<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class Simbase {
    private $client;
    private $apiKey;

    /**
     * @param string|null $apiKey (Optional) Pass key manually or let it load from ENV
     */
    public function __construct($apiKey = null) {
        // 1. Resolve API Key
        if (!$apiKey) {
            $apiKey = $_ENV['SIMBASE_API_KEY'] ?? $_ENV['SIMBASE_KEY'] ?? '';
        }

        $this->apiKey = trim($apiKey);

        // 2. Initialize Client with V2 Base URI
        $this->client = new Client([
            'base_uri' => 'https://api.simbase.com/v2/', //
            'timeout'  => 30000,
            'verify'   => false, // Keep false for local dev, set to true in production
            'headers' => [
                'Authorization' => "Bearer {$this->apiKey}", //
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
            ]
        ]);
    }

    /**
     * Get Account Balance
     * Endpoint: GET /v2/account/balance
     */
    public function getAccountBalance() {
        try {
            $response = $this->client->request('GET', 'account/balance'); //
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            return [
                'error' => 'Simbase Auth Failed', 
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Fetch SIM details
     * Endpoint: GET /v2/simcards/{iccid}
     */
    public function getSimDetails(string $iccid) {
        try {
            $response = $this->client->request('GET', "simcards/{$iccid}");
            $data = json_decode($response->getBody()->getContents(), true);

            return $data;
        } catch (GuzzleException $e) {
            return ['error' => 'Simbase API unreachable', 'message' => $e->getMessage()];
        }
    }

    /**
     * Update Sim Name
     * Endpoint: PATCH /v2/simcards/{iccid}
     * Body: { "name": "New Name" }
     */
    public function setSimName(string $iccid, string $newName) {
        try {
            $response = $this->client->request('PATCH', "simcards/{$iccid}", [
                'json' => ['name' => $newName] //
            ]);
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            return ['error' => 'Failed to update SIM name', 'message' => $e->getMessage()];
        }
    }

    /**
     * Enable or Disable SIM
     * Endpoint: PATCH /v2/simcards/{iccid}
     * Body: { "state": "enabled" } (previously "sim_state")
     */
    public function setSimState(string $iccid, string $state) {
        // V2 uses 'state' instead of 'sim_state'
        // Ensure state is valid for V2 (usually 'enabled', 'disabled', or 'active')
        if (!in_array($state, ['enabled', 'disabled'])) {
             return ['error' => 'Invalid state. Use enabled or disabled.'];
        }

        try {
            $response = $this->client->request('POST', "simcards/{$iccid}/state", [
                'json' => ['state' => $state] //
            ]);
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            return ['error' => "Failed to set SIM to {$state}", 'message' => $e->getMessage()];
        }
    }

    /**
     * Fetch usage history
     * Endpoint: GET /v2/usage/simcards/{iccid}
     */
    public function getUsageHistory(string $iccid) {
        try {
            // Updated endpoint for V2
            $response = $this->client->request('GET', "usage/simcards/{$iccid}"); //
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            return ['error' => 'Could not fetch usage history', 'message' => $e->getMessage()];
        }
    }
}