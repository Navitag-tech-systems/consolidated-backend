<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class Simbase {
    private $client;

    public function __construct() {
        // Retrieve API Key directly from .env
        $apiKey = $_ENV['SIMBASE_API_KEY'] ?? '';

        $this->client = new Client([
            'base_uri' => 'https://api.simbase.com/v1/',
            'timeout'  => 5.0,
            'headers' => [
                'Authorization' => "Bearer {$apiKey}",
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
            ]
        ]);
    }

    /**
     * Fetch SIM details
     */
    public function getSimDetails(string $iccid) {
        try {
            $response = $this->client->request('GET', "simcards/{$iccid}");
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            return ['error' => 'Simbase API unreachable', 'message' => $e->getMessage()];
        }
    }

    /**
     * Get Sim Name (device_name)
     */
    public function getSimName(string $iccid) {
        $details = $this->getSimDetails($iccid);
        return $details['device_name'] ?? 'Unknown Device';
    }

    /**
     * Update Sim Name
     */
    public function setSimName(string $iccid, string $newName) {
        try {
            $response = $this->client->request('PATCH', "simcards/{$iccid}", [
                'json' => ['device_name' => $newName]
            ]);
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            return ['error' => 'Failed to update SIM name'];
        }
    }

    /**
     * Enable or Disable SIM
     * @param string $state 'enabled' or 'disabled'
     */
    public function setSimState(string $iccid, string $state) {
        if (!in_array($state, ['enabled', 'disabled'])) {
            return ['error' => 'Invalid state. Use enabled or disabled.'];
        }

        try {
            $response = $this->client->request('PATCH', "simcards/{$iccid}", [
                'json' => ['sim_state' => $state]
            ]);
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            return ['error' => "Failed to set SIM to {$state}"];
        }
    }

    /**
     * Fetch recent data usage logs
     */
    public function getUsageHistory(string $iccid) {
        try {
            $response = $this->client->request('GET', "simcards/{$iccid}/usage");
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            return ['error' => 'Could not fetch usage history'];
        }
    }
}