<?php
/**
 * Writes configuration.yaml of zigbee2mqtt and the files of the MQTT gateway
 * from the plugin settings. Runs on every save, on install and - through
 * ExecStartPre of the service - before every start of zigbee2mqtt, so
 * changed broker credentials of the MQTT gateway are picked up.
 *
 * 4.1.1 (B1, B2): before anything is written, mqtt.json, service.json and
 * configuration.yaml are checked. A file that exists but cannot be read is
 * never replaced by defaults - for configuration.yaml that would cost the
 * network key, the PAN IDs and the channel, i.e. every paired device. It
 * is kept as <file>.kaputt (0600) and written back once from its second
 * copy next to the config folder if that copy is readable. Otherwise
 * nothing is written, the file "startsperre" stops the start (second
 * ExecStartPre of the service) and the reason is logged and notified once.
 *
 * Exit codes: 0 done, 3 start blocked, 4 php-yaml missing (nothing written),
 * 5 a file could not be written.
 */
require_once "loxberry_system.php";
require_once "loxberry_log.php";
require_once "loxberry_io.php";
require_once LBPBINDIR . "/defines.php";
require_once LBPBINDIR . "/zigbee2mqttng.php";
require_once LBPHTMLAUTHDIR . "/model/ServiceConfig.php";
require_once LBPHTMLAUTHDIR . "/model/MqttConfig.php";

$konfigOrdner = zng_konfig_ordner();
$sperre = zng_startsperre_datei();

############ 4.1.1: state of the configuration (B1, B2) ##################

// Read only. While the start is blocked for the same reason, systemd tries
// again every 30 s (Restart=always) - that is reported once, not every time.
$lage = zng_konfig_lage();
$kaputt = array_keys(array_filter($lage, function ($l) {
    return $l === "kaputt";
}));
if ($kaputt && is_file($sperre) && trim((string) @file_get_contents($sperre)) === implode(",", $kaputt)) {
    exit(3);
}

$log = LBLog::newLog(["name" => "Service"]);

LOGSTART("Update configuration");

if (in_array("ohne_yaml", $lage, true)) {
    LOGERR("php-yaml is not loaded - configuration.yaml can neither be checked nor written. Nothing was changed.");
    LOGEND("Update configuration failed");
    exit(4);
}

$gesperrt = array();
foreach ($kaputt as $name) {
    list($datei, $art) = zng_konfig_dateien()[$name];
    list($aktion, $kaputtDatei) = zng_konfig_heilen($datei, $art, $konfigOrdner, $name);
    if ($aktion === "geheilt") {
        LOGWARN("$name could not be read. The unreadable file is kept as $kaputtDatei; $name was restored from its second copy " . zng_zweitschrift_pfad($konfigOrdner, $name) . ". Please check the settings.");
        notify(LBPPLUGINDIR, "zigbee", "Zigbee2MqttNG: $name war beschädigt und wurde aus der Zweitschrift wiederhergestellt. Die beschädigte Datei liegt als $kaputtDatei. Bitte die Einstellungen prüfen.", true);
    } else {
        $gesperrt[] = $name;
        LOGCRIT("$name could not be read and has no readable second copy. It is kept unchanged (copy: $kaputtDatei). Nothing was written, zigbee2mqtt is not started.");
    }
}
if ($gesperrt) {
    $grund = implode(",", $gesperrt);
    if (!zng_schreiben_atomar($sperre, $grund . "\n", ZNG_MODUS_GEHEIM)) {
        LOGCRIT("The start block $sperre could not be written either - zigbee2mqtt may start with an unreadable configuration.");
    }
    notify(LBPPLUGINDIR, "zigbee", "Zigbee2MqttNG: $grund nicht lesbar - zigbee2mqtt wird nicht gestartet, damit das Zigbee-Netz nicht verloren geht. Was zu tun ist, steht im Reiter Einstellungen.", true);
    LOGEND("Update configuration stopped - start blocked");
    exit(3);
}
if (is_file($sperre)) {
    @unlink($sperre);
    LOGOK("All configuration files are readable again - start block removed");
}

