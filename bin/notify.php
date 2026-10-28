<?php
/**
 * Sends the notifications for the events the bridge extension collected
 * within a minute (see bin/zigbee2mqttng_extension.mjs); an alarm comes at
 * once, alone.
 *
 * Usage: php notify.php offline:<device> battery:<device>:<level>
 *                       alarm:<device>:<art> update:<device>:<version> ...
 *
 * 4.2.5 (Gruppe 38, E2): the ways come from config/meldungen.json (Settings
 * tab, section "Notifications"), occasion x way - see
 * zng_meldungen_versenden() in include/Z2mBridge.php: LoxBerry notification,
 * SignalBot (its way "messages of other plugins", answer read for at most
 * 5 s) and the shared speech module 1.2.0 (not in the quiet time - except an
 * alarm). At most 10 Signal messages and 10 announcements per hour.
 */
require_once "loxberry_system.php";
require_once "loxberry_log.php";
require_once "loxberry_io.php";
require_once LBPBINDIR . "/defines.php";
require_once LBPBINDIR . "/zigbee2mqttng.php";
require_once LBPHTMLAUTHDIR . "/model/MqttConfig.php";
require_once LBPHTMLAUTHDIR . "/include/Z2mBridge.php";

$log = LBLog::newLog(["name" => "Service"]);

$anlaesse = zng_meldung_ereignisse(array_slice($argv, 1));
if (!$anlaesse) {
    exit(0);
}
list($meldungen, $mlage) = zng_meldungen_laden();
LOGSTART("Notifications");
$k = null;
if (!empty($meldungen["spracheEin"]) && is_file(LBPHTMLAUTHDIR . "/sprachausgabe.php")) {
    require_once LBPHTMLAUTHDIR . "/sprachausgabe.php";
    $k = zng_ansage_k();
}
zng_meldungen_versenden($anlaesse, $meldungen, $mlage, date("H:i"), $k, zng_broker_credentials(MqttConfig::load()));
LOGEND("Notifications");
exit(0);
