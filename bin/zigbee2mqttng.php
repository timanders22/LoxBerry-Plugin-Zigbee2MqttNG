<?php
/**
 * Helpers shared by update-config.php and the web frontend.
 *
 * Expects defines.php to be loaded.
 */

/**
 * 4.1.1 (B7): heartbeat of the extension below <topic>/ - must match
 * HEARTBEAT_TOPIC in zigbee2mqttng_extension.mjs
 */
const ZNG_LEBENSZEICHEN = "zigbee2mqttng";

/**
 * Reads a json file, returns $default when it is missing or broken
 */
function zng_read_json($file, $default = null)
{
    if (!is_file($file)) {
        return $default;
    }
    $data = json_decode(file_get_contents($file), true);
    return $data === null ? $default : $data;
}

/**
 * Writes a file only when its content changed. The MQTT gateway watches the
 * subscription file and reloads on every write, so unchanged content is kept.
 */
function zng_write_if_changed($file, $content)
{
    if (is_file($file) && file_get_contents($file) === $content) {
        return false;
    }
    file_put_contents($file, $content);
    return true;
}

/**
 * True if a topic part can be used in a subscription (no wildcards)
 */
function zng_topic_ok($name)
{
    return is_string($name) && $name !== "" && strpbrk($name, "+#") === false;
}

/**
 * Name of a value as the MQTT gateway forwards it (virtual HTTP input,
 * mqtt_resetaftersend.cfg): "/" and "%" become "_", nothing else changes.
 */
function zng_gateway_name($topic)
{
    return str_replace(array("/", "%"), "_", (string) $topic);
}

/**
 * True if an action value can be used as topic level
 */
function zng_action_ok($value)
{
    return is_string($value) && preg_match('/^[A-Za-z0-9_\-]{1,40}$/', $value);
}

/**
 * Action values a device can send (expose "action" of type enum)
 * Must give the same result as actionValues() in zigbee2mqttng_extension.mjs.
 */
function zng_action_values($device)
{
    $values = array();
    $walk = function ($exposes) use (&$walk, &$values) {
        foreach ((array) $exposes as $e) {
            if (isset($e["features"]) && is_array($e["features"])) {
                $walk($e["features"]);
            } elseif (isset($e["property"]) && $e["property"] === "action" && isset($e["type"]) && $e["type"] === "enum" && isset($e["values"]) && is_array($e["values"])) {
                foreach ($e["values"] as $v) {
                    if (zng_action_ok($v) && !in_array((string) $v, $values, true)) {
                        $values[] = (string) $v;
                    }
                }
            }
        }
    };
    $walk(isset($device["definition"]["exposes"]) ? $device["definition"]["exposes"] : array());
    return $values;
}

/**
 * Devices that have state topics (no coordinator, usable name)
 */
function zng_state_devices($devices)
{
    $list = array();
    foreach ((array) $devices as $device) {
        if (!isset($device["friendly_name"]) || (isset($device["type"]) && $device["type"] == "Coordinator")) {
            continue;
        }
        if (zng_topic_ok($device["friendly_name"])) {
            $list[] = $device;
        }
    }
    return $list;
}

/**
 * Subscription lines for the MQTT gateway: only the state topics of devices
 * and groups - the large bridge/* messages (device list, logging, ...) stay
 * away from the Miniserver.
 * Must give the same result as subscriptionLines() in zigbee2mqttng_extension.mjs.
 */
function zng_subscription_lines($base, $devices, $groups, $availability, $haus)
{
    // 4.1.1 (B7): the heartbeat of the extension (<topic>/zigbee2mqttng/ts
    // and /zaehler) - the one value that tells Loxone zigbee2mqtt is alive
    $lines = array($base . "/bridge/state", $base . "/" . ZNG_LEBENSZEICHEN . "/#");
    foreach (zng_state_devices($devices) as $device) {
        $lines[] = $base . "/" . $device["friendly_name"];
        if ($availability) {
            $lines[] = $base . "/" . $device["friendly_name"] . "/erreichbar";
        }
        if (count(zng_action_values($device)) > 0) {
            $lines[] = $base . "/" . $device["friendly_name"] . "/aktion/+";
        }
    }
    foreach ((array) $groups as $group) {
        if (isset($group["friendly_name"]) && zng_topic_ok($group["friendly_name"])) {
            $lines[] = $base . "/" . $group["friendly_name"];
        }
    }
    if ($haus) {
        $lines[] = "haus/tuer/#";
    }
    return implode("\n", $lines) . "\n";
}

/**
 * mqtt_resetaftersend.cfg: the gateway sends 0 after every button counter,
 * so Loxone sees one pulse per press.
 * Must give the same result as resetLines() in zigbee2mqttng_extension.mjs.
 */
function zng_reset_lines($base, $devices)
{
    $lines = array();
    foreach (zng_state_devices($devices) as $device) {
        foreach (zng_action_values($device) as $value) {
            $lines[] = zng_gateway_name($base . "/" . $device["friendly_name"] . "/aktion/" . $value);
        }
    }
    return count($lines) > 0 ? implode("\n", $lines) . "\n" : "";
}

/**
 * Lists serial devices. Paths below /dev/serial/by-id never change, whereas
 * /dev/ttyACM0 and /dev/ttyACM1 may swap on every boot as soon as a second
 * stick (e.g. a Thread radio for Matter) is plugged in.
 */
function zng_serial_ports()
{
    $ports = array();
    foreach (glob("/dev/serial/by-id/*") ?: array() as $link) {
        $target = realpath($link);
        $ports[] = array("path" => $link, "target" => $target ? $target : "", "stable" => true);
    }
    foreach (array("/dev/ttyACM*", "/dev/ttyUSB*", "/dev/ttyAMA*") as $pattern) {
        foreach (glob($pattern) ?: array() as $dev) {
            $ports[] = array("path" => $dev, "target" => $dev, "stable" => false);
        }
    }
    return $ports;
}

/**
 * True if the given port is a kernel name that may change between boots
 */
function zng_port_unstable($port)
{
    return (bool) preg_match('#^/dev/tty(ACM|USB)[0-9]+$#', (string) $port);
}

