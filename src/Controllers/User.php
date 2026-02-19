<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Container\ContainerInterface; // <--- 1. Add this import
use Exception;

class User {
    protected $container;

    public function __construct(ContainerInterface $container) {
        $this->container = $container;
        
    }

    /**
     * POST /users/sync
     */
    public function sync(Request $request, Response $response) {
        $firebaseUser = $request->getAttribute('firebase_user');
        $data = $request->getParsedBody();

        if (!isset($data['country_code'])) {
            return $this->jsonResponse($response, ["error" => "country_code is required"], 400);
        }

        // get server url based on country code
        $db = $this->container->get('db');
        $countryData = $db->fetchOne("SELECT * FROM country_servers WHERE country_code = ?", [$data['country_code']]);

        if (isset($countryData['error'])) {
            return $this->jsonResponse($response, [
                'error' => 'Database error occurred.',
                'details' => $countryData['message']
            ], 500);
        }

        if (empty($countryData)) {
            return $this->jsonResponse($response, ['error' => 'Invalid country code or no server assigned to this region.'], 404);
        }

        try {   
            // Build Traccar user object
            $encodedPassword = rtrim(strtr(base64_encode($firebaseUser['email']), '+/', '-_'), '=');
            
            $userArray = [
                "id" => 0, // 0 indicates new user creation to Traccar
                "name" => isset($data['name']) ? $data['name'] : "user." . $firebaseUser['sub'],
                "email" => $firebaseUser['email'],
                "administrator" => false,
                "password" => $encodedPassword, // Required for creation AND updates
                "deviceLimit" => -1,
                "userLimit" => 0,
                "limitCommands" => true,
                "attributes" => ["auth_sub" => $firebaseUser['sub']]
            ];

            if(isset($data['phone'])){ $userArray['phone'] = $data['phone']; }

            // Check if user exists in local MySQL database
            $dbCheck = $db->fetchAll('SELECT * from users WHERE auth_uid = ?', [$firebaseUser['sub']]);

            // ======================================================
            // CASE 1: User NOT in local DB (Create or Link)
            // ======================================================
            if(count($dbCheck) < 1 ){     
                try {
                    $traccar = ($this->container->get('traccar'))($countryData['server_url']);

                    // Try to create the user in Traccar
                    $traccarUser = $traccar->createUser($userArray);
                    
                    if (isset($traccarUser['error'])) {
                        throw new \Exception($traccarUser['message']);
                    }   
                } catch(\Exception $tcreate) {
                    // GAP 2: Handle Duplicate Key (Account Linking)
                    if (str_contains($tcreate->getMessage(), 'duplicate key') || str_contains($tcreate->getMessage(), 'Unique index or primary key violation')) {
                        return $this->jsonResponse($response, ["error" => 'User email exists on Traccar, but account could not be retrieved for linking.'], 500);
                    } else {
                        return $this->jsonResponse($response, ["error" => "Traccar Error: " . $tcreate->getMessage()], 500);
                    }
                }
                
                // Insert into MySQL
                $dbrows = "email, auth_uid, server_id, server_url, country_code";
                $dbvalues = "?, ?, ?, ?, ?";
                $inserts = [ $firebaseUser['email'], $firebaseUser['sub'], $traccarUser['id'], $countryData['server_url'], $data['country_code'] ];

                if(isset($data['phone']) ){ 
                    $dbrows .= ", phone";
                    $dbvalues .= ", ?";
                    array_push($inserts, $data['phone']);
                }  

                // Note: If 'name' is also a column in your MySQL users table, you should add it to the insert query above.
                $dbinsert = $db->execute("INSERT INTO users({$dbrows}) VALUES ({$dbvalues})", $inserts);
                
                if(isset($dbinsert['error']) ){
                    $traccar->deleteUser($traccarUser['id']);
                    return $this->jsonResponse($response, ["error" => "Clean up complete Database Insert Error: " . $dbinsert['error']], 500);
                } else {
                    return $this->jsonResponse($response, [
                        "status" => "success", 
                        //"server_id" => $traccarUser['id'],
                        //"internal_id" => $dbinsert["last_insert_id"],
                        "server_url" => $countryData['server_url'],
                        "name" => $userArray['name'] ?? null,
                        "phone" => $userArray['phone'] ?? null
                    ], 201);
                }

            // ======================================================
            // CASE 2: Data Integrity Error
            // ======================================================
            } else if(count($dbCheck) > 1 ){
                return $this->jsonResponse($response, ["error" => "Integrity Error: Multiple user records found in database.", "db"=> $dbCheck], 500);
            
            // ======================================================
            // CASE 3: User exists in local DB (Sync/Refresh)
            // ======================================================
            } else {
                $localUser = $dbCheck[0];
                $traccar = ($this->container->get('traccar'))($countryData['server_url']); 
                $traccar_id = $localUser['server_id'];

                try {
                    // Verify the user still exists in Traccar
                    $traccarUser = $traccar->getUser($traccar_id);
                    if(isset($traccarUser['error'])){
                        // 90% sure that user does not exsist. So create user and and update DB
                        $traccarUser = $traccar->createUser($userArray);
                        if (isset($traccarUser['error'])) {
                            throw new \Exception($traccarUser['message']);
                        }
                        $updateResult = $db->execute("UPDATE users SET server_id = ? WHERE id = ?", [ $traccarUser['id'], $localUser['id'] ]);

                        if (isset($updateResult['error'])) {
                            $traccar->deleteUser($traccarUser['id']);
                            throw new \Exception("Database Sync Error: " . $updateResult['error']);
                        }

                        return $this->jsonResponse($response, [
                            "status" => "success", 
                            //"server_id" => $traccarUser['id'],
                            //"internal_id" => $localUser['id'],
                            "server_url" => $localUser['server_url'] ?? $countryData['server_url'],
                            "name" => $localUser['name'] ?? $traccarUser['name'] ?? null,
                            "phone" => $localUser['phone'] ?? $traccarUser['phone'] ?? null
                        ], 200);
                    } else {
                    // user exists and is properly linked. update if there are changes
                        $needsUpdate = false;

                        // Compare Name
                        if (($userArray['name'] ?? '') !== ($traccarUser['name'] ?? '')) {
                            $needsUpdate = true;
                            $traccarUser['name'] = $userArray['name'];
                        }
                        
                        if (($userArray['email'] ?? '') !== ($traccarUser['email'] ?? '')) {
                            $needsUpdate = true;
                            $traccarUser['email'] = $userArray['email'];
                            $traccarUser['password'] = $userArray['password']; 
                        }

                        // Compare Phone
                        if (($userArray['phone'] ?? '') !== ($traccarUser['phone'] ?? '')) {
                            $needsUpdate = true;
                            $traccarUser['phone'] = $userArray['phone'] ?? null;
                        }

                        // Compare Auth Sub (Attributes)
                        $localSub = $userArray['attributes']['auth_sub'] ?? null;
                        $remoteSub = $traccarUser['attributes']['auth_sub'] ?? null;

                        if ($localSub !== $remoteSub) {
                            $needsUpdate = true;
                            $traccarUser['attributes']['auth_sub'] = $localSub;
                        }
                        
                        if($needsUpdate){
                            $updateCheck = $traccar->updateUser($traccarUser["id"], $traccarUser);
                            if(isset($updateCheck['error'])) {
                                throw new \Exception("Traccar update failed: " . $updateCheck['message']);
                            }
                        }

                        // ADDED MYSQL FIELDS HERE
                        $resObj = [
                            "status" => "success", 
                            //"server_id" => $traccarUser['id'],
                            //"internal_id" => $localUser['id'],
                            "server_url" => $localUser['server_url'] ?? $countryData['server_url'],
                            "name" => $localUser['name'] ?? $traccarUser['name'] ?? null,
                            "phone" => $localUser['phone'] ?? $traccarUser['phone'] ?? null
                        ];

                        if(!empty($localUser["server_token"])){
                            $resObj['server_token'] = $localUser['server_token'];
                        }

                        return $this->jsonResponse($response, $resObj, 200);
                    }
                } catch (\Exception $e) {
                    return $this->jsonResponse($response, ["error" => "Local user exists but Traccar sync failed: " . $e->getMessage()], 500);
                }
            }
        } catch (\Exception $e) {
            return $this->jsonResponse($response, ["error" => "System Error: " . $e->getMessage()], 500);
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
     * POST /user/fcm-token
     * Body: { "fcm_token": "..." }
     */
    public function saveFcmToken(Request $request, Response $response): Response {
        $firebaseUser = $request->getAttribute('firebase_user'); // Get UID from middleware
        $data = $request->getParsedBody();
        $fcmToken = $data['fcm_token'] ?? null;

        if (!$fcmToken) {
            return $this->jsonResponse($response, ["error" => "fcm_token is required"], 400);
        }

        try {
            $db = $this->container->get('db');
            
            // Update the fcm_token field for the user matching the firebase sub
            $result = $db->execute(
                "UPDATE users SET fcm_token = ? WHERE auth_uid = ?", 
                [$fcmToken, $firebaseUser['sub']]
            );

            return $this->jsonResponse($response, [
                "status" => "success",
                "message" => "FCM token updated successfully"
            ]);
        } catch (\Exception $e) {
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
