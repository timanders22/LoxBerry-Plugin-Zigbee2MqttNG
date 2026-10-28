<?php
/**
 * Zigbee2MqttNG - Inhalte der Reiter (seit 4.2.0).
 *
 * Bis 4.1.1 war jeder Reiter eine eigene Seite hinter der LoxBerry-
 * Navigationsleiste. Seit 4.2.0 gibt es EINE Seite mit gruenen Reitern
 * (Entscheidung Nr. 44, index.php). Was die Einzelseiten vor dem Rendern
 * ihrer Twig-Vorlage taten, steht hier je Reiter als Funktion - die Rumpfe
 * sind beim Bau aus den Seiten herausgeloest und wortgleich, neu ist nur
 * die global-Zeile (auf der Seite waren es Variablen des Dateirumpfs). Die
 * Hilfsfunktionen von devices.php und test.php stehen wortgleich weiter
 * unten; in statusRows() ist eine Zeile dazugekommen (zng_reiterprobe).
 *
 * Dazu der Kopf ueber den Reitern (Entscheidung Nr. 43): zng_kopf() liest
 * nur, was die Reiter ohnehin lesen - den Dienst wie der Reiter Test, die
 * Geraeteliste, die Erreichbarkeit und bridge/info wie der Reiter Geraete,
 * die MQTT-Einstellungen und das Gateway wie der Reiter MQTT. Kein Netz,
 * keine Anfrage an zigbee2mqtt.
 *
 * Erwartet: include/plugin.php (defines.php, $L, Twig), model/ServiceConfig.php,
 * model/MqttConfig.php und bin/zigbee2mqttng.php sind geladen.
 */
require_once "loxberry_log.php";

/** Sprachwert; fehlt er, steht der Schluessel da - sichtbar statt leer. */
function zng_t($schluessel)
{
    global $L;
    return isset($L[$schluessel]) ? (string) $L[$schluessel] : (string) $schluessel;
}

