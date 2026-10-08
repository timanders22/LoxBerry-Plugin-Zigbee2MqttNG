<?php
/**
 * Uninstall, 4.1.1 (B12, Q4): removes the retained topics that zigbee2mqtt
 * itself left in the broker - <topic>/bridge/state (with the last will
 * "offline"), bridge/info, bridge/devices, bridge/groups,
 * bridge/definitions, bridge/extensions, bridge/converters and
 * <topic>/<device>/availability; device topics too where retain is switched
 * on (devices.yaml or device_options in configuration.yaml).
 *
 * Only when no predecessor plugin (Zigbee2Mqtt, Zigbee2Lox) with the same
 * base topic is still installed: it shares the topic, and its zigbee2mqtt
 * publishes there again - then only a <WARNING>.
 *
 * Goes through the UDP input of the MQTT gateway like clear-haus-topics.php:
 * "retain <topic> " with an empty value deletes the retained message. The
 * input drops datagrams under load, so every line is sent twice; whether
 * they arrived cannot be seen from here. With an own broker (not the one of
 * the gateway) nothing is sent: <WARNING> with the number of topics.
 *
 * Usage: php clear-retained.php <config dir> <data dir> <LoxBerry home>
 */
require_once __DIR__ . "/zigbee2mqttng.php";

$konfig = isset($argv[1]) ? rtrim($argv[1], "/") : "";
$daten = isset($argv[2]) ? rtrim($argv[2], "/") : "";
$home = isset($argv[3]) ? rtrim($argv[3], "/") : "";

$mqtt = zng_read_json($konfig . "/mqtt.json", null);
if (!is_array($mqtt) || !isset($mqtt["topic"]) || !is_string($mqtt["topic"]) || !zng_topic_ok($mqtt["topic"])) {
    echo "<WARNING> mqtt.json not readable - the retained topics of zigbee2mqtt were not removed\n";
    exit(0);
}
$basis = $mqtt["topic"];

// Q4: a predecessor with the same base topic keeps its topics
$vorgaenger = array("zigbee2mqtt" => "Zigbee2Mqtt", "zigbee2lox" => "Zigbee2Lox");
foreach ($vorgaenger as $ordner => $titel) {
    if (!is_dir($home . "/config/plugins/" . $ordner)) {
        continue;
    }
    $alt = zng_read_json($home . "/config/plugins/" . $ordner . "/mqtt.json", array());
    $altTopic = is_array($alt) && isset($alt["topic"]) && is_string($alt["topic"]) && $alt["topic"] !== "" ? $alt["topic"] : "zigbee2mqtt";
    if ($altTopic === $basis) {
        echo "<WARNING> $titel is still installed and uses the same MQTT topic '$basis'. The retained topics of zigbee2mqtt below $basis/ were left in the broker; they belong to $titel as well.\n";
        exit(0);
    }
}

$themen = array();
foreach (array("state", "info", "devices", "groups", "definitions", "extensions", "converters") as $b) {
    $themen[] = $basis . "/bridge/" . $b;
}
$retainAlle = false;
$yaml = is_file($daten . "/configuration.yaml") && function_exists("yaml_parse_file") ? @yaml_parse_file($daten . "/configuration.yaml") : null;
if (is_array($yaml) && isset($yaml["device_options"]["retain"]) && $yaml["device_options"]["retain"] === true) {
    $retainAlle = true;
}
$retainGeraete = array();
$geraeteYaml = is_file($daten . "/devices.yaml") && function_exists("yaml_parse_file") ? @yaml_parse_file($daten . "/devices.yaml") : null;
if (is_array($geraeteYaml)) {
    foreach ($geraeteYaml as $g) {
        if (is_array($g) && isset($g["friendly_name"]) && isset($g["retain"]) && $g["retain"] === true) {
            $retainGeraete[] = (string) $g["friendly_name"];
        }
    }
}
foreach (zng_state_devices(zng_read_json($daten . "/zigbee2mqttng_devices.json", array())) as $d) {
    $themen[] = $basis . "/" . $d["friendly_name"] . "/availability";
    if ($retainAlle || in_array($d["friendly_name"], $retainGeraete, true)) {
        $themen[] = $basis . "/" . $d["friendly_name"];
    }
}

if (!isset($mqtt["usemqttgateway"]) || zng_haken($mqtt["usemqttgateway"]) !== true) {
    echo "<WARNING> Own MQTT broker: " . count($themen) . " retained topic(s) of zigbee2mqtt below $basis/ were not removed (only the MQTT gateway can do that here).\n";
    exit(0);
}

$general = json_decode((string) @file_get_contents($home . "/config/system/general.json"), true);
$port = isset($general["Mqtt"]["Udpinport"]) ? (int) $general["Mqtt"]["Udpinport"] : 11884;
$socket = @stream_socket_client("udp://127.0.0.1:" . $port, $errno, $errstr, 2);
if (!$socket) {
    echo "<WARNING> Could not remove the retained topics of zigbee2mqtt: $errstr\n";
    exit(0);
}
$n = 0;
$ausgelassen = array();
foreach ($themen as $thema) {
    // "retain <topic> <value>" is split at spaces by the gateway, and a
    // wildcard would widen it: such topics are left out and named below
    if (strpbrk($thema, " +#\n\r") !== false) {
        $ausgelassen[] = $thema;
        continue;
    }
    for ($i = 0; $i < 2; $i++) {
        fwrite($socket, "retain " . $thema . " ");
        usleep(50000);
    }
    $n++;
}
fclose($socket);
echo "<INFO> Sent the removal of $n retained topic(s) of zigbee2mqtt below $basis/ to the MQTT gateway (twice each; the UDP input does not confirm)\n";
if ($ausgelassen) {
    echo "<WARNING> " . count($ausgelassen) . " retained topic(s) contain a space and cannot be removed through the UDP input of the gateway: " . implode(", ", array_slice($ausgelassen, 0, 10)) . (count($ausgelassen) > 10 ? ", ..." : "") . "\n";
}
