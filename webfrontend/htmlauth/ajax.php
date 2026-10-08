<?php

require_once "loxberry_system.php";
require_once "loxberry_log.php";
require_once "model/ServiceConfig.php";
require_once "model/MqttConfig.php";
require_once "model/Sicherung.php";
require_once LBPBINDIR . "/defines.php";
require_once LBPBINDIR . "/formHelper.php";
require_once LBPBINDIR . "/zigbee2mqttng.php";
require_once "include/Z2mBridge.php";

$log = LBLog::newLog(["name" => "Service"]);

if (isset($_GET["action"])) {
    $action = $_GET["action"];
    if (!requestFromPluginPage($action)) {
        sendresponse(403, "application/json", '{"result":false,"error":"request not accepted"}');
    }
    if ($action == "getFormData") {
        if (isset($_GET["form"])) {
            sendresponse(200, "application/json", getFormData($_GET["form"]));
        }
    } else if ($action == "setFormData") {
        if (isset($_GET["form"])) {
            setFormData($_GET["form"], $_POST);
        }
    } else if ($action == "setDevices") {
        setDevices();
    } else if ($action == "applyChanges") {
        sendresponse(200, "application/json", applyChanges());
    } else if ($action == "getPid") {
        sendresponse(200, "application/json", getPid());
    } else if ($action == "serviceAction") {
        sendresponse(200, "application/json", serviceAction(isset($_POST["tat"]) ? $_POST["tat"] : ""));
    } else if ($action == "getSerialPorts") {
        sendresponse(200, "application/json", json_encode(zng_serial_ports()));
    } else if ($action == "getRadioInfo") {
        sendresponse(200, "application/json", json_encode(zng_radio_info()));
    } else if ($action == "testPort") {
        sendresponse(200, "application/json", json_encode(zng_test_port(isset($_POST["port"]) ? $_POST["port"] : "")));
    } else if ($action == "permitJoin") {
        sendresponse(200, "application/json", permitJoin(isset($_POST["time"]) ? $_POST["time"] : ""));
    } else if ($action == "networkMap") {
        sendresponse(200, "application/json", networkMap());
    } else if ($action == "backupSettings") {
        backupSettings();
    } else if ($action == "backupNetwork") {
        backupNetwork();
    } else if ($action == "restoreSettings") {
        sendresponse(200, "application/json", restoreSettings());
    } else if ($action == "getTemplate") {
        getTemplate(isset($_GET["kind"]) ? $_GET["kind"] : "", isset($_GET["device"]) ? $_GET["device"] : "");
    }
    sendresponse(400, "application/json", '{"result":false,"error":"unknown action"}');
}

/**
 * The plugin pages call this endpoint with jQuery, which marks its
 * same-origin requests with "X-Requested-With: XMLHttpRequest". A link, a
 * form or an image on another web site cannot set this header, and a script
 * on another site would need a CORS preflight that this endpoint does not
 * answer. Because the browser sends the LoxBerry login along with such
 * requests, actions that change something or return settings are only
 * accepted with the header, and changing actions only as POST.
 * getTemplate is a plain download link and changes nothing. So is
 * backupSettings (4.1.1): the browser saves the file, another site cannot
 * read it - the same as the zigbee2mqtt backup (backupNetwork, up to
 * 4.1.1 the page backup.php).
 */
function requestFromPluginPage($action)
{
    $changing = array("setFormData", "setDevices", "applyChanges", "permitJoin", "testPort", "networkMap", "restoreSettings", "serviceAction");
    $protected = array_merge($changing, array("getFormData"));
    if (!in_array($action, $protected, true)) {
        return true;
    }
    $xhr = isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest";
    $post = isset($_SERVER["REQUEST_METHOD"]) && $_SERVER["REQUEST_METHOD"] === "POST";
    return $xhr && ($post || !in_array($action, $changing, true));
}

/**
 * apply the changes - 4.1.1 (B10): the answer says what happened, measured
 * afterwards, not what was asked for
 */
function applyChanges()
{
    LOGSTART("Restart zigbee2mqtt service");
    $ergebnis = zng_aenderungen_anwenden();
    LOGEND("Restart zigbee2mqtt service finished");
    return json_encode(array_merge(array("result" => $ergebnis["ok"]), $ergebnis));
}

