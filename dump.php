<?php

# POST /users/sync
$app->post('/users/sync', function (Request $request, Response $response) {
    $data = $request->getParsedBody();
    $serverUrl = $data['server_url'] ?? null;

    if (!$serverUrl) {
        $response->getBody()->write(json_encode(["error" => "server_url is required"]));
        return $response->withStatus(400);
    }

    $db = $this->get('db');
    $traccar = ($this->get('traccar'))($serverUrl);

    try {
        // PHP 8.5 string manipulation
        $encodedPassword = rtrim(strtr(base64_encode($data['email']), '+/', '-_'), '=');
        
        $traccarUser = $traccar->createUser([
            'name' => $data['email'],
            'email' => $data['email'],
            'password' => $encodedPassword,
        ]);

        if (isset($traccarUser['error'])) throw new Exception($traccarUser['message']);

        $db->execute("INSERT INTO users (email, firebase_uid, server_id, server_url, created_at) VALUES (?, ?, ?, ?, NOW())", [
            $data['email'], $data['uid'], $traccarUser['id'], $serverUrl
        ]);

        $response->getBody()->write(json_encode(["status" => "success", "traccar_id" => $traccarUser['id']]));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    } catch (Exception $e) {
        $response->getBody()->write(json_encode(["error" => $e->getMessage()]));
        return $response->withStatus(500);
    }
});

# POST /users/update
$app->post('/users/update', function (Request $request, Response $response) {
    $data = $request->getParsedBody();
    $db = $this->get('db');
    
    try {
        $localUser = $db->fetchOne("SELECT email FROM users WHERE id = ?", [$data['id']]);
        if (!$localUser) throw new Exception("User not found");

        $traccar = ($this->get('traccar'))($data['server_url']);
        $traccarResult = $traccar->updateUser((int)$data['server_id'], [
            'name' => $data['name'] ?? $localUser['email'],
            'email' => $localUser['email'],
            'phone' => $data['mobile'] ?? null
        ]);

        if (isset($traccarResult['error'])) throw new Exception($traccarResult['message']);

        if (isset($data['name'])) $db->execute("UPDATE users SET name = ? WHERE id = ?", [$data['name'], $data['id']]);
        if (isset($data['mobile'])) $db->execute("UPDATE users SET mobile = ? WHERE id = ?", [$data['mobile'], $data['id']]);
        
        $response->getBody()->write(json_encode(["status" => "success"]));
        return $response->withHeader('Content-Type', 'application/json');
    } catch (Exception $e) {
        $response->getBody()->write(json_encode(["error" => $e->getMessage()]));
        return $response->withStatus(500);
    }
});

# POST /users/delete
$app->post('/users/delete', function (Request $request, Response $response) {
    $data = $request->getParsedBody();
    $db = $this->get('db');
    
    try {
        $traccar = ($this->get('traccar'))($data['server_url']);
        $res = $traccar->deleteUser((int)$data['server_id']);
        if (isset($res['error'])) throw new Exception($res['message']);

        $db->execute("DELETE FROM users WHERE id = ?", [$data['id']]);
        
        $response->getBody()->write(json_encode(["status" => "success"]));
        return $response->withHeader('Content-Type', 'application/json');
    } catch (Exception $e) {
        $response->getBody()->write(json_encode(["error" => $e->getMessage()]));
        return $response->withStatus(500);
    }
});