/**
 * Checks a coordinator port without touching the service:
 *   tcp://host:port  - resolves the name and opens a TCP connection
 *   /dev/...         - checks that the device exists and is readable
 * Only these two forms are accepted; nothing is passed to a shell.
 * Returns array("result" => bool, "message" => key[, "ip" => ...]).
 */
function zng_test_port($port, $timeout = 3)
{
    $port = trim((string) $port);
    if ($port === "") {
        return array("result" => false, "message" => "empty");
    }
    if (preg_match('#^tcp://([A-Za-z0-9.\-]+):([0-9]{1,5})$#', $port, $m)) {
        $tcpPort = (int) $m[2];
        if ($tcpPort < 1 || $tcpPort > 65535) {
            return array("result" => false, "message" => "invalid");
        }
        $ip = gethostbyname($m[1]);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return array("result" => false, "message" => "unresolved");
        }
        $fp = @fsockopen($ip, $tcpPort, $errno, $errstr, $timeout);
        if ($fp === false) {
            return array("result" => false, "message" => "unreachable", "ip" => $ip);
        }
        fclose($fp);
        return array("result" => true, "message" => "reachable", "ip" => $ip);
    }
    if (preg_match('#^/dev/[A-Za-z0-9/_.:\-]+$#', $port)) {
        if (!file_exists($port)) {
            return array("result" => false, "message" => "missing");
        }
        return is_readable($port)
            ? array("result" => true, "message" => "present")
            : array("result" => false, "message" => "noaccess");
    }
    return array("result" => false, "message" => "invalid");
}

/**
 * Processes that have the serial device open, found through /proc/<pid>/fd.
 * Only processes of the same user (loxberry) are visible - zigbee2mqtt of
 * this plugin and of its predecessors run as loxberry.
 * Returns array of array(pid, command).
 */
function zng_port_users($port)
{
    $target = realpath((string) $port);
    if ($target === false || strpos($target, "/dev/") !== 0) {
        return array();
    }
    $users = array();
    foreach (glob("/proc/[0-9]*/fd/*") ?: array() as $fd) {
        if (@readlink($fd) === $target) {
            $pid = (int) explode("/", $fd)[2];
            if (!isset($users[$pid])) {
                $cmd = trim(str_replace("\0", " ", (string) @file_get_contents("/proc/$pid/cmdline")));
                $unit = "";
                if (preg_match('#/system\.slice/([^/\s]+)\.service#', (string) @file_get_contents("/proc/$pid/cgroup"), $m)) {
                    $unit = $m[1];
                }
                $users[$pid] = array("pid" => $pid, "command" => $cmd, "unit" => $unit);
            }
        }
    }
    return array_values($users);
}

/**
 * True if something listens on the TCP port of this machine
 */
function zng_port_listening($port)
{
    $fp = @fsockopen("127.0.0.1", (int) $port, $errno, $errstr, 1);
    if ($fp === false) {
        return false;
    }
    fclose($fp);
    return true;
}

/**
 * Channel of a Thread operational dataset (hex TLVs). TLV type 0 is the
 * channel: one byte channel page, two bytes channel. Returns 0 if not found.
 */
function zng_dataset_channel($hex)
{
    $hex = trim((string) $hex);
    if (!preg_match('/^([0-9A-Fa-f]{2})+$/', $hex)) {
        return 0;
    }
    $bin = hex2bin($hex);
    $i = 0;
    $len = strlen($bin);
    while ($i + 2 <= $len) {
        $type = ord($bin[$i]);
        $l = ord($bin[$i + 1]);
        if ($i + 2 + $l > $len) {
            break;
        }
        if ($type == 0 && $l == 3) {
            return (ord($bin[$i + 3]) << 8) | ord($bin[$i + 4]);
        }
        $i += 2 + $l;
    }
    return 0;
}

/**
 * Thread channel: from the dataset stored by Matter2Lox, otherwise from an
 * OpenThread border router on this LoxBerry (REST port 8081).
 * Returns array(channel, source) or array(0, "").
 */
function zng_thread_channel()
{
    global $matter2loxConfigFile;
    $files = array_unique(array_merge(array($matter2loxConfigFile), glob(LBHOMEDIR . "/config/plugins/matter2lox*/matter2lox.json") ?: array()));
    foreach ($files as $file) {
        $cfg = zng_read_json($file, array());
        if (!empty($cfg["thread_dataset"])) {
            $channel = zng_dataset_channel($cfg["thread_dataset"]);
            if ($channel) {
                return array($channel, "Matter2Lox");
            }
        }
    }
    $ctx = stream_context_create(array("http" => array(
        "method" => "GET", "timeout" => 1, "ignore_errors" => true,
        "header" => "Accept: text/plain")));
    $body = @file_get_contents("http://127.0.0.1:8081/node/dataset/active", false, $ctx);
    if ($body !== false) {
        $channel = zng_dataset_channel(trim($body, " \t\r\n\"'"));
        if ($channel) {
            return array($channel, "OpenThread Border Router");
        }
    }
    return array(0, "");
}

/**
 * Zigbee channel actually used by the coordinator (bridge/info), otherwise
 * the configured one, otherwise the zigbee2mqtt default 11.
 * Returns array(channel, source).
 */
function zng_zigbee_channel()
{
    global $bridgeInfoFile, $serviceConfigFile;
    $info = zng_read_json($bridgeInfoFile, array());
    if (!empty($info["network"]["channel"])) {
        return array((int) $info["network"]["channel"], "coordinator");
    }
    $cfg = is_file($serviceConfigFile) ? @yaml_parse_file($serviceConfigFile) : false;
    if (is_array($cfg) && !empty($cfg["advanced"]["channel"])) {
        return array((int) $cfg["advanced"]["channel"], "configuration");
    }
    return array(11, "default");
}

/**
 * 2.4 GHz WLAN channel of this LoxBerry (only if it is connected by WLAN),
 * from "iw dev". Returns array(channel, interface) or array(0, "").
 */
function zng_wifi_channel()
{
    $out = (string) @shell_exec("iw dev 2>/dev/null");
    $iface = "";
    foreach (preg_split('/\r?\n/', $out) as $line) {
        if (preg_match('/^\s*Interface\s+(\S+)/', $line, $m)) {
            $iface = $m[1];
        } elseif (preg_match('/^\s*channel\s+([0-9]+)\s+\(([0-9]+) MHz/', $line, $m) && (int) $m[2] < 2500) {
            return array((int) $m[1], $iface);
        }
    }
    return array(0, "");
}

