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
     * * @param string $transId Your internal unique transaction/order ID
     * @param float $amount The amount to charge
     * @param string $currency The currency code (e.g., 'USD', 'PHP')
     * @param string $countryCode ISO 3166-1 alpha-2 country code (e.g., 'US', 'PH')
     * @param string $userId Your internal User ID
     * @param string $name User's given name
     * @param array|string $origins Array of domains where the component will be loaded (e.g., ['https://yourdomain.com'])
     * @return array Array containing the Payment Request response
     */
    public function createPaymentSession($transId, $amount, $currency, $countryCode, $userId, $name, $origins)
    {
        // Ensure origins is an array as expected by Xendit
        if (!is_array($origins)) {
            $origins = [$origins];
        }

        $payload = [
            'reference_id' => (string) $transId,
            'session_type' => 'PAY',
            'mode' => 'COMPONENTS',
            'amount' => (float) $amount,
            'currency' => $currency,
            'country' => $countryCode,
            'customer' => [
                'reference_id' => (string) $userId,
                'type' => 'INDIVIDUAL',
                'individual_detail' => [
                    'given_names' => $name
                ]
            ],
            'components_configuration' => [
                'origins' => $origins
            ]
        ];

        try {
            // Send request to Xendit
            $response = $this->client->post('payment_requests', [
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
                'message' => $e->getMessage()
            ];
        }
    }
}