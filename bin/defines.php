<?php

$mqttconfigfile = LBPCONFIGDIR . "/mqtt.json";
$configfile = LBPCONFIGDIR . "/service.json";
$serviceConfigFile = LBPDATADIR . "/configuration.yaml";
$deviceDataFile = LBPDATADIR . "/devices.yaml";
$mqttGatewaySubscriptionFile = LBPCONFIGDIR . "/mqtt_subscriptions.cfg";
$mqttGatewayResetFile = LBPCONFIGDIR . "/mqtt_resetaftersend.cfg";
$mqttGatewayConversionFile = LBPCONFIGDIR . "/mqtt_conversions.cfg";
// Environment of the systemd unit (Z2M_ONBOARD_URL), see config/zigbee2mqttng.service
$serviceEnvFile = LBPCONFIGDIR . "/service.env";

// Zigbee2MqttNG runs its own service and installation folder, so it never
// collides with the original Zigbee2Mqtt plugin or its former name Zigbee2Lox
$serviceName = "zigbee2mqttng";
$installDir = "/opt/zigbee2mqttng";
// Plugins whose Zigbee network is taken over on a fresh install (see
// postroot.sh). Plugin folder => array(title, service).
$predecessorPlugins = array(
    "zigbee2lox" => array("title" => "Zigbee2Lox", "service" => "zigbee2lox"),
    "zigbee2mqtt" => array("title" => "Zigbee2Mqtt", "service" => "zigbee2mqtt"),
);

// Bridge between zigbee2mqtt and the plugin (see bin/zigbee2mqttng_extension.mjs)
$extensionSourceFile = LBPBINDIR . "/zigbee2mqttng_extension.mjs";
$extensionTargetFile = LBPDATADIR . "/external_extensions/zigbee2mqttng.mjs";
$bridgeConfigFile = LBPDATADIR . "/zigbee2mqttng.json";
$bridgeDevicesFile = LBPDATADIR . "/zigbee2mqttng_devices.json";
$bridgeGroupsFile = LBPDATADIR . "/zigbee2mqttng_groups.json";
$bridgeInfoFile = LBPDATADIR . "/zigbee2mqttng_info.json";
$hausTopicsFile = LBPDATADIR . "/zigbee2mqttng_haus.json";
$hausNamesFile = LBPDATADIR . "/zigbee2mqttng_haus_namen.json";
$bridgeStatusFile = LBPDATADIR . "/zigbee2mqttng_status.json";
$availabilityFile = LBPDATADIR . "/zigbee2mqttng_availability.json";
$notifyStateFile = LBPDATADIR . "/zigbee2mqttng_notify.json";
$notifyScript = LBPBINDIR . "/notify.php";
// 4.2.5 (Gruppe 38, E4): firmware state per device, written by the extension
$otaFile = LBPDATADIR . "/zigbee2mqttng_ota.json";
// 4.2.5 (E2): ways of the notifications (LoxBerry, SignalBot, speech), 0600 -
// the speech block may carry speech tokens; second copy next to the folder
$meldungenFile = LBPCONFIGDIR . "/meldungen.json";
// 4.2.5 (E2): at most 10 Signal messages and 10 announcements per hour (file under a lock)
$bremseFile = LBPDATADIR . "/zigbee2mqttng_bremse.json";
// 4.2.5 (E2): last answer of SignalBot (Test tab "Signal-Weg")
$signalStatusFile = LBPDATADIR . "/zigbee2mqttng_signal.json";
// 4.2.5 (Regeln/04): result of a POST for the following GET (0600, 120 s)
$einmalmeldungFile = LBPDATADIR . "/einmalmeldung.json";
// 4.2.5 (V10): last clearing of the house topics per family (Test tab)
$hausLeerenFile = LBPDATADIR . "/zigbee2mqttng_haus_leeren.json";
// 4.2.5 (Regeln/04): secret of the form token (0600)
$aktionstokenFile = LBPCONFIGDIR . "/aktionstoken";

// Matter2Lox keeps its fixed names below haus/tuer/ here (since 0.9.35)
$matter2loxHausNamesFile = LBHOMEDIR . "/config/plugins/matter2lox.haus_namen.json";

// Matter2Lox keeps the Thread dataset of its border router here
$matter2loxConfigFile = LBHOMEDIR . "/config/plugins/matter2lox/matter2lox.json";

$L = LBSystem::readlanguage("language.ini");
$navbar = array();
$htmlhead = '';
