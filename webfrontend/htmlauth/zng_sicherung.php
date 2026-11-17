<?php
/**
 * "Einstellungen sichern" / "Einstellungen zurückspielen" (4.1.1, B3, Q5).
 *
 * Since 4.2.14 the shared backup module builds and reads the file
 * (copy sicherung.php next to this file, module 1.2.8; order of the
 * owner of 12.11.2026: all plugins behave the same). This line only hands
 * over its description (zng_sicherung_beschreibung()) and its way of
 * writing (Sicherung::schreiben()). Up to 4.2.13 the class below read and
 * checked the file itself (Sicherung::pruefen() in model/Sicherung.php;
 * since 4.2.14 this file is zng_sicherung.php - on Windows a search for the
 * module copy sicherung.php also found model/Sicherung.php).
 *
 * The configuration consists of three files, each 0600 with its own second
 * copy next to the config folder (zng_zweitschrift_pfad()):
 *   mqtt.json, service.json, meldungen.json  ->  blocks mqtt, service, meldungen
 * The file: head _hinweis, _plugin = zigbee2mqttng (unchanged since 4.1.1),
 * _fassung, _stand; then the three blocks with every key, including the broker
 * password and the token of the zigbee2mqtt UI (without them a backup is
 * worthless after moving to a second LoxBerry, CLAUDE.md 9); without the
 * speech tokens and without the origin mark ansage_token_herkunft
 * ('nie_aus_datei', module 1.2.7: it never comes from a file and keeps its
 * current value). The Zigbee network itself (network key, devices) is in the
 * ZIP that zigbee2mqtt builds ("Sicherung herunterladen").
 *
 * Restoring - as in every line with the module:
 *  - the tick "Zurückspielen bestätigen", is_uploaded_file(), at most 256 kB;
 *  - a file without _plugin = zigbee2mqttng is refused as a whole (every
 *    version since 4.1.1 wrote it; 4.0.0 and 4.1.0 had no such button);
 *  - an unknown key in a block is dropped and named, never with its value
 *    (up to 4.2.13 the whole file was refused);
 *  - an empty text in the file does not overwrite a value that is set;
 *  - a key a backup of an older version does not carry keeps its current
 *    value and is named; the block meldungen (since 4.2.5) as a whole;
 *  - every value passes the same check as the form (MqttConfig::wert,
 *    ServiceConfig::wert, zng_meldungen_pruefen(), the speech module);
 *    a half valid file changes nothing, all complaints are collected;
 *  - a file that carries a speech token is refused; the current speech
 *    tokens stay;
 *  - a key of the current files that this version does not know is dropped
 *    and named;
 *  - the three files are written in one piece each; if one fails, the ones
 *    already written are put back (Sicherung::schreiben()); every second
 *    copy follows, and the module checks all three pairs.
 * Expects defines.php, zigbee2mqttng.php and both config classes.
 */
require_once __DIR__ . "/sicherung.php";

