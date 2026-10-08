/**
 * Settings tab
 */

let serialPorts = [];

/**
 * Lists the serial devices and warns about names like /dev/ttyACM0 that may
 * change on every boot as soon as a second stick (e.g. Thread) is plugged in
 */
function loadSerialPorts() {
    $.getJSON(`ajax.php?action=getSerialPorts`).done(function (ports) {
        serialPorts = ports;
        const list = $("#serialports").empty();
        const lines = [];
        ports.forEach(p => {
            list.append($("<option>").attr("value", p.path));
            if (p.stable) {
                lines.push(`<code>${escapeHtml(p.path)}</code> &rarr; ${escapeHtml(p.target)}`);
            }
        });
        $("#portlist").html(lines.length ? `${escapeHtml($("#portlist").data("title") || "")}<br>${lines.join("<br>")}` : "");
        checkPort();
    });
}

function checkPort() {
    const port = ($("#ServiceConfig\\[port\\]").val() || "").trim();
    const warning = $("#portwarning");
    if (!/^\/dev\/tty(ACM|USB)[0-9]+$/.test(port)) {
        warning.hide();
        return;
    }
    const stable = serialPorts.find(p => p.stable && p.target === port);
    warning.empty().text(warning.data("text"));
    if (stable) {
        warning.append(" ").append($("<a href='#'>").text(stable.path).click(function (e) {
            e.preventDefault();
            $("#ServiceConfig\\[port\\]").val(stable.path);
            checkPort();
        }));
    }
    warning.show();
}

/**
 * Shows the Zigbee, the Thread and the WLAN channel next to each other
 */
function loadRadioInfo() {
    $.getJSON(`ajax.php?action=getRadioInfo`).done(function (info) {
        const box = $("#radioinfo");
        const lines = [escapeHtml(box.data("zigbee").replace("%s", info.zigbee))];
        let level = "";
        if (info.thread) {
            lines.push(escapeHtml(box.data("thread").replace("%s", info.thread).replace("%t", info.threadSource)));
            if (info.level === "conflict") {
                lines.push(escapeHtml(box.data("conflict")));
                level = "zng-error";
            } else if (info.level === "adjacent") {
                lines.push(escapeHtml(box.data("adjacent")));
                level = "zng-warning";
            } else {
                lines.push(`<span class="zng-ok">${escapeHtml(box.data("ok"))}</span>`);
            }
        }
        if (info.wifi) {
            lines.push(escapeHtml(box.data("wifi").replace("%s", info.wifi).replace("%t", info.wifiInterface)));
            if (info.wifiLevel === "conflict") {
                lines.push(escapeHtml(box.data("wificonflict")));
                level = level || "zng-warning";
            } else if (info.wifiLevel === "near") {
                lines.push(escapeHtml(box.data("wifinear")));
            }
        }
        box.html(level ? `<div class="${level}">${lines.join("<br>")}</div>` : lines.join("<br>"));
    });
}

/**
 * Known coordinators. Values from the vendor documentation; a preset only
 * fills the form fields, nothing is saved until "Save and apply".
 * SONOFF Dongle Max / Dongle-M: https://dongle.sonoff.tech/guide/dongle-m/donglem_connecting_to_zigbee2mqtt/
 * Others: https://www.zigbee2mqtt.io/guide/adapters/
 */
const coordinatorPresets = {
    "dongle-m-net": { port: "tcp://Dongle-M.local:6638", adapter: "ember", baudrate: "115200", rtscts: "false" },
    "dongle-m-usb": { port: null, adapter: "ember", baudrate: "115200", rtscts: "false" },
    "dongle-e": { port: null, adapter: "ember", baudrate: "115200", rtscts: "false" },
    "dongle-p": { port: null, adapter: "zstack", baudrate: "115200", rtscts: "false" },
    "conbee": { port: null, adapter: "deconz", baudrate: "", rtscts: "" }
};

/**
 * Fills port, adapter, baudrate and rtscts from the selected preset.
 * For USB the port is only kept if it already is a device path - the
 * device name differs per system, so it is not guessed.
 */
