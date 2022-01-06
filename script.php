#!/usr/bin/php
<?php

// Munin script to graph informations from an SFR Router and ONT with FTTH connection
// Router Version: NB6VAC-MAIN-R4.0.44k
// ONT Version: I-010G-Q
//
// Make symbolic links in /etc/munin/plugins to this script in order to graph:
// - box_intensity: intensity of received/transmitted optical signal
// - box_speed: speed on each ports (WAN, Wifi and Ethernet)
// - box_uptime: uptime of ONT and router
// - box_clients: number of machines connected to each local port (Wifi and Ethernet)
//
// configure with a [box_*] section and parameters:
// - password: router password of admin user
// - ip: IP address of router
// - hostname: virtual hostname to use (ala SNMP plugin)
//
// Author: Daniel Borges
// License: WTFPL
//
// Inspired from the bash/php script of Sebastien95:
// https://communaute.red-by-sfr.fr/t5/Box-d%C3%A9codeur-TV/Statistiques-BOX/td-p/387753/page/4

////////////
// helpers
////////////
libxml_use_internal_errors(true); // hide imperfect HTML formatting errors

function cred_hash($value, $token) {
    return hash_hmac("sha256", hash("sha256", $value), $token);
}

function println($str) {
    echo $str."\n";
}

function fetch($curl, $url) {
    curl_setopt($curl, CURLOPT_URL, $url);
    $text = curl_exec($curl);
    $dom = new DOMDocument;
    $dom->loadHTML($text);
    return simplexml_import_dom($dom);
}

function parseDate($date) {
    $date = explode("\n", trim($date));
    $days = explode(" ", $date[0])[0];
    $hours = (float)explode(" ", trim($date[1]))[0];
    $minutes = (float)explode(" ", trim($date[2]))[0];
    return $days.".".(int)(($hours * 60.0 + $minutes) / 1440.0 * 100.0);
}

function parseKeyValues($data) {
    $values = [];
    foreach (explode("\n", $data) as $line) {
        if (strpos($line, "=") !== false) {
            $kv = explode("=", $line);
            $values[trim($kv[0])] = trim($kv[1]);
        }
    }
    return $values;
}

//////////////////////
// parsing functions
//////////////////////

// with http://${ip}/state
// and  http://${ip}/fiber
function uptime($state, $fiber) {
    $router = $state->xpath('//table[@id="wan_info"]')[0]->tr[2]->td;
    $fiber = $fiber->xpath('//table[@id="ont_infos"]')[0]->tr[3]->td;
    return "router.value ".parseDate($router)."\n"
        ."fiber.value ".parseDate($fiber);
}

// with http://${ip}/state/lan/extra
// and  http://${ip}/state/lan/wifi
function speed($lan, $wifi) {
    $data = $lan->xpath('//pre');
    $lan1 = parseKeyValues($data[0][0]);
    $lan2 = parseKeyValues($data[1][0]);
    $lan3 = parseKeyValues($data[2][0]);
    $lan4 = parseKeyValues($data[3][0]);
    $fiber = parseKeyValues($data[4][0]);

    $data = $wifi->xpath('//pre');
    $wifi24GHz = parseKeyValues($data[0][0]);
    $wifi5GHz = parseKeyValues($data[1][0]);

    return 
         "lan1down.value ".$lan1["rx_good_bytes"]."\n"
        ."lan1up.value "  .$lan1["tx_good_bytes"]."\n"
        ."lan2down.value ".$lan2["rx_good_bytes"]."\n"
        ."lan2up.value "  .$lan2["tx_good_bytes"]."\n"
        ."lan3down.value ".$lan3["rx_good_bytes"]."\n"
        ."lan3up.value "  .$lan3["tx_good_bytes"]."\n"
        ."lan4down.value ".$lan4["rx_good_bytes"]."\n"
        ."lan4up.value "  .$lan4["tx_good_bytes"]."\n"
        ."fiberdown.value ".$fiber["rx_good_bytes"]."\n"
        ."fiberup.value "  .$fiber["tx_good_bytes"]."\n"
        ."wifi5down.value ".$wifi5GHz["rxbyte"]."\n"
        ."wifi5up.value ".$wifi5GHz["txbyte"]."\n"
        ."wifi24down.value ".$wifi24GHz["rxbyte"]."\n"
        ."wifi24up.value ".$wifi24GHz["txbyte"];
}

// with http://${ip}/network
function clients($network) {
    $translate = array(
        "LAN 1"=>"lan1",
        "LAN 2"=>"lan2",
        "LAN 3"=>"lan3",
        "LAN 4"=>"lan4",
        "Wifi 5GHz"=>"wifi5",
        "Wifi 2.4GHz"=>"wifi24"
    );
    $values = array_fill_keys(array_values($translate), 0);
    foreach ($network->xpath('//table[@id="network_clients"]')[0]->tbody[0]->tr as $client) {
        $port = trim($client->td[4][0]);
        $values[$translate[$port]]++;
    }
    $muninify = fn($k,$v) => "$k.value $v";
    $values = array_map($muninify, array_keys($values), array_values($values));
    return join("\n", $values);
}

