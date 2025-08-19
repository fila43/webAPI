<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

// Konfigurace API klíčů
define('RELAY_API_KEY', 'relay_secret_key_2024');  // Pro relay komunikaci
define('ESP_API_KEY', 'esp_secret_key_2024');      // Pro ESP8266 zařízení

$data_dir = 'relay_data';
$esp_dir = 'esp_data';
$commands_dir = 'esp_commands';

// Vytvoř adresáře pokud neexistují
foreach ([$data_dir, $esp_dir, $commands_dir] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

$method = $_SERVER['REQUEST_METHOD'];
$request_uri = $_SERVER['REQUEST_URI'];
$path = parse_url($request_uri, PHP_URL_PATH);

// ESP8266 endpoint
if ($path === '/api/status' || strpos($path, '/api/status') !== false) {
    handleESPStatusEndpoint();
    exit;
}

// Původní relay API
$action = $_GET['action'] ?? '';
$client_id = $_GET['client_id'] ?? '';
$api_key = $_GET['api_key'] ?? '';

// Kontrola API klíče pro relay komunikaci
if ($api_key !== RELAY_API_KEY) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid API key']);
    exit;
}

if (empty($client_id)) {
    http_response_code(400);
    echo json_encode(['error' => 'client_id required']);
    exit;
}

switch ($method) {
    case 'POST':
        // Odeslat zprávu
        if ($action === 'send') {
            $input = json_decode(file_get_contents('php://input'), true);
            $target_client = $_GET['target'] ?? '';
            
            if (empty($target_client)) {
                http_response_code(400);
                echo json_encode(['error' => 'target client required']);
                exit;
            }
            
            $message = [
                'from' => $client_id,
                'to' => $target_client,
                'data' => $input,
                'timestamp' => time(),
                'id' => uniqid()
            ];
            
            $inbox_file = $data_dir . '/' . $target_client . '_inbox.json';
            $messages = [];
            
            if (file_exists($inbox_file)) {
                $messages = json_decode(file_get_contents($inbox_file), true) ?: [];
            }
            
            $messages[] = $message;
            
            // Uchovej pouze posledních 100 zpráv
            if (count($messages) > 100) {
                $messages = array_slice($messages, -100);
            }
            
            file_put_contents($inbox_file, json_encode($messages, JSON_PRETTY_PRINT));
            
            echo json_encode(['success' => true, 'message_id' => $message['id']]);
        }
        break;
        
    case 'GET':
        if ($action === 'receive') {
            // Přijmout zprávy
            $inbox_file = $data_dir . '/' . $client_id . '_inbox.json';
            
            if (!file_exists($inbox_file)) {
                echo json_encode(['messages' => []]);
                exit;
            }
            
            $messages = json_decode(file_get_contents($inbox_file), true) ?: [];
            
            // Označit zprávy jako přečtené (smazat je)
            if ($_GET['clear'] === 'true') {
                unlink($inbox_file);
            }
            
            echo json_encode(['messages' => $messages]);
            
        } elseif ($action === 'heartbeat') {
            // Heartbeat - klient hlásí, že je online
            $heartbeat_file = $data_dir . '/' . $client_id . '_heartbeat.json';
            $heartbeat_data = [
                'client_id' => $client_id,
                'last_seen' => time(),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ];
            
            file_put_contents($heartbeat_file, json_encode($heartbeat_data, JSON_PRETTY_PRINT));
            echo json_encode(['success' => true, 'timestamp' => time()]);
            
        } elseif ($action === 'status') {
            // Status všech klientů
            $status = [];
            $files = glob($data_dir . '/*_heartbeat.json');
            
            foreach ($files as $file) {
                $data = json_decode(file_get_contents($file), true);
                if ($data && (time() - $data['last_seen']) < 300) { // Online pokud heartbeat do 5 minut
                    $client = str_replace(['_heartbeat.json'], '', basename($file));
                    $status[$client] = [
                        'online' => true,
                        'last_seen' => $data['last_seen'],
                        'ip' => $data['ip']
                    ];
                }
            }
            
            echo json_encode(['clients' => $status]);
            
        } elseif ($action === 'esp_devices') {
            // Seznam ESP zařízení
            echo json_encode(getESPDevices());
            
        } elseif ($action === 'esp_commands') {
            // Odeslat příkaz ESP zařízení
            $device_id = $_GET['device_id'] ?? '';
            $command = $_GET['command'] ?? '';
            $value = $_GET['value'] ?? null;
            
            if (empty($device_id) || empty($command)) {
                echo json_encode(['error' => 'device_id and command required']);
                exit;
            }
            
            $result = sendESPCommand($device_id, $command, $value);
            echo json_encode($result);
        }
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
}