/**
 * Do a Zigbee channel (2 MHz wide, 2405 + 5 * (ch - 11) MHz) and a 2.4 GHz
 * WLAN channel (about 20 MHz wide, 2412 + 5 * (ch - 1) MHz, channel 14 at
 * 2484 MHz) overlap? Returns "conflict", "near" or "ok".
 */
function zng_wifi_overlap($zigbee, $wifi)
{
    if (!$zigbee || !$wifi) {
        return "ok";
    }
    $fz = 2405 + 5 * ($zigbee - 11);
    $fw = $wifi == 14 ? 2484 : 2412 + 5 * ($wifi - 1);
    $distance = abs($fz - $fw);
    if ($distance <= 11) {
        return "conflict";
    }
    if ($distance <= 16) {
        return "near";
    }
    return "ok";
}

/**
 * Predecessor plugins (the original Zigbee2Mqtt and Zigbee2Lox, the former
 * name of this plugin) that are still installed. All use the same adapter, so
 * only one of them may run.
 */
function zng_predecessor_plugins()
{
    global $predecessorPlugins;
    $list = array();
    foreach ($predecessorPlugins as $folder => $plugin) {
        if (!is_dir(LBHOMEDIR . "/config/plugins/" . $folder)) {
            continue;
        }
        $active = trim((string) shell_exec("systemctl is-active " . escapeshellarg($plugin["service"]) . " 2>/dev/null")) === "active";
        $list[] = array("title" => $plugin["title"], "installed" => true, "active" => $active);
    }
    return $list;
}

/**
 * Settings of the LoxBerry MQTT gateway that matter for the Loxone templates
 */
function zng_gateway_info()
{
    $general = zng_read_json(LBSCONFIGDIR . "/general.json", array());
    $mqtt = isset($general["Mqtt"]) ? $general["Mqtt"] : array();
    $gateway = zng_read_json(LBSCONFIGDIR . "/mqttgateway.json", array());
    return array(
        "udpinport" => isset($mqtt["Udpinport"]) ? (int) $mqtt["Udpinport"] : 11884,
        "version" => isset($mqtt["Gatewayversion"]) ? (int) $mqtt["Gatewayversion"] : 0,
        "autostart" => !isset($mqtt["Gatewayautostart"]) || is_enabled($mqtt["Gatewayautostart"]),
        "udpport" => isset($gateway["Main"]["udpport"]) ? (int) $gateway["Main"]["udpport"] : 0,
        "use_udp" => isset($gateway["Main"]["use_udp"]) && is_enabled($gateway["Main"]["use_udp"]),
        "use_http" => !isset($gateway["Main"]["use_http"]) || is_enabled($gateway["Main"]["use_http"]),
        "convert_booleans" => !isset($gateway["Main"]["convert_booleans"]) || is_enabled($gateway["Main"]["convert_booleans"]),
    );
}

/**
 * State of the systemd service: pid (0 if stopped) and start time
 */
function zng_service_state($service)
{
    $pid = (int) trim((string) shell_exec("systemctl show --property MainPID --value " . escapeshellarg($service) . " 2>/dev/null"));
    $since = "";
    $sinceTs = 0;
    if ($pid > 0) {
        $since = trim((string) shell_exec("systemctl show --property ActiveEnterTimestamp --value " . escapeshellarg($service) . " 2>/dev/null"));
        $sinceTs = $since !== "" ? (int) strtotime($since) : 0;
    }
    return array("pid" => $pid, "since" => $since, "sinceTs" => $sinceTs);
}

/**
 * Broker credentials: those of the MQTT gateway or of the own broker
 */
function zng_broker_credentials($mqttcfg)
{
    if (is_enabled($mqttcfg->usemqttgateway)) {
        return mqtt_connectiondetails();
    }
    return array(
        "brokerhost" => $mqttcfg->server,
        "brokerport" => $mqttcfg->port,
        "brokeruser" => $mqttcfg->username,
        "brokerpass" => $mqttcfg->password,
    );
}

/* ==================================================================
 * 4.1.1: reading and writing the configuration safely
 * (house rules Regeln/05; findings B1, B2, B3, B4, B11 of the check of
 * 08.10.2026)
 * ================================================================== */

/**
 * Mode of every file that carries credentials or the network key:
 * mqtt.json (broker password), service.json (token of the zigbee2mqtt UI),
 * configuration.yaml (network key, broker password), their second copies
 * and the .kaputt copies.
 */
const ZNG_MODUS_GEHEIM = 0600;

/**
 * Writes a file in one piece: a temporary file in the same folder gets the
 * mode BEFORE the content, the written length is checked, then rename().
 * A full disk or a power cut leaves the old or the new file, never half of
 * it. Returns true on success; on failure the old file is untouched.
 */
