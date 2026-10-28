<?php

/**
 * MQTT Configuration class
 */
class MqttConfig
{

    /**
     * Use the mqtt-gateway mqtt server instead of a custom mqtt server
     * @var bool
     */

    public $usemqttgateway = false;
    /**
     * The mqtt topic
     * @var string
     */
    public $topic = '';

    /**
     * The mqtt server username
     *  @var string */
    public $username = '';

    /**
     * The mqtt server password
     * @var string */
    public $password = '';

    /**
     * The mqtt server url
     *  @var string */
    public $server = '';

    /**
     * The mqtt server port
     * @var string */
    public $port = '';

    /**
     * Register mqtt topic on mqtt gateway
     */
    public $registerMqttTopic = false;

    /**
     * What is registered on the mqtt gateway: "devices" (only the state
     * topics of devices and groups) or "all" (<topic>/#)
     * @var string
     */
    public $forwardMode = 'devices';

    /**
     * Publish doors and locks under haus/tuer/<name>/offen|verriegelt
     * (house convention shared with Matter2Lox)
     * @var bool
     */
    public $hausTopics = false;

    /**
     * 4.2.5 (Gruppe 38, E1): indoor climate under haus/klima/<name>/..., off by default
     * @var bool
     */
    public $hausKlima = false;

    /**
     * 4.2.5 (E1): outdoor sensors (marked "Wetter (außen)") under haus/wetter/<name>/..., off by default
     * @var bool
     */
    public $hausWetter = false;

    /**
     * 4.2.5 (E1): smoke, water, gas and CO detectors under haus/alarm/<name>/..., off by default
     * @var bool
     */
    public $hausAlarm = false;

    /**
     * 4.2.5 (E1): area per device: { "<ieee>": "klima" | "wetter" | "aus" }.
     * A device that is missing gets the proposal: klima with a klima field,
     * otherwise aus.
     * @var array
     */
    public $hausBereiche = array();

    /**
     * 4.2.5 (E1): alarm devices whose alarm is NOT reported (list of IEEE)
     * @var array
     */
    public $hausAlarmAus = array();

    /**
     * Creats a new instance
     */
    public function __construct()
    {
    }

    /***
     * Loads the configuration and creates a new instance of the class.
     * Only known settings are taken over.
     */
    public static function load()
    {
        $mqttconfigfile = LBPCONFIGDIR . "/mqtt.json";
        $data = json_decode(@file_get_contents($mqttconfigfile), true);
        $class = new MqttConfig();
        foreach ((is_array($data) ? $data : array()) as $key => $value) {
            if (property_exists($class, $key)) {
                $class->{$key} = $value;
            }
        }
        return $class;
    }

    /**
     * Checks the values. Returns a list of language keys of the errors.
     */
    public function validate()
    {
        $daten = get_object_vars($this);
        $errors = array();
        foreach ($daten as $key => $value) {
            list($ok, $result) = self::wert($key, $value, $daten);
            if (!$ok && !in_array($result, $errors, true)) {
                $errors[] = $result;
            }
        }
        return $errors;
    }

