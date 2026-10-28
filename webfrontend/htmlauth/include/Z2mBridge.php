<?php
require_once "loxberry_system.php";
require_once "loxberry_io.php";
require_once "phpMQTT/phpMQTT.php";
require_once __DIR__ . "/../model/MqttConfig.php";
require_once LBPBINDIR . "/zigbee2mqttng.php";

/**
 * 4.2.5 (Gruppe 38, V2, own finding E1): the broker went away during a
 * request. Thrown by ZngMqtt instead of phpMQTT's endless reconnect.
 */
class ZngBrokerWeg extends RuntimeException
{
}

/**
 * 4.2.5 (Gruppe 38, V2): phpMQTT of LoxBerry with hard limits - one file,
 * nothing in LoxBerry is changed.
 *
 * phpMQTT reconnects on its own at two places of proc(): after EOF of the
 * socket and after two keepalive periods without a packet. Both call
 * connect_auto(), which loops with sleep(10) until a broker answers - a page
 * request (Test tab, pairing, network map) hung without end when the broker
 * went away (measured: killed after 25 s). Here:
 *   - connect_auto() tries connect() exactly once and throws ZngBrokerWeg;
 *     called from inside proc() it does not reconnect at all;
 *   - proc() throws at once when the socket is gone or at EOF;
 *   - read() waits at most $lesefrist seconds for the rest of a packet (with
 *     stream_select, no busy loop) and throws at EOF - phpMQTT's read() loops
 *     on a non-blocking socket until the bytes arrive;
 *   - message() passes the retain bit (first byte of the packet) as third
 *     argument to the callback: function ($topic, $msg, $retain).
 */
class ZngMqtt extends Bluerhinos\phpMQTT
{
    /** @var float seconds a read of a started packet may take */
    public $lesefrist = 3.0;

    /** @var string first byte of the last packet proc() read */
    public $kopfbyte = "";

    /** @var bool true while proc() runs */
    private $imProc = false;

    public function connect_auto($clean = true, $will = null, $username = null, $password = null): bool
    {
        if ($this->imProc) {
            throw new ZngBrokerWeg("broker gone (no reconnect)");
        }
        if ($this->connect($clean, $will, $username, $password) === false) {
            throw new ZngBrokerWeg("connect failed");
        }
        return true;
    }

    public function proc(bool $loop = true)
    {
        if (!$this->offen()) {
            throw new ZngBrokerWeg("socket closed");
        }
        if ($this->timesinceping < (time() - ($this->keepalive * 2))) {
            throw new ZngBrokerWeg("no packet for two keepalive periods");
        }
        $this->imProc = true;
        try {
            return parent::proc($loop);
        } finally {
            $this->imProc = false;
        }
    }

    public function read($int = 8192, $nb = false)
    {
        if (!is_resource($this->socket)) {
            throw new ZngBrokerWeg("socket closed");
        }
        if ($nb) {
            $b = fread($this->socket, $int);
            if ((int) $int === 1 && is_string($b) && $b !== "") {
                $this->kopfbyte = $b;
            }
            return $b;
        }
        $string = "";
        $ende = microtime(true) + max(0.1, (float) $this->lesefrist);
        while (strlen($string) < $int) {
            $teil = fread($this->socket, $int - strlen($string));
            if (is_string($teil) && $teil !== "") {
                $string .= $teil;
                continue;
            }
            if (feof($this->socket)) {
                throw new ZngBrokerWeg("EOF while reading");
            }
            $rest = $ende - microtime(true);
            if ($rest <= 0) {
                throw new ZngBrokerWeg("timeout while reading");
            }
            $r = array($this->socket);
            $w = null;
            $e = null;
            @stream_select($r, $w, $e, 0, (int) min(200000, $rest * 1000000));
        }
        return $string;
    }