function zng_schreiben_atomar($file, $content, $mode = ZNG_MODUS_GEHEIM)
{
    $content = (string) $content;
    $dir = dirname($file);
    $tmp = @tempnam($dir, "." . basename($file) . ".");
    if ($tmp === false) {
        return false;
    }
    // tempnam() falls back to the system temp folder when $dir is not
    // writable - a rename() from there is no longer atomic
    if (realpath(dirname($tmp)) !== realpath($dir)) {
        @unlink($tmp);
        return false;
    }
    @chmod($tmp, $mode);
    $written = @file_put_contents($tmp, $content);
    clearstatcache(true, $tmp);
    if ($written !== strlen($content) || @filesize($tmp) !== strlen($content) || !@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/**
 * Writes only when the content differs. Returns true when the file holds
 * $content afterwards.
 */
function zng_schreiben_wenn_anders($file, $content, $mode = ZNG_MODUS_GEHEIM)
{
    clearstatcache(true, $file);
    if (is_file($file) && @file_get_contents($file) === (string) $content) {
        @chmod($file, $mode);
        return true;
    }
    return zng_schreiben_atomar($file, $content, $mode);
}

/**
 * State of a JSON configuration (Regeln/05):
 *   "fehlt"  - no file: fresh install, the defaults may be written
 *   "ok"     - a JSON object
 *   "kaputt" - the file exists but is empty, cut off or no object. That is
 *              an error, never an empty configuration.
 */
function zng_json_lage($file)
{
    clearstatcache(true, $file);
    if (!file_exists($file)) {
        return "fehlt";
    }
    $raw = @file_get_contents($file);
    if (!is_string($raw) || trim($raw) === "") {
        return "kaputt";
    }
    $data = json_decode($raw, true);
    return is_array($data) && substr(ltrim($raw), 0, 1) === "{" ? "ok" : "kaputt";
}

/**
 * Keys below "advanced" in configuration.yaml whose loss costs the Zigbee
 * network: without them zigbee2mqtt would form a new network (B2).
 */
function zng_yaml_merkworte()
{
    return array("network_key", "pan_id", "ext_pan_id", "channel");
}

/**
 * What $neu lost compared with $alt (both parsed configuration.yaml):
 * a top level section, or one of the keys of zng_yaml_merkworte() (a list
 * such as the network key also when its length differs). Returns the list
 * of names, empty if nothing is missing.
 */
function zng_yaml_verlust($alt, $neu)
{
    $fehlt = array();
    foreach (array_keys($alt) as $key) {
        if ($key !== "permit_join" && !array_key_exists($key, $neu)) {
            $fehlt[] = (string) $key;
        }
    }
    $a = isset($alt["advanced"]) && is_array($alt["advanced"]) ? $alt["advanced"] : array();
    $n = isset($neu["advanced"]) && is_array($neu["advanced"]) ? $neu["advanced"] : array();
    foreach (zng_yaml_merkworte() as $key) {
        if (!array_key_exists($key, $a)) {
            continue;
        }
        if (!array_key_exists($key, $n)) {
            $fehlt[] = "advanced." . $key;
        } elseif (is_array($a[$key]) && (!is_array($n[$key]) || count($n[$key]) !== count($a[$key]))) {
            $fehlt[] = "advanced." . $key;
        }
    }
    return $fehlt;
}

/**
 * Reads configuration.yaml. Returns array(state, data):
 *   "fehlt"     - no file (fresh install)
 *   "ok"        - a YAML mapping
 *   "kaputt"    - empty, unreadable, no mapping, or it lost something its
 *                 second copy $zweitschrift has (zng_yaml_verlust): a file
 *                 cut off by a power cut is still valid YAML up to the cut,
 *                 only the comparison notices what is missing
 *   "ohne_yaml" - php-yaml is not loaded, nothing can be checked
 */
function zng_yaml_lesen($file, $zweitschrift = null)
{
    clearstatcache(true, $file);
    if (!file_exists($file)) {
        return array("fehlt", array());
    }
    if (!function_exists("yaml_parse")) {
        return array("ohne_yaml", null);
    }
    $raw = @file_get_contents($file);
    if (!is_string($raw) || trim($raw) === "") {
        return array("kaputt", null);
    }
    $data = @yaml_parse($raw);
    if (!is_array($data) || ($data && array_keys($data) === range(0, count($data) - 1))) {
        return array("kaputt", null);
    }
    if ($zweitschrift !== null && is_file($zweitschrift)) {
        $alt = @yaml_parse((string) @file_get_contents($zweitschrift));
        if (is_array($alt) && zng_yaml_verlust($alt, $data)) {
            return array("kaputt", $data);
        }
    }
    return array("ok", $data);
}

/**
 * Second copy next to the config folder (Regeln/05, Regeln/06): the
 * installer removes config/plugins/<folder>/ on every upgrade, a file next
 * to it survives. $configDir = config/plugins/<folder>.
 */
function zng_zweitschrift_pfad($configDir, $name)
{
    return rtrim($configDir, "/\\") . ".backup." . $name;
}

/**
 * Copies a file to its second copy - only when it is readable and carries
 * content: JSON an object with at least one key, YAML a non-empty mapping
 * that lost nothing the second copy has. An unreadable state never
 * overwrites a good second copy.
 * Returns "gezogen", "gleich", "ohne_inhalt" or "fehler".
 */
function zng_zweitschrift_ziehen($file, $art, $configDir, $name)
{
    $ziel = zng_zweitschrift_pfad($configDir, $name);
    if ($art === "yaml") {
        list($lage, $data) = zng_yaml_lesen($file, $ziel);
        $inhalt = $lage === "ok" && is_array($data) && count($data) > 0;
    } else {
        $inhalt = zng_json_lage($file) === "ok" && count((array) json_decode((string) @file_get_contents($file), true)) > 0;
    }
    if (!$inhalt) {
        return "ohne_inhalt";
    }
    $raw = (string) @file_get_contents($file);
    if (is_file($ziel) && @file_get_contents($ziel) === $raw) {
        @chmod($ziel, ZNG_MODUS_GEHEIM);
        return "gleich";
    }
    return zng_schreiben_atomar($ziel, $raw, ZNG_MODUS_GEHEIM) ? "gezogen" : "fehler";
}

/**
 * B1/B2: a file that exists but cannot be read. It stays where it is and
 * is copied to <file>.kaputt (0600) for the record. If its second copy is
 * readable, that is written back once ("geheilt"); otherwise nothing is
 * written ("gesperrt"). Returns array(action, path of the .kaputt copy).
 */
function zng_konfig_heilen($file, $art, $configDir, $name)
{
    $kaputt = $file . ".kaputt";
    $raw = @file_get_contents($file);
    if (is_string($raw) && !(is_file($kaputt) && @file_get_contents($kaputt) === $raw)) {
        zng_schreiben_atomar($kaputt, $raw, ZNG_MODUS_GEHEIM);
    }
    $zweitschrift = zng_zweitschrift_pfad($configDir, $name);
    if ($art === "yaml") {
        list($lage, $data) = zng_yaml_lesen($zweitschrift);
        $gut = $lage === "ok" && is_array($data) && count($data) > 0;
    } else {
        $gut = zng_json_lage($zweitschrift) === "ok";
    }
    if ($gut && zng_schreiben_atomar($file, (string) file_get_contents($zweitschrift), ZNG_MODUS_GEHEIM)) {
        return array("geheilt", $kaputt);
    }
    return array("gesperrt", $kaputt);
}

/** Config folder of this plugin (config/plugins/<folder>) */
function zng_konfig_ordner()
{
    global $mqttconfigfile;
    return dirname($mqttconfigfile);
}

/**
 * The files whose loss hurts: name => array(path, kind). The name is also
 * the suffix of the second copy.
 */
function zng_konfig_dateien()
{
    global $mqttconfigfile, $configfile, $serviceConfigFile;
    return array(
        "mqtt.json" => array($mqttconfigfile, "json"),
        "service.json" => array($configfile, "json"),
        "configuration.yaml" => array($serviceConfigFile, "yaml"),
    );
}

/** name => state of the three files, reading only (nothing is healed) */
function zng_konfig_lage()
{
    $lage = array();
    foreach (zng_konfig_dateien() as $name => $d) {
        if ($d[1] === "yaml") {
            list($l) = zng_yaml_lesen($d[0], zng_zweitschrift_pfad(zng_konfig_ordner(), $name));
        } else {
            $l = zng_json_lage($d[0]);
        }
        $lage[$name] = $l;
    }
    return $lage;
}

/**
 * File that blocks the start of zigbee2mqtt (checked by ExecStartPre of the
 * service). It holds the names of the unreadable files.
 */
function zng_startsperre_datei()
{
    return zng_konfig_ordner() . "/startsperre";
}

/**
 * For the settings pages and the Test tab: every file that is broken or
 * kept as .kaputt, the start block and the second copies.
 */
function zng_konfig_anzeige()
{
    $zeilen = array();
    foreach (zng_konfig_lage() as $name => $lage) {
        $datei = zng_konfig_dateien()[$name][0];
        $zeilen[] = array(
            "name" => $name,
            "lage" => $lage,
            "datei" => $datei,
            "kaputt" => is_file($datei . ".kaputt") ? $datei . ".kaputt" : "",
            "zweitschrift" => is_file(zng_zweitschrift_pfad(zng_konfig_ordner(), $name)),
        );
    }
    $sperre = zng_startsperre_datei();
    return array(
        "dateien" => $zeilen,
        "sperre" => is_file($sperre) ? trim((string) @file_get_contents($sperre)) : "",
        "zweitschriftOrdner" => dirname(zng_konfig_ordner()),
    );
}

/* ---------------- value checks (B11) ----------------
 * One place for the form, the restore of a backup and update-config.php.
 * Patterns end with \z (a "$" lets a final line break through), the type
 * is checked before the pattern, nothing is silently reinterpreted. */

/** A switch: true/false or exactly "true"/"false". Returns bool or null. */
function zng_haken($value)
{
    if (is_bool($value)) {
        return $value;
    }
    if ($value === "true") {
        return true;
    }
    if ($value === "false") {
        return false;
    }
    return null;
}

/** Text without control characters, at most $max bytes */
function zng_text_ok($value, $max = 255)
{
    return is_string($value) && strlen($value) <= $max && !preg_match('/[\x00-\x1F\x7F]/', $value);
}

/**
 * A whole number written as text (an int is taken as its text) that
 * matches $muster and lies within $min..$max. Returns the text or null.
 */
function zng_zahl_text($value, $min, $max, $muster)
{
    if (is_int($value)) {
        $value = (string) $value;
    }
    if (!is_string($value) || !preg_match($muster, $value)) {
        return null;
    }
    $n = (int) $value;
    return $n >= $min && $n <= $max ? $value : null;
}

/* ---------------- the service (B10, B14) ---------------- */

/**
 * Starts, restarts or stops the service and reports what it did - measured
 * afterwards (systemctl is-active, MainPID), not taken from the return
 * value: a start counts as running only when the same PID is still active
 * three seconds later (zigbee2mqtt without its adapter crashes right after
 * the start, and Restart=always tries again every 30 s).
 * Returns array(tat, rc, ausgabe, zustand, pid, laeuft, ok, sperre).
 */
function zng_dienst_schalten($service, $tat)
{
    if (!in_array($tat, array("start", "restart", "stop"), true)) {
        return array("tat" => (string) $tat, "ok" => false, "laeuft" => false, "rc" => -1, "ausgabe" => "invalid", "zustand" => "", "pid" => 0, "sperre" => "");
    }
    $ausgabe = array();
    $rc = 0;
    exec("sudo -n systemctl " . $tat . " " . escapeshellarg($service) . " 2>&1", $ausgabe, $rc);
    $ende = microtime(true) + 10;
    $zustand = "";
    $pid = 0;
    $ok = false;
    while (true) {
        $zustand = trim((string) shell_exec("systemctl is-active " . escapeshellarg($service) . " 2>/dev/null"));
        $state = zng_service_state($service);
        $pid = $state["pid"];
        if ($tat === "stop") {
            $ok = $zustand !== "active" && $zustand !== "deactivating" && $pid === 0;
        } elseif ($zustand === "active" && $pid > 0) {
            sleep(3);
            $nochmal = zng_service_state($service);
            $zustand = trim((string) shell_exec("systemctl is-active " . escapeshellarg($service) . " 2>/dev/null"));
            $ok = $zustand === "active" && $nochmal["pid"] === $pid;
            $pid = $nochmal["pid"];
        }
        if ($ok || microtime(true) >= $ende) {
            break;
        }
        usleep(500000);
    }
    $sperre = zng_startsperre_datei();
    return array(
        "tat" => $tat,
        "rc" => $rc,
        "ausgabe" => trim(implode(" ", array_slice($ausgabe, 0, 3))),
        "zustand" => $zustand,
        "pid" => $pid,
        "laeuft" => $zustand === "active" && $pid > 0,
        "ok" => $ok,
        "sperre" => is_file($sperre) ? trim((string) @file_get_contents($sperre)) : "",
    );
}

/* ==================================================================
 * Loxone templates
 * ================================================================== */

/**
 * Name used in templates: ZIGBEE_<NAME>_<PROPERTY>, the counterpart of
 * MATTER_<N>_<E>_<TOPIC> in Matter2Lox
 */
function zng_title($device, $property)
{
    $text = strtr($device . "_" . $property, array("ä" => "ae", "ö" => "oe", "ü" => "ue", "ß" => "ss", "Ä" => "Ae", "Ö" => "Oe", "Ü" => "Ue"));
    $name = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $text));
    return "ZIGBEE_" . trim($name, "_");
}

