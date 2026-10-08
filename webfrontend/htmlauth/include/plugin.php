<?php
require_once "loxberry_web.php";
require_once "loxberry_system.php";
require_once LBPBINDIR . "/formHelper.php";
require_once LBPBINDIR . "/defines.php";

require __DIR__ . '/vendor/autoload.php';

/**
 * Plugin helper class
 */
class Plugin
{
    /**
     * Creates the page header.
     * Since 4.2.0 there is one page with green tabs (house standard,
     * decision Nr. 44) and no LoxBerry navigation bar any more: the global
     * navigation array stays as defines.php leaves it, empty. The scripts of
     * the open tab go into the LoxBerry header, as the single pages did up
     * to 4.1.1; the styles stand in index.php (css/plugin.css is gone).
     * $L is globally available from defines.php
     */
    static function createHeader(array $scripts)
    {
        $template_title = "Zigbee2MqttNG";
        $helplink = "https://github.com/timanders22/LoxBerry-Plugin-Zigbee2MqttNG#readme";
        $helptemplate = "help.html";

        global $htmlhead;
        foreach ($scripts as $value) {
            $htmlhead .= '<script src="js/' . $value . '"></script>';
        }

        // Creates the loxberry header
        LBWeb::lbheader($template_title, $helplink, $helptemplate);
    }

    /**
     * Initializes the plugin environment
     */
    static function initializeTwig()
    {
        global $lbptemplatedir;
        global $L;
        $loader = new \Twig\Loader\FilesystemLoader($lbptemplatedir);
        $twig = new \Twig\Environment($loader, [
            'cache' => "$lbptemplatedir/cache",
        ]);

        $filter = new \Twig\TwigFilter('trans', function ($string) use ($L) {
            return isset($L[$string]) ? $L[$string] : $string;
        });
        $twig->addFilter($filter);
        return $twig;
    }
}
