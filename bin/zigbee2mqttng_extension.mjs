// Zigbee2MqttNG bridge extension for zigbee2mqtt.
//
// Installed by bin/update-config.php as data/external_extensions/zigbee2mqttng.mjs
// and configured through data/zigbee2mqttng.json. It
//  - keeps the device list (bridge/devices, bridge/groups, bridge/info) as files
//    for the web frontend (device list, Loxone templates, radio channel check),
//  - keeps the files of the LoxBerry MQTT gateway up to date
//    (mqtt_subscriptions.cfg: only the state topics, so the large bridge/*
//    messages never reach the Miniserver; mqtt_resetaftersend.cfg: the button
//    pulses),
//  - publishes <topic>/<device>/erreichbar (1/0) from the availability of
//    zigbee2mqtt - the same name and meaning as in Matter2Lox,
//  - publishes button presses as <topic>/<device>/aktion/<action>: a counter
//    that the gateway resets to 0 after sending, so Loxone sees one pulse per
//    press,
//  - optionally publishes the house topics for other plugins (Regeln/07
//    Nr. 15), each family with its own switch on the MQTT tab, off by default:
//        haus/tuer/<name>/offen|verriegelt        1/0, "-" without statement   retained
//        haus/klima/<name>/<feld>, .../zeit        numbers (HAUS_FELDER)         retained   (4.2.5)
//        haus/wetter/<name>/<feld>, .../zeit       outdoor sensors only          retained   (4.2.5)
//        haus/alarm/<name>/rauch|wasser|gas|co     1 alarm, 0 quiet, -1          retained   (4.2.5)
//        haus/<familie>/<name>/anbieter            "zigbee2mqttng"               retained   (4.2.5, E-E1 A)
//        haus/<familie>/zaehler/zigbee2mqttng      0...999 every minute          NEVER retained (4.2.5)
//    <name> is fixed once given (one name per device for all families), a
//    retained value of another provider is never overwritten. Removing the
//    topics with read back is done by bin/haus-leeren.php (4.2.5, V10).
//  - sends notifications (offline, low battery, alarm, firmware update) through
//    bin/notify.php, which decides the ways (LoxBerry, SignalBot, speech),
//  - 4.1.1: publishes a heartbeat for Loxone every minute, never retained:
//        <topic>/zigbee2mqttng/ts       unix seconds
//        <topic>/zigbee2mqttng/zaehler  0...999, wraps around
//        <topic>/zigbee2mqttng/updates  devices with a firmware update (4.2.5, -1 = no statement yet)
//    erreichbar and the device values keep their last value in Loxone when
//    zigbee2mqtt dies; only the heartbeat stops. A retained heartbeat would
//    say "alive" for a dead service (house rule Regeln/07).
//  - 4.2.0 (Z2MNG-E1): sends erreichbar of all devices again 30 s after
//    the gateway files changed and every 15 minutes, never retained -
//    see ERREICHBAR_NACH_MS.
//  - 4.2.5 (E4): <topic>/<device>/update_verfuegbar 1/0/-1 for devices that
//    support OTA, never retained; zigbee2mqttng_ota.json for the frontend.
//
// Do not edit the copy in data/external_extensions - it is overwritten.

import {execFile} from "node:child_process";
import fs from "node:fs";
import path from "node:path";

const HAUS_BASE = "haus";
const HAUS_ROOT = "tuer";
// 4.2.5 (E1): the families of house topics this plugin can provide
export const FAMILIEN = ["tuer", "klima", "wetter", "alarm"];
// 4.2.5 (V10, E-E1 A): never a <name> - they are topics of their own
export const RESERVIERT = ["zaehler", "anbieter"];
export const ANBIETER = "zigbee2mqttng";
const NO_STATEMENT = "-";
const UMLAUTS = [["ä", "ae"], ["ö", "oe"], ["ü", "ue"], ["ß", "ss"], ["Ä", "ae"], ["Ö", "oe"], ["Ü", "ue"]];
// Retained values under haus/<familie>/ arrive right after subscribing. Nothing is
// published there before this time, so a foreign value is known beforehand.
const FOREIGN_WAIT_MS = 3000;
const NOTIFY_DELAY_MS = 60000;
// Heartbeat below <topic>/ - must match zng_subscription_lines() and the
// templates in bin/zigbee2mqttng.php
export const HEARTBEAT_TOPIC = "zigbee2mqttng";
const HEARTBEAT_MS = 60000;
// 4.2.5 (E1): the full set of the house topics every 30 heartbeats (30 min),
// after the start and after switching a family on
export const HAUS_VOLL_TAKT = 30;
// Z2MNG-E1 (4.2.0, measured on the device 09.10.2026): when the gateway
// files change, the MQTT gateway unsubscribes all topics and subscribes
// again ~10 s later. An erreichbar sent in that gap (it is sent when a
// device joins - exactly when the files change) is lost, and Loxone keeps
// the old 0. 30 s after the LAST change the current erreichbar of every
// device is sent again; every further change moves the time on.
const ERREICHBAR_NACH_MS = 30000;
// ... and with every 15th heartbeat (15 min): a restart of the gateway or
// of the LoxBerry changes no file. Not every minute - that would push one
// message per device and minute through broker and gateway for a state
// that rarely changes. Not retained, as before: zigbee2mqtt states it,
// a retained 1 would outlive a dead service (Regeln/07).
export const ERREICHBAR_TAKT = 15;

// 4.2.5 (device test 28.10.): a device whose last message (herdsman lastSeen)
// is older than this many seconds, or unknown, is silent: its house values
// go to "no statement" (temperatur -999, other fields -1, haus/tuer "-");
// zeit keeps the real time. Checked on every heartbeat (60 s), so a sensor
// that falls silent switches after one hour and back with its next message.
export const STUMM_SEKUNDEN = 3600;