/**
 * Runs update-config.php and restarts the service. Returns the result of
 * zng_dienst_schalten() plus "update" (exit code of update-config.php:
 * 0 done, 3 start blocked, 4 php-yaml missing, 5 a file not written).
 * Expects LOGSTART to be called.
 */
function zng_aenderungen_anwenden()
{
    global $serviceName;
    $ausgabe = array();
    $update = 0;
    exec("php " . escapeshellarg(LBPBINDIR . "/update-config.php") . " 2>&1", $ausgabe, $update);
    if ($update === 3) {
        LOGERR("update-config.php blocked the start: a configuration file cannot be read (see the Service log)");
    } elseif ($update !== 0) {
        LOGERR("update-config.php ended with code $update (see the Service log)");
    }
    $dienst = zng_dienst_schalten($serviceName, "restart");
    if ($dienst["ok"]) {
        LOGOK("zigbee2mqtt restarted, PID " . $dienst["pid"]);
    } else {
        LOGERR("zigbee2mqtt does not run after the restart: systemctl " . $dienst["rc"] . " " . $dienst["ausgabe"] . ", state " . $dienst["zustand"]);
    }
    return array_merge($dienst, array("update" => $update, "ok" => $dienst["ok"] && $update === 0));
}

/**
 * 4.1.1 (B14): start (green), restart and stop (orange) on the settings tab
 */
function serviceAction($tat)
{
    global $serviceName;
    if (!in_array($tat, array("start", "restart", "stop"), true)) {
        return json_encode(array("ok" => false, "tat" => "", "ausgabe" => "invalid"));
    }
    LOGSTART("Service: " . $tat);
    $ergebnis = zng_dienst_schalten($serviceName, $tat);
    if ($ergebnis["ok"]) {
        LOGOK("Service $tat: done, state " . $ergebnis["zustand"] . ($ergebnis["pid"] ? ", PID " . $ergebnis["pid"] : ""));
    } else {
        LOGERR("Service $tat: not done - systemctl " . $ergebnis["rc"] . " " . $ergebnis["ausgabe"] . ", state " . $ergebnis["zustand"]);
    }
    LOGEND("Service: " . $tat);
    return json_encode($ergebnis);
}

/**
 * Retrievs the form data for the given form
 */
function getFormData($form)
{
    switch ($form) {
        case "ServiceConfig":
            return ServiceConfig::load()->toJson();
        case "MqttConfig":
            // without the password - it never goes to the browser
            return MqttConfig::load()->toFormJson();
    }
    return "{}";
}

/**
 * Checks and saves the form data. Answers {"result":true} or
 * {"result":false,"errors":[{"form","message"}]}.
 */
function setFormData($form, $formData)
{
    global $L;
    switch ($form) {
        case "ServiceConfig":
            $class = new ReflectionClass(ServiceConfig::class);
            $saved = ServiceConfig::load();
            break;
        case "MqttConfig":
            $class = new ReflectionClass(MqttConfig::class);
            $saved = MqttConfig::load();
            break;
        default:
            sendresponse(400, "application/json", '{"result":false}');
    }

    $values = isset($formData[$class->getName()]) && is_array($formData[$class->getName()]) ? $formData[$class->getName()] : array();
    foreach ($values as $name => $value) {
        if ($value === "on" || $value === "true") {
            $values[$name] = true;
        }
        if ($value === "off" || $value === "false") {
            $values[$name] = false;
        }
    }
    // rtscts is a three-way select: "", "true", "false" - kept as text
    if ($form == "ServiceConfig" && isset($formData["ServiceConfig"]["rtscts"])) {
        $values["rtscts"] = (string) $formData["ServiceConfig"]["rtscts"];
    }

    // 4.1.1 (B1): an unreadable file is never silently replaced. If its
    // second copy is readable, it is restored and this save is refused (the
    // form showed defaults); without a second copy the form replaces it,
    // and the unreadable file stays as .kaputt.
    $datei = $form == "ServiceConfig" ? LBPCONFIGDIR . "/service.json" : LBPCONFIGDIR . "/mqtt.json";
    if (zng_json_lage($datei) === "kaputt") {
        LOGSTART("Save " . basename($datei));
        list($aktion, $kaputt) = zng_konfig_heilen($datei, "json", LBPCONFIGDIR, basename($datei));
        if ($aktion === "geheilt") {
            LOGWARN(basename($datei) . " was unreadable (kept as $kaputt) and was restored from its second copy - the save was refused");
            LOGEND("Save refused");
            sendresponse(200, "application/json", json_encode(array("result" => false, "errors" => array(
                array("form" => $form, "message" => sprintf($L["Konfig.GeheiltNeuLaden"], basename($datei)))))));
        }
        LOGWARN(basename($datei) . " was unreadable (kept as $kaputt); it is replaced by the values of the form");
        LOGEND("Save");
    }

    $data = MakeObjectFromArray($class, $values);
    $data->keepFrom($saved);
    $errors = array();
    foreach ($data->validate() as $key) {
        $errors[] = array("form" => $form, "message" => isset($L[$key]) ? $L[$key] : $key);
    }
    if ($errors) {
        sendresponse(200, "application/json", json_encode(array("result" => false, "errors" => $errors)));
    }
    if (!$data->save()) {
        sendresponse(200, "application/json", json_encode(array("result" => false, "errors" => array(
            array("form" => $form, "message" => sprintf($L["Konfig.Schreibfehler"], basename($datei)))))));
    }
    sendresponse(200, "application/json", '{"result":true}');
}

