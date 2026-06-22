<?php

// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

function send_json($json)
{
    header('Content-Type: application/json; charset=utf-8');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . strlen($json));
    echo $json;
    exit;
}

function send_forbidden($msg = "default")
{
    header('HTTP/1.0 403 Forbidden');
    echo "You are forbidden! (" . $msg . ")";
    exit;
}

function send_error($msg = "error")
{
    header('HTTP/1.0 400 Bad Request');
    echo $msg;
    exit;
}

function get_client_ip()
{
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }

    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        return $_SERVER['HTTP_X_REAL_IP'];
    }

    return isset($_SERVER["REMOTE_ADDR"]) ? $_SERVER["REMOTE_ADDR"] : "";
}

function is_error_message_type($message)
{
    return in_array($message, array("bridge_error", "bridge_errors", "error", "errors"), true);
}

function has_error_content($params)
{
    if (isset($params["errors"]) && is_array($params["errors"]) && count($params["errors"]) > 0) {
        return true;
    }

    foreach (array("error", "errorText", "errorMessage", "messageText", "logText") as $field) {
        if (isset($params[$field]) && is_string($params[$field]) && trim($params[$field]) !== "") {
            return true;
        }
    }

    return false;
}

function extract_error_lines($log_text)
{
    if (!is_string($log_text) || trim($log_text) === "") {
        return array();
    }

    $error_lines = array();
    $lines = preg_split('/\r\n|\r|\n/', $log_text);

    foreach ($lines as $line) {
        if (preg_match('/\b(ERROR|ERR|FAIL|FAILED|FATAL|PANIC|EXCEPTION|ASSERT|CRASH)\b/i', $line)) {
            $error_lines[] = $line;
        }
    }

    return $error_lines;
}

function load_week_data($storage_file, $week_key)
{
    $data = array();
    $data["week"] = $week_key;
    $data["updated_at"] = gmdate("c");
    $data["upload_count"] = 0;
    $data["uploads"] = array();

    if (!file_exists($storage_file)) {
        return $data;
    }

    $existing = file_get_contents($storage_file);

    if ($existing === false || empty(trim($existing))) {
        return $data;
    }

    $decoded = json_decode($existing, true);

    if (!is_null($decoded) && is_array($decoded) && isset($decoded["uploads"]) && is_array($decoded["uploads"])) {
        return $decoded;
    }

    return $data;
}

function store_upload($storage_file, $week_key, $upload)
{
    $handle = fopen($storage_file, 'c+');

    if ($handle === false) {
        send_forbidden("Open fail.");
        exit;
    }

    $locked = false;
    $error = null;
    $result = null;

    try {
        if (!flock($handle, LOCK_EX)) {
            $error = "Lock fail.";
            return null;
        }

        $locked = true;

        rewind($handle);
        $raw = stream_get_contents($handle);

        if ($raw === false) {
            $raw = "";
        }

        if (trim($raw) === "") {
            $data = array(
                "week" => $week_key,
                "updated_at" => gmdate("c"),
                "upload_count" => 0,
                "uploads" => array(),
            );
        } else {
            $data = json_decode($raw, true);

            if (!is_array($data) || !isset($data["uploads"]) || !is_array($data["uploads"])) {
                $backup_file = $storage_file . ".corrupt." . gmdate("Ymd_His") . ".bak";
                file_put_contents($backup_file, $raw, LOCK_EX);

                $data = array(
                    "week" => $week_key,
                    "updated_at" => gmdate("c"),
                    "upload_count" => 0,
                    "uploads" => array(),
                    "recovered_from_corrupt_backup" => basename($backup_file),
                );
            }
        }

        $data["week"] = $week_key;
        $data["uploads"][] = $upload;
        $data["updated_at"] = gmdate("c");
        $data["upload_count"] = count($data["uploads"]);

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            $error = "JSON encode error.";
            return null;
        }

        rewind($handle);

        if (!ftruncate($handle, 0)) {
            $error = "Truncate fail.";
            return null;
        }

        $bytes = fwrite($handle, $json);

        if ($bytes === false || $bytes < strlen($json)) {
            $error = "Write error.";
            return null;
        }

        if (!fflush($handle)) {
            $error = "Flush fail.";
            return null;
        }

        $result = $data;
        return $result;
    } finally {
        if ($locked) {
            flock($handle, LOCK_UN);
        }

        fclose($handle);

        if ($error !== null) {
            send_forbidden($error);
            exit;
        }
    }
}

function extract_http_status($headers)
{
    if (!is_array($headers) || count($headers) === 0) {
        return 0;
    }

    $first_header = isset($headers[0]) ? $headers[0] : "";

    if (preg_match('/HTTP\/\S+\s+(\d{3})/', $first_header, $matches) !== 1) {
        return 0;
    }

    return (int) $matches[1];
}

function truncate_string($value, $max_length)
{
    if (!is_string($value)) {
        return "";
    }

    if (strlen($value) <= $max_length) {
        return $value;
    }

    return substr($value, 0, $max_length) . "...";
}