function applyPreset() {
    const preset = coordinatorPresets[$("#coordinatorPreset").val()];
    if (!preset) {
        return;
    }
    const port = $("#ServiceConfig\\[port\\]");
    if (preset.port !== null) {
        port.val(preset.port);
    } else if (!port.val().startsWith("/dev/")) {
        port.val("");
    }
    ["adapter", "rtscts"].forEach(function (name) {
        const field = $(`#ServiceConfig\\[${name}\\]`);
        field.val(preset[name]);
        try { field.selectmenu("refresh"); } catch (e) { }
    });
    $("#ServiceConfig\\[baudrate\\]").val(preset.baudrate);
    $("#testportresult").text("");
    checkPort();
}

/**
 * Asks the backend whether the port in the form is reachable (tcp://) or
 * present (/dev/...). Tests the value in the form, not the saved one.
 */
function testPort() {
    const result = $("#testportresult");
    result.css("color", "grey").text("...");
    $.post(`ajax.php?action=testPort`, { port: $("#ServiceConfig\\[port\\]").val() }, null, "json")
        .done(function (data) {
            let text = result.data(data.message) || data.message;
            if (data.ip) {
                text += " (" + data.ip + ")";
            }
            result.css("color", data.result ? "green" : "red").text(text);
        })
        .fail(function () {
            result.css("color", "red").text("error");
        });
}

/**
 * 4.1.1 (B14): start, restart, stop - the answer is measured afterwards
 */
function serviceAction(button) {
    const result = $("#dienstergebnis");
    $(".zng-dienst").prop("disabled", true);
    result.css("color", "grey").text(result.data("laeuft-schon"));
    $.post(`ajax.php?action=serviceAction`, { tat: $(button).data("tat") }, null, "json")
        .done(function (answer) {
            result.css("color", answer && answer.ok ? "green" : "red").text(dienstText(answer));
        })
        .fail(function () {
            result.css("color", "red").text(dienstText(null));
        })
        .always(function () {
            $(".zng-dienst").prop("disabled", false);
            getPid();
        });
}

/**
 * 4.1.1 (B3): "Einstellungen zurückspielen" - the file goes to the backend,
 * which checks every value; a half valid file changes nothing
 */
function restoreSettings() {
    const box = $("#sicherungergebnis");
    const file = $("#sicherungdatei")[0].files[0];
    if (!file) {
        box.attr("class", "zng-error").text(box.data("keinedatei")).show();
        return;
    }
    const data = new FormData();
    data.append("sicherung", file);
    box.attr("class", "").css("color", "grey").text("...").show();
    $.ajax({ url: `ajax.php?action=restoreSettings`, type: "POST", data: data, processData: false, contentType: false, dataType: "json" })
        .done(function (answer) {
            box.css("color", "");
            if (answer && answer.result) {
                let text = box.data("ok") + " " + (answer.teile || []).join(", ") + ".";
                if (answer.fehlend > 0) {
                    text += " " + String(box.data("fehlend")).replace("%d", answer.fehlend);
                }
                box.attr("class", answer.ok ? "zng-warning" : "zng-error").text(text + " " + dienstText(answer));
            } else {
                const list = $("<ul>");
                ((answer && answer.errors) || []).forEach(function (e) {
                    list.append($("<li>").text(e));
                });
                box.attr("class", "zng-error").empty().append($("<b>").text(box.data("abgelehnt"))).append(list);
            }
        })
        .fail(function () {
            box.attr("class", "zng-error").text(dienstText(null));
        });
}

$(document).ready(function () {
    $("#saveapply").click(function () {
        saveAndApply(["ServiceConfig"]);
    });
    $(".zng-dienst").click(function () {
        serviceAction(this);
    });
    $("#sicherungladen").click(restoreSettings);
    $("#coordinatorPreset").change(applyPreset);
    $("#testport").click(testPort);
    $("#ServiceConfig\\[port\\]").on("input change", checkPort);

    fetchFormData("ServiceConfig").then(data => {
        setFormData("ServiceConfig", data);
        checkPort();
    });

    loadSerialPorts();
    loadRadioInfo();
    getPid();
    setInterval(getPid, 5000);
});