    /**
     * 4.1.1 (B11): checks one value. Returns array(true, normalised value)
     * or array(false, language key of the error). $daten holds all values,
     * for the checks that depend on another one. Used by the form, the
     * restore of a backup and update-config.php.
     */
    public static function wert($key, $value, $daten)
    {
        $gateway = isset($daten["usemqttgateway"]) && zng_haken($daten["usemqttgateway"]) === true;
        switch ($key) {
            case "usemqttgateway":
            case "registerMqttTopic":
            case "hausTopics":
            case "hausKlima":
            case "hausWetter":
            case "hausAlarm":
                $b = zng_haken($value);
                return $b === null ? array(false, "Mqtt.ValSwitch") : array(true, $b);
            case "hausBereiche":
                // 4.2.5 (E1): an object IEEE => klima|wetter|aus - one area per device
                if (!is_array($value)) {
                    return array(false, "Mqtt.ValHausBereich");
                }
                foreach ($value as $ieee => $b) {
                    if (!zng_ieee_ok((string) $ieee) || !in_array($b, array("klima", "wetter", "aus"), true)) {
                        return array(false, "Mqtt.ValHausBereich");
                    }
                }
                return array(true, $value);
            case "hausAlarmAus":
                if (!is_array($value) || ($value && array_keys($value) !== range(0, count($value) - 1))) {
                    return array(false, "Mqtt.ValHausGeraet");
                }
                foreach ($value as $ieee) {
                    if (!zng_ieee_ok($ieee)) {
                        return array(false, "Mqtt.ValHausGeraet");
                    }
                }
                return array(true, array_values(array_unique($value)));
            case "topic":
                $ok = is_string($value) && $value !== "" && strlen($value) <= 200
                    && strpbrk($value, "+#") === false && strpos($value, "//") === false
                    && $value[0] !== "/" && substr($value, -1) !== "/" && !preg_match('/[\s\x00-\x1F\x7F]/', $value);
                return $ok ? array(true, $value) : array(false, "Mqtt.ValInvalidTopic");
            case "server":
                // with the gateway the field is hidden and unused: only the type is checked
                if ($gateway) {
                    return zng_text_ok($value, 253) ? array(true, $value) : array(false, "Mqtt.ValInvalidServer");
                }
                return is_string($value) && preg_match('/^[A-Za-z0-9.\-:\[\]]{1,253}\z/', $value)
                    ? array(true, $value) : array(false, "Mqtt.ValInvalidServer");
            case "port":
                if (is_int($value)) {
                    $value = (string) $value;
                }
                if ($gateway) {
                    return zng_text_ok($value, 5) ? array(true, $value) : array(false, "Mqtt.ValInvalidPort");
                }
                $port = zng_zahl_text($value, 1, 65535, '/^[0-9]{1,5}\z/');
                return $port === null ? array(false, "Mqtt.ValInvalidPort") : array(true, $port);
            case "username":
                return zng_text_ok($value) ? array(true, $value) : array(false, "Mqtt.ValInvalidUser");
            case "password":
                return zng_text_ok($value) ? array(true, $value) : array(false, "Mqtt.ValInvalidPassword");
            case "forwardMode":
                return in_array($value, array("devices", "all"), true) ? array(true, $value) : array(false, "Mqtt.ValForwardMode");
        }
        return array(false, "Common.UnknownKey");
    }

    /**
     * 4.1.1 (B1): state of mqtt.json - "fehlt", "ok" or "kaputt"
     */
    public static function lage()
    {
        return zng_json_lage(LBPCONFIGDIR . "/mqtt.json");
    }

    /**
     * The password is never sent to the browser. An empty password field
     * keeps the saved password, unless the user name was emptied as well.
     */
    public function keepFrom(MqttConfig $saved)
    {
        if ((string) $this->password === "" && (string) $this->username !== "") {
            $this->password = $saved->password;
        }
        // 4.2.5 (E1): hausBereiche/hausAlarmAus come from the device table,
        // see ajax.php setFormData() and zng_haus_bereiche_lesen()
    }

    /**
     * Saves the instance to the configuration file (JSON object for the
     * areas also when it is empty).
     */
    public function toJsonDaten()
    {
        $d = get_object_vars($this);
        $d["hausBereiche"] = (object) (is_array($this->hausBereiche) ? $this->hausBereiche : array());
        $d["hausAlarmAus"] = array_values(is_array($this->hausAlarmAus) ? $this->hausAlarmAus : array());
        return $d;
    }

    /**
     * Data for the form: without the password
     */
    public function toFormJson()
    {
        $data = get_object_vars($this);
        $data["password"] = "";
        $data["passwordSet"] = (string) $this->password !== "";
        return json_encode($data, JSON_PRETTY_PRINT);
    }

    /**
     * Saves the instance to the configuration file. Returns false if it
     * could not be written (the old file is kept then).
     */
    public function save()
    {
        // 4.1.1 (B4): in one piece and 0600 (broker password); the second
        // copy next to the config folder follows a readable state only
        $mqttconfigfile = LBPCONFIGDIR . "/mqtt.json";
        if (!zng_schreiben_wenn_anders($mqttconfigfile, $this->toJson(), ZNG_MODUS_GEHEIM)) {
            return false;
        }
        zng_zweitschrift_ziehen($mqttconfigfile, "json", LBPCONFIGDIR, "mqtt.json");
        return true;
    }

    /**
     * Creates a json string out of the class
     */
    public function toJson()
    {
        return json_encode($this->toJsonDaten(), JSON_PRETTY_PRINT);
    }
}