function forward_bridge_logs_to_ingest($endpoint, $secret, $payload)
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($json === false) {
        return array(
            "ok" => false,
            "method" => "none",
            "error" => "json_encode_failed",
            "http_status" => 0,
        );
    }

    if (function_exists("curl_init")) {
        return forward_bridge_logs_to_ingest_with_curl($endpoint, $secret, $json);
    }

    return forward_bridge_logs_to_ingest_with_stream($endpoint, $secret, $json);
}

function forward_bridge_logs_to_ingest_with_curl($endpoint, $secret, $json)
{
    $ch = curl_init($endpoint);

    if ($ch === false) {
        return array(
            "ok" => false,
            "method" => "curl",
            "error" => "curl_init_failed",
            "http_status" => 0,
        );
    }

    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => array(
            "Content-Type: application/json",
            "Accept: application/json",
            "User-Agent: WalkerPisa-Bridge-Logs",
            "X-Log-Auth: " . $secret,
        ),
        CURLOPT_POSTFIELDS => $json,
    ));

    $body = curl_exec($ch);
    $curl_error = curl_error($ch);
    $curl_errno = curl_errno($ch);
    $http_status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($body === false) {
        return array(
            "ok" => false,
            "method" => "curl",
            "error" => "curl_exec_failed",
            "curl_errno" => $curl_errno,
            "curl_error" => $curl_error,
            "http_status" => $http_status,
        );
    }

    $decoded = json_decode($body, true);
    $is_success = ($http_status >= 200 && $http_status < 300);

    return array(
        "ok" => $is_success,
        "method" => "curl",
        "http_status" => $http_status,
        "ingest_response" => is_array($decoded) ? $decoded : null,
        "raw_body" => $is_success ? null : truncate_string($body, 1000),
    );
}

function forward_bridge_logs_to_ingest_with_stream($endpoint, $secret, $json)
{
    $context = stream_context_create(array(
        "http" => array(
            "method" => "POST",
            "ignore_errors" => true,
            "timeout" => 30,
            "header" => implode("\r\n", array(
                "Content-Type: application/json",
                "Accept: application/json",
                "User-Agent: WalkerPisa-Bridge-Logs",
                "X-Log-Auth: " . $secret,
            )),
            "content" => $json,
        ),
    ));

    $body = file_get_contents($endpoint, false, $context);
    $http_status = extract_http_status(isset($http_response_header) ? $http_response_header : array());

    if ($body === false) {
        return array(
            "ok" => false,
            "method" => "stream",
            "error" => "request_failed",
            "http_status" => $http_status,
        );
    }

    $decoded = json_decode($body, true);
    $is_success = ($http_status >= 200 && $http_status < 300);

    return array(
        "ok" => $is_success,
        "method" => "stream",
        "http_status" => $http_status,
        "ingest_response" => is_array($decoded) ? $decoded : null,
        "raw_body" => $is_success ? null : truncate_string($body, 1000),
    );
}
/*
    HELPERS
*/
function scalar_to_string($value)
{
    if (is_null($value)) {
        return "";
    }

    if (is_scalar($value)) {
        return trim((string) $value);
    }

    return "";
}

function get_payload_source_device_id($params)
{
    if (!isset($params["source"]) || !is_array($params["source"])) {
        return "";
    }

    return scalar_to_string($params["source"]["deviceId"] ?? "");
}

function has_source_identity($params)
{
    $bridge_id = scalar_to_string($params["bridgeId"] ?? "");
    $source_device_id = get_payload_source_device_id($params);

    return $bridge_id !== "" || $source_device_id !== "";
}

function get_payload_log_text($params)
{
    $top_level_log_text = scalar_to_string($params["logText"] ?? "");

    if ($top_level_log_text !== "") {
        return $top_level_log_text;
    }

    if (isset($params["raw"]) && is_array($params["raw"])) {
        return scalar_to_string($params["raw"]["logText"] ?? "");
    }

    return "";
}

function has_non_empty_array_of_objects($value)
{
    if (!is_array($value) || count($value) === 0) {
        return false;
    }

    foreach ($value as $item) {
        if (is_array($item)) {
            return true;
        }
    }

    return false;
}

function has_log_processable_content($params)
{
    if (get_payload_log_text($params) !== "") {
        return true;
    }

    if (has_non_empty_array_of_objects($params["events"] ?? null)) {
        return true;
    }

    if (has_non_empty_array_of_objects($params["vehicleEvents"] ?? null)) {
        return true;
    }

    return false;
}
/*
    RUNTIME CONFIG
*/
require_once __DIR__ . '/../../vendor/autoload.php';
\App\Support\Bootstrap::init();

/*
    USER AGENT VALIDATION
*/
$expected_user_agent = (string) \App\Support\Env::get('LOG_INGEST_USER_AGENT', 'WalkerPisa-Bridge-Logs');
$provided_user_agent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');

if ($expected_user_agent === '' || $provided_user_agent !== $expected_user_agent) {
    send_forbidden("Invalid source.");
    exit;
}

