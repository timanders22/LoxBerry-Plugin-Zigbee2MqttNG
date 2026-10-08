<?php
/**
 * Zigbee2MqttNG - bis 4.1.1 der Download der ZIP-Sicherung von zigbee2mqtt; seit 4.2.0
 * ajax.php?action=backupNetwork, wortgleich. Ein alter Verweis landet im
 * Reiter Einstellungen, wo der Knopf steht.
 *
 * Seit 4.2.0 ein Reiter der Startseite (Entscheidung Nr. 44: gruene Reiter
 * statt der LoxBerry-Navigationsleiste); vorbereitet wird der Inhalt in
 * zng_bereiche.php. Diese Datei leitet nur noch um, damit alte Lesezeichen
 * und Verweise nicht ins Leere laufen.
 */
header('Location: index.php?form=settings', true, 302);
exit;