/**
 * Flattens the exposes of a device into a list of single values
 */
function zng_flatten_exposes($exposes)
{
    $result = array();
    foreach ((array) $exposes as $expose) {
        if (isset($expose["features"]) && is_array($expose["features"]) && (!isset($expose["type"]) || $expose["type"] != "composite")) {
            $result = array_merge($result, zng_flatten_exposes($expose["features"]));
        } elseif (isset($expose["property"]) && isset($expose["type"]) && in_array($expose["type"], array("binary", "numeric", "enum", "text"))) {
            $result[] = $expose;
        }
    }
    return $result;
}

/**
 * True if the MQTT gateway turns the value into 1/0 when "convert booleans"
 * is on (LoxBerry is_enabled() / is_disabled()). Other binary values like
 * LOCK/UNLOCK or OPEN/CLOSE arrive as text.
 */
function zng_gateway_boolean($value)
{
    if (is_bool($value)) {
        return true;
    }
    return in_array(strtolower((string) $value), array("true", "false", "on", "off", "yes", "no", "1", "0", "enabled", "disabled", "enable", "disable"), true);
}

/**
 * Inputs and outputs of all devices, ready for the frontend and the templates
 */
function zng_device_ios($base, $devices, $availability)
{
    $list = array();
    foreach (zng_state_devices($devices) as $device) {
        $name = $device["friendly_name"];
        $topic = $base . "/" . $name;
        $entry = array(
            "name" => $name,
            "model" => isset($device["definition"]["model"]) ? $device["definition"]["model"] : "",
            "vendor" => isset($device["definition"]["vendor"]) ? $device["definition"]["vendor"] : "",
            "inputs" => array(),
            "outputs" => array(),
        );
        $seen = array();
        foreach (zng_flatten_exposes(isset($device["definition"]["exposes"]) ? $device["definition"]["exposes"] : array()) as $e) {
            $property = $e["property"];
            if (isset($seen[$property]) || $property === "action") {
                continue;
            }
            $seen[$property] = true;
            $access = isset($e["access"]) ? (int) $e["access"] : 1;
            $unit = isset($e["unit"]) ? $e["unit"] : "";
            if ($access & 1) {
                $input = array(
                    "title" => zng_title($name, $property),
                    "property" => $property,
                    "type" => $e["type"],
                    "vi" => zng_gateway_name($topic . "/" . $property),
                    "udp" => $topic . "/" . $property,
                    "unit" => $unit,
                    "kachel" => zng_kachel($name, $property),
                );
                $on = isset($e["value_on"]) ? $e["value_on"] : true;
                $off = isset($e["value_off"]) ? $e["value_off"] : false;
                if ($e["type"] == "binary" && zng_gateway_boolean($on) && zng_gateway_boolean($off)) {
                    $input["analog"] = false;
                    $input["min"] = 0;
                    $input["max"] = 1;
                } elseif ($e["type"] == "numeric") {
                    $input["analog"] = true;
                    // 4.1.1 (B9): limits by unit instead of +-2147483647
                    list($input["min"], $input["max"]) = zng_grenzen($e);
                } else {
                    // enum, text and binary values like LOCK/UNLOCK arrive as
                    // text - Loxone cannot read them from a UDP input
                    $input["text"] = true;
                }
                $entry["inputs"][] = $input;
            }
            if ($access & 2) {
                $set = $topic . "/set/" . $property;
                $title = zng_title($name, $property);
                if ($e["type"] == "binary") {
                    $entry["outputs"][] = array("title" => $title, "property" => $property, "analog" => false,
                        "kachel" => zng_kachel($name, $property),
                        "on" => zng_udp_command($set, zng_payload(isset($e["value_on"]) ? $e["value_on"] : "ON")),
                        "off" => zng_udp_command($set, zng_payload(isset($e["value_off"]) ? $e["value_off"] : "OFF")));
                } elseif ($e["type"] == "numeric") {
                    $entry["outputs"][] = array("title" => $title, "property" => $property, "analog" => true,
                        "kachel" => zng_kachel($name, $property),
                        "on" => zng_udp_command($set, "<v>"), "off" => "");
                } elseif ($e["type"] == "enum" && isset($e["values"]) && count($e["values"]) <= 12) {
                    foreach ($e["values"] as $value) {
                        $entry["outputs"][] = array("title" => zng_title($name, $property . "_" . $value), "property" => $property,
                            "kachel" => zng_kachel($name, $property . " " . zng_payload($value)),
                            "analog" => false, "on" => zng_udp_command($set, zng_payload($value)), "off" => "");
                    }
                }
            }
        }
        // button presses: one pulse per press (counter, reset by the gateway)
        foreach (zng_action_values($device) as $value) {
            $entry["inputs"][] = array("title" => zng_title($name, "aktion_" . $value), "property" => "aktion/" . $value,
                "type" => "action", "vi" => zng_gateway_name($topic . "/aktion/" . $value),
                "udp" => $topic . "/aktion/" . $value, "unit" => "", "analog" => false, "min" => 0, "max" => 1,
                "kachel" => zng_kachel($name, $value));
        }
        if ($availability) {
            $entry["inputs"][] = array("title" => zng_title($name, "erreichbar"), "property" => "erreichbar",
                "type" => "binary", "vi" => zng_gateway_name($topic . "/erreichbar"),
                "udp" => $topic . "/erreichbar", "unit" => "", "analog" => false, "min" => 0, "max" => 1,
                "kachel" => zng_kachel($name, "erreichbar"));
        }
        $list[] = $entry;
    }
    return $list;
}