    public function message($msg)
    {
        $kopf = $this->kopfbyte !== "" ? ord($this->kopfbyte) : 0x30;
        $retain = ($kopf & 1) === 1;
        $tlen = (ord($msg[0]) << 8) + ord($msg[1]);
        $topic = substr($msg, 2, $tlen);
        $inhalt = (string) substr($msg, $tlen + 2);
        if ((($kopf >> 1) & 3) > 0) {
            // QoS 1/2: a packet id follows the topic (phpMQTT forgot it)
            $inhalt = (string) substr($inhalt, 2);
        }
        foreach ($this->topics as $key => $top) {
            $muster = '/^' . str_replace(array('\#', '\+'), array('.*', '[^\/]*'), preg_quote($key, '/')) . '$/';
            if (!preg_match($muster, $topic)) {
                continue;
            }
            if ($top['function'] === '__direct_return_message__') {
                return $inhalt;
            }
            if (is_callable($top['function'])) {
                call_user_func($top['function'], $topic, $inhalt, $retain);
            }
        }
        return false;
    }

    /** True while the socket is open and not at EOF */
    public function offen()
    {
        return is_resource($this->socket) && !feof($this->socket);
    }

    /** The socket, for stream_set_timeout() */
    public function sockel()
    {
        return $this->socket;
    }

    /** DISCONNECT if possible, then close - never a warning on a dead socket */
    public function schliessen()
    {
        if (is_resource($this->socket)) {
            @fwrite($this->socket, chr(0xe0) . chr(0x00));
            @fclose($this->socket);
        }
    }

    /** Publish only on an open socket; throws ZngBrokerWeg otherwise */
    public function senden($topic, $content, $retain = false)
    {
        if (!$this->offen()) {
            throw new ZngBrokerWeg("socket closed");
        }
        // a write to a socket the broker just closed warns - the next read says it
        @$this->publish($topic, $content, 0, $retain);
    }

    /** SUBSCRIBE only on an open socket (waits for the SUBACK, see read()) */
    public function abonnieren(array $topics)
    {
        if (!$this->offen()) {
            throw new ZngBrokerWeg("socket closed");
        }
        @$this->subscribe($topics, 0);
    }
}

/**
 * 4.2.5 (V2): opens a ZngMqtt connection with the given credentials
 * (zng_broker_credentials()). Returns the client or null. The password goes
 * only into the CONNECT packet. After connecting, the socket gets a 2 s
 * timeout so that half a packet does not hold read().
 */
function zng_mqtt_verbinden(array $creds, $kennung = "zigbee2mqttng", $keepalive = 10)
{
    if (empty($creds["brokerhost"]) || (int) $creds["brokerport"] <= 0) {
        return null;
    }
    $m = new ZngMqtt((string) $creds["brokerhost"], (int) $creds["brokerport"], $kennung . "-" . bin2hex(random_bytes(4)));
    $m->keepalive = (int) $keepalive;
    $user = isset($creds["brokeruser"]) && (string) $creds["brokeruser"] !== "" ? (string) $creds["brokeruser"] : null;
    $pass = isset($creds["brokerpass"]) && (string) $creds["brokerpass"] !== "" ? (string) $creds["brokerpass"] : null;
    try {
        $ok = @$m->connect(true, null, $user, $pass);
    } catch (ZngBrokerWeg $e) {
        $ok = false;
    }
    if (!$ok) {
        $m->schliessen();
        return null;
    }
    if (is_resource($m->sockel())) {
        stream_set_timeout($m->sockel(), 2);
    }
    return $m;
}

/**
 * 4.2.5 (Gruppe 38, V10/E1/E2): one short session at the broker, the form of
 * fer_haus_sitzung() (FerienFeiertage 1.2.28): CONNECT, PUBLISH $senden,
 * SUBSCRIBE the exact topics of $rueck, read their retained values until the
 * window ends, DISCONNECT.
 *   $senden  topic => array(value, retain); an empty value only with retain
 *            (removes a retained message); a topic with # or + never
 *   $rueck   exact topics (no wildcard) whose RETAINED values are read
 *   $fenster seconds to wait for retained values after the SUBACK
 * Returns array('lage' => ok | kein_broker | weg, 'werte' => topic => value
 * (retained only), 'gesendet' => n).
 */
