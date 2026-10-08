/**
 * Form helpers shared by the settings and the MQTT tab.
 * All requests carry "X-Requested-With: XMLHttpRequest" (jQuery does that for
 * same-origin requests) - ajax.php only accepts changing actions with it.
 */

/**
 * Fetches the form data from the backend
 * @param {string} name Name of the form
 */
function fetchFormData(name) {
    return new Promise((resolve, reject) => {
        $.getJSON(`ajax.php?action=getFormData&form=${name}`)
            .done(resolve)
            .fail((jqxhr, textStatus, error) => reject(error));
    });
}

/**
 * Sets the form data
 * @param {string} name of the form
 * @param {object} data for the form values
 */
function setFormData(name, data) {
    Object.keys(data).forEach((key) => {
        const field = $(`#${name}\\[${key}\\]`);
        if (field.length === 0) {
            return;
        }
        try {
            if (field.is("select")) {
                field.val(data[key] === null ? "" : String(data[key]));
                try { field.selectmenu("refresh"); } catch (e) { }
            } else if (field.attr("type") === "checkbox") {
                field.prop("checked", data[key] === true || data[key] === "true" || data[key] === 1 || data[key] === "1");
                try { field.checkboxradio("refresh"); } catch (e) { }
            } else {
                field.val(data[key]);
            }
        } catch (e) {
        }
    });
}

/**
 * Sends the form to the backend. Resolves with the answer; a validation
 * error of the backend rejects with {errors: [...]}.
 * @param {string} name Name of the form
 */
function updateFormData(name) {
    let data = $(`#${name}`).serializeArray();
    /* Because serializeArray() ignores unset checkboxes: */
    const uncheckedItems = $(`#${name} input[type=checkbox]:not(:checked)`).map(function () {
        return { "name": this.name, "value": false };
    }).get();
    data = data.concat(uncheckedItems);

    return new Promise((resolve, reject) => {
        $.post(`ajax.php?action=setFormData&form=${name}`, data, null, "json")
            .done(function (answer) {
                if (answer && answer.result) {
                    resolve(answer);
                } else {
                    reject(answer || {});
                }
            })
            .fail(function (jqxhr) {
                reject(jqxhr.responseJSON || {});
            });
    });
}

/**
 * Runs update-config.php and restarts zigbee2mqtt. 4.1.1 (B10): resolves
 * with the measured answer {ok, laeuft, pid, zustand, update, sperre, ...};
 * "ok" false means the service does not run afterwards.
 */
function applyChanges() {
    return new Promise((resolve, reject) => {
        $.post(`ajax.php?action=applyChanges`, null, null, "json")
            .done(resolve)
            .fail((jqxhr, textStatus, error) => reject(error));
    });
}

/**
 * 4.1.1 (B10): what happened to the service, from the measured answer
 */
function dienstText(a) {
    const box = $("#dienstmeldungen");
    if (!a || typeof a !== "object") {
        return box.data("unbekannt") || "";
    }
    if (a.update === 3 || a.sperre) {
        return box.data("gesperrt") || "";
    }
    if (a.tat === "stop") {
        return a.ok ? box.data("angehalten") : box.data("nichtangehalten");
    }
    if (a.ok) {
        return String(box.data("laeuft") || "").replace("%s", a.pid);
    }
    return String(box.data("laeuftnicht") || "").replace("%s", a.zustand || "?");
}

/**
 * Shows the validation errors of the backend and marks the fields
 * @param {object} answer {errors: [{field, message}]}
 */
function showErrors(answer) {
    const box = $("#validationerrors");
    $(".zng-field-error").removeClass("zng-field-error");
    const errors = (answer && answer.errors) || [];
    if (errors.length === 0) {
        box.hide();
        return;
    }
    const list = $("<ul>");
    errors.forEach(function (e) {
        list.append($("<li>").text(e.message));
        if (e.field) {
            $(`[name="${e.form}[${e.field}]"]`).addClass("zng-field-error");
        }
    });
    box.empty().append($("<b>").text(box.data("title"))).append(list).show();
}

/**
 * Saves the given forms and restarts zigbee2mqtt
 * @param {string[]} forms names of the forms
 */
function saveAndApply(forms) {
    $(".saveok").hide();
    $(".saveerror").hide();
    $(".submitting").show();
    showErrors(null);

    $(".savedienst").text("");
    Promise.all(forms.map(updateFormData))
        .then(function () {
            return applyChanges().then(function (answer) {
                $(".submitting").hide();
                if (answer && answer.ok) {
                    $(".saveok").show();
                    $(".savedienst").css("color", "green").text(" " + dienstText(answer));
                    setTimeout(function () { location.reload(); }, 1500);
                } else {
                    // saved, but the service does not run - no reload, the
                    // message stays
                    $(".savedienst").css("color", "red").text($("#dienstmeldungen").data("gespeichert") + " " + dienstText(answer));
                }
            }, function () {
                $(".submitting").hide();
                $(".savedienst").css("color", "red").text($("#dienstmeldungen").data("gespeichert") + " " + dienstText(null));
            });
        })
        .catch(function (answer) {
            $(".submitting").hide();
            $(".saveerror").show();
            showErrors(answer);
        });
}

/**
 * Shows whether the service runs
 */
function getPid() {
    $.getJSON(`ajax.php?action=getPid`)
        .done(function (data) {
            if (data.pid != 0) {
                $("#servicepid").text(data.pid);
                $("#service_not_running").hide();
                $("#service_running").show();
            } else {
                $("#service_not_running").show();
                $("#service_running").hide();
            }
        })
        .fail(function () {
            $("#service_not_running").show();
            $("#service_running").hide();
        });
}

function escapeHtml(text) {
    return $("<div>").text(text).html();
}