function handleESPStatusEndpoint() {
    global $esp_dir, $commands_dir;
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }
    
    // Získej JSON data
    $json_data = file_get_contents('php://input');
    $data = json_decode($json_data, true);
    
    if (!$data) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        return;
    }
    
    // Validace API klíče
    if (($data['api_key'] ?? '') !== ESP_API_KEY) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid API key']);
        return;
    }
    
    // Validace povinných polí
    $required_fields = ['device_id', 'device_name', 'timestamp'];
    foreach ($required_fields as $field) {
        if (!isset($data[$field])) {
            http_response_code(400);
            echo json_encode(['error' => "Missing required field: $field"]);
            return;
        }
    }
    
    $device_id = $data['device_id'];
    
    // Ulož data od ESP zařízení
    $esp_data = [
        'device_id' => $device_id,
        'device_name' => $data['device_name'],
        'timestamp' => $data['timestamp'],
        'received_at' => time(),
        'uptime' => $data['uptime'] ?? 0,
        'device_state' => $data['device_state'] ?? 'UNKNOWN',
        'fsm_state' => $data['fsm_state'] ?? 0,
        'current_temp' => $data['current_temp'] ?? null,
        'desired_temp' => $data['desired_temp'] ?? null,
        'mode' => $data['mode'] ?? 'unknown',
        'wifi_rssi' => $data['wifi_rssi'] ?? null,
        'local_ip' => $data['local_ip'] ?? 'unknown',
        'server_ip' => $data['server_ip'] ?? 'unknown',
        'timer_intervals' => $data['timer_intervals'] ?? [],
        'client_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ];
    
    // Ulož do souboru pro dané zařízení
    $esp_file = $esp_dir . '/device_' . $device_id . '.json';
    file_put_contents($esp_file, json_encode($esp_data, JSON_PRETTY_PRINT));
    
    // Ulož do historie
    $history_file = $esp_dir . '/device_' . $device_id . '_history.json';
    $history = [];
    if (file_exists($history_file)) {
        $history = json_decode(file_get_contents($history_file), true) ?: [];
    }
    
    $history[] = $esp_data;
    
    // Uchovej pouze posledních 5 záznamů
    if (count($history) > 5) {
        $history = array_slice($history, -5);
    }
    
    file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
    
    // Zkontroluj, jestli není čekající příkaz (jen jeden posledni)
    $command_file = $commands_dir . '/device_' . $device_id . '_command.json';
    $response = [];
    
    if (file_exists($command_file)) {
        $command_data = json_decode(file_get_contents($command_file), true);
        
        if ($command_data) {
            $response = [
                'command' => $command_data['command'],
                'value' => $command_data['value'] ?? null
            ];
            
            // Smaž příkaz po odeslání
            unlink($command_file);
        }
    }
    
    // Zaloguj komunikaci
    $log_entry = [
        'timestamp' => time(),
        'device_id' => $device_id,
        'action' => 'status_received',
        'data' => $esp_data,
        'response' => $response
    ];
    
    $log_file = $esp_dir . '/communication_log.json';
    $log = [];
    if (file_exists($log_file)) {
        $log = json_decode(file_get_contents($log_file), true) ?: [];
    }
    
    $log[] = $log_entry;
    
    // Uchovej pouze posledních 5 záznamů
    if (count($log) > 5) {
        $log = array_slice($log, -5);
    }
    
    file_put_contents($log_file, json_encode($log, JSON_PRETTY_PRINT));
    
    // Odpověz ESP zařízení
    echo json_encode($response);
}

function getESPDevices() {
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
                'fsm_state' => $data['fsm_state']
            ];
        }
    }
    
    return $devices;
}

function sendESPCommand($device_id, $command, $value = null) {
    global $commands_dir;
    
    $valid_commands = [
        'turn_on', 'turn_off', 'set_auto_temp', 'set_auto_timer', 
        'set_temp', 'set_name'
    ];
    
    if (!in_array($command, $valid_commands)) {
        return ['error' => 'Invalid command'];
    }
    
    $command_data = [
        'command' => $command,
        'timestamp' => time(),
        'id' => uniqid()
    ];
    
    if ($value !== null) {
        $command_data['value'] = $value;
    }
    
    // Ulož jen jeden posledni příkaz (přepíše předchozí)
    $command_file = $commands_dir . '/device_' . $device_id . '_command.json';
    file_put_contents($command_file, json_encode($command_data, JSON_PRETTY_PRINT));
    
    return [
        'success' => true,
        'command_id' => $command_data['id'],
        'message' => "Command '$command' set for device $device_id (replaces previous)"
    ];
}
?>