function zng_mqtt_sitzung(array $creds, array $senden, array $rueck = array(), $fenster = 1.0)
{
    $aus = array("lage" => "kein_broker", "werte" => array(), "gesendet" => 0);
    $m = zng_mqtt_verbinden($creds, "zigbee2mqttng-haus");
    if ($m === null) {
        return $aus;
    }
    $aus["lage"] = "weg";
    try {
        foreach ($senden as $t => $s) {
            if (!is_string($t) || $t === "" || strpbrk($t, "+#") !== false) {
                continue;
            }
            $wert = (string) $s[0];
            $retain = !empty($s[1]);
            if ($wert === "" && !$retain) {
                continue;
            }
            $m->senden($t, $wert, $retain);
            $aus["gesendet"]++;
        }
        $werte = array();
        $abo = array();
        foreach ($rueck as $t) {
            if (is_string($t) && $t !== "" && strpbrk($t, "+#") === false) {
                $abo[$t] = array("qos" => 0, "function" => function ($topic, $msg, $retain = false) use (&$werte) {
                    if ($retain) {
                        $werte[$topic] = $msg;
                    }
                });
            }
        }
        if ($abo) {
            $m->abonnieren($abo);
            $ende = microtime(true) + (float) $fenster;
            while (microtime(true) < $ende) {
                $m->lesefrist = max(0.2, $ende - microtime(true) + 0.5);
                $m->proc();
            }
        }
        $aus["werte"] = $werte;
        $aus["lage"] = "ok";
    } catch (ZngBrokerWeg $e) {
        $aus["werte"] = array();
    }
    $m->schliessen();
    return $aus;
}

/**
 * 4.2.5 (Gruppe 38, V10): removes OWN retained house topics with read back,
 * the form of fer_haus_leeren() (FerienFeiertage 1.2.28). Own topics are the
 * entries of zigbee2mqttng_haus.json (topic => IEEE, "-IEEE" for a device
 * that left the network) - never haus/#, never a foreign topic.
 *   1. read back (retained only); nothing held -> "nichts"
 *   2. empty retained message for the held topics, read back again in the
 *      same session; up to $runden rounds
 * Afterwards the cleared topics are removed from the file (in one piece).
 * A file without entries for these families is the marker "never provider".
 *   $familien  e.g. array("klima") or the four families
 *   $nur       names (<name>) only these, empty = all of the families
 * Returns array('lage' => geleert | nichts | rest | kein_broker, 'themen' =>
 * number of own topics, 'offen' => topics still held, 'nie' => true when the
 * file had no entry for them, 'grund' => state of the failed session).
 */
function zng_haus_leeren(array $creds, $hausDatei, array $familien, array $nur = array(), $runden = 3)
{
    $e = zng_haus_leeren_lauf($creds, $hausDatei, $familien, $nur, $runden);
    // for the Test tab: state and time of the last clearing per family (next to the topic file)
    $merkDatei = dirname($hausDatei) . "/zigbee2mqttng_haus_leeren.json";
    $m = zng_read_json($merkDatei, array());
    $m = is_array($m) ? $m : array();
    foreach ($familien as $f) {
        $m[$f] = array("lage" => $e["nie"] ? "nie" : $e["lage"], "zeit" => time());
    }
    zng_schreiben_atomar($merkDatei, json_encode($m), 0644);
    return $e;
}