// true when the last message (unix seconds, -1 unknown) is too old or unknown
export function istStumm(zeit, jetzt) {
    return !(typeof zeit === "number" && zeit > 0) || jetzt - zeit > STUMM_SEKUNDEN;
}

// 4.2.5 (Gruppe 38, E1): fields of the house topics klima, wetter and alarm.
// One row: [families, field, property in the exposes, unit required ("" = any),
// kind (z1 number with one decimal, z0 whole number, b yes/no), no statement,
// lowest plausible value, highest plausible value]. Families "kw" = klima and
// wetter, "k" = klima only, "a" = alarm.
// VOC: passed on unchanged in the unit the device reports, the unit is in the
// name: µg/m³ (default of herdsman-converters e.voc()) -> voc_ugm3, ppb
// (e.voc().withUnit("ppb")) -> voc_ppb. Never converted - ppb <-> µg/m³
// depends on the gas mixture. VOC without a unit or with another unit gets no
// field.
// MUST BE WORD FOR WORD THE SAME AS zng_haus_felder() IN bin/zigbee2mqttng.php
// (the Test tab compares both, line "Haus-Felder").
// HAUS_FELDER BEGIN
export const HAUS_FELDER = [
    ["kw", "temperatur", "temperature", "", "z1", -999, -60, 100],
    ["kw", "feuchte", "humidity", "", "z0", -1, 0, 100],
    ["kw", "druck", "pressure", "hPa", "z1", -1, 300, 1200],
    ["kw", "helligkeit", "illuminance", "lx", "z0", -1, 0, 200000],
    ["k", "co2", "co2", "", "z0", -1, 0, 10000],
    ["k", "voc_ugm3", "voc", "µg/m³", "z0", -1, 0, 60000],
    ["k", "voc_ppb", "voc", "ppb", "z0", -1, 0, 60000],
    ["k", "pm25", "pm25", "", "z0", -1, 0, 1000],
    ["a", "rauch", "smoke", "", "b", -1, 0, 1],
    ["a", "wasser", "water_leak", "", "b", -1, 0, 1],
    ["a", "gas", "gas", "", "b", -1, 0, 1],
    ["a", "co", "carbon_monoxide", "", "b", -1, 0, 1],
];
// HAUS_FELDER END

function readJson(file, fallback) {
    try {
        return JSON.parse(fs.readFileSync(file, "utf8"));
    } catch {
        return fallback;
    }
}

// 4.2.5 (Gruppe 38, V9): in one piece - first <file>.tmp.<pid>, then rename;
// on failure the .tmp is removed and false is returned (the old file stays).
// Same as zng_write_if_changed() in bin/zigbee2mqttng.php.
export function writeIfChanged(file, content) {
    if (!file) {
        return false;
    }
    try {
        if (fs.existsSync(file) && fs.readFileSync(file, "utf8") === content) {
            return false;
        }
    } catch {
        // unreadable: written anew below
    }
    const tmp = `${file}.tmp.${process.pid}`;
    try {
        fs.writeFileSync(tmp, content);
        fs.renameSync(tmp, file);
        return true;
    } catch {
        try {
            fs.unlinkSync(tmp);
        } catch {
            // nothing left behind
        }
        return false;
    }
}

// Same rule as haus_name() in Matter2Lox: lower case, umlauts spelled out,
// everything except a-z 0-9 _ - becomes "_", at most 40 characters.
export function hausName(text) {
    let t = String(text ?? "");
    for (const [a, b] of UMLAUTS) {
        t = t.split(a).join(b);
    }
    t = t.toLowerCase().replace(/[^a-z0-9_-]+/g, "_").replace(/^_+|_+$/g, "");
    return t.slice(0, 40).replace(/_+$/, "");
}

// Name of a value as the MQTT gateway forwards it: "/", " " and "%" become "_"
// (4.2.4, Entwurf zb E8: spaces too, as the gateway does; same result as zng_gateway_name() in zigbee2mqttng.php)
export function gatewayName(topic) {
    return String(topic).replace(/[/ %]/g, "_");
}

