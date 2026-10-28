<?php
/**
 * Zigbee2MqttNG 4.2.5 (Gruppe 38) - the server forms of the page.
 *
 * The settings, MQTT and devices tabs save through ajax.php (since 4.1.1).
 * The forms added in 4.2.5 follow the house form of Regeln/04 instead:
 *   - every form carries formular=<name> and the form token (hidden field
 *     "formtoken" right before </form>); the token is checked before any
 *     handler runs, fail closed (no stored secret = refused);
 *   - only POST is handled here (REQUEST_METHOD), every POST ends with a
 *     303 redirect to index.php?form=<tab>; the result travels as a one-time
 *     message (data folder, 0600, 120 s, read and deleted only on GET);
 *   - after a complaint the typed values come back (X-2), never a token.
 * Forms: "meldungen" (Settings tab, E2), "sperre" (Settings tab, lift the
 * start block "rueckkopie", V3), "schalten" (Test tab: test message to
 * SignalBot, test announcement per target, E2).
 *
 * Expects include/plugin.php ($L, defines.php), the config classes and
 * bin/zigbee2mqttng.php to be loaded.
 */

/** The secret of the form token (config folder, 0600); created once. '' if it cannot be had. */
function zng_aktionstoken()
{
    global $aktionstokenFile;
    clearstatcache(true, $aktionstokenFile);
    $t = is_file($aktionstokenFile) ? trim((string) @file_get_contents($aktionstokenFile)) : "";
    if (preg_match('/^[0-9a-f]{32,128}\z/', $t)) {
        return $t;
    }
    $neu = bin2hex(random_bytes(24));
    if (!zng_schreiben_atomar($aktionstokenFile, $neu . "\n", ZNG_MODUS_GEHEIM)) {
        return "";
    }
    return $neu;
}

/** The form token (Regeln/04): hash_hmac over a fixed text with the secret. '' without a secret. */
function zng_formtoken()
{
    $geheim = zng_aktionstoken();
    return $geheim === "" ? "" : hash_hmac("sha256", "formular-v1", $geheim);
}

/** The hidden field, right before </form> */
function zng_formtoken_feld()
{
    return '<input type="hidden" name="formtoken" value="' . zng_e(zng_formtoken()) . '">';
}

/** Writes the one-time message for the following GET (0600, data folder) */
function zng_einmal_schreiben(array $daten)
{
    global $einmalmeldungFile;
    $daten["zeit"] = time();
    zng_schreiben_atomar($einmalmeldungFile, json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ZNG_MODUS_GEHEIM);
}

/**
 * Reads the one-time message - only on GET, deleted BEFORE it is shown;
 * older than 120 s is dropped. Returns the array or null.
 */
function zng_einmal_lesen()
{
    global $einmalmeldungFile;
    static $gelesen = false;
    static $inhalt = null;
    if ($gelesen) {
        return $inhalt;
    }
    $gelesen = true;
    if (!isset($_SERVER["REQUEST_METHOD"]) || $_SERVER["REQUEST_METHOD"] !== "GET" || !is_file($einmalmeldungFile)) {
        return null;
    }
    $d = json_decode((string) @file_get_contents($einmalmeldungFile), true);
    @unlink($einmalmeldungFile);
    if (!is_array($d) || !isset($d["zeit"]) || !is_int($d["zeit"]) || $d["zeit"] > time() || time() - $d["zeit"] > 120) {
        return null;
    }
    $inhalt = $d;
    return $inhalt;
}

/** The one-time message for one tab as HTML boxes (only texts, masked) */
function zng_einmal_html($reiter)
{
    $e = zng_einmal_lesen();
    if (!is_array($e) || !isset($e["reiter"]) || $e["reiter"] !== $reiter || empty($e["texte"])) {
        return "";
    }
    $klasse = array("ok" => "sm-hinweis", "warn" => "sm-warnung", "fehler" => "sm-fehler");
    $k = isset($e["art"], $klasse[$e["art"]]) ? $klasse[$e["art"]] : "sm-hinweis";
    $o = '<div class="' . $k . '">';
    if (!empty($e["titel"])) {
        $o .= '<b>' . zng_e($e["titel"]) . '</b>';
    }
    $o .= '<ul style="margin:4px 0 0">';
    foreach ((array) $e["texte"] as $t) {
        $o .= '<li>' . zng_e($t) . '</li>';
    }
    return $o . '</ul></div>';
}