$rawMqtt = zng_read_json($mqttconfigfile, array());
$mqttcfg = MqttConfig::load();
$serviceCfg = ServiceConfig::load();
$serviceDaten = get_object_vars($serviceCfg);

list($yamlLage, $zigbee2mqttConfig) = zng_yaml_lesen($serviceConfigFile, zng_zweitschrift_pfad($konfigOrdner, "configuration.yaml"));
if (!is_array($zigbee2mqttConfig)) {
    // "fehlt": fresh install, written anew below
    $zigbee2mqttConfig = array();
}

$schreibfehler = array();

############ handle upgrade from previous version  ##################

//registerMqttTopic added in 0.8.0 ==> defaults to true to be backwards compatible
if (!array_key_exists('registerMqttTopic', $rawMqtt)) {
    $mqttcfg->registerMqttTopic = true;
}
if (!$mqttcfg->save()) {
    $schreibfehler[] = "mqtt.json";
}

//fixed values used by plugin
$zigbee2mqttConfig["homeassistant"]["enabled"] = false;
$zigbee2mqttConfig["advanced"]["log_directory"] = "log";
$zigbee2mqttConfig["advanced"]["log_file"] = "zigbee2mqtt.log";
$zigbee2mqttConfig["advanced"]["log_output"] = array("console", "file");
$zigbee2mqttConfig["advanced"]["output"] = "json";
// The bridge extension of the plugin is an external extension
$zigbee2mqttConfig["advanced"]["enable_external_js"] = true;
$zigbee2mqttConfig["device_options"]["empty"] = false;
$zigbee2mqttConfig["devices"] = "devices.yaml";
$zigbee2mqttConfig["groups"] = "groups.yaml";
// zigbee2mqtt 2.x no longer knows permit_join in its configuration - pairing
// is opened with the button on the Devices tab (bridge/request/permit_join)
unset($zigbee2mqttConfig["permit_join"]);

$availability = (bool) is_enabled($serviceCfg->availability);
$haus = (bool) is_enabled($mqttcfg->hausTopics);

//MQTT parameter
$registerTopics = false;
if (is_enabled($mqttcfg->usemqttgateway)) {
    $registerTopics = (bool) is_enabled($mqttcfg->registerMqttTopic);
}
$creds = zng_broker_credentials($mqttcfg);

// Gateway subscriptions. "devices" registers only the state topics of the
// devices and groups, so the big bridge/* messages (device list, logging)
// never reach the Miniserver. The list is kept up to date by the extension
// whenever devices join, leave or are renamed.
$devices = zng_read_json($bridgeDevicesFile, array());
if (!$registerTopics) {
    zng_write_if_changed($mqttGatewaySubscriptionFile, "");
    zng_write_if_changed($mqttGatewayResetFile, "");
} else {
    if ($mqttcfg->forwardMode == "all" || !is_file($bridgeDevicesFile)) {
        // without a device list yet (first start) everything is forwarded once
        zng_write_if_changed($mqttGatewaySubscriptionFile, $mqttcfg->topic . "/#\n" . ($haus ? "haus/tuer/#\n" : ""));
    } else {
        zng_write_if_changed($mqttGatewaySubscriptionFile, zng_subscription_lines(
            $mqttcfg->topic,
            $devices,
            zng_read_json($bridgeGroupsFile, array()),
            $availability,
            $haus
        ));
    }
    zng_write_if_changed($mqttGatewayResetFile, zng_reset_lines($mqttcfg->topic, $devices));
}

// Up to 4.0.0 online/offline was converted by mqtt_conversions.cfg. The
// gateway applies such conversions to the values of ALL plugins, so the
// extension now publishes <topic>/<device>/erreichbar as 1/0 instead.
zng_write_if_changed($mqttGatewayConversionFile, "");

