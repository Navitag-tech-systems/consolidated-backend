<?php

namespace App\Services;

use Kreait\Firebase\Factory;
use SensitiveParameter;

class Firebase {
    private readonly \Kreait\Firebase\Contract\Auth $auth;
    private readonly \Kreait\Firebase\Contract\Messaging $messaging;

    public function __construct(
        #[SensitiveParameter] ?string $credentialsPath = null
    ) {
        $path = $credentialsPath ?? __DIR__ . '/../../project_secret/track-navitag-com-firebase-adminsdk-fbsvc-c7f71b0345.json';
        
        $factory = (new Factory)->withServiceAccount($path);

        $this->auth = $factory->createAuth();
        $this->messaging = $factory->createMessaging();
    }

    public function getAuth(): \Kreait\Firebase\Contract\Auth {
        return $this->auth;
    }

    public function getMessaging(): \Kreait\Firebase\Contract\Messaging {
        return $this->messaging;
    }

    // Inside src/services/Firebase.php

    public function createCustomToken($uid) 
    {
        try {
            // Assuming you have the Kreait Firebase Auth instance initialized as $this->auth
            $customToken = $this->auth->createCustomToken($uid);
            return $customToken->toString();
        } catch (\Exception $e) {
            // Handle error (e.g., log it)
            return null;
        }
    }
}