/** Redirect after a POST (303) and end */
function zng_umleiten($reiter)
{
    header("Location: index.php?form=" . rawurlencode($reiter), true, 303);
    exit(0);
}

/**
 * Handles a POST of the page and ends with a redirect. Called by index.php
 * before anything is printed. A GET returns at once.
 */
function zng_formulare_post()
{
    if (!isset($_SERVER["REQUEST_METHOD"]) || $_SERVER["REQUEST_METHOD"] !== "POST") {
        return;
    }
    $formular = isset($_POST["formular"]) && is_string($_POST["formular"]) ? $_POST["formular"] : "";
    $reiter = array("meldungen" => "settings", "sperre" => "settings", "schalten" => "test");
    $ziel = isset($reiter[$formular]) ? $reiter[$formular] : "settings";
    $soll = zng_formtoken();
    $ist = isset($_POST["formtoken"]) && is_string($_POST["formtoken"]) ? $_POST["formtoken"] : "";
    if ($soll === "" || !hash_equals($soll, $ist)) {
        zng_einmal_schreiben(array("reiter" => $ziel, "art" => "fehler", "titel" => zng_t("Formular.Abgewiesen"),
            "texte" => array(zng_t("Formular.TokenFalsch"))));
        zng_umleiten($ziel);
    }
    if (!isset($reiter[$formular])) {
        zng_einmal_schreiben(array("reiter" => $ziel, "art" => "fehler", "titel" => zng_t("Formular.Abgewiesen"),
            "texte" => array(zng_t("Formular.Unbekannt"))));
        zng_umleiten($ziel);
    }
    $log = LBLog::newLog(["name" => "Service"]);
    $GLOBALS["log"] = $log;
    if ($formular === "meldungen") {
        zng_formular_meldungen();
    } elseif ($formular === "sperre") {
        zng_formular_sperre();
    } else {
        zng_formular_schalten();
    }
    zng_umleiten($ziel);
}

/** the speech module, loaded when it is needed */
function zng_modul()
{
    if (!function_exists("ansage_formular_html")) {
        require_once LBPHTMLAUTHDIR . "/sprachausgabe.php";
    }
}

/** Options of the speech module for this line: the table of further targets (1.2.0) */
function zng_ansage_opt()
{
    return array("ziele" => true);
}

/**
 * Runs update-config.php (bridge configuration of the extension) and
 * restarts zigbee2mqtt only when that configuration changed. Returns a text
 * for the one-time message, '' when nothing had to be done.
 */
function zng_bruecke_auffrischen()
{
    global $bridgeConfigFile, $serviceName;
    $vorher = is_file($bridgeConfigFile) ? (string) @file_get_contents($bridgeConfigFile) : "";
    $ausgabe = array();
    $rc = 0;
    exec("php " . escapeshellarg(LBPBINDIR . "/update-config.php") . " 2>&1", $ausgabe, $rc);
    clearstatcache(true, $bridgeConfigFile);
    $nachher = is_file($bridgeConfigFile) ? (string) @file_get_contents($bridgeConfigFile) : "";
    if ($rc !== 0) {
        LOGWARN("update-config.php ended with code $rc (see the Service log)");
        return sprintf(zng_t("Meldungen.UpdateConfigFehler"), $rc);
    }
    if ($vorher === $nachher) {
        return "";
    }
    $d = zng_dienst_schalten($serviceName, "restart");
    LOGINF("Notifications changed - zigbee2mqtt restarted: " . ($d["ok"] ? "running, PID " . $d["pid"] : "NOT running, state " . $d["zustand"]));
    return $d["ok"] ? zng_t("Meldungen.Neustart") : sprintf(zng_t("Meldungen.NeustartFehler"), $d["zustand"]);
}