/**
 * Saves devices.yaml (sent as text/plain)
 */
function setDevices()
{
    global $deviceDataFile, $L;

    LOGSTART("Update device configuration");

    $data = file_get_contents('php://input');
    $parsed = trim($data) === "" ? array() : @yaml_parse($data);
    // an empty file or "{}" is fine, a list or a single value is not
    if ($parsed === false || !(is_array($parsed) || $parsed === null) || (is_array($parsed) && $parsed && array_keys($parsed) === range(0, count($parsed) - 1))) {
        LOGERR("Sent device configuration is invalid");
        LOGEND("Update failed");
        sendresponse(400, "application/json", json_encode(array("result" => false, "error" => $L["Devices.YamlInvalid"])));
    }

    file_put_contents($deviceDataFile, $data);
    LOGOK("Update OK");
    LOGEND("Update finished");
    sendresponse(200, "application/json", '{"result":true}');
}

/**
 * Gets the pid of the zigbee2mqtt service
 */
function getPid()
{
    global $serviceName;
    $state = zng_service_state($serviceName);
    return json_encode(array("pid" => $state["pid"]));
}

/**
 * Zigbee, Thread and WLAN channel. All use the 2.4 GHz band, Zigbee and Thread
 * even the same channels (IEEE 802.15.4, 11-26).
 */
function zng_radio_info()
{
    list($zigbee, $zigbeeSource) = zng_zigbee_channel();
    list($thread, $threadSource) = zng_thread_channel();
    list($wifi, $wifiInterface) = zng_wifi_channel();
    $level = "ok";
    if ($thread && $thread == $zigbee) {
        $level = "conflict";
    } elseif ($thread && abs($thread - $zigbee) == 1) {
        $level = "adjacent";
    }
    return array(
        "zigbee" => $zigbee,
        "zigbeeSource" => $zigbeeSource,
        "thread" => $thread,
        "threadSource" => $threadSource,
        "level" => $level,
        "wifi" => $wifi,
        "wifiInterface" => $wifiInterface,
        "wifiLevel" => zng_wifi_overlap($zigbee, $wifi),
        "predecessors" => zng_predecessor_plugins(),
    );
}

/**
 * Opens or closes pairing at runtime.
 * zigbee2mqtt 2.x ignores permit_join in configuration.yaml, pairing is only
 * possible through the MQTT request <topic>/bridge/request/permit_join with
 * {"time": 1..254} (seconds) or {"time": 0} to close it again.
 */
function permitJoin($time)
{
    $time = trim((string) $time);
    if (!preg_match('/^[0-9]{1,3}$/', $time) || (int) $time > 254) {
        return json_encode(["result" => false, "message" => "invalid"]);
    }
    $time = (int) $time;
    $bridge = new Z2mBridge();
    if (!$bridge->connect()) {
        return json_encode(["result" => false, "message" => "nobroker"]);
    }
    $answer = $bridge->request("permit_join", array("time" => $time), 5.0);
    $bridge->close();
    if ($answer === null) {
        LOGWARN("permit_join: no answer from zigbee2mqtt within 5 s");
        return json_encode(["result" => false, "message" => "noanswer"]);
    }
    if (!isset($answer["status"]) || $answer["status"] !== "ok") {
        $error = isset($answer["error"]) ? (string) $answer["error"] : "";
        LOGWARN("permit_join refused by zigbee2mqtt: " . $error);
        return json_encode(["result" => false, "message" => "refused", "error" => $error]);
    }
    LOGINF("permit_join set to $time s");
    return json_encode(["result" => true, "message" => $time > 0 ? "open" : "closed", "time" => $time]);
}

