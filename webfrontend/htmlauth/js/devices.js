/**
 * Devices tab: pairing, network map and devices.yaml
 */

/* ---------------- pairing ---------------- */

let permitJoinTimer = null;

/**
 * Counts the remaining pairing time down next to the buttons
 * @param {number} end end of pairing (ms since epoch)
 * @param {string} text text in front of the seconds
 */
function showPairingCountdown(end, text) {
    const result = $("#permitjoinresult");
    clearInterval(permitJoinTimer);
    const tick = function () {
        const left = Math.round((end - Date.now()) / 1000);
        if (left <= 0) {
            clearInterval(permitJoinTimer);
            result.css("color", "grey").text(result.data("closed"));
            return;
        }
        result.css("color", "green").text(text + " " + left + " s");
    };
    tick();
    permitJoinTimer = setInterval(tick, 1000);
}

/**
 * Opens (254 s) or closes (0) pairing on the running zigbee2mqtt and shows
 * the answer.
 * @param {number} time seconds, 0 closes
 */
function permitJoin(time) {
    const result = $("#permitjoinresult");
    result.css("color", "grey").text("...");
    $.post(`ajax.php?action=permitJoin`, { time: time }, null, "json")
        .done(function (data) {
            clearInterval(permitJoinTimer);
            let text = result.data(data.message) || data.message;
            if (data.error) {
                text += " " + data.error;
            }
            if (data.result && data.time > 0) {
                showPairingCountdown(Date.now() + data.time * 1000, text);
            } else {
                result.css("color", data.result ? "grey" : "red").text(text);
            }
        })
        .fail(function () {
            result.css("color", "red").text("error");
        });
}

/* ---------------- network map ---------------- */

const mapColors = {
    Coordinator: "#1565c0",
    Router: "#6dac20",
    EndDevice: "#90a4ae"
};

let network = null;

/**
 * Colour of a link by its link quality (0..255).
 */
function lqiColor(lqi) {
    if (lqi >= 100) {
        return "#6dac20";
    }
    if (lqi >= 50) {
        return "#e0620d";
    }
    return "#c62828";
}

/**
 * Draws the map. Links that were reported by both ends are drawn once,
 * with the better of the two link qualities.
 */
function drawMap(data) {
    const nodes = data.nodes.map(function (node) {
        const failed = node.failed.length > 0;
        return {
            id: node.id,
            label: node.name,
            title: node.id + " (" + node.type + ")" + (failed ? " - failed: " + node.failed.join(", ") : ""),
            shape: node.type === "Coordinator" ? "box" : "dot",
            size: node.type === "EndDevice" ? 10 : 16,
            color: {
                background: mapColors[node.type] || "#90a4ae",
                border: failed ? "#c62828" : (mapColors[node.type] || "#90a4ae")
            },
            borderWidth: failed ? 3 : 1,
            font: { color: node.type === "Coordinator" ? "#ffffff" : "#333333" }
        };
    });

    const names = {};
    data.nodes.forEach(function (node) {
        names[node.id] = node.name;
    });

    const pairs = {};
    data.links.forEach(function (link) {
        const key = [link.source, link.target].sort().join("|");
        if (!pairs[key] || pairs[key].lqi < link.lqi) {
            pairs[key] = link;
        }
    });
    const links = Object.keys(pairs).map(function (key) {
        return pairs[key];
    });

    const edges = links.map(function (link) {
        return {
            from: link.source,
            to: link.target,
            label: String(link.lqi),
            color: { color: lqiColor(link.lqi) },
            width: link.lqi >= 100 ? 3 : (link.lqi >= 50 ? 2 : 1),
            font: { size: 11, align: "middle" }
        };
    });

    $("#devicemap").show();
    const options = {
        autoResize: true,
        physics: {
            solver: "repulsion",
            repulsion: { nodeDistance: 180 },
            stabilization: { enabled: true, iterations: 200 }
        },
        interaction: { hover: true }
    };
    if (network !== null) {
        network.destroy();
    }
    network = new vis.Network(document.getElementById("devicemap"), { nodes: nodes, edges: edges }, options);

    // the same links as a table, sorted from weakest to strongest
    const body = $("#maplinks tbody").empty();
    links.sort(function (a, b) {
        return a.lqi - b.lqi;
    }).forEach(function (link) {
        $("<tr>")
            .append($("<td>").text(names[link.source] || link.source))
            .append($("<td>").text(names[link.target] || link.target))
            .append($("<td>").css("color", lqiColor(link.lqi)).text(link.lqi))
            .appendTo(body);
    });
    $("#maplinks").show();
}

/**
 * Asks zigbee2mqtt for a new scan and draws the result.
 */
function scanNetwork() {
    const status = $("#mapstatus");
    $("#mapscan").prop("disabled", true);
    status.css("color", "grey").text(status.data("scanning"));
    $.ajax({ type: "POST", url: "ajax.php?action=networkMap", dataType: "json", timeout: 160000 })
        .done(function (data) {
            if (data.result) {
                status.css("color", "green").text(status.data("done") + " " + data.time + ": " + data.nodes.length + " " + status.data("devices") + ", " + data.links.length + " " + status.data("links"));
                drawMap(data);
            } else {
                status.css("color", "red").text((status.data(data.message) || data.message) + (data.error ? " " + data.error : ""));
            }
        })
        .fail(function () {
            status.css("color", "red").text("error");
        })
        .always(function () {
            $("#mapscan").prop("disabled", false);
        });
}

/* ---------------- devices.yaml ---------------- */

function saveDevices() {
    $(".saveok").hide();
    $(".saveerror").hide();
    $("#validationerrors").hide();
    $(".submitting").show();

    const failed = function (jqxhr) {
        $(".submitting").hide();
        $(".saveerror").show();
        if (jqxhr && jqxhr.responseJSON && jqxhr.responseJSON.error) {
            $("#validationerrors").text(jqxhr.responseJSON.error).show();
        }
    };
    $.ajax({
        type: "POST",
        contentType: "text/plain",
        url: "ajax.php?action=setDevices",
        dataType: "json",
        data: ace.edit("editor").getValue()
    })
        .done(function () {
            // 4.1.1 (B10): saved - whether zigbee2mqtt runs afterwards is
            // measured and said
            applyChanges().then(function (answer) {
                $(".submitting").hide();
                if (answer && answer.ok) {
                    $(".saveok").show();
                    $(".savedienst").css("color", "green").text(" " + dienstText(answer));
                } else {
                    $(".savedienst").css("color", "red").text($("#dienstmeldungen").data("gespeichert") + " " + dienstText(answer));
                }
            }, failed);
        })
        .fail(failed);
}

$(document).ready(function () {
    $("#permitjoinopen").click(function () {
        permitJoin(254);
    });
    $("#permitjoinclose").click(function () {
        permitJoin(0);
    });
    // pairing already open (e.g. from the zigbee2mqtt UI)
    const result = $("#permitjoinresult");
    const end = parseInt(result.data("end"), 10);
    if (end > Date.now()) {
        showPairingCountdown(end, result.data("open"));
    }

    $("#mapscan").click(scanNetwork);

    const editor = ace.edit("editor");
    editor.setTheme("ace/theme/chrome");
    editor.getSession().setMode("ace/mode/yaml");
    $("#saveapply").click(saveDevices);
});
