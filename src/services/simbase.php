<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class Simbase {
    private $client;
    private $apiKey;

    public function __construct(string $apiKey) {
        $this->apiKey = $apiKey;
        $this->client = new Client([
            'base_uri' => 'https://api.simbase.com/v1/',
            'timeout'  => 5.0, // Don't let a slow API hang your app
            'headers' => [
                'Authorization' => "Bearer {$this->apiKey}",
                'Accept'        => 'application/json',
            ]
        ]);
    }

    /**
     * Fetch SIM details and current data usage
     */
    public function getSimDetails(string $iccid) {
        try {
            $response = $this->client->request('GET', "simcards/{$iccid}");
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            // Log error here if needed
            return [
                'error' => 'Simbase API unreachable',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Fetch recent data usage logs
     */
    public function getUsageHistory(string $iccid) {
        try {
            // Example endpoint for usage history
            $response = $this->client->request('GET', "simcards/{$iccid}/usage");
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            return ['error' => 'Could not fetch usage history'];
        }
    }
}