/**
 * Sends bridge/request/networkmap (type raw, without routes) and returns
 * {"result": true, "nodes": [...], "links": [...]} or {"result": false,
 * "message": "nobroker" | "noanswer" | "refused", "error": "..."}.
 * zigbee2mqtt asks every router for its neighbour table, which takes a few
 * seconds in a small network and can take a minute or more in a large one.
 */
function networkMap()
{
    @set_time_limit(150);
    $bridge = new Z2mBridge();
    if (!$bridge->connect()) {
        return json_encode(array("result" => false, "message" => "nobroker"));
    }
    $answer = $bridge->request("networkmap", array("type" => "raw", "routes" => false), 120.0);
    $bridge->close();
    if ($answer === null) {
        return json_encode(array("result" => false, "message" => "noanswer"));
    }
    if (!isset($answer["status"]) || $answer["status"] !== "ok" || !isset($answer["data"]["value"]["nodes"])) {
        return json_encode(array("result" => false, "message" => "refused", "error" => isset($answer["error"]) ? (string) $answer["error"] : ""));
    }
    $nodes = array();
    foreach ($answer["data"]["value"]["nodes"] as $node) {
        $nodes[] = array(
            "id" => $node["ieeeAddr"],
            "name" => isset($node["friendlyName"]) ? $node["friendlyName"] : $node["ieeeAddr"],
            "type" => isset($node["type"]) ? $node["type"] : "",
            "failed" => isset($node["failed"]) && is_array($node["failed"]) ? $node["failed"] : array()
        );
    }
    $links = array();
    foreach ($answer["data"]["value"]["links"] as $link) {
        $links[] = array(
            "source" => isset($link["source"]["ieeeAddr"]) ? $link["source"]["ieeeAddr"] : $link["sourceIeeeAddr"],
            "target" => isset($link["target"]["ieeeAddr"]) ? $link["target"]["ieeeAddr"] : $link["targetIeeeAddr"],
            "lqi" => isset($link["lqi"]) ? (int) $link["lqi"] : (isset($link["linkquality"]) ? (int) $link["linkquality"] : 0),
            "relationship" => isset($link["relationship"]) ? (int) $link["relationship"] : -1
        );
    }
    return json_encode(array("result" => true, "nodes" => $nodes, "links" => $links, "time" => date("H:i:s")));
}

/**
 * 4.1.1 (B3): "Einstellungen sichern" - mqtt.json and service.json with all
 * keys and the credentials as one JSON file. Refused while a configuration
 * file is unreadable (the backup would hold defaults).
 */
