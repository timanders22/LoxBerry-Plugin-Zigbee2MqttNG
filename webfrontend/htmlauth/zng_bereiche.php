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
    echo $twig->render('ui.html', array("port" => $port, "service" => $serviceCfg));
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
    ));
}

/** Reiter Einbindung in Loxone (bis 4.1.1 loxone.php) */
function zng_bereich_loxone($twig)
{
    global $bridgeDevicesFile, $mqttGatewaySubscriptionFile;
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
    ));
}

/**
 * Reiter Test (bis 4.1.1 test.php). Die Bruecke zum Broker (phpMQTT) wird
 * nur hier geladen: nur dieser Reiter fragt den Broker.
 */
function zng_bereich_test($twig)
{
    require_once __DIR__ . '/include/Z2mBridge.php';
    echo $twig->render('test.html', array("rows" => statusRows(), "time" => date("H:i:s")));
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
    global $bridgeDevicesFile, $availabilityFile;
    $result = array("devices" => array(), "stateTime" => "", "showLastSeen" => false);

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
        $result["devices"][] = array(
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
    if (is_array($devices)) {
        $rows = array_merge($rows, deviceRows($devices, dirname($serviceConfigFile) . "/state.json", (int) $serviceCfg->batteryThreshold));
        $rows[] = actionsRow($mqttcfg->topic, $devices);
    }
    $rows = array_merge($rows, gatewayRows($mqttcfg));
    $rows[] = hausRow($mqttcfg);
    // 4.1.1
    $rows[] = heartbeatRow($status, $service["pid"]);
    $rows = array_merge($rows, konfigRows());
    $rows[] = vorlagenRow();
    // 4.2.0: Reiterleiste, Bereiche und Positivliste in index.php
    $rows[] = zng_reiterprobe(__DIR__ . "/index.php");
    return $rows;
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
 * House topics: fixed names, topics another provider owns, names that
 * Matter2Lox gives as well
 */
function hausRow($mqttcfg)
{
    global $L, $hausNamesFile, $bridgeStatusFile, $matter2loxHausNamesFile;
    if (!is_enabled($mqttcfg->hausTopics)) {
        return statusRow("Test.Haus", "info", $L["Test.HausOff"]);
    }
    $names = array_values(zng_read_json($hausNamesFile, array()));
    $status = zng_read_json($bridgeStatusFile, array());
    $conflicts = isset($status["conflicts"]) ? (array) $status["conflicts"] : array();
    $matter = zng_read_json($matter2loxHausNamesFile, array());
    $matterNames = isset($matter["namen"]) && is_array($matter["namen"]) ? array_values($matter["namen"]) : array();
    $both = array_values(array_intersect($names, $matterNames));
    $parts = array(sprintf($L["Test.HausNames"], count($names), implode(", ", $names)));
    $state = "ok";
    if ($conflicts) {
        $state = "hint";
        $parts[] = sprintf($L["Test.HausConflict"], implode(", ", $conflicts));
    }
    if ($both) {
        $state = "hint";
        $parts[] = sprintf($L["Test.HausMatter"], implode(", ", $both));
    }
    return statusRow("Test.Haus", $state, implode(" - ", $parts));
}