/** Form "meldungen" (Settings tab, E2) */
function zng_formular_meldungen()
{
    zng_modul();
    list($alt, $lage) = zng_meldungen_laden();
    $k = zng_ansage_k();
    $werte = array();
    foreach (ZNG_WEGE as $weg) {
        foreach (ZNG_ANLAESSE as $a) {
            $werte[$weg][$a] = isset($_POST["m_" . $weg][$a]) && $_POST["m_" . $weg][$a] === "1";
        }
    }
    $werte["signalEin"] = isset($_POST["m_signalEin"]) && $_POST["m_signalEin"] === "1";
    $werte["spracheEin"] = isset($_POST["m_spracheEin"]) && $_POST["m_spracheEin"] === "1";
    foreach (array("batterieSchwelle", "signalPraefix", "ruheVon", "ruheBis") as $f) {
        $werte[$f] = isset($_POST["m_" . $f]) && is_string($_POST["m_" . $f]) ? trim($_POST["m_" . $f]) : null;
    }
    list($fehler, $neu) = zng_meldungen_pruefen($werte, $alt);
    $mangel = array();
    $bean = array();
    $hinweise = array();
    $neu["tts"] = ansage_formular_lesen($_POST, is_array($alt["tts"]) ? $alt["tts"] : array(), $mangel, $bean, zng_ansage_opt(), $k, $hinweise);
    $texte = array();
    foreach ($fehler as $f) {
        $texte[] = zng_t($f[0]);
        $bean[] = "m_" . $f[1];
    }
    foreach ($mangel as $m) {
        $texte[] = $m["text"];
    }
    if ($texte) {
        // X-2: the typed values come back - never a token
        $eingaben = array();
        foreach ($_POST as $name => $w) {
            if (in_array($name, array("formtoken", "formular", "tts_alexa_token", "tts_google_token"), true)) {
                continue;
            }
            $eingaben[$name] = $w;
        }
        zng_einmal_schreiben(array("reiter" => "settings", "art" => "fehler", "titel" => zng_t("Meldungen.NichtGespeichert"),
            "texte" => $texte, "formular" => "meldungen", "eingaben" => $eingaben, "bean" => $bean));
        return;
    }
    if (!zng_meldungen_speichern($neu)) {
        LOGERR("meldungen.json could not be written - the previous file stays");
        zng_einmal_schreiben(array("reiter" => "settings", "art" => "fehler", "titel" => zng_t("Meldungen.NichtGespeichert"),
            "texte" => array(sprintf(zng_t("Konfig.Schreibfehler"), "meldungen.json"))));
        return;
    }
    LOGOK("Notifications saved" . ($lage === "kaputt" ? " (meldungen.json was unreadable before)" : ""));
    $texte = array(zng_t("Meldungen.Gespeichert"));
    foreach ($hinweise as $h) {
        $texte[] = $h["text"];
    }
    $b = zng_bruecke_auffrischen();
    if ($b !== "") {
        $texte[] = $b;
    }
    zng_einmal_schreiben(array("reiter" => "settings", "art" => "ok", "texte" => $texte));
}

/** Form "sperre" (Settings tab, V3): lifts the reason "rueckkopie" of the start block */
function zng_formular_sperre()
{
    global $serviceName;
    $gruende = zng_sperre_gruende();
    if (!in_array("rueckkopie", $gruende, true)) {
        zng_einmal_schreiben(array("reiter" => "settings", "art" => "warn", "texte" => array(zng_t("Konfig.SperreNichtRueckkopie"))));
        return;
    }
    $rest = array_values(array_diff($gruende, array("rueckkopie")));
    if (!zng_sperre_schreiben($rest)) {
        zng_einmal_schreiben(array("reiter" => "settings", "art" => "fehler", "texte" => array(sprintf(zng_t("Konfig.Schreibfehler"), "startsperre"))));
        return;
    }
    LOGWARN("Start block 'rueckkopie' lifted by the user on the Settings tab");
    $ausgabe = array();
    $rc = 0;
    exec("php " . escapeshellarg(LBPBINDIR . "/update-config.php") . " 2>&1", $ausgabe, $rc);
    $d = zng_dienst_schalten($serviceName, "start");
    $texte = array(zng_t("Konfig.SperreAufgehoben"));
    $texte[] = $d["ok"] ? sprintf(zng_t("Dienst.Laeuft"), $d["pid"]) : sprintf(zng_t("Dienst.LaeuftNicht"), $d["zustand"] !== "" ? $d["zustand"] : "?");
    zng_einmal_schreiben(array("reiter" => "settings", "art" => $d["ok"] ? "ok" : "warn", "texte" => $texte));
}