function backupSettings()
{
    global $L;
    $inhalt = Sicherung::inhalt();
    if ($inhalt === null) {
        header("Content-Type: text/plain; charset=utf-8", true, 503);
        echo $L["Sicherung.Kaputt"] . "\n";
        exit(0);
    }
    header("Content-Type: application/json; charset=utf-8");
    header('Content-Disposition: attachment; filename="zigbee2mqttng_einstellungen_' . date('Ymd_His') . '.json"');
    echo json_encode($inhalt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

/**
 * 4.2.0: downloads a backup of the zigbee2mqtt data folder as zip - up to
 * 4.1.1 the page backup.php, the code is the same. zigbee2mqtt builds it
 * itself on bridge/request/backup: configuration.yaml, devices,
 * database.db, coordinator_backup.json, state.json. It contains the
 * network key and the MQTT credentials.
 * Nothing is changed. On failure a short plain text says why.
 * Ends the request itself (exit), like backupSettings().
 */
function backupNetwork()
{
    global $L;
    $bridge = new Z2mBridge();
    $message = "";
    if (!$bridge->connect()) {
        $message = $L["Common.NoBroker"];
    } else {
        $answer = $bridge->request("backup", array(), 30.0);
        $bridge->close();
        if ($answer === null) {
            $message = $L["Common.NoAnswer"];
        } elseif (!isset($answer["status"]) || $answer["status"] !== "ok" || !isset($answer["data"]["zip"])) {
            $message = $L["Backup.Refused"] . " " . (isset($answer["error"]) ? $answer["error"] : "");
        } else {
            $zip = base64_decode($answer["data"]["zip"], true);
            if ($zip === false || substr($zip, 0, 2) !== "PK") {
                $message = $L["Backup.Refused"];
            } else {
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="zigbee2mqttng_backup_' . date('Ymd_His') . '.zip"');
                header('Content-Length: ' . strlen($zip));
                echo $zip;
                exit(0);
            }
        }
    }
    header('Content-Type: text/plain; charset=utf-8', true, 503);
    echo $message . "\n";
    exit(0);
}

/**
 * 4.1.1 (B3): "Einstellungen zurückspielen". Checks the uploaded file
 * (Sicherung::pruefen), writes it only if every value passes, then brings
 * zigbee2mqtt up to date and says what happened to the service.
 */
function restoreSettings()
{
    global $L, $serviceName;
    $datei = isset($_FILES["sicherung"]) ? $_FILES["sicherung"] : null;
    if (!is_array($datei) || !isset($datei["tmp_name"]) || !is_string($datei["tmp_name"]) || !is_uploaded_file($datei["tmp_name"])) {
        return json_encode(array("result" => false, "errors" => array($L["Sicherung.KeineDatei"])));
    }
    if ((int) $datei["size"] > Sicherung::MAX_BYTE || @filesize($datei["tmp_name"]) > Sicherung::MAX_BYTE) {
        return json_encode(array("result" => false, "errors" => array($L["Sicherung.Groesse"])));
    }
    list($fehler, $neu, $info) = Sicherung::pruefen((string) file_get_contents($datei["tmp_name"]));
    if ($fehler) {
        $texte = array();
        foreach ($fehler as $f) {
            $teile = explode(": ", $f, 2);
            $texte[] = (isset($L[$teile[0]]) ? $L[$teile[0]] : $teile[0]) . (isset($teile[1]) ? ": " . $teile[1] : "");
        }
        return json_encode(array("result" => false, "errors" => $texte));
    }
    LOGSTART("Restore settings");
    if (!Sicherung::schreiben($neu)) {
        LOGERR("Restore: the settings could not be written - nothing was changed");
        LOGEND("Restore failed");
        return json_encode(array("result" => false, "errors" => array($L["Sicherung.Schreibfehler"])));
    }
    LOGOK("Restore: " . implode(", ", $info["teile"]) . " written, " . $info["fehlend"] . " key(s) not in the file kept their value");
    $dienst = zng_aenderungen_anwenden();
    LOGEND("Restore finished");
    return json_encode(array_merge(array("result" => true, "teile" => $info["teile"], "fehlend" => $info["fehlend"]), $dienst));
}

/**
 * Loxone template as download: kind "inhttp" (virtual HTTP input, 4.1.1),
 * "in" (virtual UDP input) or "out" (virtual output), for one device or all
 * devices
 */
function getTemplate($kind, $device)
{
    // 4.1.1 (B8): built in zng_vorlage(); "inhttp" is new (gateway in HTTP
    // mode), "in" stays the UDP input, "out" the virtual output
    list($file, $xml) = zng_vorlage((string) $kind, (string) $device);
    if ($file === null) {
        $codes = array("device" => 404, "noudpport" => 409, "kind" => 400);
        sendresponse($codes[$xml], "application/json", json_encode(array("error" => $xml)));
    }
    header("Content-Type: application/xml; charset=utf-8");
    header('Content-Disposition: attachment; filename="' . $file . '"');
    echo $xml;
    exit(0);
}

function sendresponse($httpstatus, $contenttype, $response = null)
{

    $codes = array(
        200 => "OK",
        409 => "CONFLICT",
        204 => "NO CONTENT",
        304 => "NOT MODIFIED",
        400 => "BAD REQUEST",
        403 => "FORBIDDEN",
        404 => "NOT FOUND",
        405 => "METHOD NOT ALLOWED",
        500 => "INTERNAL SERVER ERROR",
        501 => "NOT IMPLEMENTED"
    );
    if (isset($_SERVER["SERVER_PROTOCOL"])) {
        header($_SERVER["SERVER_PROTOCOL"] . " $httpstatus " . $codes[$httpstatus]);
        header("Content-Type: $contenttype");
    }

    if ($response) {
        echo $response . "\n";
    }
    exit(0);
}
