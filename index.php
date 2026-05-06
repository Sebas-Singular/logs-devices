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
    header('Content-Length: '.strlen($json));
    echo $json;
    exit;
}

function send_forbidden($msg = "default")
{
    header('HTTP/1.0 403 Forbidden');
    echo "You are forbidden! (".$msg.")";
    exit;
}

function send_error($msg = "error")
{
    header('HTTP/1.0 400 Bad Request');
    echo $msg;
    exit;
}

/*
    USER AGENT VALIDATION
*/
if (!isset($_SERVER['HTTP_USER_AGENT']) || $_SERVER['HTTP_USER_AGENT'] != "WalkerPisa-Bridge-Logs")
{
    send_forbidden("Invalid source.");
    exit;
}

/*
    SHARED SECRET VALIDATION
    Header esperado: X-Log-Auth: tu-secreto
*/
$expected_auth = "WalkerPisaLogs-2026-ST";

if (!isset($_SERVER['HTTP_X_LOG_AUTH']) || $_SERVER['HTTP_X_LOG_AUTH'] !== $expected_auth)
{
    send_forbidden("Invalid auth.");
    exit;
}

/*
    RAW JSON BODY VALIDATION
*/
$raw = file_get_contents('php://input');

if ($raw === false || empty(trim($raw)))
{
    send_error("Empty body");
    exit;
}

$params = json_decode(trim($raw), true);

if (is_null($params) || !is_array($params))
{
    send_error("JSON decode error");
    exit;
}

/*
    BASIC PAYLOAD VALIDATION
*/
if (!isset($params["message"]) || $params["message"] !== "bridge_logs")
{
    send_forbidden("Invalid message type.");
    exit;
}

if (!isset($params["bridgeId"]))
{
    send_forbidden("Missing bridgeId.");
    exit;
}

if (!isset($params["logText"]) || !is_string($params["logText"]))
{
    send_forbidden("Missing logText.");
    exit;
}

/*
    STORAGE
*/
$storage_dir = __DIR__ . "/storage";

if (!is_dir($storage_dir) && !mkdir($storage_dir, 0775, true) && !is_dir($storage_dir))
{
    send_forbidden("Storage dir create failed.");
    exit;
}

if (!is_writable($storage_dir))
{
    send_forbidden("Storage dir not writable.");
    exit;
}

$week_key = gmdate("o") . "_W" . gmdate("W");
$storage_file = $storage_dir . "/bridge_logs_" . $week_key . ".json";

$data = array();
$data["week"] = $week_key;
$data["updated_at"] = gmdate("c");
$data["upload_count"] = 0;
$data["uploads"] = array();

if (file_exists($storage_file))
{
    $existing = file_get_contents($storage_file);

    if ($existing !== false && !empty(trim($existing)))
    {
        $decoded = json_decode($existing, true);

        if (!is_null($decoded) && is_array($decoded) && isset($decoded["uploads"]) && is_array($decoded["uploads"]))
        {
            $data = $decoded;
        }
    }
}

$upload = array();
$upload["received_at"] = gmdate("c");
$upload["remote_addr"] = isset($_SERVER["REMOTE_ADDR"]) ? $_SERVER["REMOTE_ADDR"] : "";
$upload["user_agent"] = isset($_SERVER["HTTP_USER_AGENT"]) ? $_SERVER["HTTP_USER_AGENT"] : "";
$upload["payload"] = $params;

$data["week"] = $week_key;
$data["uploads"][] = $upload;
$data["updated_at"] = gmdate("c");
$data["upload_count"] = count($data["uploads"]);

$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

if ($json === false)
{
    send_forbidden("JSON encode error.");
    exit;
}

if (file_put_contents($storage_file, $json, LOCK_EX) === false)
{
    send_forbidden("Write error.");
    exit;
}

/*
    RESPONSE
*/
$response = array();
$response["ok"] = true;
$response["stored"] = true;
$response["week"] = $week_key;
$response["stored_in"] = basename($storage_file);
$response["upload_count"] = $data["upload_count"];
$response["datetime"] = gmdate("c");

send_json(json_encode($response));
exit;
