<?php
/**
 * Zigbee2MqttNG - bis 4.1.1 die Seite "Test".
 *
 * Seit 4.2.0 ein Reiter der Startseite (Entscheidung Nr. 44: gruene Reiter
 * statt der LoxBerry-Navigationsleiste); vorbereitet wird der Inhalt in
 * zng_bereiche.php. Diese Datei leitet nur noch um, damit alte Lesezeichen
 * und Verweise nicht ins Leere laufen.
 */
header('Location: index.php?form=test', true, 302);
exit;
