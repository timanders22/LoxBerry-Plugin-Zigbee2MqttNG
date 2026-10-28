<?php
/**
 * 4.2.5 (Gruppe 38, V10): removes the retained house topics of this plugin
 * WITH read back - the replacement of clear-haus-topics.php (UDP input of the
 * gateway, no read back, "Removed n topic(s)" without knowing it).
 *
 *   php haus-leeren.php <LBHOMEDIR> [tuer|klima|wetter|alarm|alle] [--nur <name>,...]
 *
 * Own topics only: the entries of data/plugins/<folder>/zigbee2mqttng_haus.json
 * (topic => IEEE) - never haus/#, never a foreign topic. Connection through
 * ZngMqtt (include/Z2mBridge.php) with zng_broker_credentials(): the broker
 * of the MQTT gateway or the own broker. The password goes only into the
 * CONNECT packet, never into an argument.
 * Prints one installer line per family (<OK>, <INFO>, <WARNING>), exit code
 * always 0 so that an uninstall goes on.
 * Called by uninstall/uninstall (alle). ajax.php uses the same function
 * zng_haus_leeren() directly after saving the MQTT tab.
 */
$home = isset($argv[1]) ? rtrim((string) $argv[1], "/") : "";
if ($home !== "" && (getenv("LBHOMEDIR") === false || getenv("LBHOMEDIR") === "")) {
    putenv("LBHOMEDIR=" . $home);
}
require_once "loxberry_system.php";
require_once "loxberry_io.php";
require_once LBPBINDIR . "/defines.php";
require_once LBPBINDIR . "/zigbee2mqttng.php";
require_once LBPHTMLAUTHDIR . "/model/MqttConfig.php";
require_once LBPHTMLAUTHDIR . "/include/Z2mBridge.php";

$wahl = isset($argv[2]) ? (string) $argv[2] : "alle";
$alle = array("tuer", "klima", "wetter", "alarm");
if ($wahl === "alle") {
    $familien = $alle;
} elseif (in_array($wahl, $alle, true)) {
    $familien = array($wahl);
} else {
    echo "<WARNING> haus-leeren.php: unknown family '" . preg_replace('/[^a-z0-9_\-]/', '?', $wahl) . "' - nothing done\n";
    exit(0);
}
$nur = array();
for ($i = 3; $i < count($argv); $i++) {
    if ($argv[$i] === "--nur" && isset($argv[$i + 1])) {
        foreach (explode(",", (string) $argv[$i + 1]) as $n) {
            if (preg_match('/^[a-z0-9_\-]{1,60}\z/', $n)) {
                $nur[] = $n;
            }
        }
        $i++;
    }
}

$creds = zng_broker_credentials(MqttConfig::load());
foreach ($familien as $familie) {
    $e = zng_haus_leeren($creds, $hausTopicsFile, array($familie), $nur);
    $wer = "haus/" . $familie . ($nur ? " (" . implode(", ", $nur) . ")" : "");
    if ($e["lage"] === "geleert") {
        echo "<OK> $wer: geleert (vom Broker bestätigt, " . $e["themen"] . " Themen)\n";
    } elseif ($e["lage"] === "nichts" && $e["nie"]) {
        echo "<INFO> $wer: nie Anbieter – nichts zu leeren\n";
    } elseif ($e["lage"] === "nichts") {
        echo "<INFO> $wer: nichts zu leeren (der Broker hält keines der " . $e["themen"] . " Themen zurück)\n";
    } elseif ($e["lage"] === "rest") {
        echo "<WARNING> $wer: noch zurückbehalten – bitte von Hand leeren: " . implode(", ", $e["offen"]) . " (mosquitto_pub -r -n -t <thema>)\n";
    } else {
        echo "<WARNING> $wer: Broker nicht erreichbar (" . $e["grund"] . ") – bitte von Hand leeren, die Themen stehen in " . $hausTopicsFile . "\n";
    }
}
exit(0);
