<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class Xendit
{
    private $client;
    private $secretKey;

    public function __construct()
    {
        // Fetch the secret key from your .env file
        $this->secretKey = $_ENV['XENDIT_SECRET_KEY'] ?? '';

        if (empty($this->secretKey)) {
            throw new \Exception("XENDIT_SECRET_KEY is not set in the environment variables.");
        }

        // Initialize Guzzle Client with base Xendit Configuration
        $this->client = new Client([
            'base_uri' => 'https://api.xendit.co/',
            'headers' => [
                // Xendit requires Basic Auth where the username is the Secret Key and the password is blank
                'Authorization' => 'Basic ' . base64_encode($this->secretKey . ':'),
                'Content-Type'  => 'application/json',
                // API Version is recommended for Payment Requests
                'api-version'   => '2022-07-31' 
            ],
            // Disable SSL verification strictly for local development if needed, but keep true for production
            'verify' => true 
        ]);
    }

    /**
     * Create a Payment Request Session for the Xendit UI Component.
     */
    public function createPaymentSession($transId, $amount, $currency, $countryCode, $userId, $firstName, $lastName, $email, $phone, $origins)
    {
        // Ensure origins is an array as expected by Xendit
        if (!is_array($origins)) {
            $origins = [$origins];
        }

        $payload = [
            'reference_id' => (string)"trans-". $transId,
            'session_type' => 'PAY',
            'mode' => 'COMPONENTS',
            'amount' => (float) $amount,
            'currency' => 'PHP', // 'USD',//$currency,
            'country' => 'PH',
            'allow_save_payment_method' => 'OPTIONAL', // Added to match your requested sample data
            'customer' => [
                'reference_id' => (string) "cx-". $transId,
                'type' => 'INDIVIDUAL',
                'individual_detail' => [
                    'given_names' => $firstName
                ]
            ],
            'components_configuration' => [
                'origins' => $origins
            ]
        ];

        // Conditionally add optional fields so Xendit doesn't throw validation errors on nulls
        if (!empty($lastName)) {
            $payload['customer']['individual_detail']['surname'] = $lastName;
        }
        if (!empty($email)) {
            $payload['customer']['email'] = $email;
        }
        /*if (!empty($phone)) {
            $payload['customer']['mobile_number'] = $phone;
        }*/

        try {
            // Send request to Xendit
            $response = $this->client->post('sessions', [
                'json' => $payload
            ]);

            return [
                'success' => true,
                'data' => json_decode($response->getBody()->getContents(), true)
            ];

        } catch (RequestException $e) {
            $errorData = null;
            if ($e->hasResponse()) {
                $errorData = json_decode($e->getResponse()->getBody()->getContents(), true);
            }

            return [
                'success' => false,
                'error' => $errorData,
                'message' => $e->getMessage(),
                'data' => $payload
            ];
        }
    }
}