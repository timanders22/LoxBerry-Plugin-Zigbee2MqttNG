<?php
/**
 * 4.1.1 (B3, Q5): "Einstellungen sichern" / "Einstellungen zurückspielen".
 *
 * The backup holds every key of mqtt.json and service.json - including the
 * broker password and the token of the zigbee2mqtt UI: without them a
 * backup is worthless after moving to a second LoxBerry (CLAUDE.md 9). The
 * text at the button says to keep it like a password. The Zigbee network
 * itself (network key, device database, coordinator backup) is in the ZIP
 * that zigbee2mqtt builds ("Sicherung herunterladen").
 *
 * Restoring (house pattern WOLF ISM NG 3.0.8, Regeln/05):
 *  - at most 64 kB, a JSON object;
 *  - keys starting with "_" are the readable head and are skipped;
 *  - anything else than "mqtt" and "service", and any key inside them that
 *    the plugin does not know, is rejected before anything is merged;
 *  - every value passes the same check as the form (MqttConfig::wert,
 *    ServiceConfig::wert); all complaints are collected;
 *  - a half valid file changes nothing; a key missing in the file keeps its
 *    current value (the reply counts them);
 *  - both files are written in one piece; if the second one fails, the
 *    first is put back.
 * Expects defines.php, zigbee2mqttng.php and both config classes.
 */
class Sicherung
{
    const MAX_BYTE = 65536;

    /** part of the file => class of the configuration */
    const TEILE = array("mqtt" => "MqttConfig", "service" => "ServiceConfig");

    /**
     * Content of the backup file as array, or null if a configuration file
     * is unreadable right now (then the backup would hold defaults).
     */
    public static function inhalt()
    {
        if (MqttConfig::lage() === "kaputt" || ServiceConfig::lage() === "kaputt") {
            return null;
        }
        return array(
            "_hinweis" => "Zigbee2MqttNG - Einstellungen (mqtt.json, service.json). Enthaelt das Kennwort des MQTT-Brokers und das Token der Zigbee2mqtt UI: wie ein Kennwort aufbewahren. Das Zigbee-Netz selbst (Netzwerkschluessel, Geraete) steckt im ZIP von \"Sicherung herunterladen\".",
            "_stand" => date("Y-m-d H:i:s"),
            "_plugin" => "zigbee2mqttng",
            "mqtt" => get_object_vars(MqttConfig::load()),
            "service" => get_object_vars(ServiceConfig::load()),
        );
    }

    /**
     * Checks a backup file. Returns array(complaints, new values per part,
     * info). Complaints are language keys, optionally with ": detail"; an
     * empty list means the file can be written as it is.
     */
    public static function pruefen($roh)
    {
        if (!is_string($roh) || $roh === "" || strlen($roh) > self::MAX_BYTE) {
            return array(array("Sicherung.Groesse"), array(), array());
        }
        $daten = json_decode($roh, true);
        if (!is_array($daten) || substr(ltrim($roh), 0, 1) !== "{") {
            return array(array("Sicherung.KeinJson"), array(), array());
        }
        $fehler = array();
        $fremd = array();
        foreach (array_keys($daten) as $key) {
            if (is_string($key) && $key !== "" && $key[0] === "_") {
                continue;   // readable head: skipped, not rejected
            }
            if (!is_string($key) || !isset(self::TEILE[$key])) {
                $fremd[] = (string) $key;
            }
        }
        if ($fremd) {
            $fehler[] = "Sicherung.Fremd: " . implode(", ", $fremd);
        }
        $teile = array_intersect_key($daten, self::TEILE);
        if (!$teile) {
            $fehler[] = "Sicherung.Leer";
        }
        $neu = array();
        $info = array("fehlend" => 0, "teile" => array());
        foreach ($teile as $teil => $werte) {
            $klasse = self::TEILE[$teil];
            if (!is_array($werte) || ($werte && array_keys($werte) === range(0, count($werte) - 1))) {
                $fehler[] = "Sicherung.TeilKeinObjekt: " . $teil;
                continue;
            }
            $aktuell = get_object_vars($klasse::load());
            $unbekannt = array_diff(array_map("strval", array_keys($werte)), array_keys($aktuell));
            if ($unbekannt) {
                $fehler[] = "Sicherung.FremderSchluessel: " . $teil . "." . implode(", " . $teil . ".", $unbekannt);
                continue;
            }
            $zusammen = array_merge($aktuell, $werte);
            foreach ($werte as $key => $value) {
                list($ok, $result) = $klasse::wert($key, $value, $zusammen);
                if ($ok) {
                    $zusammen[$key] = $result;
                } else {
                    $fehler[] = $result . ": " . $teil . "." . $key;
                }
            }
            $info["fehlend"] += count(array_diff_key($aktuell, $werte));
            $info["teile"][] = $teil;
            $neu[$teil] = $zusammen;
        }
        return array($fehler, $fehler ? array() : $neu, $info);
    }

    /**
     * Writes the checked values (from pruefen()). All or nothing: if a file
     * cannot be written, the ones already written are put back.
     * Returns true on success.
     */
    public static function schreiben($neu)
    {
        $dateien = array("mqtt" => LBPCONFIGDIR . "/mqtt.json", "service" => LBPCONFIGDIR . "/service.json");
        $vorher = array();
        foreach ($neu as $teil => $werte) {
            $klasse = self::TEILE[$teil];
            $objekt = new $klasse();
            foreach ($werte as $key => $value) {
                $objekt->{$key} = $value;
            }
            $vorher[$teil] = is_file($dateien[$teil]) ? @file_get_contents($dateien[$teil]) : false;
            if (!$objekt->save()) {
                foreach ($vorher as $t => $raw) {
                    if ($t !== $teil && is_string($raw)) {
                        zng_schreiben_atomar($dateien[$t], $raw, ZNG_MODUS_GEHEIM);
                    }
                }
                return false;
            }
        }
        return true;
    }
}