/*
    SHARED SECRET VALIDATION
    Header esperado: X-Log-Auth: valor actual de LOG_INGEST_SECRET en runtime.php
*/
$expected_auth = (string) \App\Support\Env::get('LOG_INGEST_SECRET', '');
$forward_ingest_enabled = ((string) \App\Support\Env::get('LEGACY_FORWARD_ENABLED', 'true')) !== 'false';
$forward_ingest_endpoint = rtrim((string) \App\Support\Env::get('APP_URL', 'https://logs.singularthings.io'), '/') . '/api/ingest.php';
$provided_auth = (string) ($_SERVER['HTTP_X_LOG_AUTH'] ?? '');

if ($expected_auth === '' || !hash_equals($expected_auth, $provided_auth)) {
    send_forbidden("Invalid auth.");
    exit;
}

/*
    RAW JSON BODY VALIDATION
*/
$raw = file_get_contents('php://input');

if ($raw === false || empty(trim($raw))) {
    send_error("Empty body");
    exit;
}

$params = json_decode(trim($raw), true);

if (is_null($params) || !is_array($params)) {
    send_error("JSON decode error");
    exit;
}

/*
    BASIC PAYLOAD VALIDATION
*/
if (!isset($params["message"]) || !is_string($params["message"])) {
    send_forbidden("Invalid message type.");
    exit;
}

$message = $params["message"];
$is_logs_message = ($message === "bridge_logs");
$is_error_message = is_error_message_type($message);

if (!$is_logs_message && !$is_error_message) {
    send_forbidden("Invalid message type.");
    exit;
}

if (!has_source_identity($params)) {
    send_forbidden("Missing source identity.");
    exit;
}

if ($is_logs_message && !has_log_processable_content($params)) {
    send_forbidden("Missing log content.");
    exit;
}

if ($is_error_message && !has_error_content($params)) {
    send_forbidden("Missing error content.");
    exit;
}

/*
    STORAGE
*/
$storage_dir = __DIR__ . "/storage";

if (!is_dir($storage_dir) && !mkdir($storage_dir, 0775, true) && !is_dir($storage_dir)) {
    send_forbidden("Storage dir create failed.");
    exit;
}

if (!is_writable($storage_dir)) {
    send_forbidden("Storage dir not writable.");
    exit;
}

$week_key = gmdate("o") . "_W" . gmdate("W");
$logs_file = $storage_dir . "/bridge_logs_" . $week_key . ".json";
$errors_file = $storage_dir . "/bridge_errors_" . $week_key . ".json";

$upload = array();
$upload["received_at"] = gmdate("c");
$upload["remote_addr"] = get_client_ip();
$upload["user_agent"] = isset($_SERVER["HTTP_USER_AGENT"]) ? $_SERVER["HTTP_USER_AGENT"] : "";
$upload["payload"] = $params;

$logs_data = null;
$errors_data = null;
$stored_in = array();
$extracted_errors = array();
$forward_result = null;

if ($is_logs_message) {
    $logs_data = store_upload($logs_file, $week_key, $upload);
    $stored_in[] = basename($logs_file);

    if ($forward_ingest_enabled) {
        $forward_result = forward_bridge_logs_to_ingest(
            $forward_ingest_endpoint,
            $expected_auth,
            $params
        );
    }

    $log_text_for_error_extraction = get_payload_log_text($params);
    $extracted_errors = extract_error_lines($log_text_for_error_extraction);

    if (count($extracted_errors) > 0) {
        $error_upload = $upload;
        $error_upload["payload"]["message"] = "bridge_log_errors";
        $error_upload["payload"]["errorLines"] = $extracted_errors;
        $error_upload["payload"]["errorLineCount"] = count($extracted_errors);

        $errors_data = store_upload($errors_file, $week_key, $error_upload);
        $stored_in[] = basename($errors_file);
    }
}

if ($is_error_message) {
    $errors_data = store_upload($errors_file, $week_key, $upload);
    $stored_in[] = basename($errors_file);
}

/*
    RESPONSE
*/
$response = array();
$response["ok"] = true;
$response["stored"] = true;
$response["week"] = $week_key;
$stored_files = array_values(array_unique($stored_in));
$response["stored_in"] = isset($stored_files[0]) ? $stored_files[0] : "";
$response["stored_files"] = $stored_files;
$response["upload_count"] = !is_null($logs_data) ? $logs_data["upload_count"] : (!is_null($errors_data) ? $errors_data["upload_count"] : 0);
$response["logs_upload_count"] = !is_null($logs_data) ? $logs_data["upload_count"] : null;
$response["errors_upload_count"] = !is_null($errors_data) ? $errors_data["upload_count"] : null;
$response["extracted_error_count"] = count($extracted_errors);
$response["forward_attempted"] = $is_logs_message && $forward_ingest_enabled;
$response["forward_endpoint"] = $forward_ingest_endpoint;
$response["forwarded_to_ingest"] = is_array($forward_result) ? (bool) $forward_result["ok"] : null;
$response["ingest_forward"] = $forward_result;
$response["datetime"] = gmdate("c");

send_json(json_encode($response));
exit;
