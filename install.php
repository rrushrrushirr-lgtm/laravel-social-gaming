<?php
// Check if the form has been submitted and handle the request
// Initialize a default message
$message = "Fill all the data as required for steps needed";

// Check if the form has been submitted
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $step = isset($_POST['step']) ? $_POST['step'] : '';

    switch ($step) {
case '1':
    $phpVersion = PHP_VERSION;
    $redisInstalled = extension_loaded('redis');
    $dbConnectionStatus = 'Not connected';

    // EthioDeploy injects the linked MySQL addon's credentials as DATABASE_URL.
    // Prefer that managed value; only use the manual fields when it is absent.
    if (!extension_loaded('mysqli')) {
        $dbConnectionStatus = 'Failed: PHP mysqli extension is not installed.';
    } else {
        try {
            mysqli_report(MYSQLI_REPORT_OFF);
            $databaseUrl = getenv('DATABASE_URL') ?: ($_ENV['DATABASE_URL'] ?? '');
            if ($databaseUrl !== '') {
                $parts = parse_url($databaseUrl);
                if (!is_array($parts) || empty($parts['host']) || empty($parts['user']) || !isset($parts['pass']) || empty($parts['path'])) {
                    throw new RuntimeException('The managed DATABASE_URL is not a valid MySQL connection URL.');
                }
                $dbHost = $parts['host'];
                $dbUser = rawurldecode($parts['user']);
                $dbPass = rawurldecode($parts['pass']);
                $dbName = ltrim($parts['path'], '/');
                $dbPort = isset($parts['port']) ? (int)$parts['port'] : 3306;
                $connectionSource = 'EthioDeploy managed DATABASE_URL';
            } else {
                $dbHost = trim($_POST['db_host'] ?? '');
                $dbUser = trim($_POST['db_user'] ?? '');
                $dbPass = $_POST['db_pass'] ?? '';
                $dbName = trim($_POST['db_name'] ?? '');
                $dbPort = 3306;
                $connectionSource = 'manual database fields';
            }

            if ($dbHost === '' || $dbUser === '' || $dbName === '') {
                $dbConnectionStatus = 'Failed: database host, name and username are missing.';
            } else {
                $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
                if ($mysqli->connect_errno) {
                    $dbConnectionStatus = 'Failed to connect using ' . $connectionSource . ': ' . $mysqli->connect_error;
                } else {
                    $dbConnectionStatus = 'Connected successfully using ' . $connectionSource;
                    $mysqli->close();
                }
            }
        } catch (Throwable $e) {
            $dbConnectionStatus = 'Database check error: ' . $e->getMessage();
        }
    }

    $message = "PHP Version (recommended 8+) Your PHP Version is: " . htmlspecialchars($phpVersion, ENT_QUOTES, 'UTF-8') . "\n"
             . "<br>Redis Installed (Required for many games): " . ($redisInstalled ? 'Yes' : 'No') . "<br>"
             . "Database Connection : " . htmlspecialchars($dbConnectionStatus, ENT_QUOTES, 'UTF-8') . "<br> PM2 tests require SSH, https://pm2.io/docs/runtime/guide/installation/";
    break;

    case '2':
    $sqlFile = 'v10.sql';

    if (file_exists($sqlFile)) {
        // Attempt to connect to the database
        $mysqli = new mysqli($_POST['db_host'], $_POST['db_user'], $_POST['db_pass'], $_POST['db_name']);
        if ($mysqli->connect_error) {
            $message = "Failed to connect to the database: " . $mysqli->connect_error;
        } else {
            // Read and execute the SQL file
            $sqlContent = file_get_contents($sqlFile);
            try {
                if ($mysqli->multi_query($sqlContent)) {
                    do {
                        // Skip results
                        if ($result = $mysqli->store_result()) {
                            $result->free();
                        }
                    } while ($mysqli->more_results() && $mysqli->next_result());

                    $message = "SQL file '{$sqlFile}' executed successfully.";
                } else {
                    $message = "Error encountered while executing SQL: " . $mysqli->error;
                }
            } catch (mysqli_sql_exception $ex) {
                $message = "SQL error: " . $ex->getMessage();
            }
        }
    } else {
        $message = "SQL file '{$sqlFile}' does not exist.";
    }
    break;

    
case '3':
    $dbHost = $_POST['db_host'];
    $dbUser = $_POST['db_user'];
    $dbPass = $_POST['db_pass'];
    $dbName = $_POST['db_name'];
    $adminPassword = $_POST['admin_password'];

    try {
        $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
        if ($mysqli->connect_error) {
            $message = "Failed to connect to database: " . $mysqli->connect_error;
        } else {
            // Bcrypt the admin password
            $hashedPassword = password_hash($adminPassword, PASSWORD_BCRYPT);

            // Update the password in the database
            $updateQuery = "UPDATE w_users SET `password` = '{$hashedPassword}'";
            if ($mysqli->query($updateQuery) === TRUE) {
                $message = "Admin password updated successfully.";
            } else {
                $message = "Error updating admin password: " . $mysqli->error;
            }
        }
    } catch (mysqli_sql_exception $ex) {
        $message = "SQL error: " . $ex->getMessage();
    }
    break;




case '4':
    $configFiles = [
        'arcade_config.json',
        'socket_config.json',
        'socket_config2.json'
    ];

    $defaultPorts = ['22188' => $_POST['port1'], '22154' => $_POST['port2'], '22197' => $_POST['port3']];
    $message = "";

    foreach ($configFiles as $file) {
        if (file_exists($file)) {
            $content = file_get_contents($file);
            // Replace the domain name
            $content = str_replace("betshop.io", $_POST['domain_name'], $content);
            
            // Replace ports if they have changed
            foreach ($defaultPorts as $defaultPort => $newPort) {
                if ($defaultPort != $newPort) {
                    $content = str_replace($defaultPort, $newPort, $content);
                }
            }

            // Write the changes back to the file
            if (file_put_contents($file, $content) === false) {
                $message .= "Failed to update {$file}.<br>";
            } else {
                $message .= "{$file} updated successfully.<br>";
            }
        } else {
            $message .= "File {$file} does not exist.<br>";
        }
    }
    break;

case '5':
    $envFilePath = 'casino/.env';

    try {
        // Prefer the linked EthioDeploy MySQL addon's managed connection URL.
        $databaseUrl = getenv('DATABASE_URL') ?: ($_ENV['DATABASE_URL'] ?? '');
        if ($databaseUrl !== '') {
            $parts = parse_url($databaseUrl);
            if (!is_array($parts) || empty($parts['host']) || empty($parts['user']) || !isset($parts['pass']) || empty($parts['path'])) {
                throw new RuntimeException('EthioDeploy DATABASE_URL is not a valid MySQL URL.');
            }
            $dbHost = $parts['host'];
            $dbPort = isset($parts['port']) ? (int)$parts['port'] : 3306;
            $dbName = ltrim($parts['path'], '/');
            $dbUser = rawurldecode($parts['user']);
            $dbPass = rawurldecode($parts['pass']);
        } else {
            $dbHost = trim($_POST['db_host'] ?? '');
            $dbPort = 3306;
            $dbName = trim($_POST['db_name'] ?? '');
            $dbUser = trim($_POST['db_user'] ?? '');
            $dbPass = $_POST['db_pass'] ?? '';
        }

        if ($dbHost === '' || $dbName === '' || $dbUser === '') {
            throw new RuntimeException('Database connection details are incomplete.');
        }

        $domain = trim($_POST['domain_name'] ?? '');
        $domain = preg_replace('#^https?://#i', '', $domain);
        $domain = rtrim($domain, '/');
        if ($domain === '') {
            throw new RuntimeException('Enter the app domain, without https://.');
        }

        $env = [
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_KEY' => 'base64:' . base64_encode(random_bytes(32)),
            'APP_URL' => 'https://' . $domain,
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $dbHost,
            'DB_PORT' => (string)$dbPort,
            'DB_DATABASE' => $dbName,
            'DB_USERNAME' => $dbUser,
            'DB_PASSWORD' => $dbPass,
            'DB_PREFIX' => 'w_',
            'CACHE_DRIVER' => 'file',
            'SESSION_DRIVER' => 'file',
            'QUEUE_DRIVER' => 'sync',
            'BROADCAST_DRIVER' => 'log',
            'SESSION_LIFETIME' => '120',
            'MAIL_MAILER' => 'log',
            'MAIL_HOST' => '127.0.0.1',
            'MAIL_PORT' => '2525',
            'MAIL_USERNAME' => '',
            'MAIL_PASSWORD' => '',
            'MAIL_ENCRYPTION' => 'null',
            'MAIL_FROM_ADDRESS' => 'no-reply@' . $domain,
            'MAIL_FROM_NAME' => 'Social Gaming Demo',
            'JWT_SECRET' => bin2hex(random_bytes(32)),
            'MIN_ADD' => '100',
            'MAX_ADD' => '1000000',
            'MAX_INVITES' => '10',
            'REGISTER_NOTIFY_EMAIL' => '',
            'DEMO_KEY' => '',
        ];

        $envLines = [];
        foreach ($env as $key => $value) {
            // Quote values safely for dotenv; escape backslashes and quotes.
            $value = str_replace(["\\", '"', "\r", "\n"], ["\\\\", '\\"', '', ''], (string)$value);
            $envLines[] = $key . '="' . $value . '"';
        }
        $envContent = implode("\n", $envLines) . "\n";

        if (file_put_contents($envFilePath, $envContent, LOCK_EX) === false) {
            $message = "Failed to write the .env file. Check permissions for casino/.env.";
        } else {
            $message = "The .env file was created using the EthioDeploy database connection. Email sending is set to log mode for the demo.";
        }
    } catch (Throwable $e) {
        $message = "Could not create .env: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    }
    break;

    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Open Source Casino App SIMPLE Installer</title>
<style>
    body {
        font-family: Arial, sans-serif;
        margin: 0;
        padding: 0;
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        height: 100vh;
        background-color: #f4f4f4;
    }
    .installer-form {
        width: 70%;
        max-width: 1000px;
        margin: 0 auto;
        background: white;
        padding: 20px;
        border-radius: 5px;
        box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
    }
    .message-box {
        background-color: #f2f2f2;
        color: #333;
        border: 1px solid #ddd;
        padding: 15px;
        margin-bottom: 20px;
        text-align: center;
        border-radius: 5px;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        max-width: 80%;
        margin: 20px auto;
    }
    .installer-form label, .installer-form input {
        display: inline-block; /* Align label and input inline */
    }
    .installer-form label {
        width: 20%; /* Adjust as per requirement */
        margin-right: 10px; /* Space between label and input */
        text-align: right; /* Right align the label text */
    }
    .installer-form input {
        width: 75%; /* Adjust as per requirement */
        padding: 10px;
        margin: 5px 0;
        border: 1px solid #ddd;
        border-radius: 4px;
    }
    button {
        width: auto;
        padding: 10px 20px;
        background-color: #007bff;
        color: white;
        border: none;
        border-radius: 4px;
        cursor: pointer;
    }
    button:hover {
        background-color: #0056b3;
    }
.sql-box {
    background-color: #f8f8f8; /* Light gray background */
    border: 1px solid #ddd; /* Light gray border */
    padding: 15px;
    overflow-wrap: break-word; /* Allows long lines to break and wrap to the next line */
    font-family: 'Courier New', Courier, monospace; /* Monospaced font for better code readability */
    font-size: 14px; /* Adjust font size for readability */
    line-height: 1.5; /* Space between lines */
    color: #333; /* Dark text for contrast */
    white-space: pre-wrap; /* Preserves formatting and allows text to wrap */
    word-wrap: break-word; /* Breaks long words to prevent overflow */
    border-radius: 5px; /* Rounded corners */
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1); /* Subtle shadow */
    margin-bottom: 20px; /* Spacing below the code block */
    max-width: 100%; /* Limit the maximum width of the box */
}
.mini-installer-box {
    background-color: #f2f2f2;
    border: 1px solid #ddd;
    padding: 20px;
    border-radius: 5px;
    margin: 20px auto;
    width: 80%;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    text-align: center;
}

.mini-installer-box h3 {
    margin-bottom: 15px;
    color: #333;
}

.mini-installer-box p {
    font-size: 14px;
    line-height: 1.6;
    margin-bottom: 10px;
}

.mini-installer-box a {
    color: #007bff;
    text-decoration: none;
}

.mini-installer-box a:hover {
    text-decoration: underline;
}

</style>

</head>
<body>      <p>  <Br><Br><Br><Br><Br> 
         <br><Br> 

<div class="installer-form"><div class="message-box">
    <h2>Mini Installer</h2>
    <p>
        Our official Github Repo: 
        <a href="https://github.com/gamingdotme/opensource-casino-v10" target="_blank">https://github.com/gamingdotme/opensource-casino-v10</a>
    </p>
    <p>
        Discord Open Source Casino link: 
        <a href="https://discord.gg/3QpZNd89WZ" target="_blank">https://discord.gg/3QpZNd89WZ</a>
    </p>
    <p>
        Sponsor Hosting Promex: 
        <a href="https://Promex.me" target="_blank">https://Promex.me</a>
    </p>
</div><div class="message-box"><center><Br><Br> <center><?php echo $message; ?></center> </div>  
    <form action="install.php" method="post">
        <h2>Database and Application Information</h2>

        <label for="db_host">Database Host</label>         <input type="text" name="db_host" id="db_host" placeholder="Database Host" value="<?php echo isset($_POST['db_host']) ? htmlspecialchars($_POST['db_host']) : ''; ?>">

        <label for="db_name">Database Name</label>
        <input type="text" name="db_name" id="db_name" placeholder="Database Name" value="<?php echo isset($_POST['db_name']) ? htmlspecialchars($_POST['db_name']) : ''; ?>">

        <label for="db_user">Database Username</label>
        <input type="text" name="db_user" id="db_user" placeholder="Database Username" value="<?php echo isset($_POST['db_user']) ? htmlspecialchars($_POST['db_user']) : ''; ?>">

        <label for="db_pass">Database Password</label>
        <input type="password" name="db_pass" id="db_pass" placeholder="Database Password" value="<?php echo isset($_POST['db_pass']) ? htmlspecialchars($_POST['db_pass']) : ''; ?>">

        <label for="email">From Email</label>
        <input type="email" name="email" id="email" placeholder="From Email" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">

        <label for="emailusername">Email Username</label>
        <input type="text" name="emailusername" id="emailusername" placeholder="Email username Typically same as your email" value="<?php echo isset($_POST['emailusername']) ? htmlspecialchars($_POST['emailusername']) : ''; ?>">

        <label for="emailpass">SMTP Password</label>
        <input type="password" name="emailpass" id="emailpass" placeholder="Your SMTP email/username Password" value="<?php echo isset($_POST['emailpass']) ? htmlspecialchars($_POST['emailpass']) : ''; ?>">

        <label for="emailserver">SMTP Host</label>
        <input type="text" name="emailserver" id="emailserver" placeholder="Email SMTP Host typically same as your domain" value="<?php echo isset($_POST['emailserver']) ? htmlspecialchars($_POST['emailserver']) : ''; ?>">

        <label for="domain_name">Domain Name</label>
        <input type="text" name="domain_name" id="domain_name" placeholder="Domain Name" required value="<?php echo isset($_POST['domain_name']) ? htmlspecialchars($_POST['domain_name']) : ''; ?>">

        <label for="admin_password">Admin Password</label>
        <input type="password" name="admin_password" id="admin_password" placeholder="Default Admin Password" value="<?php echo isset($_POST['admin_password']) ? htmlspecialchars($_POST['admin_password']) : ''; ?>">

        <label for="user_password">User Password</label>
        <input type="password" name="user_password" id="user_password" placeholder="Default User Password" value="<?php echo isset($_POST['user_password']) ? htmlspecialchars($_POST['user_password']) : ''; ?>">

        <label for="port1">Port 1</label>
        <input type="number" name="port1" id="port1" placeholder="Port 1" value="<?php echo isset($_POST['port1']) ? htmlspecialchars($_POST['port1']) : '22188'; ?>">

        <label for="port2">Port 2</label>
        <input type="number" name="port2" id="port2" placeholder="Port 2" value="<?php echo isset($_POST['port2']) ? htmlspecialchars($_POST['port2']) : '22154'; ?>">

        <label for="port3">Port 3</label>
        <input type="number" name="port3" id="port3" placeholder="Port 3" value="<?php echo isset($_POST['port3']) ? htmlspecialchars($_POST['port3']) : '22197'; ?>">

        <!-- Submit buttons for steps -->
        <button type="submit" name="step" value="1">Check DB, Redis And PHP Version</button>
        <button type="submit" name="step" value="2">Import v10.sql</button>
        <button type="submit" name="step" value="3">Reset Admin/Users Password</button>
        <button type="submit" name="step" value="4">Websockets Ports</button>
        <button type="submit" name="step" value="5">Write casino/.env</button>
    </form>
</div>

    </div>
    <br>
<!-- Button to show/hide extra instructions -->
<div class="installer-form">
    <button onclick="toggleInstructions()">What are the next steps?</button>
</div>

<div class="installer-form" id="extraInstructions" style="display: none;">
    <div class="instruction-box">
        <p>Generate an SSL and copy paste contents as below:</p>
        <p>Certificate: (CRT) ==> crt.crt <br> Private Key (KEY) --> key.key <br> Certificate Authority Bundle: (CABUNDLE) ==> intermediate.pem</p>
        <p>Reminder to install pm2 and while in ssh https://pm2.keymetrics.io/docs/usage/quick-start/</p>
        <p>In folder /casino/PTWebSocket/, run the following commands:</p>
        <pre class="sql-box">pm2 start Arcade.js --watch 
pm2 start Server.js --watch 
pm2 start Slots.js --watch</pre>
        <p>If you have a firewall, make sure the provided Ports are open to all traffic.</p>
        <p>Find download packages at https://discord.gg/QfUJhzFsju</p>
        <p>Shops are active in this version. You can manually select which categories each shop can use on creation.</p>
        <p>OR add this trigger to your MySQL so ALL categories are added to ALL new shops:</p>
        <pre class="sql-box">CREATE TRIGGER `after_shop_insert` 
AFTER INSERT ON `w_shops`
FOR EACH ROW 
BEGIN
    -- Duplicate games with shop_id 1 to the new shop_id
    INSERT INTO w_games (name, title, shop_id, jpg_id, label, device, gamebank, chanceFirepot1, chanceFirepot2, chanceFirepot3, fireCount1, fireCount2, fireCount3, lines_percent_config_spin, lines_percent_config_spin_bonus, lines_percent_config_bonus, lines_percent_config_bonus_bonus, rezerv, cask, advanced, bet, scaleMode, slotViewState, view, denomination, category_temp, original_id, bids, stat_in, stat_out, created_at, updated_at)
    SELECT name, title, NEW.id, jpg_id, label, device, gamebank, chanceFirepot1, chanceFirepot2, chanceFirepot3, fireCount1, fireCount2, fireCount3, lines_percent_config_spin, lines_percent_config_spin_bonus, lines_percent_config_bonus, lines_percent_config_bonus_bonus, rezerv, cask, advanced, bet, scaleMode, slotViewState, view, denomination, category_temp, original_id, bids, stat_in, stat_out, created_at, NOW()
    FROM w_games
    WHERE shop_id = 1;

    -- Duplicate jpg entries with shop_id 1 to the new shop_id
    INSERT INTO w_jpg (date_time, name, balance, start_balance, pay_sum, percent, user_id, shop_id)
    SELECT date_time, name, balance, start_balance, pay_sum, percent, user_id, NEW.id
    FROM w_jpg
    WHERE shop_id = 1;
END;</pre>
    </div>
</div>

<script>
    function toggleInstructions() {
        var x = document.getElementById("extraInstructions");
        if (x.style.display === "none") {
            x.style.display = "block";
        } else {
            x.style.display = "none";
        }
    }
</script>
</body>
</html>
