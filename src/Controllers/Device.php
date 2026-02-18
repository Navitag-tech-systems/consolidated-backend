<?php

namespace App\Controllers;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use DateTimeImmutable;
use DateTimeZone;

class Device
{
    protected $container;

    // Inject the Slim Container so we can access 'db' and 'simbase' services
    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    /**
     * Enable Device Route
     * POST /device/enable
     */
    public function enable(Request $request, Response $response, array $args): Response
    {
        $firebaseUser = $request->getAttribute('firebase_user');
        $data = $request->getParsedBody();
        $db = $this->container->get('db');

        // 1. Fetch user and device
        $user = $db->fetchOne("SELECT server_url, server_id FROM users WHERE auth_uid = ?", [$firebaseUser['sub']]);
        $device = $db->fetchOne("SELECT server_url, server_ref, sim_iccid, server_user_id, preloaded_months, expiration FROM device_inventory WHERE imei = ?", [$data['imei']]);

        // --- Validation ---
        if (empty($device['server_user_id'])) {
            return $this->jsonResponse($response, ['error' => 'Device not assigned to a user.'], 400);
        }
        if (empty($device['sim_iccid'])) {
            return $this->jsonResponse($response, ['error' => 'No SIM card associated with this device.'], 400);
        }
        
        if ($device['server_user_id'] != $user['server_id']) {
            return $this->jsonResponse($response, ['error' => 'Unauthorized device ownership.'], 403);
        }

        // --- 2. Expiration Check (UTC) ---
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        if (!empty($device['expiration'])) {
            $expirationDate = new DateTimeImmutable($device['expiration'], new DateTimeZone('UTC'));
            // Note: This logic prevents enabling if ALREADY expired. 
            // If the intent is to renew via this endpoint, this check will block it.
            if ($now > $expirationDate) {
                return $this->jsonResponse($response, ['error' => 'Device is expired. Please renew.'], 403);
            }
        }

        // --- 3. Check Simbase Status ---
        $simbase = $this->container->get('simbase');
        $simDetails = $simbase->getSimDetails($device['sim_iccid']);

        if (!$simDetails || isset($simDetails['error'])) {
            return $this->jsonResponse($response, ['error' => 'Failed to fetch SIM status.'], 502);
        }

        $status = $simDetails['status'] ?? 'unknown';

        if ($status === 'enabled' || $status === 'active') {
            return $this->jsonResponse($response, [
                'message' => 'Device is already enabled.',
                'expiration' => $device['expiration']
            ], 200);
        }

        // --- 4. Activate & Calculate New Expiration ---
        $activationResult = $simbase->setSimState($device['sim_iccid'], 'enabled');
        
        // Handle Simbase failure
        if (!$activationResult) {
            return $this->jsonResponse($response, ['error' => 'Failed to activate SIM.'], 500);
        }

        $monthsToAdd = (int)($device['preloaded_months'] ?? 1);
        
        // Add months and set time to end of day UTC
        $newExpirationDate = $now->modify("+$monthsToAdd months")->setTime(23, 59, 59);
        $newExpirationStr = $newExpirationDate->format('Y-m-d H:i:s');

        // Update DB
        $db->execute(
            "UPDATE device_inventory SET expiration = ? WHERE imei = ?", 
            [$newExpirationStr, $data['imei']]
        );

        return $this->jsonResponse($response, [
            'message' => 'Device enabled successfully',
            'new_expiration' => $newExpirationStr,
        ], 200);
    }

    /**
     * Disable Device Route
     * POST /device/disable
     */
    public function disable(Request $request, Response $response, array $args): Response
    {
        $firebaseUser = $request->getAttribute('firebase_user');
        $data = $request->getParsedBody();
        $db = $this->container->get('db');

        // 1. Fetch user and device
        $user = $db->fetchOne("SELECT server_url, server_id FROM users WHERE auth_uid = ?", [$firebaseUser['sub']]);
        $device = $db->fetchOne("SELECT server_url, server_ref, sim_iccid, server_user_id, expiration FROM device_inventory WHERE imei = ?", [$data['imei']]);

        // --- Validation ---
        if (empty($device['server_user_id'])) {
            return $this->jsonResponse($response, ['error' => 'Device not assigned to a user.'], 400);
        }
        if (empty($device['sim_iccid'])) {
            return $this->jsonResponse($response, ['error' => 'No SIM card associated with this device.'], 400);
        }
        
        if ($device['server_user_id'] != $user['server_id']) {
            return $this->jsonResponse($response, ['error' => 'Unauthorized device ownership.'], 403);
        }

        // --- 3. Check Simbase Status ---
        $simbase = $this->container->get('simbase');
        $simDetails = $simbase->getSimDetails($device['sim_iccid']);

        if (!$simDetails || isset($simDetails['error'])) {
            return $this->jsonResponse($response, ['error' => 'Failed to fetch SIM status.'], 502);
        }

        $status = $simDetails['status'] ?? 'unknown';

        if ($status === 'disabled' || $status === 'inactive') {
            return $this->jsonResponse($response, [
                'message' => 'Device is already disabled.',
            ], 200);
        }

        // --- 4. Deactivate ---
        $activationResult = $simbase->setSimState($device['sim_iccid'], 'disabled');
        
        if (!$activationResult) {
            return $this->jsonResponse($response, ['error' => 'Failed to disable SIM.'], 500);
        }

        return $this->jsonResponse($response, [
            'message' => 'Device disabled successfully',
            'sim_status' => 'disabled'
        ], 200);
    }

    /**
     * Helper to return JSON response
     */
    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}