/** Form "schalten" (Test tab, E2): test message to SignalBot, test announcement */
function zng_formular_schalten()
{
    global $signalStatusFile;
    list($m) = zng_meldungen_laden();
    if (isset($_POST["signaltest"])) {
        require_once __DIR__ . "/include/Z2mBridge.php";
        $q = zng_signal_senden(zng_broker_credentials(MqttConfig::load()), (string) $m["signalPraefix"], zng_t("Test.SignalTestText"), false);
        zng_signal_merken($signalStatusFile, $q);
        LOGINF("Test message to SignalBot: " . $q["lage"] . ($q["grund"] !== "" ? " " . $q["grund"] : ""));
        $text = sprintf(zng_t("Test.SignalQuittung_" . $q["lage"]), $q["grund"]);
        zng_einmal_schreiben(array("reiter" => "test", "art" => $q["lage"] === "angenommen" ? "ok" : "warn", "texte" => array($text)));
        return;
    }
    zng_modul();
    $nr = ansage_test_ziel($_POST);
    if ($nr === 0 && !isset($_POST["ansagetest"])) {
        zng_einmal_schreiben(array("reiter" => "test", "art" => "fehler", "texte" => array(zng_t("Formular.Unbekannt"))));
        return;
    }
    $k = zng_ansage_k();
    $r = ansage_testansage(is_array($m["tts"]) ? $m["tts"] : array(), $k, $nr);
    LOGINF("Test announcement" . ($nr ? " target $nr" : "") . ": " . ansage_kurz($r));
    $text = (int) $r["stand"] === 1 ? sprintf(zng_t("Test.AnsageOk"), (int) $r["zeichen"])
        : sprintf(zng_t("Test.AnsageFehl"), ansage_kennung_text((string) $r["kennung"], $k));
    zng_einmal_schreiben(array("reiter" => "test", "art" => (int) $r["stand"] === 1 ? "ok" : "warn", "texte" => array($text)));
}

/* ------------------------------------------------------------------
 * HTML of the forms
 * ------------------------------------------------------------------ */

/**
 * The section "Notifications" of the Settings tab: occasions x ways, SignalBot,
 * speech (module block 1.2.0), quiet time - own form "meldungen".
 */
