<?php
/**
 * Zigbee2MqttNG - die Oberflaeche.
 *
 * Seit 4.2.0 EINE Seite mit gruenen Reitern nach Hausstandard (Entscheidung
 * Nr. 44, Kopf nach Nr. 43): Statusuebersicht ueber den Reitern, gruener
 * Kasten oben im ersten Reiter, keine LoxBerry-Navigationsleiste mehr. Die
 * Einzelseiten bis 4.1.1 (devices.php, mqtt.php, ui.php, loxone.php,
 * test.php, log.php, backup.php) leiten nur noch hierher um.
 *
 * Die Reiterinhalte bleiben die Twig-Vorlagen unter templates/ und
 * speichern weiter per AJAX (ajax.php); vorbereitet werden sie in
 * zng_bereiche.php. Welcher Reiter offen ist, entscheidet der Server, und
 * nur der offene wird aufgebaut - jeder Reiterwechsel ist ein Seitenaufruf
 * (wie Live, Bilder und Videos bei Intercom 2.2.20). Warum nicht alle Reiter
 * in einem Dokument wie in den Hausplugins (Regeln/04):
 *   - drei Reiter tragen dieselben Element-Kennungen (saveapply,
 *     validationerrors, dienstmeldungen); in einem Dokument binge ein Klick
 *     auf "Speichern" an das falsche Formular;
 *   - jeder Reiter bringt seine eigenen Skripte mit (ace und vis-network im
 *     Reiter Geraete, die Abfrage des Dienstes alle 5 s);
 *   - der Reiter Test fragt den Broker, der Reiter Logdateien den
 *     Logmanager - beides liefe sonst bei jedem Aufruf jedes Reiters.
 * Die Reiter sind deshalb echte Verweise ohne Umschaltskript.
 */
require_once 'include/plugin.php';
require_once 'model/ServiceConfig.php';
require_once 'model/MqttConfig.php';
require_once LBPBINDIR . '/zigbee2mqttng.php';
require_once __DIR__ . '/zng_bereiche.php';

// Positivliste der Reiter, ausgeschrieben: hausstandard_pruefen.py sucht sie
// woertlich. Leiste und Bereiche unten stehen ebenso ausgeschrieben; die
// Zeile "Reiter" im Reiter Test haelt alle drei gegeneinander.
$zng_reiter = array('tab-settings', 'tab-devices', 'tab-mqtt', 'tab-loxone', 'tab-test', 'tab-log');

$zng_tab = 'tab-settings';
if (isset($_GET['form']) && is_string($_GET['form'])
    && in_array('tab-' . $_GET['form'], $zng_reiter, true)) {
    $zng_tab = 'tab-' . $_GET['form'];
}
// Die Zigbee2mqtt UI ist eine Ansicht des Reiters Geraete (bis 4.1.1 ui.php)
$zng_ui = $zng_tab === 'tab-devices' && isset($_GET['ansicht']) && $_GET['ansicht'] === 'ui';

// Die Skripte des offenen Reiters - dieselben wie bis 4.1.1 je Seite; die
// Ansicht Zigbee2mqtt UI braucht keines (ui.php lud die des Reiters Geraete
// mit, und devices.js suchte dort einen Editor, den es nicht gab).
$zng_skripte = array(
    'tab-settings' => array('common.js', 'index.js'),
    'tab-devices'  => $zng_ui ? array() : array('vendor/ace.js', 'vendor/vis-network.min.js', 'common.js', 'devices.js'),
    'tab-mqtt'     => array('common.js', 'mqtt.js'),
);

$twig = Plugin::initializeTwig();
$zng_kopf = zng_kopf();
Plugin::createHeader(isset($zng_skripte[$zng_tab]) ? $zng_skripte[$zng_tab] : array());
?>
<style>
/* Hausstandard: woertlich aus VORLAGE_hausstandard.css.html (Grundblock,
   Rollbehaelter, Auswahlfeld mit Pfeil); die Ergaenzungen dieser Linie
   stehen darunter. */
