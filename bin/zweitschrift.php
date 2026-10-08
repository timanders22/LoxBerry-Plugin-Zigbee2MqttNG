<?php
/**
 * 4.1.1 (B3, B5): second copies for the install hooks, without LoxBerry
 * libraries (preupgrade.sh calls it from the new archive, before the
 * installer removes the plugin folders).
 *
 *   php zweitschrift.php ziehen   <config dir> <data dir>
 *       mqtt.json, service.json, configuration.yaml and
 *       coordinator_backup.json to <config dir>.backup.<name> (0600) - each
 *       only when it is readable and carries content; an unreadable file
 *       never replaces a good second copy.
 *   php zweitschrift.php beiseite <config dir>
 *       fresh install: second copies left by an earlier installation are
 *       renamed to <config dir>.alt.<name> and NOT used (decision of
 *       29.09.2026, Bauart F). The uninstall removes both.
 *
 * Prints installer lines (<OK>, <INFO>, <WARNING>). Exit code 0, or 1 if a
 * second copy could not be written.
 */
require_once __DIR__ . "/zigbee2mqttng.php";

$befehl = isset($argv[1]) ? $argv[1] : "";
$konfig = isset($argv[2]) ? rtrim($argv[2], "/") : "";
if ($konfig === "" || !is_dir($konfig)) {
    echo "<WARNING> Second copies: config folder '$konfig' not found - nothing done\n";
    exit(1);
}

if ($befehl === "ziehen") {
    $daten = isset($argv[3]) ? rtrim($argv[3], "/") : "";
    $rc = 0;
    foreach (array(
        "mqtt.json" => array($konfig . "/mqtt.json", "json"),
        "service.json" => array($konfig . "/service.json", "json"),
        "configuration.yaml" => array($daten . "/configuration.yaml", "yaml"),
        "coordinator_backup.json" => array($daten . "/coordinator_backup.json", "json"),
    ) as $name => $d) {
        if (!is_file($d[0])) {
            continue;
        }
        $ergebnis = zng_zweitschrift_ziehen($d[0], $d[1], $konfig, $name);
        $ziel = zng_zweitschrift_pfad($konfig, $name);
        if ($ergebnis === "gezogen") {
            echo "<OK> Second copy of $name written: $ziel\n";
        } elseif ($ergebnis === "gleich") {
            echo "<INFO> Second copy of $name is up to date\n";
        } elseif ($ergebnis === "ohne_inhalt") {
            echo "<WARNING> $name is not readable or empty - its second copy was left unchanged" . (is_file($ziel) ? " ($ziel)" : "") . "\n";
        } else {
            echo "<WARNING> Second copy of $name could not be written: $ziel\n";
            $rc = 1;
        }
    }
    exit($rc);
}

if ($befehl === "beiseite") {
    $n = 0;
    foreach (glob($konfig . ".backup.*") ?: array() as $datei) {
        $ziel = $konfig . ".alt." . substr($datei, strlen($konfig . ".backup."));
        if (@rename($datei, $ziel)) {
            @chmod($ziel, ZNG_MODUS_GEHEIM);
            $n++;
        }
    }
    if ($n > 0) {
        echo "<INFO> Fresh installation: $n second cop" . ($n == 1 ? "y" : "ies") . " of an earlier installation set aside as " . $konfig . ".alt.* (not used)\n";
    }
    exit(0);
}

echo "<WARNING> zweitschrift.php: unknown command '$befehl'\n";
exit(1);