function zng_meldungen_html()
{
    zng_modul();
    list($m, $lage) = zng_meldungen_laden();
    $k = zng_ansage_k();
    $e = zng_einmal_lesen();
    $x2 = is_array($e) && isset($e["formular"]) && $e["formular"] === "meldungen" && isset($e["eingaben"]) && is_array($e["eingaben"]);
    $ein = $x2 ? $e["eingaben"] : array();
    $bean = $x2 && isset($e["bean"]) && is_array($e["bean"]) ? $e["bean"] : array();
    $mark = function ($name) use ($bean) {
        return in_array($name, $bean, true) ? ' class="sm-beanstandet"' : '';
    };
    $wert = function ($name, $gespeichert) use ($x2, $ein) {
        return $x2 && isset($ein[$name]) && is_string($ein[$name]) ? $ein[$name] : (string) $gespeichert;
    };
    $haken = function ($name, $gespeichert, $sub = null) use ($x2, $ein) {
        if (!$x2) {
            return (bool) $gespeichert;
        }
        if ($sub === null) {
            return isset($ein[$name]) && $ein[$name] === "1";
        }
        return isset($ein[$name][$sub]) && $ein[$name][$sub] === "1";
    };
    $o = array();
    if ($lage === "kaputt") {
        $o[] = '<div class="sm-fehler">' . zng_e(zng_t("Meldungen.Kaputt")) . '</div>';
    } elseif ($lage === "fehlt") {
        $o[] = '<p class="sm-hilfe">' . zng_e(zng_t("Meldungen.Uebergang")) . '</p>';
    }
    $o[] = '<form method="post" action="index.php" data-ajax="false">';
    $o[] = '<input type="hidden" name="formular" value="meldungen">';
    $o[] = '<div class="sm-breit"><table class="sm-tbl">';
    $o[] = '<tr><th>' . zng_e(zng_t("Meldungen.Anlass")) . '</th><th>' . zng_e(zng_t("Meldungen.WegLb")) . '</th><th>'
        . zng_e(zng_t("Meldungen.WegSignal")) . '</th><th>' . zng_e(zng_t("Meldungen.WegSprache")) . '</th></tr>';
    foreach (ZNG_ANLAESSE as $a) {
        $o[] = '<tr><td>' . zng_e(zng_t("Meldungen.Anlass_" . $a)) . '</td>';
        foreach (ZNG_WEGE as $weg) {
            $an = $haken("m_" . $weg, !empty($m[$weg][$a]), $a);
            $o[] = '<td><label><input data-role="none" type="checkbox" name="m_' . $weg . '[' . $a . ']" value="1"' . ($an ? ' checked' : '') . '> '
                . zng_e(zng_t("Meldungen.Melden")) . '</label></td>';
        }
        $o[] = '</tr>';
    }
    $o[] = '</table></div>';
    $o[] = '<p class="sm-hilfe">' . zng_e(zng_t("Meldungen.TabelleHilfe")) . '</p>';
    $o[] = '<div class="sm-feld"><label for="m_batterieSchwelle">' . zng_e(zng_t("ServiceConfig.LabelBatteryThreshold")) . '</label>'
        . '<input data-role="none" type="text" inputmode="numeric" id="m_batterieSchwelle" name="m_batterieSchwelle" value="'
        . zng_e($wert("m_batterieSchwelle", $m["batterieSchwelle"])) . '"' . $mark("m_batterieSchwelle") . '>'
        . '<p class="sm-hilfe">' . zng_e(zng_t("ServiceConfig.HintBatteryThreshold")) . '</p></div>';

    $o[] = '<h3>' . zng_e(zng_t("Meldungen.SignalTitel")) . '</h3>';
    $o[] = '<div class="sm-feld sm-haken"><label><input data-role="none" type="checkbox" name="m_signalEin" value="1"'
        . ($haken("m_signalEin", !empty($m["signalEin"])) ? ' checked' : '') . '> ' . zng_e(zng_t("Meldungen.SignalEin")) . '</label>'
        . '<p class="sm-hilfe">' . zng_e(zng_t("Meldungen.SignalHinweis")) . '</p>';
    if (zng_plugin_installiert("signalbot") === false) {
        $o[] = '<p class="sm-hilfe sm-aus">' . zng_e(zng_t("Meldungen.SignalNichtDa")) . '</p>';
    }
    $o[] = '</div>';
    $o[] = '<div class="sm-feld"><label for="m_signalPraefix">' . zng_e(zng_t("Meldungen.Praefix")) . '</label>'
        . '<input data-role="none" type="text" id="m_signalPraefix" name="m_signalPraefix" value="'
        . zng_e($wert("m_signalPraefix", $m["signalPraefix"])) . '"' . $mark("m_signalPraefix") . '>'
        . '<p class="sm-hilfe">' . zng_e(zng_t("Meldungen.PraefixHilfe")) . '</p></div>';

    $o[] = '<h3>' . zng_e(zng_t("Meldungen.SpracheTitel")) . '</h3>';
    $o[] = '<div class="sm-feld sm-haken"><label><input data-role="none" type="checkbox" name="m_spracheEin" value="1"'
        . ($haken("m_spracheEin", !empty($m["spracheEin"])) ? ' checked' : '') . '> ' . zng_e(zng_t("Meldungen.SpracheEin")) . '</label>'
        . '<p class="sm-hilfe">' . zng_e(zng_t("Meldungen.SpracheHinweis")) . '</p></div>';
    $o[] = '<div class="sm-feld"><label for="m_ruheVon">' . zng_e(zng_t("Meldungen.Ruhezeit")) . '</label>'
        . '<input data-role="none" type="text" id="m_ruheVon" name="m_ruheVon" style="max-width:8em" value="'
        . zng_e($wert("m_ruheVon", $m["ruheVon"])) . '"' . $mark("m_ruheVon") . '> – '
        . '<input data-role="none" type="text" id="m_ruheBis" name="m_ruheBis" style="max-width:8em" value="'
        . zng_e($wert("m_ruheBis", $m["ruheBis"])) . '"' . $mark("m_ruheBis") . '>'
        . '<p class="sm-hilfe">' . zng_e(zng_t("Meldungen.RuhezeitHilfe")) . '</p></div>';
    $h = zng_ansage_opt();
    $h["w"] = function ($name, $gespeichert) use ($x2, $ein) {
        return $x2 && isset($ein[$name]) && is_string($ein[$name]) ? $ein[$name] : $gespeichert;
    };
    $h["m"] = function ($name) use ($bean) {
        return in_array($name, $bean, true) ? ' class="sm-beanstandet"' : '';
    };
    $o[] = ansage_formular_html(is_array($m["tts"]) ? $m["tts"] : array(), $h, $k);
    $o[] = '<div class="sm-knopfreihe"><button data-role="none" type="submit" class="sm-btn sm-b-aktion">'
        . zng_e(zng_t("Meldungen.Speichern")) . '</button></div>';
    $o[] = zng_formtoken_feld();
    $o[] = '</form>';
    return implode("\n", $o) . "\n";
}

