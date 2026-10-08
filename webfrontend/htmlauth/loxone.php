<?php
require_once 'include/plugin.php';
require_once 'model/ServiceConfig.php';
require_once 'model/MqttConfig.php';
require_once LBPBINDIR . '/zigbee2mqttng.php';

$twig = Plugin::initializeTwig();

// Include header and set page as active
Plugin::createHeader(Plugin::LOXONE);

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

//creates the footer
LBWeb::lbfooter();