/**
 * Payload as zigbee2mqtt expects it on .../set/<property>
 */
function zng_payload($value)
{
    if (is_bool($value)) {
        return $value ? "true" : "false";
    }
    return (string) $value;
}

/**
 * Command for the UDP input of the MQTT gateway. The JSON form keeps topics
 * with spaces intact - the plain form "publish <topic> <value>" is split at
 * every space by the gateway.
 */
function zng_udp_command($topic, $value)
{
    return json_encode(array("topic" => $topic, "value" => $value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function zng_x($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/**
 * Virtual UDP input for the values the MQTT gateway sends via UDP.
 * The gateway bundles "MQTT: topic/property=value " pairs into one packet,
 * so every command looks for "<topic>/<property>=".
 */
function zng_xml_virtual_in_udp($title, $port, $inputs, $comment = "")
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInUdp HintText="" Title="' . zng_x($title) . '" Comment="' . zng_x($comment) . '" Address="" Port="' . (int) $port . '">' . $crlf;
    $o .= "\t" . '<Info templateType="1" minVersion="17010727"/>' . $crlf;
    foreach ($inputs as $in) {
        if (!empty($in["text"])) {
            continue;
        }
        $min = $in["min"];
        $o .= "\t" . '<VirtualInUdpCmd ';
        $o .= 'Title="' . zng_x($in["title"]) . '" ';
        // 4.1.1 (B9): the Comment becomes the tile name in Loxone Config -
        // a short "<device>: <value>" instead of the whole topic
        $o .= 'Comment="' . zng_x($in["kachel"]) . '" ';
        $o .= 'Address="" ';
        $o .= 'Check="' . zng_x($in["udp"] . '=\v') . '" ';
        $o .= 'Signed="' . ($min < 0 ? 'true' : 'false') . '" ';
        $o .= 'Analog="' . ($in["analog"] ? 'true' : 'false') . '" ';
        $o .= 'SourceValLow="0" DestValLow="0" SourceValHigh="100" DestValHigh="100" DefVal="0" ';
        $o .= 'MinVal="' . zng_x($min) . '" ';
        $o .= 'MaxVal="' . zng_x($in["max"]) . '" ';
        $o .= 'Unit="' . zng_x('<v.1>' . ($in["unit"] !== "" ? ' ' . $in["unit"] : '')) . '" ';
        $o .= 'HintText=""';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualInUdp>' . $crlf;
    return $o;
}

/**
 * 4.1.1 (B8, Q3): virtual HTTP input for the MQTT gateway in HTTP mode (the
 * way this house runs it). The gateway sets the inputs of the Miniserver by
 * name, so this is the trick of Regeln/07 4: VirtualInHttp with the dummy
 * address http://localhost polled once a week, Title = the name the gateway
 * sends (topic with "_" for "/" and "%"), Check=" " (an empty field after
 * the import). Values that arrive as text are left out - the template is
 * only proven for numbers. To be checked once in Loxone Config against a
 * live value (X-9).
 */
function zng_xml_virtual_in_http($title, $inputs, $comment)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInHttp HintText="" Title="' . zng_x($title) . '" Comment="' . zng_x($comment) . '" Address="http://localhost" PollingTime="604800">' . $crlf;
    $o .= "\t" . '<Info templateType="2" minVersion="17010727"/>' . $crlf;
    foreach ($inputs as $in) {
        if (!empty($in["text"])) {
            continue;
        }
        $min = $in["min"];
        $o .= "\t" . '<VirtualInHttpCmd ';
        $o .= 'Title="' . zng_x($in["vi"]) . '" ';
        $o .= 'Comment="' . zng_x($in["kachel"]) . '" ';
        $o .= 'Check=" " ';
        $o .= 'Signed="' . ($min < 0 ? 'true' : 'false') . '" ';
        $o .= 'Analog="true" ';
        $o .= 'SourceValLow="0" DestValLow="0" SourceValHigh="100" DestValHigh="100" DefVal="0" ';
        $o .= 'MinVal="' . zng_x($min) . '" ';
        $o .= 'MaxVal="' . zng_x($in["max"]) . '" ';
        $o .= 'Unit="' . zng_x(($in["analog"] ? '<v.1>' : '<v>') . ($in["unit"] !== "" ? ' ' . $in["unit"] : '')) . '" ';
        $o .= 'HintText=""';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return $o;
}

/**
 * Virtual output: commands go as {"topic":...,"value":...} to the UDP input
 * of the MQTT gateway on the LoxBerry.
 */
function zng_xml_virtual_out($title, $address, $outputs, $comment = "")
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualOut HintText="" Title="' . zng_x($title) . '" Comment="' . zng_x($comment) . '" Address="' . zng_x($address) . '" CmdInit="" CloseAfterSend="true" CmdSep="">' . $crlf;
    $o .= "\t" . '<Info templateType="3" minVersion="17010727"/>' . $crlf;
    foreach ($outputs as $out) {
        $o .= "\t" . '<VirtualOutCmd ';
        $o .= 'Title="' . zng_x($out["title"]) . '" ';
        // 4.1.1 (B9): a readable name instead of the identifier in Title
        $o .= 'Comment="' . zng_x($out["kachel"]) . '" ';
        $o .= 'CmdOnMethod="GET" CmdOffMethod="GET" ';
        $o .= 'CmdOn="' . zng_x($out["on"]) . '" CmdOnHTTP="" CmdOnPost="" ';
        $o .= 'CmdOff="' . zng_x($out["off"]) . '" CmdOffHTTP="" CmdOffPost="" ';
        $o .= 'CmdAnswer="" ';
        $o .= 'Analog="' . ($out["analog"] ? 'true' : 'false') . '" ';
        $o .= 'Repeat="0" RepeatRate="0" ';
        $o .= 'HintText=""';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualOut>' . $crlf;
    return $o;
}

/* ---------------- 4.1.1: names, limits, heartbeat, templates ---------------- */

/** Number of characters (UTF-8) */
function zng_laenge($text)
{
    $n = preg_match_all('/./us', (string) $text);
    return $n === false ? strlen((string) $text) : $n;
}

/** The first $n characters (UTF-8) */
function zng_kuerzen($text, $n)
{
    if (!preg_match_all('/./us', (string) $text, $m)) {
        return substr((string) $text, 0, $n);
    }
    return implode("", array_slice($m[0], 0, $n));
}

/**
 * 4.1.1 (B9): tile name "<device>: <value>" for the Comment of a template
 * command. Loxone Config turns that Comment into the name shown in the app
 * (Regeln/07), so it stays at 40 characters or less: a long device name is
 * shortened with "…", the value stays whole.
 */
function zng_kachel($geraet, $wert)
{
    $geraet = (string) $geraet;
    $wert = (string) $wert;
    $platz = 40 - zng_laenge($wert) - 2;
    if ($platz < 4) {
        return zng_kuerzen($wert, 40);
    }
    if (zng_laenge($geraet) > $platz) {
        $geraet = rtrim(zng_kuerzen($geraet, $platz - 1)) . "…";
    }
    return $geraet . ": " . $wert;
}

/**
 * 4.1.1 (B9): MinVal/MaxVal of a numeric value. In Loxone they are a
 * validation: a value beyond them becomes 0 (Regeln/07) - so the limits are
 * generous. The ones zigbee2mqtt names (value_min/value_max) win, otherwise
 * by unit, otherwise the full range.
 */
function zng_grenzen($e)
{
    $min = isset($e["value_min"]) && is_numeric($e["value_min"]) ? $e["value_min"] + 0 : null;
    $max = isset($e["value_max"]) && is_numeric($e["value_max"]) ? $e["value_max"] + 0 : null;
    if ($min !== null && $max !== null && $min < $max) {
        return array($min, $max);
    }
    $einheiten = array(
        "%" => array(0, 100),
        "°C" => array(-60, 150),
        "°F" => array(-80, 300),
        "W" => array(-100000, 100000),
        "kW" => array(-1000, 1000),
        "V" => array(0, 1000),
        "mV" => array(0, 100000),
        "A" => array(-1000, 1000),
        "mA" => array(-100000, 100000),
        "kWh" => array(0, 100000000),
        "Wh" => array(0, 2000000000),
        "lx" => array(0, 200000),
        "hPa" => array(0, 2000),
        "ppm" => array(0, 100000),
        "ppb" => array(0, 1000000),
        "µg/m³" => array(0, 100000),
        "Hz" => array(0, 1000),
        "lqi" => array(0, 255),
    );
    $unit = isset($e["unit"]) ? (string) $e["unit"] : "";
    if (isset($einheiten[$unit])) {
        $g = $einheiten[$unit];
        return array($min !== null ? min($min, $g[0]) : $g[0], $max !== null ? max($max, $g[1]) : $g[1]);
    }
    return array($min !== null ? $min : -2147483647, $max !== null ? $max : 2147483647);
}

/**
 * 4.1.1 (B7): the two heartbeat values as template inputs
 */
function zng_lebenszeichen_ios($base)
{
    global $L;
    $liste = array();
    foreach (array("ts" => array("Loxone.KachelTs", "Lebenszeichen Zeit", "", 2147483647),
                   "zaehler" => array("Loxone.KachelZaehler", "Lebenszeichen Zähler", "", 999)) as $wert => $t) {
        $topic = $base . "/" . ZNG_LEBENSZEICHEN . "/" . $wert;
        $liste[] = array(
            "title" => zng_title(ZNG_LEBENSZEICHEN, $wert),
            "property" => ZNG_LEBENSZEICHEN . "/" . $wert,
            "type" => "lebenszeichen",
            "vi" => zng_gateway_name($topic),
            "udp" => $topic,
            "unit" => $t[2],
            "kachel" => "Zigbee2MqttNG: " . (isset($L[$t[0]]) ? $L[$t[0]] : $t[1]),
            "analog" => true,
            "min" => 0,
            "max" => $t[3],
        );
    }
    return $liste;
}

/**
 * 4.1.1 (B8): builds a Loxone template.
 *   $kind "inhttp" virtual HTTP input (gateway in HTTP mode)
 *         "in"     virtual UDP input (gateway in UDP mode)
 *         "out"    virtual output (commands to the UDP input of the gateway)
 *   $device "" = all devices, with the heartbeat
 * Returns array(file name, xml, number of commands) or array(null, error,
 * 0) with error "device", "noudpport" or "kind".
 * Expects the config classes to be loaded.
 */
function zng_vorlage($kind, $device)
{
    global $bridgeDevicesFile, $L;
    $mqttcfg = MqttConfig::load();
    $serviceCfg = ServiceConfig::load();
    $ios = zng_device_ios($mqttcfg->topic, zng_read_json($bridgeDevicesFile, array()), is_enabled($serviceCfg->availability));
    if ($device !== "") {
        $ios = array_values(array_filter($ios, function ($d) use ($device) {
            return $d["name"] === $device;
        }));
        if (count($ios) == 0) {
            return array(null, "device", 0);
        }
    }
    $gateway = zng_gateway_info();
    $title = $device !== "" ? "Zigbee " . $device : "Zigbee2MqttNG";
    $inputs = $device === "" ? zng_lebenszeichen_ios($mqttcfg->topic) : array();
    $outputs = array();
    foreach ($ios as $d) {
        $inputs = array_merge($inputs, $d["inputs"]);
        $outputs = array_merge($outputs, $d["outputs"]);
    }
    $text = count(array_filter($inputs, function ($in) {
        return !empty($in["text"]);
    }));
    $file = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $title);
    $t = function ($key, $vorgabe) use ($L) {
        return isset($L[$key]) ? $L[$key] : $vorgabe;
    };
    if ($kind == "inhttp") {
        $comment = sprintf($t("Loxone.VorlageHttp", "Zigbee2MqttNG via MQTT gateway (HTTP). %d text value(s) left out."), $text);
        $xml = zng_xml_virtual_in_http($title, $inputs, $comment);
        return array("VI_" . $file . ".xml", $xml, count($inputs) - $text);
    }
    if ($kind == "in") {
        if ($gateway["udpport"] <= 0) {
            return array(null, "noudpport", 0);
        }
        $comment = sprintf($t("Loxone.VorlageUdp", "Zigbee2MqttNG via MQTT gateway (UDP). %d text value(s) left out."), $text);
        $xml = zng_xml_virtual_in_udp($title, $gateway["udpport"], $inputs, $comment);
        return array("VIU_" . $file . ".xml", $xml, count($inputs) - $text);
    }
    if ($kind == "out") {
        $address = "/dev/udp/" . LBSystem::get_localip() . "/" . $gateway["udpinport"];
        $xml = zng_xml_virtual_out($title, $address, $outputs, $t("Loxone.VorlageOut", "Zigbee2MqttNG commands to the UDP input of the MQTT gateway."));
        return array("VQ_" . $file . ".xml", $xml, count($outputs));
    }
    return array(null, "kind", 0);
}