.sm-wrap { max-width: 980px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap, .sm-wrap *, .sm-tabs, .sm-tabs * { text-shadow: none !important; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0;
          padding: 9px 18px; font-size: 0.95em; color: #444 !important; text-decoration: none; display: inline-block; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-feld { margin: 14px 0; }
.sm-feld > label { display: block; font-weight: 600; font-size: 0.9em; color: #555; margin: 0 0 4px; }
.sm-feld .ui-input-text, .sm-feld .ui-select, .sm-feld .ui-textinput { max-width: 520px; }
.sm-feld .ui-input-text input, .sm-feld .ui-input-text textarea { font-size: 0.95em; }
.sm-hilfe { font-size: 0.85em; color: #555; margin: 4px 0 0; max-width: 640px; }
.sm-step { border: 1px solid #ddd; border-left: 4px solid #6dac20; background: #fafafa;
    border-radius: 6px; padding: 12px 14px; margin: 12px 0; font-size: 0.92em; line-height: 1.5; }
.sm-tbl { border-collapse: collapse; width: 100%; margin: 8px 0; font-size: 0.9em; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ccc; padding: 5px 7px; text-align: left; vertical-align: top; }
.sm-tbl th { background: #eef3e6; font-weight: 600; }
.sm-mono { font-family: Consolas, "Courier New", monospace; background: #f0f0f0;
    padding: 1px 4px; border-radius: 3px; font-size: 0.94em; word-break: break-all; }
.sm-pre { background: #f4f4f4; border: 1px solid #ccc; padding: 10px; font-size: 0.85em;
    overflow: auto; margin: 8px 0; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.sm-wrap .sm-knopfreihe .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button.sm-btn {
    flex: 0 0 auto; min-width: 250px; text-align: center; display: inline-flex;
    align-items: center; justify-content: center; line-height: 1.25;
    padding: 10px 14px !important; border-radius: 6px !important;
    color: #fff !important; text-decoration: none !important; font-size: 0.92em;
    border: 0 !important; cursor: pointer; font-weight: 600 !important;
    text-shadow: none !important; box-shadow: none !important;
    opacity: 1 !important; margin: 0 !important; width: auto !important; }
.sm-kacheln { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0; }
.sm-kachel { border: 1px solid #ddd; border-radius: 10px; padding: 10px 14px; min-width: 130px; }
.sm-kachel b { display: block; font-size: 1.35em; color: #33691e; }

.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-wrap .sm-btn.sm-b-lesen   { background: #6dac20 !important; }
.sm-wrap .sm-btn.sm-b-technik { background: #546e7a !important; }
.sm-wrap .sm-btn.sm-b-aktion  { background: #e0620d !important; }
.sm-wrap .sm-btn.sm-b-lesen:hover,   .sm-wrap .sm-btn.sm-b-lesen:focus   { background: #5c9219 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-technik:hover, .sm-wrap .sm-btn.sm-b-technik:focus { background: #435962 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-aktion:hover,  .sm-wrap .sm-btn.sm-b-aktion:focus  { background: #b84f0a !important; color: #fff !important; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
.sm-seite { display: none; padding-top: 4px; }
.sm-seite.sm-active { display: block; }
.sm-hinweis { border: 1px solid #cfe3b0; background: #f2f8ea; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-warnung { border: 1px solid #f0c9a0; background: #fdf4ec; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-an  { color: #1a7f1a; font-weight: 700; }
.sm-aus { color: #b00000; font-weight: 700; }
.sm-breit { overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 10px 0; }
.sm-breit .sm-tbl { margin: 0; min-width: 760px; }
.sm-wrap select {
    appearance: none; -webkit-appearance: none; -moz-appearance: none;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='9' viewBox='0 0 14 9'%3E%3Cpath d='M1 1l6 6 6-6' fill='none' stroke='%234f7d17' stroke-width='2'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 10px center;
    padding-right: 32px; cursor: pointer; }
.sm-tbl select { padding-right: 28px; background-position: right 7px center; }

/* Ergaenzungen dieser Linie (4.2.0) */
/* Die LoxBerry-Navigationsleiste hat seit 4.2.0 keine Eintraege mehr; den
   leeren Behaelter zeichnet LoxBerry trotzdem samt Unterkante (Intercom
   2.2.20, am Geraet gesehen). */
#vuenavbar { display: none; }
/* Felder in Hausform (Dashboard, Vorlage): Beschriftung ueber dem Feld,
   Feld in Hausbreite, Hilfe darunter. Alle Felder tragen data-role="none" -
   jQuery Mobile baut sie nicht um, Rahmen und Breite kommen von hier. Keine
   Kurzform background:, sie loeschte den Pfeil der Auswahlfelder. */
.sm-wrap .sm-feld input[type=text], .sm-wrap .sm-feld input[type=password], .sm-wrap .sm-feld select {
    width: 100%; max-width: 520px; box-sizing: border-box;
    border: 1px solid #ccc; border-radius: 6px; background-color: #fff; color: #333;
    padding-top: 8px; padding-bottom: 8px; padding-left: 10px; font-size: 0.95em; }
.sm-wrap .sm-feld input[type=text], .sm-wrap .sm-feld input[type=password] { padding-right: 10px; }
/* Kontrollkaestchen: klein, Beschriftung daneben */
.sm-feld.sm-haken > label { display: inline-flex; align-items: center; gap: 8px;
    font-weight: normal; font-size: 0.95em; color: #333; margin: 0; cursor: pointer; }
.sm-feld.sm-haken input[type=checkbox] { width: 18px; height: 18px; margin: 0; }
/* Ein Geheimnis maskiert, mit Knopf zum Aufdecken (Regeln/05) */
.sm-geheim { display: inline-flex; flex-wrap: wrap; gap: 10px; align-items: center; }
.sm-geheim input { width: 22em; max-width: 100%; box-sizing: border-box; border: 1px solid #ccc;
    border-radius: 6px; background-color: #f7f7f7; padding: 8px 10px; font-size: 0.95em;
    font-family: Consolas, "Courier New", monospace; }
.sm-fehler { border: 1px solid #ef9a9a; background: #ffebee; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
/* Zeichen der Pruefzeilen und Geraetezustaende (bis 4.1.1 mit dem Kuerzel zng) */
.sm-ok   { color: #4f7d17; }
.sm-fail { color: #c62828; }
.sm-hint { color: #e0620d; }
.sm-info { color: #546e7a; }
.sm-klein { font-size: 85%; color: #666; }
.sm-mitte { display: flex; align-items: center; justify-content: center; flex-wrap: wrap; }
.sm-tbl code { word-break: break-all; }
.sm-step h3 { margin-top: 0; }
.sm-wrap .sm-beanstandet { outline: 2px solid #c62828; }
.sm-wrap .sm-btn:disabled { opacity: 0.5 !important; cursor: default; }
/* Welle Bild (Entscheidung 45): Bild der Bausteine aus dem gemeinsamen Musterprojekt. */
.sm-bild { margin: 12px 0; }
.sm-bild img { max-width: 100%; height: auto; border: 1px solid #ccc; border-radius: 4px; background: #fff; }
.sm-bild figcaption { font-size: .9em; color: #555; margin-top: 4px; }
.submitting { color: grey; }
.saveok { color: green; }
.saveerror { color: red; }
#service_running { color: green; }
#service_not_running { color: red; }
</style>

<div class="sm-wrap">

<table class="sm-tbl" style="max-width:620px">
<tr><th><?= zng_e(zng_t('KOPF.EIGENSCHAFT')) ?></th><th><?= zng_e(zng_t('KOPF.WERT')) ?></th></tr>
<tr><td><?= zng_e(zng_t('KOPF.DIENST')) ?></td>
    <td class="<?= $zng_kopf['pid'] > 0 ? 'sm-an' : 'sm-aus' ?>"><?= zng_e($zng_kopf['dienst']) ?></td></tr>
<tr><td><?= zng_e(zng_t('KOPF.KOORDINATOR')) ?></td>
    <td><?= zng_e($zng_kopf['koordinator']) ?><br><span class="sm-klein"><?= zng_e($zng_kopf['anschluss']) ?></span></td></tr>
<tr><td><?= zng_e(zng_t('KOPF.GERAETE')) ?></td>
    <td class="<?= $zng_kopf['unerreichbar'] > 0 ? 'sm-aus' : '' ?>"><?= zng_e($zng_kopf['geraete']) ?></td></tr>
<tr><td>MQTT</td>
    <td><?= zng_e($zng_kopf['mqtt']) ?><?= $zng_kopf['mqtt_klein'] !== '' ? '<br><span class="sm-klein">' . zng_e($zng_kopf['mqtt_klein']) . '</span>' : '' ?></td></tr>
</table>

<div class="sm-tabs">
  <a href="index.php?form=settings" data-ajax="false" class="sm-tab<?= $zng_tab === 'tab-settings' ? ' sm-active' : '' ?>" data-ziel="tab-settings"><?= zng_e(zng_t('REITER.EINSTELLUNGEN')) ?></a>
  <a href="index.php?form=devices" data-ajax="false" class="sm-tab<?= $zng_tab === 'tab-devices' ? ' sm-active' : '' ?>" data-ziel="tab-devices"><?= zng_e(zng_t('REITER.GERAETE')) ?></a>
  <a href="index.php?form=mqtt" data-ajax="false" class="sm-tab<?= $zng_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" data-ziel="tab-mqtt">MQTT</a>
  <a href="index.php?form=loxone" data-ajax="false" class="sm-tab<?= $zng_tab === 'tab-loxone' ? ' sm-active' : '' ?>" data-ziel="tab-loxone"><?= zng_e(zng_t('REITER.LOXONE')) ?></a>
  <a href="index.php?form=test" data-ajax="false" class="sm-tab<?= $zng_tab === 'tab-test' ? ' sm-active' : '' ?>" data-ziel="tab-test"><?= zng_e(zng_t('REITER.TEST')) ?></a>
  <a href="index.php?form=log" data-ajax="false" class="sm-tab<?= $zng_tab === 'tab-log' ? ' sm-active' : '' ?>" data-ziel="tab-log"><?= zng_e(zng_t('REITER.LOG')) ?></a>
</div>

<!-- Je Reiter ein Bereich; sm-active setzt der Server. Gefuellt wird nur
     der offene - die uebrigen bleiben leer und unsichtbar. -->
<div class="sm-seite<?= $zng_tab === 'tab-settings' ? ' sm-active' : '' ?>" id="tab-settings">
<div class="sm-hinweis"><?= zng_t('KOPF.WAS_IST_DAS') ?></div>
<?php if ($zng_tab === 'tab-settings') { zng_bereich_settings($twig); } ?>
</div>

<div class="sm-seite<?= $zng_tab === 'tab-devices' ? ' sm-active' : '' ?>" id="tab-devices">
<?php if ($zng_tab === 'tab-devices' && $zng_ui) { zng_bereich_ui($twig); } elseif ($zng_tab === 'tab-devices') { zng_bereich_devices($twig); } ?>
</div>

<div class="sm-seite<?= $zng_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" id="tab-mqtt">
<?php if ($zng_tab === 'tab-mqtt') { zng_bereich_mqtt($twig); } ?>
</div>

<div class="sm-seite<?= $zng_tab === 'tab-loxone' ? ' sm-active' : '' ?>" id="tab-loxone">
<?php if ($zng_tab === 'tab-loxone') { zng_bereich_loxone($twig); } ?>
</div>

<div class="sm-seite<?= $zng_tab === 'tab-test' ? ' sm-active' : '' ?>" id="tab-test">
<?php if ($zng_tab === 'tab-test') { zng_bereich_test($twig); } ?>
</div>

<div class="sm-seite<?= $zng_tab === 'tab-log' ? ' sm-active' : '' ?>" id="tab-log">
<?php if ($zng_tab === 'tab-log') { zng_bereich_log($twig); } ?>
</div>

</div>
<?php
LBWeb::lbfooter();
