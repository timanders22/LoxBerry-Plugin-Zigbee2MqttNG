<?php
require_once "loxberry_system.php";
require_once "loxberry_log.php";
require_once "loxberry_io.php";
require_once LBPBINDIR . "/defines.php";
require_once LBPBINDIR . "/zigbee2mqttng.php";


$log = LBLog::newLog(["name" => "Service"]);

LOGSTART("Update encryption");

// 4.1.1 (B2): only a readable configuration.yaml gets the GENERATE values;
// an unreadable one is never written anew (it would lose the network)
list($lage, $zigbee2mqttConfig) = zng_yaml_lesen($serviceConfigFile, zng_zweitschrift_pfad(zng_konfig_ordner(), "configuration.yaml"));
if ($lage !== "ok") {
    LOGERR("configuration.yaml is not readable ($lage) - encryption key not set, nothing written");
    LOGEND("Update encryption failed");
    exit(3);
}

//autogenerate KEY
$zigbee2mqttConfig["advanced"]["network_key"] = "GENERATE";
// Own PAN ID and extended PAN ID instead of the zigbee2mqtt defaults, which
// every installation in the neighbourhood would share
$zigbee2mqttConfig["advanced"]["pan_id"] = "GENERATE";
$zigbee2mqttConfig["advanced"]["ext_pan_id"] = "GENERATE";


// 4.1.1 (B4): in one piece, 0600 - the file carries the network key
if (!zng_schreiben_atomar($serviceConfigFile, yaml_emit($zigbee2mqttConfig), ZNG_MODUS_GEHEIM)) {
    LOGERR("configuration.yaml could not be written - encryption key not set");
    LOGEND("Update encryption failed");
    exit(5);
}
// 4.2.5 (Gruppe 38, V1): the second copy written a moment ago by
// update-config.php has no network key yet - it must never heal the file
// once zigbee2mqtt formed the network. Set aside; the next start of
// zigbee2mqtt writes the second copy with the real values.
if (zng_zweitschrift_yaml_beiseite(zng_konfig_ordner())) {
    LOGINF("Second copy of configuration.yaml without network key set aside (" . zng_konfig_ordner() . ".alt.configuration.yaml)");
}
LOGOK("Update encryption successful");
LOGEND("Update encryption finished");