$zigbee2mqttConfig["mqtt"]["base_topic"] = $mqttcfg->topic;
$zigbee2mqttConfig["mqtt"]["server"] = "mqtt://" . $creds['brokerhost'] . ":" . $creds['brokerport'];
$zigbee2mqttConfig["mqtt"]["user"] = $creds['brokeruser'];
$zigbee2mqttConfig["mqtt"]["password"] = $creds['brokerpass'];


// 4.1.1 (B11): a value that does not pass its check is not reinterpreted
// (until 4.1.0 a channel "15.7" became 15, a flow control "maybe" became
// false). It is logged, and configuration.yaml keeps its own value.
function zng_service_wert($key)
{
    global $serviceCfg, $serviceDaten;
    list($ok) = ServiceConfig::wert($key, $serviceCfg->{$key}, $serviceDaten);
    if (!$ok) {
        LOGERR("service.json: the value of $key is invalid - configuration.yaml keeps its own value. Please save the settings again.");
    }
    return $ok;
}

//customizable parameters
if ($serviceCfg->port != "") {
    if (zng_service_wert("port")) {
        $zigbee2mqttConfig["serial"]["port"] = $serviceCfg->port;
    }
} else {
    $zigbee2mqttConfig["serial"]["port"] = null;
}

if (zng_service_wert("frontendPort")) {
    $frontendPort = (int) $serviceCfg->frontendPort;
} elseif (isset($zigbee2mqttConfig["frontend"]["port"]) && is_int($zigbee2mqttConfig["frontend"]["port"])
    && $zigbee2mqttConfig["frontend"]["port"] >= 1 && $zigbee2mqttConfig["frontend"]["port"] <= 65535) {
    $frontendPort = $zigbee2mqttConfig["frontend"]["port"];
} else {
    $frontendPort = 8881;
    LOGWARN("frontend port: 8881 is used");
}
if (is_enabled($serviceCfg->enableUI)) {
    $zigbee2mqttConfig["frontend"]["enabled"] = true;
    $zigbee2mqttConfig["frontend"]["port"] = $frontendPort;
    if (is_enabled($serviceCfg->frontendAuth)) {
        if (!is_string($serviceCfg->frontendToken) || !preg_match('/^[A-Za-z0-9]{16,64}\z/', $serviceCfg->frontendToken)) {
            $serviceCfg->frontendToken = bin2hex(random_bytes(12));
            LOGINF("New token for the zigbee2mqtt UI generated");
        }
        $zigbee2mqttConfig["frontend"]["auth_token"] = $serviceCfg->frontendToken;
    } else {
        unset($zigbee2mqttConfig["frontend"]["auth_token"]);
    }
} else {
    $zigbee2mqttConfig["frontend"]["enabled"] = false;
}
// The onboarding page of zigbee2mqtt (shown when configuration.yaml is
// invalid) listens on the same port as the frontend
zng_write_if_changed($serviceEnvFile, "Z2M_ONBOARD_URL=http://0.0.0.0:" . $frontendPort . "\n");

if ($serviceCfg->adapter != "" && zng_service_wert("adapter")) {
    $zigbee2mqttConfig["serial"]["adapter"] = $serviceCfg->adapter;
}

// Baudrate and rtscts are only written when the user set them. An empty value
// means "leave configuration.yaml alone", so a hand-edited value survives and
// zigbee2mqtt keeps choosing the default for everyone who does not care.
if ($serviceCfg->baudrate !== "" && $serviceCfg->baudrate !== null && zng_service_wert("baudrate")) {
    $zigbee2mqttConfig["serial"]["baudrate"] = (int) $serviceCfg->baudrate;
}

if ($serviceCfg->rtscts !== "" && $serviceCfg->rtscts !== null && zng_service_wert("rtscts")) {
    $zigbee2mqttConfig["serial"]["rtscts"] = ($serviceCfg->rtscts === true || $serviceCfg->rtscts === "true");
}