// with http://${ip}/fiber
function intensity($fiber) {
    $fiber = $fiber->xpath('//table[@id="ont_infos"]')[0]->tr;
    $rx = explode(" ", $fiber[7]->td)[0];
    $tx = explode(" ", $fiber[8]->td)[0];
    return "rx.value ".$rx."\n"
        ."tx.value ".$tx;
}

function graph($graph) {
    $ip = $_SERVER["ip"];
    $password = $_SERVER["password"];

    // init curl session
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_COOKIEFILE, ""); // cookies in memory
    curl_setopt($ch, CURLOPT_COOKIEJAR, ""); // cookies in memory
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // curl_exec will return content

    // retrieve HMAC key
    curl_setopt($ch, CURLOPT_URL, "http://${ip}/login");
    //curl_setopt($ch, CURLOPT_COOKIESESSION, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, "action=challenge");
    curl_setopt($ch, CURLOPT_HTTPHEADER, array("X-Requested-With: XMLHttpRequest"));
    $ret = new SimpleXMLElement(curl_exec($ch));
    $HMACToken = $ret->challenge;

    // set key for next calls
    curl_setopt($ch, CURLOPT_HTTPHEADER, array("Set-Cookie: sid=".$HMACToken));

    // login
    $credentials = cred_hash("admin", $HMACToken).cred_hash($password, $HMACToken);
    $fields = "method=passwd&zsid=".$HMACToken."&hash=".$credentials;
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    curl_exec($ch);

    // retrieve data
    curl_setopt($ch, CURLOPT_HTTPGET, true);
    switch ($graph) {
    case "box_uptime":
        $state = fetch($ch, "http://${ip}/state/wan");
        $fiber = fetch($ch, "http://${ip}/fiber");
        println(uptime($state, $fiber));
        break;

    case "box_clients":    
        $network = fetch($ch, "http://${ip}/network");
        println(clients($network));
        break;

    case "box_speed":
        $lan = fetch($ch, "http://${ip}/state/lan/extra");
        $wifi = fetch($ch, "http://${ip}/state/wifi");
        println(speed($lan, $wifi));
        break;

    case "box_intensity":
        $fiber = fetch($ch, "http://${ip}/fiber");
        println(intensity($fiber));
        break;
    }
    
    curl_close($ch);
}

function config($graph) {
    $hostname = $_SERVER["hostname"];

    $prettify = array(
        "fiber" => "WAN",
        "lan1" => "Ethernet 1",
        "lan2" => "Ethernet 2",
        "lan3" => "Ethernet 3",
        "lan4" => "Ethernet 4",
        "wifi5" => "Wi-Fi 5 GHz",
        "wifi24" => "Wi-Fi 2.4 GHz"
    );

    println("host_name $hostname");
    switch ($graph) {
    case "box_uptime":
        println("graph_title Uptime");
        println("graph_args --base 1000 -l 0");
        println("graph_scale no");
        println("graph_vlabel uptime in days");
        println("graph_category system");
        println("router.label Router");
        println("fiber.label ONT");
        break;
    case "box_clients":
        println("graph_title Network Clients");
        println("graph_args -l 0");
        println("graph_vlabel clients");
        println("graph_category network");
        foreach (array("wifi24","wifi5","lan1","lan2","lan3","lan4") as $port) {
            println("$port.label ".$prettify[$port]);
        }
        break;
    case "box_speed":
        println("graph_order fiber lan1 lan2 lan3 lan4");
        println("graph_title Traffic");
        println("graph_args --base 1000");
        println("graph_vlabel bits in (-) / out (+) per \${graph_period}");
        println("graph_category network");
        println("update_rate 60");
        foreach (array("fiber","wifi24","wifi5","lan1","lan2","lan3","lan4") as $port) {
            $label = $prettify[$port];
            println("${port}down.label $label received");
            println("${port}down.type DERIVE");
            println("${port}down.graph no");
            println("${port}down.cdef ${port}down,8,*");
            println("${port}down.min 0");
            println("${port}down.max 1000000000");
            println("${port}up.label $label");
            println("${port}up.type DERIVE");
            println("${port}up.negative ${port}down");
            println("${port}up.cdef ${port}up,8,*");
            println("${port}up.min 0");
            println("${port}up.max 1000000000");
            println("${port}up.info Traffic of $label port. Max speed is 1000 Mb/s");
        }
        break;
    case "box_intensity":
        println("graph_title Optical Signal Intensity");
        println("graph_args --base 1000");
        println("graph_vlabel dBm");
        println("graph_category network");
        println("tx.label Transmission");
        println("rx.label Reception");
        break;
    }
}

if (count($argv) == 1) {
    graph(basename($argv[0]));
} else if (count($argv) > 1 && $argv[1] == "config") {
    config(basename($argv[0]));
}
?>