function zng_haus_leeren_lauf(array $creds, $hausDatei, array $familien, array $nur, $runden)
{
    $merk = zng_read_json($hausDatei, array());
    $merk = is_array($merk) ? $merk : array();
    $th = array();
    foreach (array_keys($merk) as $t) {
        $teile = explode("/", (string) $t);
        if (count($teile) !== 4 || $teile[0] !== "haus" || !in_array($teile[1], $familien, true)
            || strpbrk((string) $t, "+#") !== false) {
            continue;
        }
        if ($nur && !in_array($teile[2], $nur, true)) {
            continue;
        }
        $th[] = (string) $t;
    }
    if (!$th) {
        return array("lage" => "nichts", "themen" => 0, "offen" => array(), "nie" => true, "grund" => "");
    }
    $belegt = function ($werte) {
        $o = array();
        foreach ($werte as $t => $v) {
            if ((string) $v !== "") {
                $o[] = $t;
            }
        }
        return $o;
    };
    $austragen = function ($weg) use ($hausDatei) {
        if (!$weg) {
            return;
        }
        $jetzt = zng_read_json($hausDatei, array());
        $jetzt = is_array($jetzt) ? $jetzt : array();
        foreach ($weg as $t) {
            unset($jetzt[$t]);
        }
        zng_schreiben_atomar($hausDatei, json_encode((object) $jetzt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 0644);
    };
    $r = zng_mqtt_sitzung($creds, array(), $th);
    if ($r["lage"] !== "ok") {
        return array("lage" => "kein_broker", "themen" => count($th), "offen" => array(), "nie" => false, "grund" => $r["lage"]);
    }
    $offen = $belegt($r["werte"]);
    if (!$offen) {
        $austragen($th);
        return array("lage" => "nichts", "themen" => count($th), "offen" => array(), "nie" => false, "grund" => "");
    }
    $lage = "rest";
    $grund = "";
    for ($i = 1; $i <= max(1, (int) $runden); $i++) {
        $senden = array();
        foreach ($offen as $t) {
            $senden[$t] = array("", true);
        }
        $r = zng_mqtt_sitzung($creds, $senden, $th);
        if ($r["lage"] !== "ok") {
            $lage = "kein_broker";
            $grund = $r["lage"];
            continue;
        }
        $offen = $belegt($r["werte"]);
        if (!$offen) {
            $austragen($th);
            return array("lage" => "geleert", "themen" => count($th), "offen" => array(), "nie" => false, "grund" => "");
        }
        $lage = "rest";
    }
    $austragen(array_values(array_diff($th, $offen)));
    return array("lage" => $lage, "themen" => count($th), "offen" => $offen, "nie" => false, "grund" => $grund);
}

/**
 * 4.2.5 (V10, E1): after saving the MQTT tab or restoring a backup: removes
 * what this installation should no longer provide - a family switched off,
 * or a device the user deselected (area "do not report", "report alarm" off).
 * Returns a list of array('familie', 'nur' (names), result of zng_haus_leeren()).
 */
function zng_haus_aufraeumen()
{
    global $hausTopicsFile, $hausNamesFile, $bridgeDevicesFile;
    $mqtt = MqttConfig::load();
    $an = array("tuer" => is_enabled($mqtt->hausTopics), "klima" => is_enabled($mqtt->hausKlima),
        "wetter" => is_enabled($mqtt->hausWetter), "alarm" => is_enabled($mqtt->hausAlarm));
    $merk = zng_read_json($hausTopicsFile, array());
    $merk = is_array($merk) ? $merk : array();
    $ist = array();
    $istIeee = array();
    foreach ($merk as $t => $ieee) {
        $teile = explode("/", (string) $t);
        if (count($teile) === 4 && $teile[0] === "haus" && isset($an[$teile[1]])) {
            $ist[$teile[1]][$teile[2]] = true;
            $istIeee[$teile[1]][$teile[2]] = (string) $ieee;
        }
    }
    $namen = zng_read_json($hausNamesFile, array());
    $namen = is_array($namen) ? $namen : array();
    $zuordnung = zng_haus_zuordnung(zng_read_json($bridgeDevicesFile, array()), (array) $mqtt->hausBereiche, (array) $mqtt->hausAlarmAus);
    $creds = zng_broker_credentials($mqtt);
    $ergebnisse = array();
    foreach ($an as $familie => $ein) {
        if (empty($ist[$familie])) {
            continue;
        }
        if (!$ein) {
            $ergebnisse[] = array("familie" => $familie, "nur" => array(), "ergebnis" => zng_haus_leeren($creds, $hausTopicsFile, array($familie)));
            continue;
        }
        if ($familie === "tuer") {
            continue;
        }
        $soll = array();
        foreach (array_keys($zuordnung[$familie]) as $ieee) {
            if (isset($namen[$ieee])) {
                $soll[$namen[$ieee]] = true;
            }
        }
        $weg = array();
        foreach (array_keys($ist[$familie]) as $name) {
            // a device that left the network keeps "-IEEE" (no statement was sent) - not deselected
            if (!isset($soll[$name]) && substr($istIeee[$familie][$name], 0, 1) !== "-") {
                $weg[] = (string) $name;
            }
        }
        if ($weg) {
            $ergebnisse[] = array("familie" => $familie, "nur" => $weg, "ergebnis" => zng_haus_leeren($creds, $hausTopicsFile, array($familie), $weg));
        }
    }
    return $ergebnisse;
}

/**
 * 4.2.5 (Gruppe 38, E2): a message for SignalBot - its way "messages of other
 * plugins" (Entwurf G32 7.2): JSON {"text": ..., "dringend": 0|1} to
 * <prefix>/befehl/plugin/zigbee2mqttng, never retained, at most 4000 bytes.
 * Before, <prefix>/befehl/plugin/zigbee2mqttng/quittung is subscribed and
 * read for at most $warte seconds. SignalBot puts "[zigbee2mqttng] " in front.
 * Returns array('lage' => angenommen | abgelehnt | keine | kein_broker,
 * 'grund' => text after "abgelehnt: ", 'antwort' => the answer (cut to 200)).
 */
function zng_signal_senden(array $creds, $praefix, $text, $dringend, $warte = 5.0)
{
    $aus = array("lage" => "kein_broker", "grund" => "", "antwort" => "");
    if (!is_string($praefix) || !preg_match('#^[a-z0-9_]{1,40}(/[a-z0-9_]{1,40}){0,3}\z#', $praefix)) {
        $aus["grund"] = "PRAEFIX";
        return $aus;
    }
    $thema = $praefix . "/befehl/plugin/zigbee2mqttng";
    $text = (string) $text;
    while (strlen(json_encode(array("text" => $text, "dringend" => $dringend ? 1 : 0), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > 4000) {
        $text = (string) preg_replace('/.\z/us', '', $text);
    }
    $nutzlast = json_encode(array("text" => $text, "dringend" => $dringend ? 1 : 0), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $m = zng_mqtt_verbinden($creds, "zigbee2mqttng-signal");
    if ($m === null) {
        return $aus;
    }
    $antwort = null;
    try {
        $m->abonnieren(array($thema . "/quittung" => array("qos" => 0, "function" => function ($t, $msg, $retain = false) use (&$antwort) {
            if (!$retain && $antwort === null) {
                $antwort = (string) $msg;
            }
        })));
        $m->senden($thema, $nutzlast, false);
        $ende = microtime(true) + (float) $warte;
        while ($antwort === null && microtime(true) < $ende) {
            $m->lesefrist = max(0.2, $ende - microtime(true) + 0.5);
            $m->proc();
        }
    } catch (ZngBrokerWeg $e) {
        $m->schliessen();
        return $aus;
    }
    $m->schliessen();
    if ($antwort === null) {
        $aus["lage"] = "keine";
        return $aus;
    }
    $aus["antwort"] = substr(preg_replace('/[\x00-\x1F\x7F]/', ' ', $antwort), 0, 200);
    if (trim($antwort) === "angenommen") {
        $aus["lage"] = "angenommen";
    } else {
        $aus["lage"] = "abgelehnt";
        $aus["grund"] = trim(preg_replace('/^abgelehnt:\s*/', '', $aus["antwort"]));
    }
    return $aus;
}

/** A device name for a message: never an IEEE address (zigbee2mqtt names a new device by it) */
function zng_meldung_name($name)
{
    global $L;
    $name = trim(preg_replace('/0x[0-9A-Fa-f]{16}/', '', (string) $name), " _-:");
    return $name === "" ? (isset($L["Notify.OhneName"]) ? $L["Notify.OhneName"] : "?") : $name;
}

/**
 * 4.2.5 (E2): the events of the extension (offline:<device>,
 * battery:<device>:<level>, alarm:<device>:<art>, update:<device>:<version>)
 * per occasion: array(occasion => list of device texts). A device name may
 * contain ":" - the last part is the level/art/version.
 */
function zng_meldung_ereignisse(array $events)
{
    global $L;
    $t = function ($k) use ($L) {
        return isset($L[$k]) ? $L[$k] : $k;
    };
    $a = array("offline" => array(), "batterie" => array(), "alarm" => array(), "update" => array());
    foreach ($events as $event) {
        $teile = explode(":", (string) $event, 2);
        if (count($teile) !== 2) {
            continue;
        }
        $rest = $teile[1];
        $pos = strrpos($rest, ":");
        if ($teile[0] === "offline") {
            $a["offline"][] = zng_meldung_name($rest);
        } elseif ($teile[0] === "battery" && $pos !== false) {
            $a["batterie"][] = zng_meldung_name(substr($rest, 0, $pos)) . " (" . (int) substr($rest, $pos + 1) . " %)";
        } elseif ($teile[0] === "alarm" && $pos !== false) {
            $a["alarm"][] = zng_meldung_name(substr($rest, 0, $pos)) . " (" . $t("Notify.Alarm_" . preg_replace('/[^a-z0-9]/', '', substr($rest, $pos + 1))) . ")";
        } elseif ($teile[0] === "update" && $pos !== false) {
            $a["update"][] = zng_meldung_name(substr($rest, 0, $pos));
        }
    }
    return array_filter($a);
}

/**
 * 4.2.5 (E2): sends the notifications of one run on every way that is on.
 *   $anlaesse   from zng_meldung_ereignisse()
 *   $meldungen  zng_meldungen_laden(); $mlage its state (kaputt = only the LoxBerry notification)
 *   $jetzt      "H:i" for the quiet time
 *   $k          context of the speech module (null = speech not loaded)
 *   $creds      broker credentials (zng_broker_credentials())
 * Returns what happened per way (for the log and the probes): array('lb' => bool,
 * 'signal' => occasion => answer, 'sprache' => occasion => result).
 */
function zng_meldungen_versenden(array $anlaesse, array $meldungen, $mlage, $jetzt, $k, array $creds)
{
    global $L, $bremseFile, $signalStatusFile;
    $t = function ($x) use ($L) {
        return isset($L[$x]) ? $L[$x] : $x;
    };
    $erg = array("lb" => false, "signal" => array(), "sprache" => array());
    if ($mlage === "kaputt") {
        LOGWARN("meldungen.json cannot be read - only the LoxBerry notification with the defaults, no Signal, no speech");
        $meldungen["signalEin"] = false;
        $meldungen["spracheEin"] = false;
    }
    $texte = array("offline" => "Notify.Offline", "batterie" => "Notify.Battery", "alarm" => "Notify.AlarmText", "update" => "Notify.Update");
    $anlassName = array("offline" => "Notify.AnlassOffline", "batterie" => "Notify.AnlassBatterie", "alarm" => "Notify.AnlassAlarm", "update" => "Notify.AnlassUpdate");
    // 1. LoxBerry notification - one for the run, as up to 4.2.4
    $zeilen = array();
    foreach ($anlaesse as $anlass => $geraete) {
        if (!empty($meldungen["lb"][$anlass])) {
            $zeilen[] = sprintf($t($texte[$anlass]), implode(", ", $geraete));
        }
    }
    if ($zeilen) {
        notify(LBPPLUGINDIR, "zigbee", "Zigbee2MqttNG: " . implode(" ", $zeilen), isset($anlaesse["offline"]) || isset($anlaesse["alarm"]));
        LOGINF("LoxBerry notification: " . implode(", ", array_keys($anlaesse)));
        $erg["lb"] = true;
    }
    // 2. SignalBot and 3. speech - one message per occasion
    foreach ($anlaesse as $anlass => $geraete) {
        $text = sprintf($t("Notify.SignalText"), $t($anlassName[$anlass]), implode(", ", $geraete));
        $dringend = $anlass === "alarm";
        if (!empty($meldungen["signalEin"]) && !empty($meldungen["signal"][$anlass])) {
            if (!zng_bremse_frei($bremseFile, "signal", 10)) {
                LOGWARN("SignalBot: more than 10 messages within an hour - not sent (only logged): $anlass");
                $erg["signal"][$anlass] = "gebremst";
            } else {
                $q = zng_signal_senden($creds, (string) $meldungen["signalPraefix"], $text, $dringend);
                zng_signal_merken($signalStatusFile, $q);
                $erg["signal"][$anlass] = $q["lage"] . ($q["grund"] !== "" ? ": " . $q["grund"] : "");
                if ($q["lage"] === "angenommen") {
                    LOGOK("SignalBot ($anlass): angenommen");
                } elseif ($q["lage"] === "abgelehnt") {
                    LOGWARN("SignalBot ($anlass): abgelehnt: " . $q["grund"]);
                } elseif ($q["lage"] === "keine") {
                    LOGWARN("SignalBot ($anlass): keine Quittung (SignalBot nicht installiert, 'Befehle über MQTT annehmen' aus oder Brücke steht)");
                } else {
                    LOGWARN("SignalBot ($anlass): broker not reachable");
                }
            }
        }
        if (!empty($meldungen["spracheEin"]) && !empty($meldungen["sprache"][$anlass])) {
            if (!$dringend && zng_ruhezeit($jetzt, $meldungen["ruheVon"], $meldungen["ruheBis"])) {
                LOGINF("Speech ($anlass): quiet time " . $meldungen["ruheVon"] . "-" . $meldungen["ruheBis"] . " - not spoken");
                $erg["sprache"][$anlass] = "ruhezeit";
            } elseif (!zng_bremse_frei($bremseFile, "sprache", 10)) {
                LOGWARN("Speech: more than 10 announcements within an hour - not spoken (only logged): $anlass");
                $erg["sprache"][$anlass] = "gebremst";
            } elseif ($k === null || !function_exists("ansage_sprechen")) {
                LOGWARN("Speech: sprachausgabe.php is not loaded");
                $erg["sprache"][$anlass] = "ohne_modul";
            } else {
                $r = ansage_sprechen($text, is_array($meldungen["tts"]) ? $meldungen["tts"] : array(), $k, array("dringend" => $dringend));
                LOGINF("Speech ($anlass): " . ansage_kurz($r));
                $erg["sprache"][$anlass] = "stand=" . (int) $r["stand"];
            }
        }
    }
    return $erg;
}

/** 4.2.5 (E2): the last answer of SignalBot for the Test tab (time, state, reason - no text) */
function zng_signal_merken($datei, array $q)
{
    zng_schreiben_atomar($datei, json_encode(array("zeit" => time(), "lage" => $q["lage"], "grund" => $q["grund"])), 0644);
}

/**
 * Talks to the running zigbee2mqtt over MQTT, with the same broker
 * credentials that bin/update-config.php writes into configuration.yaml
 * (MQTT gateway credentials or the user's own broker).
 * 4.2.5 (V2): through ZngMqtt - a broker that goes away ends the request
 * with [] / null instead of hanging; the time limit of retained() and
 * request() is hard (the argument plus at most 1 s).
 */
class Z2mBridge
{
    /** @var string base topic of zigbee2mqtt, e.g. "zigbee2mqtt" */
    public $topic = "";

    /** @var ZngMqtt|null */
    private $mqtt = null;

    /** @var array received payloads by sub topic */
    private $messages = [];

    /** @var array broker host, port, user, password */
    private $creds = [];

    /** @var bool "broker gone" was logged already */
    private $wegGemeldet = false;

    public function __construct()
    {
        $mqttcfg = MqttConfig::load();
        $this->topic = (string) $mqttcfg->topic;
        $this->creds = zng_broker_credentials($mqttcfg);
    }

    /** host:port of the broker, for messages */
    public function address(): string
    {
        return (string) $this->creds["brokerhost"] . ":" . (string) $this->creds["brokerport"];
    }

    /**
     * Opens the connection. Returns false if no broker is configured or the
     * broker refuses the connection.
     */
    public function connect(): bool
    {
        if (empty($this->creds["brokerhost"]) || $this->topic === "") {
            return false;
        }
        $this->mqtt = zng_mqtt_verbinden($this->creds, "zigbee2mqttng");
        return $this->mqtt !== null;
    }

    /** once per request: the broker went away during it */
    private function weg($grund)
    {
        if (!$this->wegGemeldet) {
            $this->wegGemeldet = true;
            $text = "Broker went away during the request (" . $grund . ")";
            if (function_exists("LOGWARN") && isset($GLOBALS["log"])) {
                LOGWARN($text);
            } else {
                error_log("Zigbee2MqttNG: " . $text);
            }
        }
        if ($this->mqtt !== null) {
            $this->mqtt->schliessen();
            $this->mqtt = null;
        }
    }

    /**
     * Reads retained messages below the base topic, e.g. ["bridge/info",
     * "bridge/devices"]. Waits until all have arrived or the timeout is over.
     * Returns [sub topic => decoded JSON (or the raw string if it is no JSON)];
     * a topic that did not arrive is missing from the result.
     */
    public function retained(array $subTopics, float $timeout = 3.0): array
    {
        if ($this->mqtt === null) {
            return [];
        }
        $topics = [];
        foreach ($subTopics as $sub) {
            $topics[$this->topic . "/" . $sub] = ["qos" => 0, "function" => [$this, "onMessage"]];
        }
        $until = microtime(true) + $timeout;
        $hart = $until + 1.0;
        try {
            $this->mqtt->lesefrist = max(0.2, $hart - microtime(true));
            $this->mqtt->abonnieren($topics);
            while (count(array_intersect_key($this->messages, array_flip($subTopics))) < count($subTopics) && microtime(true) < $until) {
                $this->mqtt->lesefrist = max(0.1, $hart - microtime(true));
                $this->mqtt->proc();
            }
        } catch (ZngBrokerWeg $e) {
            $this->weg($e->getMessage());
            return [];
        }
        return array_intersect_key($this->messages, array_flip($subTopics));
    }

    /**
     * Sends a bridge request (e.g. "networkmap" with ["type" => "raw"]) and
     * returns the matching bridge/response as array, or null on timeout.
     */
    public function request(string $name, array $payload, float $timeout = 5.0)
    {
        if ($this->mqtt === null) {
            return null;
        }
        $transaction = bin2hex(random_bytes(6));
        $payload["transaction"] = $transaction;
        $answer = null;
        $until = microtime(true) + $timeout;
        $hart = $until + 1.0;
        try {
            $this->mqtt->lesefrist = max(0.2, $hart - microtime(true));
            $this->mqtt->abonnieren([
                $this->topic . "/bridge/response/" . $name => [
                    "qos" => 0,
                    "function" => function ($topic, $msg) use (&$answer, $transaction) {
                        $data = json_decode($msg, true);
                        if (is_array($data) && isset($data["transaction"]) && $data["transaction"] === $transaction) {
                            $answer = $data;
                        }
                    }
                ]
            ]);
            $this->mqtt->senden($this->topic . "/bridge/request/" . $name, json_encode($payload), false);
            while ($answer === null && microtime(true) < $until) {
                $this->mqtt->lesefrist = max(0.1, $hart - microtime(true));
                $this->mqtt->proc();
            }
        } catch (ZngBrokerWeg $e) {
            $this->weg($e->getMessage());
            return null;
        }
        return $answer;
    }

    /** @internal callback for retained() */
    public function onMessage($topic, $msg)
    {
        $sub = substr($topic, strlen($this->topic) + 1);
        $data = json_decode($msg, true);
        $this->messages[$sub] = ($data === null && $msg !== "null") ? $msg : $data;
    }

    public function close()
    {
        if ($this->mqtt !== null) {
            $this->mqtt->schliessen();
            $this->mqtt = null;
        }
    }
}
