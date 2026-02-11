<?php
echo "<pre>";
echo "Checking File System...\n";

$checks = [
    'src/Services/Traccar.php',
    'src/Services/Simbase.php',
    'src/Services/MysqlDatabase.php',
    'src/Middleware/FirebaseAuthMiddleware.php' // Note: This class name might be FireaseAuthMiddleware based on your upload
];

foreach ($checks as $file) {
    if (file_exists(__DIR__ . '/' . $file)) {
        echo "[OK] Found: $file\n";
    } else {
        echo "[ERROR] Missing or Wrong Case: $file\n";
        // Try to find what it IS named
        $dir = dirname($file);
        $base = basename($file);
        if (is_dir(__DIR__ . '/' . $dir)) {
            $actual = scandir(__DIR__ . '/' . $dir);
            echo "    -> Did you mean one of these? " . implode(", ", $actual) . "\n";
        } else {
            echo "    -> Folder '$dir' does not exist (Check folder casing!)\n";
        }
    }
}
echo "</pre>";