class Sicherung
{
    /**
     * Writes the values the module returns. All or nothing: if a file
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
            $klasse = $teil === "mqtt" ? "MqttConfig" : "ServiceConfig";
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

/** the speech module (sprachausgabe.php, shared with other lines) */
function zng_sicherung_modul()
{
    if (!function_exists("ansage_sicherung_bereinigen")) {
        require_once LBPHTMLAUTHDIR . "/sprachausgabe.php";
    }
}

/** A value as JSON reads it back (objects become arrays) - the form in which the module compares. */
function zng_sicherung_json_normal($w)
{
    $j = json_decode((string) json_encode($w), true);
    return $j === null ? array() : $j;
}

/** The defaults of the three files as blocks {mqtt, service, meldungen}. */
function zng_sicherung_vorgaben()
{
    return array(
        "mqtt" => zng_sicherung_json_normal((new MqttConfig())->toJsonDaten()),
        "service" => zng_sicherung_json_normal(get_object_vars(new ServiceConfig())),
        "meldungen" => zng_sicherung_json_normal(zng_meldungen_vorgaben()),
    );
}

/**
 * The current configuration as blocks {mqtt, service, meldungen} (callback
 * 'jetzt'): defaults and file content together - nothing is sorted out, so
 * the module can name a key of the current files that this version does not
 * know (MqttConfig::load()/ServiceConfig::load() take only known keys).
 * meldungen.json as zng_meldungen_laden() reads it (without the file the
 * transition from service.json), plus every further key of the file.
 */
function zng_sicherung_jetzt()
{
    $v = zng_sicherung_vorgaben();
    $aus = array();
    foreach (array("mqtt" => LBPCONFIGDIR . "/mqtt.json", "service" => LBPCONFIGDIR . "/service.json") as $blk => $datei) {
        $d = zng_json_lage($datei) === "ok" ? json_decode((string) @file_get_contents($datei), true) : array();
        $aus[$blk] = array_replace($v[$blk], zng_sicherung_json_normal(is_array($d) ? $d : array()));
    }
    global $meldungenFile;
    list($m) = zng_meldungen_laden();
    $m = zng_sicherung_json_normal($m);
    $roh = zng_json_lage($meldungenFile) === "ok" ? json_decode((string) @file_get_contents($meldungenFile), true) : array();
    foreach (is_array($roh) ? $roh : array() as $k => $w) {
        if (!array_key_exists($k, $m)) {
            $m[$k] = $w;
        } elseif (in_array($k, ZNG_WEGE, true) && is_array($w) && is_array($m[$k])) {
            foreach ($w as $a => $x) {
                if (!array_key_exists($a, $m[$k])) {
                    $m[$k][$a] = $x;
                }
            }
        }
    }
    $aus["meldungen"] = $m;
    return $aus;
}

/**
 * The description of this line for the shared backup module.
 *
 * Required and kept (decision of the owner 12.11.2026, rule 6), measured
 * 16.11.2026 on the backups of all 15 versions 4.1.1-4.2.14 (each built with
 * the code of its version): mqtt and service were written by every version -
 * required; meldungen since 4.2.5 - kept. Inside a block (module 1.2.8,
 * decision of the owner 16.11.2026) a key that every version writing the block
 * has written is required too: a file that carries the block without it is
 * rejected and the key named. Required: mqtt 9 of 14 keys (kept: hausKlima,
 * hausWetter, hausAlarm, hausBereiche, hausAlarmAus - since 4.2.5), service 13
 * of 13, meldungen batterieSchwelle, ruheBis, ruheVon, signalEin, signalPraefix,
 * spracheEin, tts and the ways lb, signal, sprache (kept: tafelEin, tafelNamen -
 * since 4.2.8). Any other key of a block keeps its current value and is named.
 */
function zng_sicherung_beschreibung()
{
    global $L;
    $vorgaben = zng_sicherung_vorgaben();
    $behalten = array("art" => "wert", "fehlt" => "behalten");
    $pflicht = array("art" => "wert", "fehlt" => "pflicht");
    $pflicht_im_block = array(
        "mqtt" => array("forwardMode", "hausTopics", "password", "port", "registerMqttTopic", "server", "topic",
            "usemqttgateway", "username"),
        "meldungen" => array("batterieSchwelle", "ruheBis", "ruheVon", "signalEin", "signalPraefix", "spracheEin"),
    );
    $knoten = array();
    foreach (array("mqtt", "service", "meldungen") as $blk) {
        $sub = array();
        foreach (array_keys($vorgaben[$blk]) as $f) {
            if ($blk === "meldungen" && $f === "ansage_token_herkunft") {
                continue;       // the origin mark: 'nie_aus_datei' below
            }
            $sub[$f] = ($blk === "service" || (isset($pflicht_im_block[$blk]) && in_array($f, $pflicht_im_block[$blk], true)))
                ? $pflicht : $behalten;
        }
        if ($blk === "meldungen") {
            // the speech block: its fields are checked by the speech module (offen)
            $sub["tts"] = array("art" => "offen", "fehlt" => "pflicht");
            foreach (ZNG_WEGE as $weg) {
                $sub[$weg] = sicherung_block(array(), array_fill_keys(ZNG_ANLAESSE, $behalten)) + array("fehlt" => "pflicht");
            }
        }
        $knoten[$blk] = array("art" => "block", "schluessel" => $sub);
    }
    // since 4.2.5; a backup of 4.1.1-4.2.4 has no block meldungen - the notifications stay as they are
    $knoten["meldungen"]["fehlt"] = "behalten";
    // "Geräte für die Haus-Themen" stays a JSON object {} in the backup, also when empty - as in mqtt.json
    // (MqttConfig::toJsonDaten(), checked 12.11.2026); read back as JSON it is an empty list [] (measured 16.11.2026:
    // the restore of such a file wrote {} again, but the backup itself carried [])
    $knoten["mqtt"]["sichern"] = function ($m) {
        if (is_array($m) && array_key_exists("hausBereiche", $m) && is_array($m["hausBereiche"])) {
            $m["hausBereiche"] = (object) $m["hausBereiche"];
        }
        return $m;
    };
    // never the speech tokens in a backup
    $knoten["meldungen"]["sichern"] = function ($m) {
        zng_sicherung_modul();
        if (is_array($m)) {
            $m["tts"] = ansage_sicherung_bereinigen(isset($m["tts"]) && is_array($m["tts"]) ? $m["tts"] : array());
        }
        return $m;
    };
    return array(
        "kennzeichen" => "zigbee2mqttng",
        "hinweis" => "Zigbee2MqttNG - Einstellungen (mqtt.json, service.json, meldungen.json). Enthaelt das Kennwort des MQTT-Brokers und das Token der Zigbee2mqtt UI: wie ein Kennwort aufbewahren. Das Zigbee-Netz selbst (Netzwerkschluessel, Geraete) steckt im ZIP von \"Sicherung herunterladen\".",
        "home" => (string) LBHOMEDIR, "ordner" => (string) LBPPLUGINDIR,
        "plugin_cfg" => dirname(dirname(__DIR__)) . "/plugin.cfg",
        "vorgaben" => $vorgaben,
        "schluessel" => $knoten,
        // module 1.2.7: the origin mark in the block meldungen never comes from a file and keeps its current value
        "nie_aus_datei" => array("meldungen.ansage_token_herkunft"),
        "pruefen" => "zng_sicherung_pruefen",
        "jetzt" => "zng_sicherung_jetzt",
        "vorbereiten" => "zng_sicherung_vorbereiten",
        // module 1.2.7: three pairs (file, second copy)
        "zweitschrift" => function () {
            $p = array();
            foreach (array("mqtt.json", "service.json", "meldungen.json") as $n) {
                $p[] = array(LBPCONFIGDIR . "/" . $n, zng_zweitschrift_pfad(LBPCONFIGDIR, $n));
            }
            return $p;
        },
        "t" => "zng_t",
    );
}

/**
 * Callback 'pruefen' per block: the same rules as the forms. Returns a list
 * of reasons (plain text, each with the name of the setting); $roh is the
 * block as it stands in the file.
 */
function zng_sicherung_pruefen($k, $w, $neu = null, $roh = null)
{
    $t = array();
    if (!is_array($w) || ($w && array_keys($w) === range(0, count($w) - 1))) {
        return zng_t("Sicherung.TeilKeinObjekt") . ": " . $k;
    }
    if ($k === "mqtt" || $k === "service") {
        $klasse = $k === "mqtt" ? "MqttConfig" : "ServiceConfig";
        foreach ($w as $f => $x) {
            list($ok, $erg) = $klasse::wert((string) $f, $x, $w);
            if (!$ok) {
                $t[] = $k . "." . $f . ": " . zng_t($erg);
            }
        }
        return $t;
    }
    if ($k !== "meldungen") {
        return $t;
    }
    zng_sicherung_modul();
    list($fehler) = zng_meldungen_pruefen($w, $w);
    foreach ($fehler as $x) {
        $t[] = "meldungen." . $x[1] . ": " . zng_t($x[0]);
    }
    if (array_key_exists("tafelEin", $w) && zng_haken($w["tafelEin"]) === null) {
        $t[] = "meldungen.tafelEin: " . zng_t("Meldungen.ValHaken");
    }
    if (array_key_exists("tafelNamen", $w) && !ansage_tafel_namen_ok($w["tafelNamen"])) {
        $t[] = "meldungen.tafelNamen: " . ansage_t("M_R_TAFEL_NAMEN", zng_ansage_k());
    }
    // a file that carries a speech token does not come from "Einstellungen sichern" (the block as in the file)
    if (is_array($roh) && isset($roh["tts"]) && is_array($roh["tts"])) {
        foreach (ansage_sicherung_mangel($roh["tts"]) as $n) {
            $t[] = zng_t("Sicherung.Token") . ": meldungen." . $n;
        }
    }
    if (array_key_exists("tts", $w)) {
        $tts = $w["tts"];
        if (!is_array($tts)) {
            $t[] = zng_t("Sicherung.TeilKeinObjekt") . ": meldungen.tts";
        } else {
            $ziele = array_key_exists("ziele", $tts) ? $tts["ziele"] : null;
            unset($tts["ziele"]);
            $g = "";
            if ($tts && ansage_wert_pruefen(ansage_sicherung_bereinigen($tts), $g) === null) {
                $t[] = zng_t("Sicherung.Sprache") . ": meldungen.tts (" . $g . ")";
            }
            if ($ziele !== null && $ziele !== array() && ansage_ziele_pruefen($ziele, $g) === null) {
                $t[] = zng_t("Sicherung.Sprache") . ": meldungen.tts.ziele (" . $g . ")";
            }
        }
    }
    return $t;
}

/**
 * Callback 'vorbereiten': every value in the normal form of its check (as the
 * form stores it: port as text, rtscts "true"/"false", switches as true/false,
 * battery level as number), and the speech block as up to 4.2.13: the
 * current speech tokens stay, fields that an older version did not know keep
 * their value, an empty list of further targets is dropped - only when the
 * block came from the file.
 */
function zng_sicherung_vorbereiten($neu, $jetzt, $r)
{
    foreach (array("mqtt" => "MqttConfig", "service" => "ServiceConfig") as $blk => $klasse) {
        if (!isset($neu[$blk]) || !is_array($neu[$blk])) {
            continue;
        }
        foreach ($neu[$blk] as $f => $x) {
            list($ok, $erg) = $klasse::wert((string) $f, $x, $neu[$blk]);
            if ($ok) {
                $neu[$blk][$f] = $erg;
            }
        }
    }
    if (!isset($neu["meldungen"]) || !is_array($neu["meldungen"])) {
        return $neu;
    }
    list(, $m) = zng_meldungen_pruefen($neu["meldungen"], $neu["meldungen"]);
    if (array_key_exists("tafelEin", $m) && zng_haken($m["tafelEin"]) !== null) {
        $m["tafelEin"] = zng_haken($m["tafelEin"]);
    }
    $behalten = isset($r["fehlt_behalten"]) && is_array($r["fehlt_behalten"]) ? $r["fehlt_behalten"] : array();
    if (isset($m["tts"]) && is_array($m["tts"]) && !in_array("meldungen", $behalten, true) && !in_array("meldungen.tts", $behalten, true)) {
        zng_sicherung_modul();
        $tts = $m["tts"];
        $ziele = array_key_exists("ziele", $tts) ? $tts["ziele"] : null;
        unset($tts["ziele"]);
        $jt = isset($jetzt["meldungen"]["tts"]) && is_array($jetzt["meldungen"]["tts"]) ? $jetzt["meldungen"]["tts"] : array();
        $block = ansage_sicherung_tokens_behalten(array_merge(ansage_sicherung_bereinigen($tts),
            $ziele !== null ? array("ziele" => $ziele) : array()), $jt);
        if (function_exists("ansage_sicherung_spaet")) {
            list($block) = ansage_sicherung_spaet($block, $jt);
        }
        if (isset($block["ziele"]) && $block["ziele"] === array()) {
            unset($block["ziele"]);
        }
        $m["tts"] = $block;
    }
    $neu["meldungen"] = $m;
    return $neu;
}

/** The backup file as JSON text, '' when it cannot be built. */
function zng_sicherung_bauen()
{
    return sicherung_json(sicherung_bauen(zng_sicherung_beschreibung(), zng_sicherung_jetzt()));
}
