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
//  - optionally publishes doors and locks under the house convention shared
//    with Matter2Lox (read by Funkwacht and Beschattungswaechter):
//        haus/tuer/<name>/offen       1 open, 0 closed, - no statement   retained
//        haus/tuer/<name>/verriegelt  1 locked, 0 not, - no statement    retained
//    The rules are the ones of Matter2Lox: <name> is fixed once given, a
//    retained value of another provider is never overwritten, and a value
//    that goes away is replaced by "-" once.
//  - optionally sends LoxBerry notifications for devices that went offline and
//    for low batteries,
//  - 4.1.1: publishes a heartbeat for Loxone every minute, never retained:
//        <topic>/zigbee2mqttng/ts       unix seconds
//        <topic>/zigbee2mqttng/zaehler  0...999, wraps around
//    erreichbar and the device values keep their last value in Loxone when
//    zigbee2mqtt dies; only the heartbeat stops. A retained heartbeat would
//    say "alive" for a dead service (house rule Regeln/07).
//
// Do not edit the copy in data/external_extensions - it is overwritten.

import {execFile} from "node:child_process";
import fs from "node:fs";
import path from "node:path";

const HAUS_BASE = "haus";
const HAUS_ROOT = "tuer";
const NO_STATEMENT = "-";
const UMLAUTS = [["ä", "ae"], ["ö", "oe"], ["ü", "ue"], ["ß", "ss"], ["Ä", "ae"], ["Ö", "oe"], ["Ü", "ue"]];
// Retained values under haus/tuer/ arrive right after subscribing. Nothing is
// published there before this time, so a foreign value is known beforehand.
const FOREIGN_WAIT_MS = 3000;
const NOTIFY_DELAY_MS = 60000;
// Heartbeat below <topic>/ - must match zng_subscription_lines() and the
// templates in bin/zigbee2mqttng.php
export const HEARTBEAT_TOPIC = "zigbee2mqttng";
const HEARTBEAT_MS = 60000;

function readJson(file, fallback) {
    try {
        return JSON.parse(fs.readFileSync(file, "utf8"));
    } catch {
        return fallback;
    }
}

