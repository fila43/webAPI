<?php
// Konfigurace
define('ESP_API_KEY', 'esp_secret_key_2024');
$esp_dir = 'esp_data';
$commands_dir = 'esp_commands';

// Zpracování příkazů z formuláře
if ($_POST && isset($_POST['action'])) {
    $device_id = $_POST['device_id'] ?? '';
    $action = $_POST['action'];
    
    if ($action === 'delete_device' && $device_id) {
        deleteDevice($device_id);
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    } elseif ($action === 'send_command') {
        $command = $_POST['command'] ?? '';
        $value = $_POST['value'] ?? null;
        
        if ($device_id && $command) {
            sendCommand($device_id, $command, $value);
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
    }
}

// Načti ESP zařízení
$devices = loadESPDevices();

function loadESPDevices() {
    global $esp_dir;
    
    $devices = [];
    $files = glob($esp_dir . '/device_*.json');
    
    foreach ($files as $file) {
        if (strpos($file, '_history.json') !== false) continue;
        
        $data = json_decode(file_get_contents($file), true);
        if ($data) {
            $device_id = $data['device_id'];
            $last_seen = $data['received_at'];
            $is_online = (time() - $last_seen) < 120; // Online pokud byl viděn do 2 minut
            
            $devices[] = [
                'device_id' => $device_id,
                'device_name' => $data['device_name'],
                'online' => $is_online,
                'last_seen' => $last_seen,
                'current_temp' => $data['current_temp'],
                'desired_temp' => $data['desired_temp'],
                'device_state' => $data['device_state'],
                'mode' => $data['mode'],
                'wifi_rssi' => $data['wifi_rssi'],
                'local_ip' => $data['local_ip'],
                'uptime' => $data['uptime'],
                'fsm_state' => $data['fsm_state'],
                'client_ip' => $data['client_ip']
            ];
        }
    }
    
    return $devices;
}

function sendCommand($device_id, $command, $value = null) {
    global $commands_dir;
    
    $valid_commands = [
        'turn_on', 'turn_off', 'set_auto_temp', 'set_auto_timer', 
        'set_temp', 'set_name'
    ];
    
    if (!in_array($command, $valid_commands)) {
        return false;
    }
    
    $command_data = [
        'command' => $command,
        'timestamp' => time(),
        'id' => uniqid()
    ];
    
    if ($value !== null) {
        $command_data['value'] = $value;
    }
    
    // Ulož příkaz
    $command_file = $commands_dir . '/device_' . $device_id . '_command.json';
    file_put_contents($command_file, json_encode($command_data, JSON_PRETTY_PRINT));
    
    return true;
}

function deleteDevice($device_id) {
    global $esp_dir, $commands_dir;
    
    // Smaž hlavní soubor zařízení
    $device_file = $esp_dir . '/device_' . $device_id . '.json';
    if (file_exists($device_file)) {
        unlink($device_file);
    }
    
    // Smaž historii
    $history_file = $esp_dir . '/device_' . $device_id . '_history.json';
    if (file_exists($history_file)) {
        unlink($history_file);
    }
    
    // Smaž čekající příkazy
    $command_file = $commands_dir . '/device_' . $device_id . '_command.json';
    if (file_exists($command_file)) {
        unlink($command_file);
    }
    
    return true;
}

function formatUptime($uptime_ms) {
    $seconds = $uptime_ms / 1000;
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    return sprintf('%dh %dm', $hours, $minutes);
}

function getStatusColor($is_online) {
    return $is_online ? '#28a745' : '#dc3545';
}

function getStatusText($is_online) {
    return $is_online ? 'ONLINE' : 'OFFLINE';
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ESP8266 Monitor</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #74b9ff 0%, #0984e3 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .header {
            text-align: center;
            color: white;
            margin-bottom: 30px;
        }

        .header h1 {
            font-size: 2.5em;
            margin-bottom: 10px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
        }

        .refresh-info {
            background: rgba(255,255,255,0.9);
            padding: 10px;
            border-radius: 8px;
            text-align: center;
            margin-bottom: 20px;
        }

        .devices-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 20px;
        }

        .device-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            position: relative;
        }

        .device-card.online {
            border-left: 5px solid #28a745;
        }

        .device-card.offline {
            border-left: 5px solid #dc3545;
            opacity: 0.7;
        }

        .device-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .device-name {
            font-weight: bold;
            font-size: 18px;
            color: #333;
        }

        .device-status {
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: bold;
            color: white;
        }

        .status-online {
            background: #28a745;
        }

        .status-offline {
            background: #dc3545;
        }

        .temp-display {
            text-align: center;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 15px;
        }

        .temp-current {
            font-size: 3em;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .temp-desired {
            font-size: 1.2em;
            opacity: 0.9;
        }

        .device-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 15px;
        }

        .info-item {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 6px;
            border: 1px solid #e9ecef;
        }

        .info-label {
            font-size: 12px;
            color: #666;
            margin-bottom: 3px;
        }

        .info-value {
            font-weight: bold;
            color: #333;
        }

        .controls-section {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            border-top: 2px solid #74b9ff;
        }

        .controls-title {
            font-weight: bold;
            margin-bottom: 10px;
            color: #333;
        }

        .control-group {
            margin-bottom: 10px;
        }

        .control-buttons {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin-bottom: 10px;
        }

        .btn {
            padding: 8px 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            font-weight: bold;
            transition: transform 0.2s;
        }

        .btn:hover {
            transform: translateY(-1px);
        }

        .btn-success {
            background: #28a745;
            color: white;
        }

        .btn-danger {
            background: #dc3545;
            color: white;
        }

        .btn-warning {
            background: #ffc107;
            color: #212529;
        }

        .btn-info {
            background: #17a2b8;
            color: white;
        }

        .btn-delete {
            background: #dc3545;
            color: white;
            border: 2px solid #c82333;
        }

        .btn-delete:hover {
            background: #c82333;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(220, 53, 69, 0.3);
        }

        .temp-controls {
            display: flex;
            gap: 5px;
            align-items: center;
        }

        .temp-input {
            width: 60px;
            padding: 5px;
            border: 1px solid #ddd;
            border-radius: 4px;
            text-align: center;
        }

        .no-devices {
            text-align: center;
            color: white;
            font-size: 1.2em;
            margin-top: 50px;
        }

        @media (max-width: 768px) {
            .device-info {
                grid-template-columns: 1fr;
            }
            .control-buttons {
                grid-template-columns: 1fr;
            }
        }
    </style>
    <meta http-equiv="refresh" content="10">
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🌡️ ESP8266 Temperature Monitor</h1>
            <p>Přímý monitoring ESP8266 zařízení</p>
        </div>

        <div class="refresh-info">
            ⏱️ Stránka se automaticky obnovuje každých 10 sekund | 
            Posledni aktualizace: <?= date('H:i:s') ?> |
            Zařízení celkem: <?= count($devices) ?>
        </div>

        <?php if (empty($devices)): ?>
            <div class="no-devices">
                <p>❌ Žádná ESP8266 zařízení nejsou připojena</p>
                <p style="font-size: 0.9em; margin-top: 10px;">
                    Zkontrolujte, že ESP zařízení odesílají data na /api/status endpoint
                </p>
            </div>
        <?php else: ?>
            <div class="devices-grid">
                <?php foreach ($devices as $device): ?>
                    <div class="device-card <?= $device['online'] ? 'online' : 'offline' ?>">
                        <div class="device-header">
                            <div class="device-name"><?= htmlspecialchars($device['device_name']) ?></div>
                            <div class="device-status status-<?= $device['online'] ? 'online' : 'offline' ?>">
                                <?= getStatusText($device['online']) ?>
                            </div>
                        </div>

                        <?php if ($device['current_temp'] !== null): ?>
                            <div class="temp-display">
                                <div class="temp-current"><?= number_format($device['current_temp'], 1) ?>°C</div>
                                <div class="temp-desired">Cíl: <?= number_format($device['desired_temp'], 1) ?>°C</div>
                            </div>
                        <?php endif; ?>

                        <div class="device-info">
                            <div class="info-item">
                                <div class="info-label">ID zařízení</div>
                                <div class="info-value"><?= $device['device_id'] ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Stav relé</div>
                                <div class="info-value"><?= $device['device_state'] ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Režim</div>
                                <div class="info-value"><?= $device['mode'] ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">WiFi síla</div>
                                <div class="info-value"><?= $device['wifi_rssi'] ?> dBm</div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">IP adresa</div>
                                <div class="info-value"><?= $device['local_ip'] ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Uptime</div>
                                <div class="info-value"><?= formatUptime($device['uptime']) ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Poslední kontakt</div>
                                <div class="info-value"><?= date('H:i:s', $device['last_seen']) ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Klientská IP</div>
                                <div class="info-value"><?= $device['client_ip'] ?></div>
                            </div>
                        </div>

                        <div class="controls-section">
                            <div class="controls-title">⚡ Ovládání</div>
                            
                            <div class="control-group">
                                <div class="control-buttons">
                                    <form method="post" style="display: inline;">
                                        <input type="hidden" name="device_id" value="<?= $device['device_id'] ?>">
                                        <input type="hidden" name="command" value="turn_on">
                                        <input type="hidden" name="action" value="send_command">
                                        <button type="submit" class="btn btn-success">🔥 Zapnout</button>
                                    </form>
                                    
                                    <form method="post" style="display: inline;">
                                        <input type="hidden" name="device_id" value="<?= $device['device_id'] ?>">
                                        <input type="hidden" name="command" value="turn_off">
                                        <input type="hidden" name="action" value="send_command">
                                        <button type="submit" class="btn btn-danger">❄️ Vypnout</button>
                                    </form>
                                </div>
                                
                                <div class="control-buttons">
                                    <form method="post" style="display: inline;">
                                        <input type="hidden" name="device_id" value="<?= $device['device_id'] ?>">
                                        <input type="hidden" name="command" value="set_auto_temp">
                                        <input type="hidden" name="action" value="send_command">
                                        <button type="submit" class="btn btn-info">🌡️ Auto teplota</button>
                                    </form>
                                    
                                    <form method="post" style="display: inline;">
                                        <input type="hidden" name="device_id" value="<?= $device['device_id'] ?>">
                                        <input type="hidden" name="command" value="set_auto_timer">
                                        <input type="hidden" name="action" value="send_command">
                                        <button type="submit" class="btn btn-warning">⏰ Auto časovač</button>
                                    </form>
                                </div>
                            </div>

                            <div class="control-group">
                                <form method="post" class="temp-controls">
                                    <input type="hidden" name="device_id" value="<?= $device['device_id'] ?>">
                                    <input type="hidden" name="command" value="set_temp">
                                    <input type="hidden" name="action" value="send_command">
                                    <label style="font-size: 13px;">Nová teplota:</label>
                                    <input type="number" name="value" class="temp-input" step="0.5" min="5" max="35" 
                                           value="<?= $device['desired_temp'] ?>">
                                    <button type="submit" class="btn btn-info">Nastavit</button>
                                </form>
                            </div>

                            <div class="control-group" style="margin-top: 15px; border-top: 1px solid #ddd; padding-top: 10px;">
                                <form method="post" onsubmit="return confirm('Opravdu chcete smazat zařízení <?= htmlspecialchars($device['device_name']) ?>? Tato akce je nevratná!')">
                                    <input type="hidden" name="device_id" value="<?= $device['device_id'] ?>">
                                    <input type="hidden" name="action" value="delete_device">
                                    <button type="submit" class="btn btn-delete" style="width: 100%;">🗑️ Odstranit zařízení</button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>