/** The form "sperre" - only while the start block carries "rueckkopie" (V3) */
function zng_sperre_html()
{
    if (!in_array("rueckkopie", zng_sperre_gruende(), true)) {
        return "";
    }
    return '<form method="post" action="index.php" data-ajax="false">'
        . '<input type="hidden" name="formular" value="sperre">'
        . '<p>' . zng_e(zng_t("Konfig.SperreRueckkopieText")) . '</p>'
        . '<div class="sm-knopfreihe"><button data-role="none" type="submit" class="sm-btn sm-b-aktion">'
        . zng_e(zng_t("Konfig.SperreAufheben")) . '</button></div>'
        . zng_formtoken_feld() . '</form>';
}

/** The form "schalten" of the Test tab (E2): test message to SignalBot, test announcement (orange) */
function zng_schalten_html()
{
    zng_modul();
    list($m) = zng_meldungen_laden();
    $tts = is_array($m["tts"]) ? $m["tts"] : array();
    $o = array('<form method="post" action="index.php" data-ajax="false">', '<input type="hidden" name="formular" value="schalten">');
    $o[] = '<div class="sm-knopfreihe">';
    $o[] = '<button data-role="none" type="submit" name="signaltest" value="1" class="sm-btn sm-b-aktion">' . zng_e(zng_t("Test.SignalTestKnopf")) . '</button>';
    $ziele = ansage_testknoepfe_html($tts, zng_ansage_k(), array("klasse" => "sm-btn sm-b-aktion"));
    if ($ziele !== "") {
        $o[] = $ziele;
    } else {
        $o[] = '<button data-role="none" type="submit" name="ansagetest" value="1" class="sm-btn sm-b-aktion">' . zng_e(zng_t("Test.AnsageKnopf")) . '</button>';
    }
    $o[] = '</div>';
    $o[] = '<p class="sm-hilfe">' . zng_e(zng_t("Test.SchaltenHilfe")) . '</p>';
    $o[] = zng_formtoken_feld();
    $o[] = '</form>';
    return implode("\n", $o) . "\n";
}

/**
 * Is a plugin installed (plugindatabase.json, only the folder names)?
 * true/false, null = the database cannot be read.
 */
function zng_plugin_installiert($ordner)
{
    $db = zng_read_json(LBHOMEDIR . "/data/system/plugindatabase.json", null);
    if (!is_array($db)) {
        return null;
    }
    $liste = isset($db["plugins"]) && is_array($db["plugins"]) ? $db["plugins"] : $db;
    foreach ($liste as $p) {
        if (is_array($p) && isset($p["folder"]) && $p["folder"] === $ordner) {
            return true;
        }
    }
    return false;
}