function writeIfChanged(file, content) {
    if (!file) {
        return false;
    }
    try {
        if (fs.existsSync(file) && fs.readFileSync(file, "utf8") === content) {
            return false;
        }
        fs.writeFileSync(file, content);
        return true;
    } catch {
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

// Name of a value as the MQTT gateway forwards it: "/" and "%" become "_"
export function gatewayName(topic) {
    return String(topic).replace(/[/%]/g, "_");
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

// Gives every device that needs one a fixed <name>. Names already given stay,
// even when the device is renamed in zigbee2mqtt. A new name that is taken
// gets the last four digits of the IEEE address appended.
export function assignHausNames(names, devices) {
    const result = {...names};
    const used = new Set(Object.values(result));
    for (const device of devices) {
        if (result[device.ieee]) {
            continue;
        }
        let name = hausName(device.name) || hausName(device.ieee);
        if (used.has(name)) {
            name = `${name}_${String(device.ieee).slice(-4)}`;
        }
        let n = 2;
        const plain = name;
        while (used.has(name)) {
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
    }

    async start() {
        const dataDir = process.env.ZIGBEE2MQTT_DATA || path.join(process.cwd(), "data");
        this.cfg = readJson(path.join(dataDir, "zigbee2mqttng.json"), {});
        this.base = this.settings.get().mqtt.base_topic;
        this.notified = readJson(this.cfg.notifyFile, {});

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

        if (this.cfg.hausTopics) {
            // Find out which retained values below haus/tuer/ belong to
            // another provider (e.g. Matter2Lox) before publishing anything.
            await this.mqtt.subscribe(`${HAUS_BASE}/${HAUS_ROOT}/#`);
            this.foreignTimer = setTimeout(() => {
                this.foreignReady = true;
                this.syncHaus().catch((e) => this.logger.warning(`Zigbee2MqttNG: ${e}`));
            }, FOREIGN_WAIT_MS);
        } else {
            await this.clearHaus();
        }
    }

    async stop() {
        clearInterval(this.heartbeatTimer);
        this.heartbeatTimer = null;
        clearTimeout(this.foreignTimer);
        clearTimeout(this.notifyTimer);
        this.flushNotifications();
        this.eventBus.removeListeners(this);
        if (this.cfg?.hausTopics) {
            await this.mqtt.unsubscribe(`${HAUS_BASE}/${HAUS_ROOT}/#`).catch(() => {});
        }
    }

    adjustMessageBeforePublish() {}

    // Read by the Test tab: is the extension loaded, which house topics
    // could not be sent because another provider owns them
    writeStatus() {
        writeIfChanged(this.cfg.statusFile, JSON.stringify({started: this.started, conflicts: [...this.conflicts],
            heartbeat: this.heartbeat}, null, 1));
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
            if (this.cfg.hausTopics && this.foreignReady) {
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
        }
    }

    // ---------------- availability -> erreichbar ----------------

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
    }

    // ---------------- buttons -> aktion/<value> ----------------

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
        execFile("php", [this.cfg.notifyScript, ...events], {timeout: 30000}, (error) => {
            if (error) {
                this.logger.warning(`Zigbee2MqttNG: notification failed: ${error.message}`);
            }
        });
    }

    // ---------------- house topics ----------------

    onMessage(data) {
        const topic = data?.topic;
        if (!topic || !topic.startsWith(`${HAUS_BASE}/${HAUS_ROOT}/`)) {
            return;
        }
        const remembered = readJson(this.cfg.hausFile, {});
        if (remembered[topic] || data.message === "") {
            // our own value from an earlier run, or a deleted value
            this.foreign.delete(topic);
            return;
        }
        this.foreign.set(topic, data.message);
    }

    hausDevices() {
        return stateDevices(this.devices ?? []).map((d) => ({ieee: d.ieee_address, name: d.friendly_name}));
    }

    async onStateChange(data) {
        if (!this.cfg.hausTopics || !this.foreignReady || !data?.entity?.isDevice?.()) {
            return;
        }
        if (!("contact" in (data.update ?? {})) && !("lock_state" in (data.update ?? {}))) {
            return;
        }
        await this.publishHaus(data.entity.ieeeAddr, data.to);
    }

    // Publishes all doors and locks and sends "-" once for the topics of
    // devices that are no longer in the network.
    async syncHaus() {
        if (this.devices === null) {
            return;
        }
        const names = assignHausNames(readJson(this.cfg.hausNamesFile, {}), this.hausDevices());
        writeIfChanged(this.cfg.hausNamesFile, JSON.stringify(names, null, 1));
        // A remembered topic maps to the IEEE address of its device; "-" in
        // front marks a topic whose "-" has already been sent.
        const present = new Set(this.hausDevices().map((d) => d.ieee));
        const remembered = readJson(this.cfg.hausFile, {});
        let changed = false;
        for (const [topic, ieee] of Object.entries(remembered)) {
            if (!String(ieee).startsWith("-") && !present.has(ieee)) {
                await this.sendHaus(topic, NO_STATEMENT);
                remembered[topic] = `-${ieee}`;
                changed = true;
            }
        }
        if (changed) {
            writeIfChanged(this.cfg.hausFile, JSON.stringify(remembered, null, 1));
        }
        for (const device of this.zigbee.devicesIterator((d) => d.type !== "Coordinator" && present.has(d.ieeeAddr))) {
            await this.publishHaus(device.ieeeAddr, this.state.get(device));
        }
    }

    async publishHaus(ieee, state) {
        const values = hausValues(state);
        if (Object.keys(values).length === 0) {
            return;
        }
        let names = readJson(this.cfg.hausNamesFile, {});
        if (!names[ieee]) {
            names = assignHausNames(names, this.hausDevices());
            writeIfChanged(this.cfg.hausNamesFile, JSON.stringify(names, null, 1));
        }
        const name = names[ieee];
        if (!name) {
            return;
        }
        const remembered = readJson(this.cfg.hausFile, {});
        let changed = false;
        for (const [key, value] of Object.entries(values)) {
            const topic = `${HAUS_BASE}/${HAUS_ROOT}/${name}/${key}`;
            if (!remembered[topic] && this.foreign.has(topic)) {
                // another provider already reports under this name
                if (!this.conflicts.has(topic)) {
                    this.conflicts.add(topic);
                    this.writeStatus();
                    this.logger.warning(`Zigbee2MqttNG: ${topic} belongs to another provider, not overwritten`);
                }
                continue;
            }
            await this.sendHaus(topic, String(value));
            if (remembered[topic] !== ieee) {
                remembered[topic] = ieee;
                changed = true;
            }
        }
        if (changed) {
            writeIfChanged(this.cfg.hausFile, JSON.stringify(remembered, null, 1));
        }
    }

    async sendHaus(topic, value) {
        await this.mqtt.publish(topic.substring(HAUS_BASE.length + 1), value, {baseTopic: HAUS_BASE, clientOptions: {retain: true, qos: 1}});
    }

    // House topics switched off: remove every retained topic sent before
    async clearHaus() {
        const remembered = readJson(this.cfg.hausFile, {});
        const topics = Object.keys(remembered).filter((t) => t.startsWith(`${HAUS_BASE}/${HAUS_ROOT}/`));
        for (const topic of topics) {
            await this.mqtt.publish(topic.substring(HAUS_BASE.length + 1), "", {baseTopic: HAUS_BASE, clientOptions: {retain: true, qos: 1}});
        }
        if (Object.keys(remembered).length > 0) {
            writeIfChanged(this.cfg.hausFile, "{}");
            this.logger.info(`Zigbee2MqttNG: removed ${topics.length} topic(s) below ${HAUS_BASE}/${HAUS_ROOT}`);
        }
    }
}