function topicOk(name) {
    return typeof name === "string" && name !== "" && !/[+#]/.test(name);
}

// Action values a device can send (expose "action" of type enum)
export function actionValues(device) {
    const values = new Set();
    const walk = (exposes) => {
        for (const e of exposes ?? []) {
            if (Array.isArray(e?.features)) {
                walk(e.features);
            } else if (e?.property === "action" && e?.type === "enum" && Array.isArray(e.values)) {
                for (const v of e.values) {
                    if (actionOk(v)) {
                        values.add(String(v));
                    }
                }
            }
        }
    };
    walk(device?.definition?.exposes);
    return [...values];
}

export function actionOk(value) {
    return typeof value === "string" && /^[A-Za-z0-9_-]{1,40}$/.test(value);
}

function stateDevices(devices) {
    return (devices ?? []).filter((d) => d?.type !== "Coordinator" && topicOk(d?.friendly_name));
}

// 4.2.5 (E4): does the device support OTA (bridge/devices definition.supports_ota)?
export function supportsOta(device) {
    return device?.definition?.supports_ota === true;
}

// Must give the same result as zng_subscription_lines() in bin/zigbee2mqttng.php
export function subscriptionLines(base, devices, groups, availability, haus) {
    const lines = [`${base}/bridge/state`, `${base}/${HEARTBEAT_TOPIC}/#`];
    for (const device of stateDevices(devices)) {
        lines.push(`${base}/${device.friendly_name}`);
        if (availability) {
            lines.push(`${base}/${device.friendly_name}/erreichbar`);
        }
        if (actionValues(device).length > 0) {
            lines.push(`${base}/${device.friendly_name}/aktion/+`);
        }
        if (supportsOta(device)) {
            lines.push(`${base}/${device.friendly_name}/update_verfuegbar`);
        }
    }
    for (const group of groups ?? []) {
        if (topicOk(group?.friendly_name)) {
            lines.push(`${base}/${group.friendly_name}`);
        }
    }
    if (haus) {
        lines.push(`${HAUS_BASE}/${HAUS_ROOT}/#`);
    }
    return `${lines.join("\n")}\n`;
}

// Must give the same result as zng_reset_lines() in bin/zigbee2mqttng.php
export function resetLines(base, devices) {
    const lines = [];
    for (const device of stateDevices(devices)) {
        for (const value of actionValues(device)) {
            lines.push(gatewayName(`${base}/${device.friendly_name}/aktion/${value}`));
        }
    }
    return lines.length > 0 ? `${lines.join("\n")}\n` : "";
}

// Values for the house topics from a device state, or {} if the device has
// neither a contact nor a lock. contact true means "closed" in zigbee2mqtt.
export function hausValues(state) {
    const values = {};
    if (state && typeof state.contact === "boolean") {
        values.offen = state.contact ? 0 : 1;
    }
    if (state && typeof state.lock_state === "string") {
        values.verriegelt = state.lock_state === "locked" ? 1 : 0;
    }
    return values;
}

// 4.2.5 (E1): the HAUS_FELDER rows a device has in its exposes (also nested in
// features, as actionValues()), as {kw: [rows], k: [rows], a: [rows]} by row.
// Same as zng_haus_geraet_felder() in bin/zigbee2mqttng.php.
export function hausFelderGeraet(device) {
    const gefunden = [];
    const walk = (exposes) => {
        for (const e of exposes ?? []) {
            if (Array.isArray(e?.features)) {
                walk(e.features);
                continue;
            }
            for (const r of HAUS_FELDER) {
                if (e?.property !== r[2] || gefunden.includes(r)) {
                    continue;
                }
                if (r[4] === "b" ? e?.type !== "binary" : e?.type !== "numeric") {
                    continue;
                }
                if (r[3] !== "" && e?.unit !== r[3]) {
                    continue;
                }
                gefunden.push(r);
            }
        }
    };
    walk(device?.definition?.exposes);
    return HAUS_FELDER.filter((r) => gefunden.includes(r));
}

// 4.2.5 (E1): a raw value of the state as the house value: rounded, within
// the plausible limits, otherwise "no statement". Same as zng_haus_wert().
export function hausWert(row, roh) {
    if (row[4] === "b") {
        if (roh === true) {
            return 1;
        }
        return roh === false ? 0 : row[5];
    }
    if (typeof roh !== "number" || !Number.isFinite(roh) || roh < row[6] || roh > row[7]) {
        return row[5];
    }
    return row[4] === "z1" ? Math.round(roh * 10) / 10 : Math.round(roh);
}

// 4.2.5 (E1): the family of a device for klima/wetter: what the user chose in
// hausBereiche, otherwise klima when the device has a klima field, else aus.
export function hausBereich(ieee, felder, bereiche) {
    const b = bereiche?.[ieee];
    if (b === "klima" || b === "wetter" || b === "aus") {
        return b;
    }
    return felder.some((r) => r[0] === "kw" || r[0] === "k") ? "klima" : "aus";
}

// Gives every device that needs one a fixed <name>. Names already given stay,
// even when the device is renamed in zigbee2mqtt. A new name that is taken
// gets the last four digits of the IEEE address appended. 4.2.5: the reserved
// names zaehler and anbieter are skipped (zaehler_2, ...).
export function assignHausNames(names, devices) {
    const result = {...names};
    const used = new Set(Object.values(result));
    for (const device of devices) {
        if (result[device.ieee]) {
            continue;
        }
        let name = hausName(device.name) || hausName(device.ieee);
        let plain = name;
        if (RESERVIERT.includes(name)) {
            name = `${name}_2`;
        } else if (used.has(name)) {
            name = `${name}_${String(device.ieee).slice(-4)}`;
            plain = name;
        }
        let n = 2;
        while (used.has(name) || RESERVIERT.includes(name)) {
            name = `${plain}_${n++}`;
        }
        used.add(name);
        result[device.ieee] = name;
    }
    return result;
}

export default class Zigbee2MqttNGExtension {
    constructor(zigbee, mqtt, state, publishEntityState, eventBus, enableDisableExtension, restartCallback, addExtension, settings, logger) {
        this.zigbee = zigbee;
        this.mqtt = mqtt;
        this.state = state;
        this.eventBus = eventBus;
        this.settings = settings;
        this.logger = logger;
        this.devices = null;
        this.groups = null;
        this.foreign = new Map();
        this.foreignReady = false;
        this.actionCounters = new Map();
        this.notifyQueue = [];
        this.notifyTimer = null;
        this.notified = {};
        this.conflicts = new Set();
        this.heartbeatTimer = null;
        this.heartbeatCount = 0;
        this.heartbeat = null;
        this.erreichbarTimer = null;
        // 4.2.5: last house value sent per topic (only send on change)
        this.hausLetzte = new Map();
        // 4.2.5 (device test 28.10.): last silent state per IEEE (stummTakt)
        this.stummLetzt = new Map();
        // 4.2.5 (E4): firmware state per device, update_verfuegbar sent last
        this.ota = {};
        this.otaGesendet = new Map();
    }

    // which house families are switched on (4.2.5)
    familienAn() {
        const an = {tuer: !!this.cfg?.hausTopics, klima: !!this.cfg?.hausKlima, wetter: !!this.cfg?.hausWetter, alarm: !!this.cfg?.hausAlarm};
        return FAMILIEN.filter((f) => an[f]);
    }

    async start() {
        const dataDir = process.env.ZIGBEE2MQTT_DATA || path.join(process.cwd(), "data");
        this.cfg = readJson(path.join(dataDir, "zigbee2mqttng.json"), {});
        this.base = this.settings.get().mqtt.base_topic;
        this.notified = readJson(this.cfg.notifyFile, {});
        this.ota = readJson(this.cfg.otaFile, {});
        if (typeof this.ota !== "object" || this.ota === null || Array.isArray(this.ota)) {
            this.ota = {};
        }

        this.eventBus.onMQTTMessagePublished(this, (data) => this.onPublished(data));
        this.eventBus.onStateChange(this, (data) => this.onStateChange(data));
        this.eventBus.onMQTTMessage(this, (data) => this.onMessage(data));

        this.started = Date.now();
        this.writeStatus();

        // Heartbeat for Loxone: right away, then every minute
        await this.sendHeartbeat().catch((e) => this.logger.warning(`Zigbee2MqttNG: ${e}`));
        this.heartbeatTimer = setInterval(() => {
            this.sendHeartbeat().catch((e) => this.logger.warning(`Zigbee2MqttNG: ${e}`));
        }, HEARTBEAT_MS);

        // The retained messages were published before this extension was
        // loaded - pick them up from the cache of the MQTT controller.
        for (const retained of Object.values(this.mqtt.retainedMessages ?? {})) {
            if (retained?.topic && retained?.options?.baseTopic === this.base) {
                this.onPublished({topic: `${this.base}/${retained.topic}`, payload: retained.payload});
            }
        }

        const an = this.familienAn();
        if (an.length > 0) {
            // Find out which retained values below haus/<familie>/ belong to
            // another provider (e.g. Matter2Lox) before publishing anything.
            for (const f of an) {
                await this.mqtt.subscribe(`${HAUS_BASE}/${f}/#`);
            }
            this.foreignTimer = setTimeout(() => {
                this.foreignReady = true;
                this.syncHaus().catch((e) => this.logger.warning(`Zigbee2MqttNG: ${e}`));
            }, FOREIGN_WAIT_MS);
        }
        // Families switched off: a fast first move. The topics stay in the
        // remembered list until bin/haus-leeren.php confirmed by reading back
        // (V10) - the list is the marker "was provider".
        for (const f of FAMILIEN.filter((x) => !an.includes(x))) {
            await this.clearHaus(f);
        }
    }

    async stop() {
        clearInterval(this.heartbeatTimer);
        this.heartbeatTimer = null;
        clearTimeout(this.erreichbarTimer);
        this.erreichbarTimer = null;
        clearTimeout(this.foreignTimer);
        clearTimeout(this.notifyTimer);
        this.flushNotifications();
        this.eventBus.removeListeners(this);
        for (const f of this.familienAn()) {
            await this.mqtt.unsubscribe(`${HAUS_BASE}/${f}/#`).catch(() => {});
        }
    }

    adjustMessageBeforePublish() {}

    // Read by the Test tab: is the extension loaded, which house topics
    // could not be sent because another provider owns them, the last
    // heartbeat (also of the house families)
    writeStatus() {
        writeIfChanged(this.cfg.statusFile, JSON.stringify({started: this.started, conflicts: [...this.conflicts],
            heartbeat: this.heartbeat, familien: this.familienAn()}, null, 1));
    }

    // ---------------- heartbeat for Loxone (4.1.1) ----------------

    // ts (unix seconds) and zaehler (0...999). Never retained, not logged
    // every minute. The Test tab reads the last one from the status file.
    async sendHeartbeat() {
        const ts = Math.floor(Date.now() / 1000);
        const zaehler = this.heartbeatCount;
        this.heartbeatCount = (this.heartbeatCount + 1) % 1000;
        this.heartbeat = {ts, zaehler};
        this.writeStatus();
        const options = {clientOptions: {retain: false}, skipLog: true};
        await this.mqtt.publish(`${HEARTBEAT_TOPIC}/ts`, String(ts), options);
        await this.mqtt.publish(`${HEARTBEAT_TOPIC}/zaehler`, String(zaehler), options);
        // 4.2.5 (E4): devices with a firmware update, -1 while no device said anything
        await this.mqtt.publish(`${HEARTBEAT_TOPIC}/updates`, String(this.otaAnzahl()), options);
        // 4.2.5 (V10, E1, E-E1 A): the heartbeat of every house family that is on,
        // haus/<familie>/zaehler/zigbee2mqttng - never retained
        for (const f of this.familienAn()) {
            await this.mqtt.publish(`${f}/zaehler/${ANBIETER}`, String(zaehler),
                {baseTopic: HAUS_BASE, clientOptions: {retain: false}, skipLog: true});
        }
        if (zaehler > 0 && zaehler % ERREICHBAR_TAKT === 0) {
            await this.erreichbarNachsenden();
            await this.otaNachsenden();
        }
        if (zaehler > 0 && zaehler % HAUS_VOLL_TAKT === 0 && this.foreignReady) {
            await this.syncHaus(true);
        }
        // 4.2.5 (device test 28.10.): silent devices, every heartbeat
        await this.stummTakt().catch((e) => this.logger.warning(`Zigbee2MqttNG: ${e}`));
    }

    // Sends the house values of every device whose silent state changed since
    // the last heartbeat (silent -> no statement, awake -> real values again).
    async stummTakt() {
        if (!this.foreignReady || this.devices === null || this.familienAn().length === 0) {
            return;
        }
        const jetzt = Math.floor(Date.now() / 1000);
        for (const d of this.hausDevices()) {
            const stumm = istStumm(this.lastSeen(d.ieee), jetzt);
            if (this.stummLetzt.get(d.ieee) === stumm) {
                continue;
            }
            this.stummLetzt.set(d.ieee, stumm);
            let st = {};
            try {
                st = this.state.get(this.zigbee.resolveEntity(d.ieee)) ?? {};
            } catch {
                st = {};
            }
            if (this.cfg.hausTopics) {
                await this.publishHaus(d.ieee, st);
            }
            await this.publishHausFelder(d.ieee, st, false);
        }
    }

    stumm(ieee) {
        return istStumm(this.lastSeen(ieee), Math.floor(Date.now() / 1000));
    }

    onPublished(data) {
        const topic = data?.topic;
        if (!topic || !topic.startsWith(`${this.base}/`)) {
            return;
        }
        const part = topic.substring(this.base.length + 1);
        if (part === "bridge/devices" || part === "bridge/groups" || part === "bridge/info") {
            this.onBridge(part, data.payload);
            return;
        }
        if (part.startsWith("bridge/")) {
            return;
        }
        if (part.endsWith("/availability")) {
            this.onAvailability(part.slice(0, -"/availability".length), data.payload);
            return;
        }
        this.onDeviceMessage(part, data.payload);
    }

    onBridge(part, raw) {
        let payload;
        try {
            payload = JSON.parse(raw);
        } catch {
            return;
        }
        if (part === "bridge/info") {
            // only what the frontend needs - the full info carries the network key
            const info = {version: payload?.version, network: payload?.network, coordinator: payload?.coordinator,
                permit_join: payload?.permit_join, permit_join_end: payload?.permit_join_end};
            writeIfChanged(this.cfg.infoFile, JSON.stringify(info, null, 1));
            return;
        }
        if (part === "bridge/devices") {
            this.devices = payload;
            writeIfChanged(this.cfg.devicesFile, JSON.stringify(payload));
            if (this.familienAn().length > 0 && this.foreignReady) {
                this.syncHaus().catch((e) => this.logger.warning(`Zigbee2MqttNG: ${e}`));
            }
        } else {
            this.groups = payload;
            writeIfChanged(this.cfg.groupsFile, JSON.stringify(payload));
        }
        this.updateGatewayFiles();
    }

    updateGatewayFiles() {
        if (!this.cfg.registerTopics || this.devices === null) {
            return;
        }
        const groups = this.groups ?? readJson(this.cfg.groupsFile, []);
        let changed = false;
        if (this.cfg.forwardMode === "devices") {
            changed = writeIfChanged(this.cfg.subscriptionFile,
                subscriptionLines(this.base, this.devices, groups, this.cfg.availability, this.cfg.hausTopics)) || changed;
        }
        changed = writeIfChanged(this.cfg.resetFile, resetLines(this.base, this.devices)) || changed;
        if (changed) {
            this.logger.info("Zigbee2MqttNG: MQTT gateway subscriptions updated");
            this.erreichbarSpaeter();
        }
    }

    // ---------------- availability -> erreichbar ----------------

    // Z2MNG-E1: send erreichbar of all devices again ERREICHBAR_NACH_MS after
    // the last change of the gateway files (debounced)
    erreichbarSpaeter() {
        clearTimeout(this.erreichbarTimer);
        this.erreichbarTimer = setTimeout(() => {
            this.erreichbarTimer = null;
            this.erreichbarNachsenden().catch((e) => this.logger.warning(`Zigbee2MqttNG: ${e}`));
            this.otaNachsenden().catch((e) => this.logger.warning(`Zigbee2MqttNG: ${e}`));
        }, ERREICHBAR_NACH_MS);
    }

    // The last state zigbee2mqtt reported (the availability file the device
    // list reads), only for devices whose erreichbar is subscribed at the
    // gateway (stateDevices, as in subscriptionLines). No notification, not
    // logged, not retained. Returns the number of messages.
    async erreichbarNachsenden() {
        if (!this.cfg.availability || this.devices === null) {
            return 0;
        }
        this.availability = this.availability ?? readJson(this.cfg.availabilityFile, {});
        let n = 0;
        for (const device of stateDevices(this.devices)) {
            const online = this.availability[device.friendly_name];
            if (typeof online !== "boolean") {
                continue;
            }
            await this.mqtt.publish(`${device.friendly_name}/erreichbar`, online ? "1" : "0",
                {clientOptions: {retain: false}, skipLog: true});
            n++;
        }
        return n;
    }


    async onAvailability(name, raw) {
        if (!this.cfg.availability || !topicOk(name)) {
            return;
        }
        let state = raw;
        try {
            const parsed = JSON.parse(raw);
            state = typeof parsed === "object" && parsed !== null ? parsed.state : parsed;
        } catch {
            // legacy payload "online" / "offline"
        }
        if (state !== "online" && state !== "offline") {
            return;
        }
        // for the device list and the Test tab
        this.availability = this.availability ?? readJson(this.cfg.availabilityFile, {});
        if (this.availability[name] !== (state === "online")) {
            this.availability[name] = state === "online";
            writeIfChanged(this.cfg.availabilityFile, JSON.stringify(this.availability, null, 1));
        }
        // Not retained, like in Matter2Lox: zigbee2mqtt publishes the
        // availability again on every start.
        await this.mqtt.publish(`${name}/erreichbar`, state === "online" ? "1" : "0", {clientOptions: {retain: false}});
        if (this.cfg.notifyOffline) {
            this.trackOffline(name, state === "offline");
        }
        // 4.2.5 (E1): offline -> every house field of the device once without
        // statement (zeit keeps its last value); online -> the full set at once
        await this.hausVerfuegbarkeit(name, state === "online").catch((e) => this.logger.warning(`Zigbee2MqttNG: ${e}`));
    }

    // ---------------- buttons -> aktion/<value>, firmware ----------------

    async onDeviceMessage(name, raw) {
        if (!raw || raw[0] !== "{") {
            return;
        }
        let payload;
        try {
            payload = JSON.parse(raw);
        } catch {
            return;
        }
        if (this.cfg.notifyBattery && typeof payload?.battery === "number") {
            this.trackBattery(name, payload.battery);
        }
        if (payload?.update && typeof payload.update === "object" && this.isDevice(name)) {
            await this.onOta(name, payload.update).catch((e) => this.logger.warning(`Zigbee2MqttNG: ${e}`));
        }
        const action = payload?.action;
        if (!actionOk(action) || !this.isDevice(name)) {
            return;
        }
        const key = `${name}/${action}`;
        const count = (this.actionCounters.get(key) ?? 0) + 1;
        this.actionCounters.set(key, count);
        await this.mqtt.publish(`${name}/aktion/${action}`, String(count), {clientOptions: {retain: false}});
    }

    isDevice(name) {
        return (this.devices ?? []).some((d) => d?.friendly_name === name && d?.type !== "Coordinator");
    }

    deviceByName(name) {
        return (this.devices ?? []).find((d) => d?.friendly_name === name && d?.type !== "Coordinator");
    }

    // ---------------- 4.2.5 (E4): firmware updates ----------------

    // update = {state: available|idle|updating|scheduled, installed_version,
    // latest_version, progress, remaining} from the device state
    async onOta(name, update) {
        const zustand = typeof update.state === "string" ? update.state : "";
        const eintrag = {state: zustand, installed: update.installed_version ?? null, latest: update.latest_version ?? null,
            progress: typeof update.progress === "number" ? update.progress : null, zeit: Math.floor(Date.now() / 1000)};
        const alt = this.ota[name];
        if (!alt || alt.state !== eintrag.state || alt.installed !== eintrag.installed || alt.latest !== eintrag.latest
            || alt.progress !== eintrag.progress) {
            this.ota[name] = eintrag;
            writeIfChanged(this.cfg.otaFile, JSON.stringify(this.ota, null, 1));
        }
        await this.otaSenden(name, false);
        if (zustand === "available" && this.cfg.notifyUpdate && eintrag.latest !== null) {
            const key = `update:${name}`;
            if (this.notified[key] !== String(eintrag.latest)) {
                this.notified[key] = String(eintrag.latest);
                this.queueNotification(`update:${name}:${eintrag.latest}`);
            }
        }
    }

    // 1 available, 0 idle/scheduled/updating, -1 no statement
    otaWert(name) {
        const z = this.ota[name]?.state;
        if (z === "available") {
            return 1;
        }
        return z === "idle" || z === "scheduled" || z === "updating" ? 0 : -1;
    }

    otaAnzahl() {
        const namen = Object.keys(this.ota);
        if (namen.length === 0) {
            return -1;
        }
        return namen.filter((n) => this.ota[n]?.state === "available").length;
    }

    // <device>/update_verfuegbar: only for devices with supports_ota, never
    // retained, on change (or always with immer)
    async otaSenden(name, immer) {
        const device = this.deviceByName(name);
        if (!device || !supportsOta(device) || !topicOk(name)) {
            return false;
        }
        const wert = String(this.otaWert(name));
        if (!immer && this.otaGesendet.get(name) === wert) {
            return false;
        }
        this.otaGesendet.set(name, wert);
        await this.mqtt.publish(`${name}/update_verfuegbar`, wert, {clientOptions: {retain: false}, skipLog: true});
        return true;
    }

    // with every 15th heartbeat and 30 s after the gateway files changed, like erreichbar
    async otaNachsenden() {
        let n = 0;
        for (const device of stateDevices(this.devices ?? [])) {
            if (await this.otaSenden(device.friendly_name, true)) {
                n++;
            }
        }
        return n;
    }

    // ---------------- notifications ----------------

    trackOffline(name, offline) {
        const key = `offline:${name}`;
        if (offline && !this.notified[key]) {
            this.notified[key] = Date.now();
            this.queueNotification(`offline:${name}`);
        } else if (!offline && this.notified[key]) {
            delete this.notified[key];
            this.saveNotified();
        }
    }

    trackBattery(name, level) {
        const key = `battery:${name}`;
        const threshold = Number(this.cfg.batteryThreshold) || 15;
        if (level <= threshold && !this.notified[key]) {
            this.notified[key] = Date.now();
            this.queueNotification(`battery:${name}:${level}`);
        } else if (level > threshold + 10 && this.notified[key]) {
            // a new battery: report again next time
            delete this.notified[key];
            this.saveNotified();
        }
    }

    // 4.2.5 (E2): alarm:<device>:<art> on a change false -> true of an alarm
    // field, at once (not collected for a minute); again only after it was false
    trackAlarm(name, feld, an) {
        const key = `alarm:${name}:${feld}`;
        if (an && !this.notified[key]) {
            this.notified[key] = Date.now();
            this.saveNotified();
            this.runNotify([`alarm:${name}:${feld}`]);
        } else if (!an && this.notified[key]) {
            delete this.notified[key];
            this.saveNotified();
        }
    }

    saveNotified() {
        writeIfChanged(this.cfg.notifyFile, JSON.stringify(this.notified, null, 1));
    }

    // Collects the events of a minute into one notification, so a power cut
    // does not send one notification per device.
    queueNotification(event) {
        this.saveNotified();
        this.notifyQueue.push(event);
        if (this.notifyTimer === null) {
            this.notifyTimer = setTimeout(() => this.flushNotifications(), NOTIFY_DELAY_MS);
        }
    }

    flushNotifications() {
        this.notifyTimer = null;
        if (this.notifyQueue.length === 0 || !this.cfg.notifyScript) {
            this.notifyQueue = [];
            return;
        }
        const events = this.notifyQueue;
        this.notifyQueue = [];
        this.runNotify(events);
    }

    runNotify(events) {
        if (!this.cfg.notifyScript || events.length === 0) {
            return;
        }
        execFile("php", [this.cfg.notifyScript, ...events], {timeout: 60000}, (error) => {
            if (error) {
                this.logger.warning(`Zigbee2MqttNG: notification failed: ${error.message}`);
            }
        });
    }

    // ---------------- house topics ----------------

    onMessage(data) {
        const topic = data?.topic;
        if (!topic || !topic.startsWith(`${HAUS_BASE}/`)) {
            return;
        }
        const teile = topic.split("/");
        if (teile.length !== 4 || !FAMILIEN.includes(teile[1]) || RESERVIERT.includes(teile[2])) {
            return;
        }
        // only the values, and the provider of a name (E-E1 A)
        const feld = teile[3];
        const felder = teile[1] === "tuer" ? ["offen", "verriegelt"] : HAUS_FELDER.map((r) => r[1]);
        if (!felder.includes(feld) && feld !== "anbieter") {
            return;
        }
        const remembered = readJson(this.cfg.hausFile, {});
        if (remembered[topic] || data.message === "" || (feld === "anbieter" && data.message === ANBIETER)) {
            // our own value from an earlier run, or a deleted value
            this.foreign.delete(topic);
            return;
        }
        this.foreign.set(topic, data.message);
    }

    hausDevices() {
        return stateDevices(this.devices ?? []).map((d) => ({ieee: d.ieee_address, name: d.friendly_name}));
    }

    // 4.2.5 (E1): per family the devices that report and their fields:
    // [{ieee, name (friendly), felder: [rows]}]
    hausZuordnung(familie) {
        const liste = [];
        for (const d of stateDevices(this.devices ?? [])) {
            if (familie === "tuer") {
                continue;
            }
            const felder = hausFelderGeraet(d);
            if (familie === "alarm") {
                const a = felder.filter((r) => r[0] === "a");
                if (a.length > 0 && !(this.cfg.hausAlarmAus ?? []).includes(d.ieee_address)) {
                    liste.push({ieee: d.ieee_address, name: d.friendly_name, felder: a});
                }
                continue;
            }
            const bereich = hausBereich(d.ieee_address, felder, this.cfg.hausBereiche ?? {});
            if (bereich !== familie) {
                continue;
            }
            const kf = felder.filter((r) => r[0] === "kw" || (r[0] === "k" && familie === "klima"));
            if (kf.length > 0) {
                liste.push({ieee: d.ieee_address, name: d.friendly_name, felder: kf});
            }
        }
        return liste;
    }

    hausNamen() {
        let names = readJson(this.cfg.hausNamesFile, {});
        const fehlt = this.hausDevices().some((d) => !names[d.ieee]);
        if (fehlt) {
            names = assignHausNames(names, this.hausDevices());
            writeIfChanged(this.cfg.hausNamesFile, JSON.stringify(names, null, 1));
        }
        return names;
    }

    async onStateChange(data) {
        if (!data?.entity?.isDevice?.()) {
            return;
        }
        const update = data.update ?? {};
        const ieee = data.entity.ieeeAddr;
        // 4.2.5 (E2): alarm notifications, independent of the house topics
        if (this.cfg.notifyAlarm) {
            for (const r of HAUS_FELDER.filter((x) => x[0] === "a")) {
                if (r[2] in update) {
                    this.trackAlarm(data.entity.name, r[1], update[r[2]] === true);
                }
            }
        }
        if (!this.foreignReady || this.familienAn().length === 0) {
            return;
        }
        if (this.cfg.hausTopics && (("contact" in update) || ("lock_state" in update))) {
            await this.publishHaus(ieee, data.to);
        }
        if (HAUS_FELDER.some((r) => r[2] in update)) {
            await this.publishHausFelder(ieee, data.to, false);
        }
    }

    // Publishes all house values and sends "no statement" once for the topics
    // of devices that are no longer in the network. voll: also unchanged values.
    async syncHaus(voll = false) {
        if (this.devices === null || this.familienAn().length === 0) {
            return;
        }
        if (voll) {
            this.hausLetzte.clear();
        }
        this.hausNamen();
        // A remembered topic maps to the IEEE address of its device; "-" in
        // front marks a topic whose "no statement" has already been sent.
        const present = new Set(this.hausDevices().map((d) => d.ieee));
        const remembered = readJson(this.cfg.hausFile, {});
        const an = this.familienAn();
        let changed = false;
        for (const [topic, ieee] of Object.entries(remembered)) {
            const teile = topic.split("/");
            if (!an.includes(teile[1]) || String(ieee).startsWith("-") || present.has(ieee)) {
                continue;
            }
            if (teile[3] !== "anbieter" && teile[3] !== "zeit") {
                await this.sendHaus(topic, this.ohneAussage(teile[1], teile[3]));
            }
            remembered[topic] = `-${ieee}`;
            changed = true;
        }
        if (changed) {
            writeIfChanged(this.cfg.hausFile, JSON.stringify(remembered, null, 1));
        }
        for (const device of this.zigbee.devicesIterator((d) => d.type !== "Coordinator" && present.has(d.ieeeAddr))) {
            const st = this.state.get(device);
            if (this.cfg.hausTopics) {
                await this.publishHaus(device.ieeeAddr, st);
            }
            await this.publishHausFelder(device.ieeeAddr, st, voll);
        }
    }

    ohneAussage(familie, feld) {
        if (familie === "tuer") {
            return NO_STATEMENT;
        }
        const r = HAUS_FELDER.find((x) => x[1] === feld);
        return String(r ? r[5] : -1);
    }

    // Sends one topic unless another provider owns it; remembers it.
    // Returns true when sent.
    async hausThema(topic, wert, ieee, remembered, nurBeiAenderung) {
        if (!remembered[topic] && this.foreign.has(topic)) {
            // another provider already reports under this name
            if (!this.conflicts.has(topic)) {
                this.conflicts.add(topic);
                this.writeStatus();
                this.logger.warning(`Zigbee2MqttNG: ${topic} belongs to another provider, not overwritten`);
            }
            return false;
        }
        if (nurBeiAenderung && this.hausLetzte.get(topic) === wert && remembered[topic] === ieee) {
            return false;
        }
        await this.sendHaus(topic, wert);
        this.hausLetzte.set(topic, wert);
        if (remembered[topic] !== ieee) {
            remembered[topic] = ieee;
            return "neu";
        }
        return true;
    }

    // E-E1 A: another provider names itself for this name - nothing of the
    // name is sent, the conflict is listed
    fremderName(familie, name, remembered) {
        const t = `${HAUS_BASE}/${familie}/${name}/anbieter`;
        if (remembered[t] || !this.foreign.has(t)) {
            return false;
        }
        if (!this.conflicts.has(t)) {
            this.conflicts.add(t);
            this.writeStatus();
            this.logger.warning(`Zigbee2MqttNG: ${t} names another provider, the name is not used`);
        }
        return true;
    }

    // the provider of a name (E-E1 A), once per name and family
    async hausAnbieter(familie, name, ieee, remembered) {
        const topic = `${HAUS_BASE}/${familie}/${name}/anbieter`;
        if (this.foreign.has(`${HAUS_BASE}/${familie}/${name}/anbieter`) && !remembered[topic]) {
            return false;
        }
        return this.hausThema(topic, ANBIETER, ieee, remembered, true);
    }

    async publishHaus(ieee, state) {
        const values = hausValues(state);
        if (Object.keys(values).length === 0) {
            return;
        }
        const name = this.hausNamen()[ieee];
        if (!name) {
            return;
        }
        const remembered = readJson(this.cfg.hausFile, {});
        if (this.fremderName(HAUS_ROOT, name, remembered)) {
            return;
        }
        let changed = false;
        let gesendet = false;
        // 4.2.5 (device test 28.10.): silent device -> "-" (no statement)
        const stumm = this.stumm(ieee);
        for (const [key, value] of Object.entries(values)) {
            const r = await this.hausThema(`${HAUS_BASE}/${HAUS_ROOT}/${name}/${key}`, stumm ? NO_STATEMENT : String(value), ieee, remembered, false);
            changed = changed || r === "neu";
            gesendet = gesendet || r !== false;
        }
        if (gesendet) {
            changed = (await this.hausAnbieter(HAUS_ROOT, name, ieee, remembered)) === "neu" || changed;
        }
        if (changed) {
            writeIfChanged(this.cfg.hausFile, JSON.stringify(remembered, null, 1));
        }
    }

    // 4.2.5 (E1): klima, wetter, alarm of one device. Only changed values
    // (alle: every value); zeit always with a changed value (klima, wetter).
    async publishHausFelder(ieee, state, alle, ohne = false) {
        const an = this.familienAn().filter((f) => f !== "tuer");
        if (an.length === 0) {
            return;
        }
        const name = this.hausNamen()[ieee];
        if (!name) {
            return;
        }
        const remembered = readJson(this.cfg.hausFile, {});
        let changed = false;
        // 4.2.5 (device test 28.10.): a silent device (last message older than
        // STUMM_SEKUNDEN or unknown) sends no statement; zeit stays the real time
        const leer = ohne || this.stumm(ieee);
        for (const familie of an) {
            const eintrag = this.hausZuordnung(familie).find((x) => x.ieee === ieee);
            if (!eintrag || this.fremderName(familie, name, remembered)) {
                continue;
            }
            let neuWert = false;
            let gesendet = false;
            for (const r of eintrag.felder) {
                const wert = String(leer ? r[5] : hausWert(r, state?.[r[2]]));
                const res = await this.hausThema(`${HAUS_BASE}/${familie}/${name}/${r[1]}`, wert, ieee, remembered, !alle);
                changed = changed || res === "neu";
                neuWert = neuWert || res !== false;
                gesendet = gesendet || res !== false;
            }
            if (familie !== "alarm" && neuWert && !ohne) {
                const zeit = String(this.lastSeen(ieee));
                changed = (await this.hausThema(`${HAUS_BASE}/${familie}/${name}/zeit`, zeit, ieee, remembered, false)) === "neu" || changed;
            }
            if (gesendet || alle) {
                changed = (await this.hausAnbieter(familie, name, ieee, remembered)) === "neu" || changed;
            }
        }
        if (changed) {
            writeIfChanged(this.cfg.hausFile, JSON.stringify(remembered, null, 1));
        }
    }

    // unix seconds of the last message of the device (herdsman lastSeen, ms;
    // checked in zigbee2mqtt 2.12.0: lib/extension/availability.ts), -1 without
    lastSeen(ieee) {
        try {
            const d = this.zigbee.resolveEntity(ieee);
            const ms = d?.zh?.lastSeen;
            return typeof ms === "number" && ms > 0 ? Math.floor(ms / 1000) : -1;
        } catch {
            return -1;
        }
    }

    // availability of a device for the house families klima/wetter/alarm
    async hausVerfuegbarkeit(friendlyName, online) {
        if (!this.foreignReady || this.familienAn().filter((f) => f !== "tuer").length === 0) {
            return;
        }
        const d = this.deviceByName(friendlyName);
        if (!d) {
            return;
        }
        if (online) {
            let st = {};
            try {
                st = this.state.get(this.zigbee.resolveEntity(d.ieee_address)) ?? {};
            } catch {
                st = {};
            }
            await this.publishHausFelder(d.ieee_address, st, true);
        } else {
            await this.publishHausFelder(d.ieee_address, {}, true, true);
        }
    }

    async sendHaus(topic, value) {
        await this.mqtt.publish(topic.substring(HAUS_BASE.length + 1), value, {baseTopic: HAUS_BASE, clientOptions: {retain: true, qos: 1}});
    }

    // A family switched off: a fast first move - empty retained messages for
    // its remembered topics. The list stays: bin/haus-leeren.php reads back
    // and only then removes the entries (4.2.5, V10).
    async clearHaus(familie) {
        const remembered = readJson(this.cfg.hausFile, {});
        const topics = Object.keys(remembered).filter((t) => t.startsWith(`${HAUS_BASE}/${familie}/`));
        for (const topic of topics) {
            await this.mqtt.publish(topic.substring(HAUS_BASE.length + 1), "", {baseTopic: HAUS_BASE, clientOptions: {retain: true, qos: 1}});
        }
        if (topics.length > 0) {
            this.logger.info(`Zigbee2MqttNG: ${HAUS_BASE}/${familie} switched off - empty retained message sent for ${topics.length} topic(s); the read back follows`);
        }
    }
}
