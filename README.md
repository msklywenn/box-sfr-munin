# box-sfr-munin
Munin script to graph informations from an SFR Router and ONT with FTTH connection.   
Router Version: NB6VAC-MAIN-R4.0.44k  
ONT Version: I-010G-Q

## Configuration
In `/etc/munin/plugin-conf.d/box`, configure with a [box_*] section and parameters:
- password: router password of admin user
- ip: IP address of router
- hostname: virtual hostname to use (to behave like an SNMP plugin)

### Example
```
[box_*]
   env.password my-nice-password
   env.ip 192.168.1.1
   env.hostname boxsfr
```

## Setup
Make symbolic links in /etc/munin/plugins with the following names to the script.php from this repo in order to graph:
- box_intensity: intensity of received/transmitted optical signal
- box_speed: speed on each ports (WAN, Wifi and Ethernet)
- box_uptime: uptime of ONT and router
- box_clients: number of machines connected to each local port (Wifi and Ethernet)

Then add a node in `/etc/munin/munin.conf` with the configured hostname.

### Example
```
[boxsfr] # configured hostname
    address localhost # assuming the munin node running the plugin is on the same machine
    use_node_name no # very important, won't work otherwise
```

Inspired from the bash/php script of Sebastien95 on SFR's forum.  
https://communaute.red-by-sfr.fr/t5/Box-d%C3%A9codeur-TV/Statistiques-BOX/td-p/387753/page/4