// Zigbee channel - only written when set, an empty field leaves the
// configuration alone. Changing it means pairing all devices again.
if ($serviceCfg->channel !== "" && $serviceCfg->channel !== null && zng_service_wert("channel")) {
    $zigbee2mqttConfig["advanced"]["channel"] = (int) $serviceCfg->channel;
}

// Availability: <topic>/<device>/erreichbar tells Loxone if a device is
// reachable - the same information Matter2Lox gives for Matter devices
$zigbee2mqttConfig["availability"]["enabled"] = $availability;

//save zigbee2mqtt config - 4.1.1 (B4): in one piece and 0600, it carries the
//network key and the broker password
if (!zng_schreiben_wenn_anders($serviceConfigFile, yaml_emit($zigbee2mqttConfig), ZNG_MODUS_GEHEIM)) {
    $schreibfehler[] = "configuration.yaml";
}

// if the adapter is empty, use the current value from the zigbee2mqtt config
if ($serviceCfg->adapter == "" && !empty($zigbee2mqttConfig["serial"]["adapter"])) {
    $serviceCfg->adapter = $zigbee2mqttConfig["serial"]["adapter"];
}
if (!$serviceCfg->save()) {
    $schreibfehler[] = "service.json";
}

// 4.1.1 (B3): second copies next to the config folder. Only a readable
// state with content replaces a second copy (zng_zweitschrift_ziehen).
// coordinator_backup.json is written by zigbee2mqtt itself.
foreach (array(
    "configuration.yaml" => array($serviceConfigFile, "yaml"),
    "coordinator_backup.json" => array(dirname($serviceConfigFile) . "/coordinator_backup.json", "json"),
) as $name => $d) {
    if (is_file($d[0]) && zng_zweitschrift_ziehen($d[0], $d[1], $konfigOrdner, $name) === "fehler") {
        $schreibfehler[] = zng_zweitschrift_pfad($konfigOrdner, $name);
    }
}

// Bridge extension: keeps the gateway files and the device list up to date,
// publishes erreichbar, button pulses, the house topics and the heartbeat,
// sends notifications
if (!is_dir(dirname($extensionTargetFile))) {
    mkdir(dirname($extensionTargetFile), 0755, true);
}
if (is_file($extensionSourceFile)) {
    zng_write_if_changed($extensionTargetFile, file_get_contents($extensionSourceFile));
}
zng_write_if_changed($bridgeConfigFile, json_encode(array(
    "registerTopics" => $registerTopics,
    "forwardMode" => $mqttcfg->forwardMode,
    "subscriptionFile" => $mqttGatewaySubscriptionFile,
    "resetFile" => $mqttGatewayResetFile,
    "availability" => $availability,
    "hausTopics" => $haus,
    "devicesFile" => $bridgeDevicesFile,
    "groupsFile" => $bridgeGroupsFile,
    "infoFile" => $bridgeInfoFile,
    "hausFile" => $hausTopicsFile,
    "hausNamesFile" => $hausNamesFile,
    "statusFile" => $bridgeStatusFile,
    "availabilityFile" => $availabilityFile,
    "notifyOffline" => $availability && is_enabled($serviceCfg->notifyOffline),
    "notifyBattery" => (bool) is_enabled($serviceCfg->notifyBattery),
    "batteryThreshold" => zng_service_wert("batteryThreshold") ? (int) $serviceCfg->batteryThreshold : 15,
    "notifyFile" => $notifyStateFile,
    "notifyScript" => $notifyScript,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

if ($schreibfehler) {
    LOGERR("Could not be written (disk full or no permission?): " . implode(", ", $schreibfehler) . ". The previous files are kept.");
    LOGEND("Update configuration finished with errors");
    exit(5);
}
LOGOK("Update successful");
LOGEND("Update configuration finished");
