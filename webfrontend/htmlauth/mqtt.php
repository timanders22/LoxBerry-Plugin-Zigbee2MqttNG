<?php
require_once 'include/plugin.php';
require_once LBPBINDIR . '/zigbee2mqttng.php';

$twig = Plugin::initializeTwig();

// Include header and set page as active
Plugin::createHeader(Plugin::MQTT);

//mqtt is not a plugin anymore in lb >=3
$mqtt_installed = (int) substr(LBSystem::lbversion(), 0, 1) > 2 || LBSystem::plugindata('mqttgateway');

echo $twig->render('mqtt.html', array(
    "konfig" => zng_konfig_anzeige(),
    "mqtt_installed" => $mqtt_installed,
    "gateway" => zng_gateway_info(),
));

//creates the footer
LBWeb::lbfooter();
