Navitag Consolidated Backend - Unified API Documentation

GLOBAL API INFORMATION
---------------------------------------------------------
* Base URL Path: All routes are prefixed with `/v1`
* Authentication: All routes are protected by the `FirebaseAuthMiddleware`. You must pass a valid Firebase Auth token in the request (usually as a Bearer token in the Authorization header).

=========================================================
1. GENERAL / SYSTEM ROUTES
=========================================================

Root Endpoint
* Path: GET /
* Description: Verifies that the API is reachable.
* Inputs: None
* Expected Success Output: 
  "Navitag Consolidated API v1."

Auth Check
* Path: GET /authcheck
* Description: Returns the decoded Firebase user information from the middleware.
* Inputs: None
* Expected Success Output: 
  {
    "iss": "...",
    "sub": "firebase_uid",
    "email": "user@example.com"
  }

Server Status 
* Path: POST /server/status
* Description: Checks the connection status for MySQL, Traccar, and Simbase.
* Inputs (JSON Body):
  - server_url (Optional, String): Custom Traccar URL. Defaults to the TRACCAR_TEST_URL environment variable if not provided.
* Expected Success Output:
  {
    "timestamp": "2026-02-19T12:00:00+00:00",
    "services": {
      "mysql": {"status": "online"},
      "traccar": {"status": "online", "version": "5.x"},
      "simbase": {"status": "online", "balance": 100}
    }
  }

Generate Server Token
* Path: POST /server/token
* Description: Generates a Traccar API token for the authenticated Firebase user and updates the user record in MySQL.
* Inputs (JSON Body):
  - server_url (Optional, String): Traccar URL. Defaults to the TRACCAR_DEFAULT_URL environment variable if not provided.
* Expected Success Output:
  {
    "status": "success",
    "server_token": "generated_token_string"
  }

Test Server
* Path: GET /server/test
* Description: A GET alternative to `/server/status` for easy browser testing. Utilizes the TRACCAR_TEST_URL environment variable.
* Inputs: None
* Expected Success Output: 
  Same JSON format as POST /server/status.

=========================================================
2. DEVICE OPERATIONS
=========================================================

Enable Device
* Path: POST /device/enable
* Description: Enables the SIM associated with the device in Simbase, calculates a new expiration date based on preloaded months, and updates the local DB.
* Inputs (JSON Body):
  - imei (Required, String): The device IMEI.
* Expected Success Output:
  {
    "message": "Device enabled successfully",
    "new_expiration": "2026-03-19 23:59:59"
  }

Disable Device
* Path: POST /device/disable
* Description: Disables the SIM associated with the device in Simbase.
* Inputs (JSON Body):
  - imei (Required, String): The device IMEI.
* Expected Success Output:
  {
    "message": "Device disabled successfully",
    "sim_status": "disabled"
  }

=========================================================
3. USER OPERATIONS
=========================================================

Sync User
* Path: POST /user/sync
* Description: Checks if the Firebase user exists in the local database and Traccar. Creates or updates the user to keep systems synchronized.
* Inputs (JSON Body):
  - country_code (Required, String): Determines which regional server to route the user to.
  - name (Optional, String): The user's name.
  - phone (Optional, String): The user's phone number.
* Expected Success Output:
  {
    "status": "success",
    "server_url": "https://regional-traccar-server.com",
    "name": "User Name",
    "phone": "+1234567890",
    "server_token": "traccar_token_string_if_available"
  }

Update User
* Path: POST /user/update
* Description: Updates the user's name and mobile number in both Traccar and the local MySQL database.
* Inputs (JSON Body):
  - id (Required, Integer): Local database user ID.
  - server_id (Required, Integer): Traccar server user ID.
  - server_url (Required, String): User's Traccar server URL.
  - name (Optional, String): Updated name.
  - mobile (Optional, String): Updated mobile number.
* Expected Success Output:
  {
    "status": "success"
  }

Delete User
* Path: POST /user/delete
* Description: Removes a user entirely from Traccar and the local database.
* Inputs (JSON Body):
  - id (Required, Integer): Local database user ID.
  - server_id (Required, Integer): Traccar server user ID.
  - server_url (Required, String): User's Traccar server URL.
* Expected Success Output:
  {
    "status": "success"
  }

Save FCM Token
* Path: POST /user/fcm-token
* Description: Saves or updates the Firebase Cloud Messaging notification token for the user.
* Inputs (JSON Body):
  - fcm_token (Required, String): The device's FCM token.
* Expected Success Output:
  {
    "status": "success",
    "message": "FCM token updated successfully"
  }

Link Device to User
* Path: POST /user/link-device
* Description: Claims an available inventory device and assigns it to the authenticated user in both local DB and Traccar.
* Inputs (JSON Body):
  - imei (Required, String): Device IMEI.
  - name (Required, String): The new custom name the user is assigning to the device.
* Expected Success Output:
  {
    "status": "success"
  }

=========================================================
4. ADMIN / INVENTORY OPERATIONS
=========================================================

Create Inventory Record
* Path: POST /inventory/createRecord
* Description: Admin utility to add a brand new tracker to the system. Verifies SIM is valid in Simbase, generates an initial name, registers it to Traccar, updates Simbase, and inserts it into the local MySQL inventory.
* Inputs (JSON Body):
  - server_url (Required, String): URL of the Traccar instance.
  - imei (Required, String): Device IMEI.
  - iccid (Required, String): SIM Card ICCID.
* Expected Success Output:
  {
    "status": "success",
    "traccar_id": 105,
    "generated_name": "@@ 1234/5678",
    "mysql_id": 42
  }

Send Notification
* Path: POST /notification/send
* Description: Sends a push notification using Firebase Cloud Messaging. (Restricted to superadmin@navitag.com)
* Inputs (JSON Body):
  - token (Required, String): Target FCM Token.
  - title (Required, String): Notification Title.
  - body (Required, String): Notification Body Content.
  - data (Optional, Object): Additional key/value payload array.
* Expected Success Output:
  {
    "status": "success",
    "message": "Notification sent"
  }

=========================================================
5. TRACKING / HISTORY OPERATIONS
=========================================================

History Positions
* Path: POST /history/positions
* Description: Retrieves the historic coordinate positions for an assigned device for a specific date, converted between the local timezone and UTC.
* Inputs (JSON Body):
  - imei (Required, String|Integer): The device IMEI.
  - date (Required, String): The date formatted as YYYY-MM-DD.
  - timezone (Required, String): Valid PHP timezone string (e.g., "Asia/Manila").
* Expected Success Output:
  [
    {
      "id": 12345,
      "deviceId": 105,
      "protocol": "osmand",
      "serverTime": "2023-10-27T10:00:00Z",
      "deviceTime": "2023-10-27T10:00:00Z",
      "latitude": 14.5995,
      "longitude": 120.9842,
      "speed": 0,
      "attributes": {}
    }
  ]