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
        if (MqttConfig::lage() === "kaputt" || ServiceConfig::lage() === "kaputt" || zng_meldungen_lage() === "kaputt") {
            return null;
        }
        // 4.2.5 (Gruppe 38, E2): the notifications too - without the speech
        // tokens (the module never puts them into a backup)
        list($meldungen) = zng_meldungen_laden();
        self::modul();
        $meldungen["tts"] = ansage_sicherung_bereinigen(is_array($meldungen["tts"]) ? $meldungen["tts"] : array());
        return array(
            "_hinweis" => "Zigbee2MqttNG - Einstellungen (mqtt.json, service.json, meldungen.json). Enthaelt das Kennwort des MQTT-Brokers und das Token der Zigbee2mqtt UI: wie ein Kennwort aufbewahren. Das Zigbee-Netz selbst (Netzwerkschluessel, Geraete) steckt im ZIP von \"Sicherung herunterladen\".",
            "_stand" => date("Y-m-d H:i:s"),
            "_plugin" => "zigbee2mqttng",
            "mqtt" => MqttConfig::load()->toJsonDaten(),
            "service" => get_object_vars(ServiceConfig::load()),
            "meldungen" => $meldungen,
        );
    }

    /** the speech module (sprachausgabe.php 1.2.1 since 4.2.6, shared with other lines) */
    private static function modul()
    {
        if (!function_exists("ansage_sicherung_bereinigen")) {
            require_once LBPHTMLAUTHDIR . "/sprachausgabe.php";
        }
    }

    /**
     * 4.2.5 (E2): checks the part "meldungen". Returns array(complaints, new
     * meldungen, number of keys missing in the file). The speech block is
     * checked by the module; a block that carries a speech token is refused
     * (it does not come from "Einstellungen sichern"), the current tokens stay.
     */
    private static function meldungenPruefen($werte)
    {
        list($aktuell) = zng_meldungen_laden();
        $fehler = array();
        $unbekannt = array_diff(array_map("strval", array_keys($werte)), array_keys($aktuell));
        if ($unbekannt) {
            return array(array("Sicherung.FremderSchluessel: meldungen." . implode(", meldungen.", $unbekannt)), $aktuell, 0);
        }
        list($f, $neu) = zng_meldungen_pruefen($werte, $aktuell);
        foreach ($f as $x) {
            $fehler[] = $x[0] . ": meldungen." . $x[1];
        }
        if (array_key_exists("tts", $werte)) {
            self::modul();
            $tts = $werte["tts"];
            if (!is_array($tts)) {
                $fehler[] = "Sicherung.TeilKeinObjekt: meldungen.tts";
            } else {
                foreach (ansage_sicherung_mangel($tts) as $n) {
                    $fehler[] = "Sicherung.Token: meldungen." . $n;
                }
                $ziele = array_key_exists("ziele", $tts) ? $tts["ziele"] : null;
                unset($tts["ziele"]);
                $g = "";
                if ($tts && ansage_wert_pruefen(ansage_sicherung_bereinigen($tts), $g) === null) {
                    $fehler[] = "Sicherung.Sprache: meldungen.tts (" . $g . ")";
                }
                if ($ziele !== null && $ziele !== array() && ansage_ziele_pruefen($ziele, $g) === null) {
                    $fehler[] = "Sicherung.Sprache: meldungen.tts.ziele (" . $g . ")";
                }
                if (!$fehler) {
                    $jetzt = is_array($aktuell["tts"]) ? $aktuell["tts"] : array();
                    $block = ansage_sicherung_tokens_behalten(array_merge(ansage_sicherung_bereinigen($tts),
                        $ziele !== null ? array("ziele" => $ziele) : array()), $jetzt);
                    if (function_exists("ansage_sicherung_spaet")) {
                        list($block) = ansage_sicherung_spaet($block, $jetzt);
                    }
                    if (isset($block["ziele"]) && $block["ziele"] === array()) {
                        unset($block["ziele"]);
                    }
                    $neu["tts"] = $block;
                }
            }
        }
        return array($fehler, $neu, count(array_diff_key($aktuell, $werte)));
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
            if (!is_string($key) || (!isset(self::TEILE[$key]) && $key !== "meldungen")) {
                $fremd[] = (string) $key;
            }
        }
        if ($fremd) {
            $fehler[] = "Sicherung.Fremd: " . implode(", ", $fremd);
        }
        $teile = array_intersect_key($daten, self::TEILE);
        if (!$teile && !array_key_exists("meldungen", $daten)) {
            $fehler[] = "Sicherung.Leer";
        }
        $neu = array();
        $info = array("fehlend" => 0, "teile" => array());
        // 4.2.5 (E2): meldungen.json; a backup of 4.2.4 has no such part - then
        // the notifications stay as they are (from notifyOffline/notifyBattery
        // of its service part, see zng_meldungen_laden())
        if (array_key_exists("meldungen", $daten)) {
            if (!is_array($daten["meldungen"]) || ($daten["meldungen"] && array_keys($daten["meldungen"]) === range(0, count($daten["meldungen"]) - 1))) {
                $fehler[] = "Sicherung.TeilKeinObjekt: meldungen";
            } else {
                list($mf, $mneu, $mfehlend) = self::meldungenPruefen($daten["meldungen"]);
                $fehler = array_merge($fehler, $mf);
                $neu["meldungen"] = $mneu;
                $info["fehlend"] += $mfehlend;
                $info["teile"][] = "meldungen";
            }
        }
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
        global $meldungenFile;
        $dateien = array("mqtt" => LBPCONFIGDIR . "/mqtt.json", "service" => LBPCONFIGDIR . "/service.json", "meldungen" => $meldungenFile);
        $vorher = array();
        foreach ($neu as $teil => $werte) {
            if ($teil === "meldungen") {
                $vorher[$teil] = is_file($dateien[$teil]) ? @file_get_contents($dateien[$teil]) : false;
                if (!zng_meldungen_speichern($werte)) {
                    foreach ($vorher as $t => $raw) {
                        if ($t !== $teil && is_string($raw)) {
                            zng_schreiben_atomar($dateien[$t], $raw, ZNG_MODUS_GEHEIM);
                        }
                    }
                    return false;
                }
                continue;
            }
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