/** Fuer HTML maskiert - nur fuer Werte OHNE Auszeichnung (Regeln/04). */
function zng_e($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

/**
 * Die Werte der Statusuebersicht ueber den Reitern (Entscheidung Nr. 43).
 * Gibt fertige Texte zurueck (ohne Auszeichnung - index.php maskiert sie)
 * und die zwei Zahlen, an denen die Farbe haengt.
 */
function zng_kopf()
{
    global $serviceName, $bridgeDevicesFile, $availabilityFile, $bridgeInfoFile, $mqttconfigfile, $configfile;
    $k = array();
    // Eine unlesbare Datei liefert beim Laden die Vorgaben (leeres Topic,
    // Anschluss automatisch) - das waere eine Aussage, die nicht stimmt.
    // Dieselbe Pruefung wie die Warnkaesten der Reiter Einstellungen und MQTT.
    $mqtt_kaputt = zng_json_lage($mqttconfigfile) === 'kaputt';
    $service_kaputt = zng_json_lage($configfile) === 'kaputt';

    // Dienst - dieselbe Abfrage wie die Zeile "Laeuft der Dienst" im Reiter Test
    $dienst = zng_service_state($serviceName);
    $k['pid'] = (int) $dienst['pid'];
    if ($k['pid'] > 0) {
        $k['dienst'] = sprintf(zng_t('KOPF.LAEUFT'), $k['pid']);
    } elseif (is_file(zng_startsperre_datei())) {
        $k['dienst'] = zng_t('KOPF.GESPERRT');
    } else {
        $k['dienst'] = zng_t('KOPF.ANGEHALTEN');
    }

    // Koordinator: was zigbee2mqtt zuletzt gemeldet hat (bridge/info, von der
    // Erweiterung als Datei abgelegt - der Reiter Geraete liest dieselbe),
    // darunter der eingetragene Anschluss aus den Einstellungen
    $service = ServiceConfig::load();
    $info = zng_read_json($bridgeInfoFile, array());
    $info = is_array($info) ? $info : array();
    $c = isset($info['coordinator']) && is_array($info['coordinator']) ? $info['coordinator'] : array();
    if (isset($c['type']) && is_string($c['type']) && $c['type'] !== '') {
        $text = $c['type'];
        if (isset($c['meta']['revision']) && is_scalar($c['meta']['revision'])) {
            $text .= ' ' . $c['meta']['revision'];
        }
        if (isset($info['network']['channel']) && is_numeric($info['network']['channel'])) {
            $text .= ', ' . sprintf(zng_t('KOPF.KANAL'), (int) $info['network']['channel']);
        }
        $k['koordinator'] = $text;
    } else {
        $k['koordinator'] = zng_t('KOPF.KOORD_KEINE');
    }
    $port = trim((string) $service->port);
    $k['anschluss'] = $service_kaputt ? zng_t('KOPF.SERVICE_KAPUTT')
        : sprintf(zng_t('KOPF.ANSCHLUSS'), $port !== '' ? $port : zng_t('KOPF.AUTOMATISCH'));

    // Geraete erreichbar: dieselbe Zuordnung wie die Geraeteliste
    // (devices: Typ ohne Coordinator, Name = friendly_name, sonst IEEE)
    $k['unerreichbar'] = 0;
    $liste = zng_read_json($bridgeDevicesFile, null);
    if (!is_array($liste)) {
        $k['geraete'] = zng_t('KOPF.GER_KEINE_LISTE');
    } else {
        $an = is_enabled($service->availability);
        $erreichbar = zng_read_json($availabilityFile, array());
        $erreichbar = is_array($erreichbar) ? $erreichbar : array();
        $m = 0;
        $n = 0;
        $ohne = 0;
        foreach ($liste as $d) {
            if (!is_array($d) || !isset($d['type']) || $d['type'] === 'Coordinator') {
                continue;
            }
            $m++;
            $name = isset($d['friendly_name']) ? $d['friendly_name'] : (isset($d['ieee_address']) ? $d['ieee_address'] : '');
            if (!isset($erreichbar[$name])) {
                $ohne++;
            } elseif ($erreichbar[$name]) {
                $n++;
            } else {
                $k['unerreichbar']++;
            }
        }
        if (!$an) {
            $k['unerreichbar'] = 0;
            $k['geraete'] = sprintf(zng_t('KOPF.GER_AUS'), $m);
        } else {
            $k['geraete'] = sprintf(zng_t('KOPF.GER_N_VON_M'), $n, $m)
                . ($ohne > 0 ? ', ' . sprintf(zng_t('KOPF.GER_OHNE'), $ohne) : '');
        }
    }

    // MQTT: der Weg aus den MQTT-Einstellungen, darunter das Gateway
    $mqtt = MqttConfig::load();
    $thema = trim((string) $mqtt->topic);
    $k['mqtt_klein'] = '';
    if ($mqtt_kaputt) {
        $k['mqtt'] = zng_t('KOPF.MQTT_KAPUTT');
    } elseif ($thema === '') {
        $k['mqtt'] = zng_t('KOPF.MQTT_LEER');
    } elseif (is_enabled($mqtt->usemqttgateway)) {
        $gw = zng_gateway_info();
        $k['mqtt'] = sprintf(zng_t('KOPF.MQTT_GATEWAY'), $thema);
        $auto = zng_t($gw['autostart'] ? 'KOPF.EIN' : 'KOPF.AUS');
        $k['mqtt_klein'] = $gw['version'] > 0
            ? sprintf(zng_t('KOPF.GATEWAY'), (int) $gw['version'], $auto)
            : sprintf(zng_t('KOPF.GATEWAY_OHNE'), $auto);
    } else {
        $broker = trim((string) $mqtt->server) . (trim((string) $mqtt->port) !== '' ? ':' . trim((string) $mqtt->port) : '');
        $k['mqtt'] = sprintf(zng_t('KOPF.MQTT_EIGEN'), $broker, $thema);
    }
    return $k;
}

/**
 * Reiter Test, Zeile "Reiter" (4.2.0, Regeln/04): Positivliste, Leiste und
 * Bereiche stehen in index.php je ausgeschrieben und koennen deshalb
 * auseinanderlaufen. Die Zeile liest index.php selbst und verlangt: dieselben
 * Reiter in derselben Reihenfolge, und an jedem Reiter UND jedem Bereich das
 * serverseitige sm-active fuer genau diesen Reiter - ohne es waere die Seite
 * ohne JavaScript leer.
 */
function zng_reiterprobe($datei)
{
    global $L;
    $s = (string) @file_get_contents($datei);
    $liste = array();
    if (preg_match('/\$zng_reiter = array\(([^)]*)\);/', $s, $m)) {
        preg_match_all('/\'(tab-[a-z]+)\'/', $m[1], $x);
        $liste = $x[1];
    }
    preg_match_all('/data-ziel="(tab-[a-z]+)"/', $s, $y);
    preg_match_all('/<div class="sm-seite[^"]*" id="(tab-[a-z]+)"/', $s, $z);
    $leiste = 0;
    if (preg_match_all('/class="sm-tab<\?= \$zng_tab === \'(tab-[a-z]+)\' \? \' sm-active\' : \'\' \?>" data-ziel="(tab-[a-z]+)"/', $s, $a, PREG_SET_ORDER)) {
        foreach ($a as $t) {
            $leiste += $t[1] === $t[2] ? 1 : 0;
        }
    }
    $bereiche = 0;
    if (preg_match_all('/class="sm-seite<\?= \$zng_tab === \'(tab-[a-z]+)\' \? \' sm-active\' : \'\' \?>" id="(tab-[a-z]+)"/', $s, $b, PREG_SET_ORDER)) {
        foreach ($b as $t) {
            $bereiche += $t[1] === $t[2] ? 1 : 0;
        }
    }
    $n = count($liste);
    if ($n > 0 && $liste === $y[1] && $liste === $z[1] && $leiste === $n && $bereiche === $n) {
        return statusRow("Test.Reiter", "ok", sprintf($L["Test.ReiterOk"], $n));
    }
    return statusRow("Test.Reiter", "fail", sprintf($L["Test.ReiterFehl"],
        implode(", ", $liste), implode(", ", $y[1]), implode(", ", $z[1]), $leiste, $bereiche));
}

/* ------------------------------------------------------------------
 * Die Reiter. Jede Funktion gibt die Twig-Vorlage ihres Reiters aus.
 * ------------------------------------------------------------------ */

/** Reiter Einstellungen (bis 4.1.1 index.php) */
function zng_bereich_settings($twig)
{
    $konfig = zng_konfig_anzeige();
    echo $twig->render('index.html', array(
        "konfig" => $konfig,
        "datenordner" => LBPDATADIR,
        "zweitschriftOrdner" => $konfig["zweitschriftOrdner"],
        "predecessors" => zng_predecessor_plugins(),
        "adapters" => ServiceConfig::ADAPTERS,
        "service" => ServiceConfig::load(),
        // 4.2.5 (Gruppe 38): one-time message of a server form, the section
        // "Notifications" (E2) and the form to lift the start block (V3)
        "einmal" => zng_einmal_html("settings"),
        "meldungen_html" => zng_meldungen_html(),
        "sperre_html" => zng_sperre_html(),
    ));
}

/** Reiter Geraete (bis 4.1.1 devices.php) */
function zng_bereich_devices($twig)
{
    global $serviceConfigFile, $deviceDataFile, $bridgeDevicesFile, $bridgeInfoFile;
    $serviceCfg = ServiceConfig::load();
    $list = deviceList(dirname($serviceConfigFile) . "/state.json");
    echo $twig->render('devices.html', array(
        "deviceData" => is_file($deviceDataFile) ? file_get_contents($deviceDataFile) : "",
        "deviceList" => $list["devices"],
        "hasList" => is_file($bridgeDevicesFile),
        "stateTime" => $list["stateTime"],
        "showLastSeen" => $list["showLastSeen"],
        "service" => $serviceCfg,
        "pairing" => zng_read_json($bridgeInfoFile, array()),
    ));
}

/** Reiter Geraete, Ansicht Zigbee2mqtt UI (bis 4.1.1 ui.php) */
function zng_bereich_ui($twig)
{
    $serviceCfg = ServiceConfig::load();
    $port = (int) $serviceCfg->frontendPort > 0 ? (int) $serviceCfg->frontendPort : 8881;
    // 4.2.5 (Gruppe 38, V9, T4-3): the zigbee2mqtt UI speaks only http. Called
    // through https, the browser blocks the http frame - then a sentence and a
    // direct link (new window) stand above it.
    $https = isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "" && strtolower((string) $_SERVER["HTTPS"]) !== "off";
    $host = isset($_SERVER["SERVER_NAME"]) && preg_match('/^[A-Za-z0-9.\-]{1,253}\z/', (string) $_SERVER["SERVER_NAME"])
        ? (string) $_SERVER["SERVER_NAME"] : "";
    echo $twig->render('ui.html', array("port" => $port, "service" => $serviceCfg, "https" => $https, "host" => $host));
}

/** Reiter MQTT (bis 4.1.1 mqtt.php) */
function zng_bereich_mqtt($twig)
{
    //mqtt is not a plugin anymore in lb >=3
    $mqtt_installed = (int) substr(LBSystem::lbversion(), 0, 1) > 2 || LBSystem::plugindata('mqttgateway');

    echo $twig->render('mqtt.html', array(
        "konfig" => zng_konfig_anzeige(),
        "mqtt_installed" => $mqtt_installed,
        "gateway" => zng_gateway_info(),
        // 4.2.5 (Gruppe 38, E1): the devices for the house topics
        "hausGeraete" => zng_haus_geraete_tabelle(),
        "hausFelder" => zng_haus_felder(),
    ));
}

/**
 * 4.2.5 (E1): rows of the table "Devices for the house topics": every device
 * with a klima/wetter or alarm field. The IEEE is not shown - it travels as a
 * hidden field with the row index and is checked on saving.
 */
function zng_haus_geraete_tabelle()
{
    global $bridgeDevicesFile, $serviceConfigFile;
    $mqtt = MqttConfig::load();
    $bereiche = is_array($mqtt->hausBereiche) ? $mqtt->hausBereiche : array();
    $alarmAus = is_array($mqtt->hausAlarmAus) ? $mqtt->hausAlarmAus : array();
    // the last values zigbee2mqtt keeps (state.json) - shown as they would go out (zng_haus_wert)
    $cache = zng_read_json(dirname($serviceConfigFile) . "/state.json", array());
    $cache = is_array($cache) ? $cache : array();
    $zeilen = array();
    foreach (zng_state_devices(zng_read_json($bridgeDevicesFile, array())) as $d) {
        if (!isset($d["ieee_address"]) || !zng_ieee_ok($d["ieee_address"])) {
            continue;
        }
        $felder = zng_haus_geraet_felder($d);
        if (!$felder) {
            continue;
        }
        $kw = array();
        $alarm = array();
        $st = isset($cache[$d["ieee_address"]]) && is_array($cache[$d["ieee_address"]]) ? $cache[$d["ieee_address"]] : array();
        foreach ($felder as $r) {
            $text = $r[1] . (array_key_exists($r[2], $st) ? " " . zng_haus_wert($r, $st[$r[2]]) : "");
            if ($r[0] === "a") {
                $alarm[] = $text;
            } else {
                $kw[] = $text;
            }
        }
        $zeilen[] = array(
            "ieee" => $d["ieee_address"],
            "name" => $d["friendly_name"],
            "modell" => isset($d["definition"]["model"]) ? (string) $d["definition"]["model"] : "",
            "felder" => implode(", ", array_merge($kw, $alarm)),
            "bereich" => $kw ? zng_haus_bereich($d["ieee_address"], $felder, $bereiche) : "",
            "klima" => (bool) $kw,
            "alarm" => (bool) $alarm,
            "alarmAn" => $alarm && !in_array($d["ieee_address"], $alarmAus, true),
        );
    }
    usort($zeilen, function ($a, $b) {
        return strcasecmp($a["name"], $b["name"]);
    });
    return $zeilen;
}

/** Reiter Einbindung in Loxone (bis 4.1.1 loxone.php) */
function zng_bereich_loxone($twig)
{
    global $bridgeDevicesFile, $mqttGatewaySubscriptionFile, $bridgeGroupsFile;
    $mqttcfg = MqttConfig::load();
    $serviceCfg = ServiceConfig::load();
    $availability = is_enabled($serviceCfg->availability);
    $devices = zng_device_ios($mqttcfg->topic, zng_read_json($bridgeDevicesFile, array()), $availability);
    $hasActions = false;
    $textCount = 0;
    // 4.1.1 (B6): real names for the block list where a device has them
    $beispiel = array("erreichbar" => "", "aktion" => "", "kontakt" => "");
    foreach ($devices as $d) {
        foreach ($d["inputs"] as $in) {
            $hasActions = $hasActions || $in["type"] === "action";
            if (!empty($in["text"])) {
                $textCount++;
            }
            if ($in["property"] === "erreichbar" && $beispiel["erreichbar"] === "") {
                $beispiel["erreichbar"] = $in["vi"];
            }
            if ($in["type"] === "action" && $beispiel["aktion"] === "") {
                $beispiel["aktion"] = $in["vi"];
            }
            if ($in["property"] === "contact" && $beispiel["kontakt"] === "") {
                $beispiel["kontakt"] = $in["vi"];
            }
        }
    }
    $topicName = zng_gateway_name($mqttcfg->topic);
    foreach (array("erreichbar" => "_<gerät>_erreichbar", "aktion" => "_<taster>_aktion_single", "kontakt" => "_<kontakt>_contact") as $k => $muster) {
        if ($beispiel[$k] === "") {
            $beispiel[$k] = $topicName . $muster;
        }
    }

    // 4.1.1 (B6): is the topic registered at the gateway (version 1)?
    $abos = array();
    if (is_readable($mqttGatewaySubscriptionFile)) {
        foreach (preg_split('/\r?\n/', (string) file_get_contents($mqttGatewaySubscriptionFile)) as $line) {
            if (trim($line) !== "") {
                $abos[] = trim($line);
            }
        }
    }
    $lebenszeichen = zng_lebenszeichen_ios($mqttcfg->topic);
    $v2liste = zng_v2_liste($mqttcfg->topic, zng_read_json($bridgeDevicesFile, array()), $availability,
                            zng_read_json($bridgeGroupsFile, array()));

    echo $twig->render('loxone.html', array(
        "devices" => $devices,
        "hasDeviceList" => is_file($bridgeDevicesFile),
        "gateway" => zng_gateway_info(),
        "usegateway" => is_enabled($mqttcfg->usemqttgateway),
        "topic" => $mqttcfg->topic,
        "hausTopics" => is_enabled($mqttcfg->hausTopics),
        "availability" => $availability,
        "hasActions" => $hasActions,
        "textCount" => $textCount,
        "loxberryIp" => LBSystem::get_localip(),
        "abos" => count($abos),
        "registriert" => count(array_filter($abos, function ($l) use ($mqttcfg) {
            return strpos($l, $mqttcfg->topic . "/") === 0;
        })) > 0,
        "lebenszeichen" => $lebenszeichen,
        "beispiel" => $beispiel,
        // 4.2.4 (Gruppe 5, Entwurf zb E5): Hakenliste fuer Gateway V2 - eigene Geraete und die zwei Musterseiten
        "v2liste" => $v2liste,
        "v2gruppen" => count(array_filter($v2liste, function ($r) { return $r["gruppe"]; })),
        "v2muster" => zng_v2_muster($mqttcfg->topic),
    ));
}

/**
 * Reiter Test (bis 4.1.1 test.php). Die Bruecke zum Broker (phpMQTT) wird
 * nur hier geladen: nur dieser Reiter fragt den Broker.
 */
function zng_bereich_test($twig)
{
    require_once __DIR__ . '/include/Z2mBridge.php';
    echo $twig->render('test.html', array("rows" => statusRows(), "time" => date("H:i:s"),
        // 4.2.5 (Gruppe 38, E2): one-time message and the buttons under "Schalten"
        "einmal" => zng_einmal_html("test"), "schalten_html" => zng_schalten_html()));
}

/** Reiter Logdateien (bis 4.1.1 log.php) */
function zng_bereich_log($twig)
{
    global $lbpplugindir;
    $loglist_html = file_get_contents("http://localhost:" . lbwebserverport() . "/admin/system/logmanager.cgi?package=" .  urlencode($lbpplugindir) . "&header=none");
    echo $twig->render('log.html', array("loglist" => $loglist_html, "logfile" => LBPLOGDIR . "/zigbee2mqtt.log"));
}

/* ------------------------------------------------------------------
 * Bis 4.1.1 in devices.php - wortgleich
 * ------------------------------------------------------------------ */

/**
 * All paired devices from the device list the bridge extension keeps
 * (bridge/devices), with the last values from the state cache of zigbee2mqtt
 * (state.json) and the availability. Read only.
 */
function deviceList($stateFile)
{
    global $bridgeDevicesFile, $availabilityFile, $otaFile;
    $result = array("devices" => array(), "stateTime" => "", "showLastSeen" => false);
    // 4.2.5 (Gruppe 38, E4): firmware state per device (written by the extension)
    $ota = zng_read_json($otaFile, array());
    $ota = is_array($ota) ? $ota : array();

    $cache = array();
    if (is_readable($stateFile)) {
        $cache = json_decode((string) file_get_contents($stateFile), true);
        $cache = is_array($cache) ? $cache : array();
        $result["stateTime"] = date("d.m.Y H:i", filemtime($stateFile));
    }
    $availability = zng_read_json($availabilityFile, array());

    foreach ((array) zng_read_json($bridgeDevicesFile, array()) as $device) {
        if (!isset($device["type"]) || $device["type"] === "Coordinator") {
            continue;
        }
        $definition = isset($device["definition"]) && is_array($device["definition"]) ? $device["definition"] : array();
        $units = array();
        foreach (zng_flatten_exposes(isset($definition["exposes"]) ? $definition["exposes"] : array()) as $feature) {
            $units[$feature["property"]] = isset($feature["unit"]) ? $feature["unit"] : "";
        }
        $state = isset($cache[$device["ieee_address"]]) && is_array($cache[$device["ieee_address"]]) ? $cache[$device["ieee_address"]] : array();
        $values = array();
        foreach ($state as $property => $value) {
            if ($property === "last_seen" || is_array($value) || $value === "" || $value === null) {
                continue;
            }
            if (is_bool($value)) {
                $value = $value ? "true" : "false";
            }
            $values[] = $property . " " . $value . (isset($units[$property]) && $units[$property] !== "" ? " " . $units[$property] : "");
        }
        if (isset($state["last_seen"])) {
            $result["showLastSeen"] = true;
        }
        $name = isset($device["friendly_name"]) ? $device["friendly_name"] : $device["ieee_address"];
        list($firmware, $firmwareKlasse) = zng_firmware_text($device, isset($ota[$name]) ? $ota[$name] : null);
        $result["devices"][] = array(
            "firmware" => $firmware,
            "firmwareKlasse" => $firmwareKlasse,
            "name" => $name,
            "ieee" => $device["ieee_address"],
            "model" => trim((isset($definition["vendor"]) ? $definition["vendor"] . " " : "") . (isset($definition["model"]) ? $definition["model"] : (isset($device["model_id"]) ? $device["model_id"] : ""))),
            "description" => isset($definition["description"]) ? $definition["description"] : "",
            "type" => $device["type"],
            "power" => isset($device["power_source"]) ? $device["power_source"] : "",
            "interview" => isset($device["interview_state"]) ? $device["interview_state"] : (!empty($device["interview_completed"]) ? "SUCCESSFUL" : ""),
            "supported" => !isset($device["supported"]) || $device["supported"],
            "disabled" => !empty($device["disabled"]),
            "reachable" => isset($availability[$name]) ? (bool) $availability[$name] : null,
            "values" => implode(", ", $values),
            "lastSeen" => isset($state["last_seen"]) ? (is_numeric($state["last_seen"]) ? date("d.m.Y H:i", (int) ($state["last_seen"] / 1000)) : (string) $state["last_seen"]) : ""
        );
    }
    usort($result["devices"], function ($a, $b) {
        return strcasecmp($a["name"], $b["name"]);
    });
    return $result;
}

/**
 * 4.2.5 (Gruppe 38, E4): the column "Firmware" - "aktuell (<installed>)",
 * "Update verfuegbar: <installed> -> <latest>", "wird eingespielt: n %",
 * "kein OTA", "noch keine Aussage". Returns array(text, class).
 */
function zng_firmware_text($device, $o)
{
    if (!zng_supports_ota($device)) {
        return array(zng_t("Devices.FwKeinOta"), "sm-info");
    }
    if (!is_array($o) || !isset($o["state"])) {
        return array(zng_t("Devices.FwKeineAussage"), "sm-info");
    }
    $v = function ($x) {
        return is_scalar($x) && (string) $x !== "" ? (string) $x : "?";
    };
    if ($o["state"] === "available" || $o["state"] === "scheduled") {
        return array(sprintf(zng_t("Devices.FwVerfuegbar"), $v(isset($o["installed"]) ? $o["installed"] : null), $v(isset($o["latest"]) ? $o["latest"] : null)), "sm-hint");
    }
    if ($o["state"] === "updating") {
        return array(sprintf(zng_t("Devices.FwLaeuft"), isset($o["progress"]) && is_numeric($o["progress"]) ? (int) $o["progress"] : 0), "sm-hint");
    }
    if ($o["state"] === "idle") {
        return array(sprintf(zng_t("Devices.FwAktuell"), $v(isset($o["installed"]) ? $o["installed"] : null)), "sm-ok");
    }
    return array(zng_t("Devices.FwKeineAussage"), "sm-info");
}

/* ------------------------------------------------------------------
 * Bis 4.1.1 in test.php - wortgleich, dazu die Zeile zng_reiterprobe
 * ------------------------------------------------------------------ */

/**
 * One line of the status table.
 * $state: ok (green), fail (red), hint (orange), info (grey)
 */
function statusRow($label, $state, $detail = "")
{
    return array("label" => $label, "state" => $state, "detail" => $detail);
}

/**
 * Collects the status. Read only: nothing is changed and no request is sent
 * to zigbee2mqtt, only its retained bridge topics are read.
 */
function statusRows()
{
    global $L, $serviceName, $serviceConfigFile, $bridgeDevicesFile, $bridgeStatusFile;
    $rows = array();
    $serviceCfg = ServiceConfig::load();
    $mqttcfg = MqttConfig::load();

    // service
    $service = zng_service_state($serviceName);
    if ($service["pid"] > 0) {
        $rows[] = statusRow("Test.Service", "ok", "PID " . $service["pid"] . ($service["since"] !== "" ? ", " . sprintf($L["Test.Since"], $service["since"]) : ""));
    } else {
        $rows[] = statusRow("Test.Service", "fail", $L["Test.ServiceStopped"]);
    }
    // 4.2.5 (V9, T4-6): the version of this plugin, next to the one of zigbee2mqtt (line Version below)
    $rows[] = fassungRow();
    foreach (zng_predecessor_plugins() as $p) {
        $rows[] = statusRow(sprintf($L["Test.Predecessor"], $p["title"]), $p["active"] ? "fail" : "hint",
            $p["active"] ? $L["Test.PredecessorActive"] : $L["Test.PredecessorInstalled"]);
    }

    // broker and retained bridge topics
    $bridge = new Z2mBridge();
    $data = array();
    if ($bridge->connect()) {
        $rows[] = statusRow("Test.Broker", "ok", $bridge->address());
        $data = $bridge->retained(array("bridge/state", "bridge/info"), 3.0);
        $bridge->close();
    } else {
        $rows[] = statusRow("Test.Broker", "fail", sprintf($L["Test.BrokerFail"], $bridge->address()));
    }

    $state = isset($data["bridge/state"]) ? $data["bridge/state"] : null;
    if (is_array($state) && isset($state["state"])) {
        $state = $state["state"];
    }
    $online = $state === "online";
    if ($online) {
        $rows[] = statusRow("Test.Online", "ok", $bridge->topic . "/bridge/state = online");
    } else {
        $rows[] = statusRow("Test.Online", "fail", $state === null ? $L["Test.OnlineNoAnswer"] : $bridge->topic . "/bridge/state = " . $state);
    }

    // bridge extension: writes its start time when zigbee2mqtt loads it
    $status = zng_read_json($bridgeStatusFile, array());
    $started = isset($status["started"]) ? (int) ($status["started"] / 1000) : 0;
    if ($started === 0) {
        $rows[] = statusRow("Test.Extension", "fail", $L["Test.ExtensionMissing"]);
    } elseif ($service["sinceTs"] > 0 && $started + 5 < $service["sinceTs"]) {
        $rows[] = statusRow("Test.Extension", $online ? "fail" : "hint", $L["Test.ExtensionStale"]);
    } else {
        $rows[] = statusRow("Test.Extension", "ok", sprintf($L["Test.ExtensionOk"], date("d.m.Y H:i:s", $started)));
    }

    $info = isset($data["bridge/info"]) && is_array($data["bridge/info"]) ? $data["bridge/info"] : null;
    if ($info === null) {
        $rows[] = statusRow("Test.NoData", "info", $L["Test.NoDataDetail"]);
        $rows[] = adapterRow((string) $serviceCfg->port, false);
    } else {
        $rows = array_merge($rows, infoRows($info, $online));
    }
    $port = $info !== null && isset($info["config"]["serial"]["port"]) ? (string) $info["config"]["serial"]["port"] : (string) $serviceCfg->port;
    if (zng_port_unstable($port)) {
        $rows[] = statusRow("Test.Adapter", "hint", sprintf($L["Test.AdapterUnstable"], $port));
    }
    $rows[] = portUsersRow($port, $service["pid"]);
    $rows[] = frontendRow($serviceCfg, $online);
    $rows[] = radioRow();

    $devices = zng_read_json($bridgeDevicesFile, null);
    list($meldungen) = zng_meldungen_laden();
    if (is_array($devices)) {
        $rows = array_merge($rows, deviceRows($devices, dirname($serviceConfigFile) . "/state.json", (int) $meldungen["batterieSchwelle"]));
        $rows[] = actionsRow($mqttcfg->topic, $devices);
        // 4.2.5 (Gruppe 38, E4)
        $rows[] = firmwareRow($devices);
    }
    $rows = array_merge($rows, gatewayRows($mqttcfg));
    // 4.2.5 (V10, E1): one line per house family, the heartbeat of the families, mjs = PHP
    $rows = array_merge($rows, hausRows($mqttcfg, $status));
    $rows[] = hausFelderRow();
    // 4.1.1
    $rows[] = heartbeatRow($status, $service["pid"]);
    $rows = array_merge($rows, konfigRows());
    // 4.2.5 (V1): network protection - only yes/no, never a value
    $rows[] = netzschutzRow();
    $rows[] = vorlagenRow();
    // 4.2.5 (E4): all three templates CRLF and well-formed
    $rows[] = vorlagenCrlfRow();
    // 4.2.5 (E2): ways of the notifications
    $rows[] = signalRow($meldungen);
    $rows = array_merge($rows, spracheRows($meldungen));
    // 4.2.4 (Gruppe 5, Entwurf zb E5/E6): Namen der Hakenliste, Deinstallationsskript (Praefixfall)
    $rows[] = namenRow($mqttcfg, $serviceCfg);
    $rows[] = uninstallRow();
    // 4.2.0: Reiterleiste, Bereiche und Positivliste in index.php
    $rows[] = zng_reiterprobe(__DIR__ . "/index.php");
    return $rows;
}

/**
 * 4.2.4 (Gruppe 5, Entwurf zb E5): every name of the tick list equals the topic (plus field) with "/", " " and
 * "%" replaced by "_" (computed without zng_gateway_name()), and mqtt_resetaftersend.cfg - written by the
 * extension with gatewayName() in JS - equals zng_reset_lines() in PHP: the two name functions agree.
 */
function namenRow($mqttcfg, $serviceCfg)
{
    global $L, $bridgeDevicesFile, $bridgeGroupsFile, $mqttGatewayResetFile;
    $devices = zng_read_json($bridgeDevicesFile, array());
    $liste = zng_v2_liste($mqttcfg->topic, $devices, is_enabled($serviceCfg->availability),
                          zng_read_json($bridgeGroupsFile, array()));
    $falsch = zng_v2_namen_abweichend($liste);
    $n = 0;
    foreach ($liste as $r) {
        $n += count($r["namen"]);
    }
    if ($falsch) {
        return statusRow("Test.Namen", "fail", sprintf($L["Test.NamenFehl"], implode(", ", $falsch)));
    }
    if (!is_readable($mqttGatewayResetFile)) {
        return statusRow("Test.Namen", "ok", sprintf($L["Test.NamenOhneDatei"], $n));
    }
    $ist = trim((string) file_get_contents($mqttGatewayResetFile));
    $soll = trim(zng_reset_lines($mqttcfg->topic, $devices));
    if ($ist !== $soll) {
        return statusRow("Test.Namen", "hint", sprintf($L["Test.NamenFehl"], "mqtt_resetaftersend.cfg"));
    }
    return statusRow("Test.Namen", "ok", sprintf($L["Test.NamenOk"], $n));
}

/**
 * 4.2.4 (Gruppe 5, Entwurf zb E6): the uninstall script of this plugin. Uninstalling the original plugin
 * "zigbee2mqtt" makes LoxBerry delete data/system/uninstall/zigbee2mqtt* - this script too (plugininstall.pl
 * :1570/:1603, prefix). The guard in uninstall/uninstall (since 4.1.1) stops it from RUNNING, not from being
 * deleted. Whether the user loxberry may read the folder is not measured - unreadable is "not checkable".
 */
function uninstallRow()
{
    global $L;
    $ordner = LBHOMEDIR . "/data/system/uninstall";
    $datei = $ordner . "/" . LBPPLUGINDIR;
    if (is_file($datei)) {
        return statusRow("Test.Uninstall", "ok", sprintf($L["Test.UninstallOk"], $datei));
    }
    if (!is_dir($ordner) || !is_readable($ordner)) {
        return statusRow("Test.Uninstall", "info", sprintf($L["Test.UninstallUnlesbar"], $ordner));
    }
    return statusRow("Test.Uninstall", "fail", sprintf($L["Test.UninstallFehlt"], $datei));
}

/**
 * 4.1.1 (B7): the heartbeat for Loxone. The extension writes the last one
 * into its status file; this line says whether it is fresh. It measures the
 * extension, not the arrival at the Miniserver.
 */
function heartbeatRow($status, $pid)
{
    global $L;
    $hb = isset($status["heartbeat"]) && is_array($status["heartbeat"]) ? $status["heartbeat"] : array();
    if (!isset($hb["ts"]) || !is_numeric($hb["ts"])) {
        return statusRow("Test.Heartbeat", $pid > 0 ? "fail" : "info", $L["Test.HeartbeatNie"]);
    }
    $alter = time() - (int) $hb["ts"];
    $text = sprintf($L["Test.HeartbeatAlter"], $alter, isset($hb["zaehler"]) ? (int) $hb["zaehler"] : -1);
    if ($alter <= 150) {
        return statusRow("Test.Heartbeat", "ok", $text);
    }
    return statusRow("Test.Heartbeat", $pid > 0 ? "fail" : "info", $text);
}

/**
 * 4.1.1 (B1-B4): are the configuration files readable, is there a second
 * copy, are they for loxberry only (0600)? Read only - nothing is healed
 * here, so the line shows the state as it is.
 */
function konfigRows()
{
    global $L;
    $rows = array();
    $anzeige = zng_konfig_anzeige();
    $kaputt = array();
    $alt = array();
    $ohneZweitschrift = array();
    $offen = array();
    foreach ($anzeige["dateien"] as $d) {
        if ($d["lage"] === "kaputt") {
            $kaputt[] = $d["name"];
        } elseif ($d["kaputt"] !== "") {
            $alt[] = $d["kaputt"];
        }
        if ($d["lage"] === "ok" && !$d["zweitschrift"]) {
            $ohneZweitschrift[] = $d["name"];
        }
        if (is_file($d["datei"]) && (fileperms($d["datei"]) & 0077) !== 0) {
            $offen[] = $d["name"] . " " . substr(sprintf("%o", fileperms($d["datei"])), -4);
        }
    }
    if ($kaputt) {
        $rows[] = statusRow("Test.Konfig", "fail", sprintf($L["Test.KonfigKaputt"], implode(", ", $kaputt)));
    } elseif ($anzeige["sperre"] !== "") {
        $rows[] = statusRow("Test.Konfig", "fail", sprintf($L["Test.KonfigSperre"], $anzeige["sperre"]));
    } elseif ($alt) {
        $rows[] = statusRow("Test.Konfig", "hint", sprintf($L["Test.KonfigAlt"], implode(", ", $alt)));
    } else {
        $rows[] = statusRow("Test.Konfig", "ok", $L["Test.KonfigOk"]);
    }
    $rows[] = $ohneZweitschrift
        ? statusRow("Test.Zweitschrift", "hint", sprintf($L["Test.ZweitschriftFehlt"], implode(", ", $ohneZweitschrift)))
        : statusRow("Test.Zweitschrift", "ok", sprintf($L["Test.ZweitschriftOk"], $anzeige["zweitschriftOrdner"]));
    $rows[] = $offen
        ? statusRow("Test.Rechte", "fail", sprintf($L["Test.RechteOffen"], implode(", ", $offen)))
        : statusRow("Test.Rechte", "ok", $L["Test.RechteOk"]);
    return $rows;
}

/**
 * 4.1.1 (B9): every template the tab Einbindung in Loxone offers is built
 * and parsed here: well-formed, every Comment 40 characters or less, no
 * title twice. Says how many commands were checked.
 */
function vorlagenRow()
{
    global $L;
    $gateway = zng_gateway_info();
    $arten = array("out");
    if ($gateway["use_http"]) {
        $arten[] = "inhttp";
    }
    if ($gateway["use_udp"] && $gateway["udpport"] > 0) {
        $arten[] = "in";
    }
    $befehle = 0;
    $fehler = array();
    foreach ($arten as $art) {
        list($datei, $xml) = zng_vorlage($art, "");
        if ($datei === null) {
            $fehler[] = $art . ": " . $xml;
            continue;
        }
        $doc = @simplexml_load_string($xml);
        if ($doc === false) {
            $fehler[] = $datei . ": XML";
            continue;
        }
        $titel = array();
        foreach ($doc->children() as $cmd) {
            if ($cmd->getName() === "Info") {
                continue;
            }
            $befehle++;
            $titel[] = (string) $cmd["Title"];
            if (zng_laenge((string) $cmd["Comment"]) > 40) {
                $fehler[] = $datei . ": Comment > 40 (" . (string) $cmd["Title"] . ")";
            }
        }
        if (count($titel) !== count(array_unique($titel))) {
            $fehler[] = $datei . ": " . $L["Test.VorlageDoppelt"];
        }
    }
    if ($fehler) {
        return statusRow("Test.Vorlage", "fail", implode("; ", $fehler));
    }
    return statusRow("Test.Vorlage", "ok", sprintf($L["Test.VorlageOk"], count($arten), $befehle));
}

/**
 * Version, coordinator, adapter and pairing from bridge/info.
 */
function infoRows($info, $online)
{
    global $L;
    $rows = array();

    $version = isset($info["version"]) ? $info["version"] : "?";
    $parts = array();
    if (isset($info["zigbee_herdsman"]["version"])) {
        $parts[] = "herdsman " . $info["zigbee_herdsman"]["version"];
    }
    if (isset($info["zigbee_herdsman_converters"]["version"])) {
        $parts[] = "converters " . $info["zigbee_herdsman_converters"]["version"];
    }
    $rows[] = statusRow("Test.Version", "info", $version . ($parts ? " (" . implode(", ", $parts) . ")" : ""));
    if (!empty($info["restart_required"])) {
        $rows[] = statusRow("Test.RestartRequired", "hint", $L["Test.RestartRequiredDetail"]);
    }

    $coordinator = isset($info["coordinator"]) ? $info["coordinator"] : array();
    $text = isset($coordinator["type"]) ? $coordinator["type"] : "?";
    if (isset($coordinator["meta"]["revision"])) {
        $text .= " " . $coordinator["meta"]["revision"];
    }
    if (isset($coordinator["ieee_address"])) {
        $text .= ", IEEE " . $coordinator["ieee_address"];
    }
    if (isset($info["network"]["channel"])) {
        $text .= ", " . sprintf($L["Test.Channel"], $info["network"]["channel"]);
    }
    $rows[] = statusRow("Test.Coordinator", "info", $text);

    $port = isset($info["config"]["serial"]["port"]) ? (string) $info["config"]["serial"]["port"] : "";
    $rows[] = adapterRow($port, $online);

    if (!empty($info["permit_join"])) {
        $end = isset($info["permit_join_end"]) ? date("H:i:s", (int) ($info["permit_join_end"] / 1000)) : "?";
        $rows[] = statusRow("Test.Pairing", "hint", sprintf($L["Test.PairingOpen"], $end));
    } else {
        $rows[] = statusRow("Test.Pairing", "ok", $L["Test.PairingClosed"]);
    }
    return $rows;
}

/**
 * Is the adapter port zigbee2mqtt uses reachable? While zigbee2mqtt is
 * online it is evidently connected, and the port is not touched: some
 * serial-over-TCP bridges accept only one client. Otherwise tcp://host:port
 * gets a TCP connect (2 s) and /dev/... is checked for presence.
 */
function adapterRow($port, $online)
{
    global $L;
    if ($port === "") {
        return statusRow("Test.Adapter", "info", $L["Test.AdapterAuto"]);
    }
    if ($online) {
        return statusRow("Test.Adapter", "ok", sprintf($L["Test.AdapterInUse"], $port));
    }
    $test = zng_test_port($port, 2);
    switch ($test["message"]) {
        case "reachable":
            return statusRow("Test.Adapter", "ok", sprintf($L["Test.AdapterReachable"], $port));
        case "present":
            return statusRow("Test.Adapter", "ok", sprintf($L["Test.AdapterPresent"], $port));
        case "missing":
        case "noaccess":
            return statusRow("Test.Adapter", "fail", sprintf($L["Test.AdapterMissing"], $port));
        default:
            return statusRow("Test.Adapter", "fail", sprintf($L["Test.AdapterUnreachable"], $port));
    }
}

/**
 * Which processes have the serial adapter open. Besides zigbee2mqtt of this
 * plugin nobody may - e.g. a predecessor plugin or a Thread border router
 * that grabbed the wrong /dev/ttyACM*.
 */
function portUsersRow($port, $ownPid)
{
    global $L, $serviceName;
    if (strpos($port, "/dev/") !== 0) {
        return statusRow("Test.PortUsers", "info", $port === "" ? $L["Test.AdapterAuto"] : $port);
    }
    $users = zng_port_users($port);
    if (!$users) {
        return statusRow("Test.PortUsers", $ownPid > 0 ? "hint" : "info", $L["Test.PortUsersNone"]);
    }
    $others = array();
    $own = array();
    foreach ($users as $u) {
        $text = $u["pid"] . ($u["unit"] !== "" ? " (" . $u["unit"] . ")" : "") . " " . substr($u["command"], 0, 60);
        if ($u["unit"] === $serviceName) {
            $own[] = $text;
        } else {
            $others[] = $text;
        }
    }
    if ($others) {
        return statusRow("Test.PortUsers", "fail", sprintf($L["Test.PortUsersOther"], implode("; ", $others)));
    }
    return statusRow("Test.PortUsers", "ok", implode("; ", $own));
}

/**
 * Port of the zigbee2mqtt UI: listens zigbee2mqtt, or is it taken by another
 * program?
 */
function frontendRow($serviceCfg, $online)
{
    global $L;
    $port = (int) $serviceCfg->frontendPort > 0 ? (int) $serviceCfg->frontendPort : 8881;
    $label = sprintf($L["Test.FrontendPort"], $port);
    $listening = zng_port_listening($port);
    if (is_enabled($serviceCfg->enableUI)) {
        if ($listening) {
            return statusRow($label, "ok", $L["Test.FrontendListening"]);
        }
        return statusRow($label, $online ? "fail" : "hint", $L["Test.FrontendDown"]);
    }
    return $listening ? statusRow($label, "hint", $L["Test.FrontendBusy"]) : statusRow($label, "info", $L["Test.FrontendFree"]);
}

/**
 * Zigbee, Thread and WLAN channel
 */
function radioRow()
{
    global $L;
    list($zigbee) = zng_zigbee_channel();
    list($thread, $threadSource) = zng_thread_channel();
    list($wifi, $wifiInterface) = zng_wifi_channel();
    $parts = array("Zigbee " . $zigbee);
    $state = "ok";
    if ($thread) {
        $parts[] = "Thread " . $thread . " (" . $threadSource . ")";
        if ($thread == $zigbee) {
            $state = "fail";
            $parts[] = $L["ServiceConfig.RadioConflict"];
        } elseif (abs($thread - $zigbee) == 1) {
            $state = "hint";
            $parts[] = $L["ServiceConfig.RadioAdjacent"];
        }
    }
    if ($wifi) {
        $parts[] = "WLAN " . $wifi . " (" . $wifiInterface . ")";
        $overlap = zng_wifi_overlap($zigbee, $wifi);
        if ($overlap === "conflict") {
            $state = $state === "fail" ? "fail" : "hint";
            $parts[] = $L["ServiceConfig.RadioWifiConflict"];
        } elseif ($overlap === "near") {
            $parts[] = $L["ServiceConfig.RadioWifiNear"];
        }
    }
    return statusRow("Test.Radio", $state, implode(", ", $parts));
}

/**
 * Device counts from the device list, availability from the bridge
 * extension, battery levels from the state cache of zigbee2mqtt (state.json,
 * written by zigbee2mqtt every few minutes).
 */
function deviceRows($devices, $stateFile, $threshold)
{
    global $L, $availabilityFile;
    $rows = array();
    $names = array();
    $incomplete = array();
    $unsupported = array();
    $disabled = array();
    foreach ($devices as $device) {
        if (!isset($device["type"]) || $device["type"] === "Coordinator") {
            continue;
        }
        $name = isset($device["friendly_name"]) ? $device["friendly_name"] : $device["ieee_address"];
        $names[$device["ieee_address"]] = $name;
        $interview = isset($device["interview_state"]) ? $device["interview_state"] : (!empty($device["interview_completed"]) ? "SUCCESSFUL" : "");
        if ($interview !== "SUCCESSFUL") {
            $incomplete[] = $name;
        }
        if (isset($device["supported"]) && !$device["supported"]) {
            $unsupported[] = $name;
        }
        if (!empty($device["disabled"])) {
            $disabled[] = $name;
        }
    }
    $rows[] = statusRow("Test.Devices", count($names) > 0 ? "ok" : "info", sprintf($L["Test.DevicesCount"], count($names)));
    if ($incomplete) {
        $rows[] = statusRow("Test.Interview", "hint", implode(", ", $incomplete));
    }
    if ($unsupported) {
        $rows[] = statusRow("Test.Unsupported", "hint", implode(", ", $unsupported));
    }
    if ($disabled) {
        $rows[] = statusRow("Test.Disabled", "info", implode(", ", $disabled));
    }
    $offline = array();
    foreach (zng_read_json($availabilityFile, array()) as $name => $reachable) {
        if (!$reachable && in_array($name, $names, true)) {
            $offline[] = $name;
        }
    }
    if ($offline) {
        $rows[] = statusRow("Test.Offline", "hint", implode(", ", $offline));
    }

    $cache = is_readable($stateFile) ? json_decode((string) file_get_contents($stateFile), true) : null;
    if (!is_array($cache)) {
        return $rows;
    }
    $threshold = $threshold > 0 ? $threshold : 15;
    $low = array();
    $counted = 0;
    foreach ($names as $ieee => $name) {
        if (isset($cache[$ieee]["battery"]) && is_numeric($cache[$ieee]["battery"])) {
            $counted++;
            if ($cache[$ieee]["battery"] <= $threshold) {
                $low[] = $name . " " . $cache[$ieee]["battery"] . " %";
            }
        }
    }
    if ($counted === 0) {
        return $rows;
    }
    $rows[] = $low
        ? statusRow("Test.Battery", "hint", sprintf($L["Test.BatteryLow"], $threshold, implode(", ", $low)))
        : statusRow("Test.Battery", "ok", sprintf($L["Test.BatteryOk"], $counted));
    return $rows;
}

/**
 * Buttons with pulse topics
 */
function actionsRow($topic, $devices)
{
    global $L;
    $count = 0;
    $topics = 0;
    foreach (zng_state_devices($devices) as $device) {
        $n = count(zng_action_values($device));
        if ($n > 0) {
            $count++;
            $topics += $n;
        }
    }
    return statusRow("Test.Actions", "info", sprintf($L["Test.ActionsCount"], $count, $topics));
}

/**
 * What the MQTT gateway needs so that values reach the Miniserver.
 * Version 1 forwards only subscribed topics, version 2 lets the user tick
 * data points in its own subscription page. Also lists conversions of other
 * plugins for online/offline: the gateway applies them to all plugins.
 */
function gatewayRows($mqttcfg)
{
    global $L, $mqttGatewaySubscriptionFile;
    $rows = array();
    $topic = $mqttcfg->topic;
    if (!is_enabled($mqttcfg->usemqttgateway)) {
        return array(statusRow("Test.Gateway", "info", $L["Test.GatewayOwnBroker"]));
    }
    $gateway = zng_gateway_info();
    $suffix = $gateway["autostart"] ? "" : " " . $L["Test.GatewayAutostartOff"];
    if ($gateway["version"] === 2) {
        $rows[] = statusRow("Test.Gateway", $gateway["autostart"] ? "info" : "hint", $L["Test.GatewayV2"] . $suffix);
    } else {
        $lines = array();
        if (is_readable($mqttGatewaySubscriptionFile)) {
            foreach (preg_split('/\r?\n/', (string) file_get_contents($mqttGatewaySubscriptionFile)) as $line) {
                if (trim($line) !== "") {
                    $lines[] = trim($line);
                }
            }
        }
        $own = array_filter($lines, function ($l) use ($topic) {
            return strpos($l, $topic . "/") === 0;
        });
        if (!$own) {
            $rows[] = statusRow("Test.Gateway", "fail", sprintf($L["Test.GatewayV1Missing"], $topic . "/...") . $suffix);
        } else {
            $rows[] = statusRow("Test.Gateway", $gateway["autostart"] ? "ok" : "hint",
                sprintf($L["Test.GatewayV1Ok"], count($lines), implode(", ", array_slice($lines, 0, 4)) . (count($lines) > 4 ? ", …" : "")) . $suffix);
        }
    }
    // conversions of other plugins for the words zigbee2mqtt sends
    $foreign = array();
    foreach (glob(LBHOMEDIR . "/config/plugins/*/mqtt_conversions.cfg") ?: array() as $file) {
        if (strpos($file, "/" . LBPPLUGINDIR . "/") !== false) {
            continue;
        }
        foreach (preg_split('/\r?\n/', (string) @file_get_contents($file)) as $line) {
            if (preg_match('/^\s*(online|offline)\s*=/i', $line)) {
                $foreign[] = basename(dirname($file)) . ": " . trim($line);
            }
        }
    }
    if ($foreign) {
        $rows[] = statusRow("Test.Gateway", "info", sprintf($L["Test.GatewayConversions"], implode(", ", $foreign)));
    }
    return $rows;
}

/**
 * 4.2.5 (Gruppe 38, V10/E1): one line per house family (tuer, klima, wetter,
 * alarm): off; or on, n names, heartbeat last s ago (status file of the
 * extension), conflicts; the last clearing (state and time). For tuer as
 * up to 4.2.4 also the names Matter2Lox gives as well.
 */
function hausRows($mqttcfg, $status)
{
    global $L, $hausNamesFile, $hausTopicsFile, $hausLeerenFile, $matter2loxHausNamesFile;
    $an = array("tuer" => is_enabled($mqttcfg->hausTopics), "klima" => is_enabled($mqttcfg->hausKlima),
        "wetter" => is_enabled($mqttcfg->hausWetter), "alarm" => is_enabled($mqttcfg->hausAlarm));
    $merk = zng_read_json($hausTopicsFile, array());
    $merk = is_array($merk) ? $merk : array();
    $leeren = zng_read_json($hausLeerenFile, array());
    $leeren = is_array($leeren) ? $leeren : array();
    $conflicts = isset($status["conflicts"]) ? (array) $status["conflicts"] : array();
    $familienLaufen = isset($status["familien"]) ? (array) $status["familien"] : array();
    $hb = isset($status["heartbeat"]["ts"]) && is_numeric($status["heartbeat"]["ts"]) ? (int) $status["heartbeat"]["ts"] : 0;
    $rows = array();
    foreach ($an as $familie => $ein) {
        $label = sprintf(zng_t("Test.HausFamilie"), $familie);
        $teile = array();
        if (isset($leeren[$familie]["lage"], $leeren[$familie]["zeit"])) {
            $letzt = sprintf(zng_t("Test.HausLeeren"), (string) $leeren[$familie]["lage"], date("d.m.Y H:i:s", (int) $leeren[$familie]["zeit"]));
        } else {
            $letzt = "";
        }
        if (!$ein) {
            $offen = 0;
            foreach (array_keys($merk) as $t) {
                $offen += strpos((string) $t, "haus/" . $familie . "/") === 0 ? 1 : 0;
            }
            $text = zng_t("Test.HausAus") . ($offen ? " – " . sprintf(zng_t("Test.HausNochGemerkt"), $offen) : "") . ($letzt !== "" ? " – " . $letzt : "");
            $rows[] = statusRow($label, $offen ? "hint" : "info", $text);
            continue;
        }
        $namen = array();
        foreach (array_keys($merk) as $t) {
            $x = explode("/", (string) $t);
            if (count($x) === 4 && $x[1] === $familie && strpos((string) $merk[$t], "-") !== 0) {
                $namen[$x[2]] = true;
            }
        }
        $state = "ok";
        $teile[] = sprintf(zng_t("Test.HausAn"), count($namen), implode(", ", array_slice(array_keys($namen), 0, 8)));
        if (!in_array($familie, $familienLaufen, true) || $hb === 0) {
            $state = "hint";
            $teile[] = zng_t("Test.HausNochNichtGeladen");
        } else {
            $alter = time() - $hb;
            $teile[] = sprintf(zng_t("Test.HausLebenszeichen"), $alter);
            if ($alter > 150) {
                $state = "fail";
            }
        }
        $eigene = array_values(array_filter($conflicts, function ($c) use ($familie) {
            return strpos((string) $c, "haus/" . $familie . "/") === 0;
        }));
        if ($eigene) {
            $state = $state === "fail" ? "fail" : "hint";
            $teile[] = sprintf($L["Test.HausConflict"], implode(", ", $eigene));
        }
        if ($familie === "tuer") {
            $names = array_values(zng_read_json($hausNamesFile, array()));
            $matter = zng_read_json($matter2loxHausNamesFile, array());
            $matterNames = isset($matter["namen"]) && is_array($matter["namen"]) ? array_values($matter["namen"]) : array();
            $both = array_values(array_intersect(array_keys($namen), $matterNames));
            if ($both) {
                $state = $state === "fail" ? "fail" : "hint";
                $teile[] = sprintf($L["Test.HausMatter"], implode(", ", $both));
            }
        }
        if ($letzt !== "") {
            $teile[] = $letzt;
        }
        $rows[] = statusRow($label, $state, implode(" – ", $teile));
    }
    return $rows;
}

/**
 * 4.2.5 (E1, P-E1-5): the field table of the extension (HAUS_FELDER) and of
 * PHP (zng_haus_felder()) - two texts of two files, compared word for word.
 */
function hausFelderRow()
{
    list($gleich, $nPhp, $nMjs) = zng_haus_felder_gleich(LBPBINDIR . "/zigbee2mqttng.php", LBPBINDIR . "/zigbee2mqttng_extension.mjs");
    return statusRow("Test.HausFelder", $gleich ? "ok" : "fail",
        sprintf(zng_t($gleich ? "Test.HausFelderOk" : "Test.HausFelderFehl"), $nPhp, $nMjs));
}

/** 4.2.5 (E4): devices with OTA, with an update (names up to 5), age of the file */
function firmwareRow($devices)
{
    global $otaFile;
    $mitOta = 0;
    foreach (zng_state_devices($devices) as $d) {
        $mitOta += zng_supports_ota($d) ? 1 : 0;
    }
    $ota = zng_read_json($otaFile, array());
    $ota = is_array($ota) ? $ota : array();
    $verfuegbar = array();
    foreach ($ota as $name => $o) {
        if (is_array($o) && isset($o["state"]) && in_array($o["state"], array("available", "scheduled"), true)) {
            $verfuegbar[] = (string) $name;
        }
    }
    $alter = is_file($otaFile) ? sprintf(zng_t("Test.FirmwareAlter"), date("d.m.Y H:i", (int) filemtime($otaFile))) : zng_t("Test.FirmwareKeineDatei");
    $text = sprintf(zng_t("Test.FirmwareText"), $mitOta, count($verfuegbar))
        . ($verfuegbar ? ": " . implode(", ", array_slice($verfuegbar, 0, 5)) . (count($verfuegbar) > 5 ? ", …" : "") : "") . " – " . $alter;
    return statusRow("Test.Firmware", $verfuegbar ? "hint" : "info", $text);
}

/** 4.2.5 (V1): does the second copy carry GENERATE, is there coordinator_backup.json - yes/no only */
function netzschutzRow()
{
    $n = zng_netzschutz_lage();
    $z = $n["zweitschrift"] === null ? zng_t("Test.NetzschutzUnbekannt") : zng_t($n["zweitschrift"] ? "Test.Ja" : "Test.Nein");
    $text = sprintf(zng_t("Test.NetzschutzText"), $z, zng_t($n["backup"] ? "Test.Ja" : "Test.Nein"));
    $state = $n["zweitschrift"] === true && $n["backup"] ? "fail" : ($n["zweitschrift"] === true ? "hint" : "ok");
    return statusRow("Test.Netzschutz", $state, $text);
}

/**
 * 4.2.5 (E4): every kind of template (in, inhttp, out) for all devices,
 * built here: well-formed (simplexml, without it the fallback below) and CRLF
 * in every line. "in" without the UDP port of the gateway is built with port 1
 * - the template is the same apart from the port.
 */
function vorlagenCrlfRow()
{
    global $bridgeDevicesFile;
    $mqttcfg = MqttConfig::load();
    $ios = zng_device_ios($mqttcfg->topic, zng_read_json($bridgeDevicesFile, array()), is_enabled(ServiceConfig::load()->availability));
    $in = zng_lebenszeichen_ios($mqttcfg->topic);
    $out = array();
    foreach ($ios as $d) {
        $in = array_merge($in, $d["inputs"]);
        $out = array_merge($out, $d["outputs"]);
    }
    $gw = zng_gateway_info();
    $xml = array(
        "in" => zng_xml_virtual_in_udp("Zigbee2MqttNG", $gw["udpport"] > 0 ? $gw["udpport"] : 1, $in, ""),
        "inhttp" => zng_xml_virtual_in_http("Zigbee2MqttNG", $in, ""),
        "out" => zng_xml_virtual_out("Zigbee2MqttNG", "/dev/udp/127.0.0.1/" . (int) $gw["udpinport"], $out, ""),
    );
    $fehler = array();
    foreach ($xml as $art => $x) {
        if (!zng_xml_wohlgeformt($x)) {
            $fehler[] = $art . ": XML";
        }
        $zeilen = substr_count($x, "\n");
        if ($zeilen === 0 || substr_count($x, "\r\n") !== $zeilen) {
            $fehler[] = $art . ": " . zng_t("Test.VorlageKeinCrlf");
        }
    }
    return $fehler ? statusRow("Test.VorlageCrlf", "fail", implode("; ", $fehler))
        : statusRow("Test.VorlageCrlf", "ok", zng_t("Test.VorlageCrlfOk"));
}

/** well-formed XML: simplexml where it is loaded, otherwise a plain check of the tags (fallback) */
function zng_xml_wohlgeformt($x)
{
    if (function_exists("simplexml_load_string")) {
        $alt = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($x);
        libxml_clear_errors();
        libxml_use_internal_errors($alt);
        return $doc !== false;
    }
    $rumpf = preg_replace('/^<\?xml[^>]*\?>\s*/', '', $x);
    $stapel = array();
    if (!preg_match_all('#<(/?)([A-Za-z][A-Za-z0-9]*)((?:\s+[A-Za-z]+="[^"<]*")*)\s*(/?)>#', $rumpf, $m, PREG_SET_ORDER)) {
        return false;
    }
    foreach ($m as $t) {
        if ($t[1] === "/") {
            if (array_pop($stapel) !== $t[2]) {
                return false;
            }
        } elseif ($t[4] !== "/") {
            $stapel[] = $t[2];
        }
    }
    return $stapel === array() && strpos(preg_replace('#<[^>]*>#', '', $rumpf), '<') === false;
}

/** 4.2.5 (E2): the Signal way - off / on, SignalBot installed yes/no, last answer (time, result) */
function signalRow($m)
{
    global $signalStatusFile;
    if (empty($m["signalEin"])) {
        return statusRow("Test.Signal", "info", zng_t("Test.SignalAus"));
    }
    $da = function_exists("zng_plugin_installiert") ? zng_plugin_installiert("signalbot") : null;
    $teile = array(sprintf(zng_t("Test.SignalAn"), (string) $m["signalPraefix"]),
        zng_t($da === true ? "Test.SignalBotDa" : ($da === false ? "Test.SignalBotFehlt" : "Test.SignalBotUnbekannt")));
    $q = zng_read_json($signalStatusFile, null);
    $state = $da === false ? "fail" : "info";
    if (is_array($q) && isset($q["lage"], $q["zeit"])) {
        $teile[] = sprintf(zng_t("Test.SignalLetzte"), date("d.m.Y H:i:s", (int) $q["zeit"]), (string) $q["lage"]
            . (isset($q["grund"]) && $q["grund"] !== "" ? ": " . (string) $q["grund"] : ""));
        $state = $q["lage"] === "angenommen" ? "ok" : ($state === "fail" ? "fail" : "hint");
    } else {
        $teile[] = zng_t("Test.SignalNie");
    }
    return statusRow("Test.Signal", $state, implode(" – ", $teile));
}

/** 4.2.5 (E2): the line of the speech module (per target with 1.2.0) */
function spracheRows($m)
{
    if (empty($m["spracheEin"])) {
        return array(statusRow("Test.Sprache", "info", zng_t("Test.SpracheAus")));
    }
    if (!function_exists("ansage_pruefzeile")) {
        require_once __DIR__ . "/sprachausgabe.php";
    }
    list($stand, $text) = ansage_pruefzeile(is_array($m["tts"]) ? $m["tts"] : array(), true, zng_ansage_k());
    $state = $stand === 1 ? "ok" : ($stand === 0 ? "fail" : "info");
    // the module masks its text itself; the table masks again - so it is unmasked here once
    return array(statusRow("Test.Sprache", $state, html_entity_decode(strip_tags((string) $text), ENT_QUOTES, "UTF-8")));
}

/** 4.2.5 (V9, T4-6): "Fassung dieses Plugins" - from the plugin database, asked by the folder name */
function fassungRow()
{
    $v = class_exists("LBSystem") ? LBSystem::pluginversion(basename(__DIR__)) : null;
    return statusRow("Test.Fassung", is_string($v) && $v !== "" ? "info" : "hint", is_string($v) && $v !== "" ? $v : zng_t("Test.FassungUnbekannt"));
}
