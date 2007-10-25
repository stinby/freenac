#!/usr/bin/php -f
<?
/**
 * /opt/nac/bin/cron_program_port.php
 *
 * Long description for file:
 * Go through the port table and check for the program flag, and
 * program the ports via SNMP 
 *
 * PHP version 5
 *
 * LICENSE: This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License as published
 * by the Free Software Foundation.
 *
 * @package                     FreeNAC
 * @author                      Hector Ortiz (FreeNAC Core Team)
 * @copyright           	2007 FreeNAC
 * @license                     http://www.gnu.org/copyleft/gpl.html   GNU Public License Version 2
 * @version                     SVN: $Id$
 * @link                                http://www.freenac.net
 *
 */

require_once 'funcs.inc.php';

$logger->setDebugLevel(0);
$logger->setLogToStdOut(false);

$query=<<<EOF
SELECT p.id, 
   p.name AS port, 
   s.ip AS switch, 
   s.name AS switch_name,
   p.auth_profile, 
   p.shutdown,
   p.restart_now,
   v.default_name AS vlan 
   FROM port p 
   LEFT JOIN vlan v
   ON p.staticvlan=v.id
   INNER JOIN switch s 
   ON p.switch=s.id 
   WHERE p.restart_now=1
   ORDER BY s.ip ASC;
EOF;
$logger->debug($query, 3);
$res=mysql_query($query);
if (!$res)
{
   $logger->logit(mysql_error());
   exit(1);
}

while ($row = mysql_fetch_array($res, MYSQL_ASSOC))
{
   foreach ($row as $k => $v)
      $switch_ports[$row['switch']][$k][]=$v;
}

#No ports have restart_now=1;
if ( ! $switch_ports)
   exit();

foreach ($switch_ports as $switch => $properties)
{
   #Retrieve the list of ports from the switch. If we don't get it, go to the next switch
   if ( ! $ports_on_switch =  ports_on_switch($switch))
      continue;
   for ($i=0; $i<count($properties['port']); $i++)
   {
      $port = $properties['port'][$i];
      $dont_restart=0;
      #If we have an empty port name, go to the next one
      if (! $port)
         continue;
      #Get the index for this port
      $port_index = get_snmp_index($port, $ports_on_switch);
      if (! $port_index)
         continue;
 
      ## Check if it is not a trunk port
      if ($properties['auth_profile'][$i] == '3')
      {
         $logger->logit("Port $port on switch $switch({$properties['switch_name'][$i]}) is a trunk port and cannot be programmed");
         continue;
      }
      ## Program port as static or dynamic
      else if (($properties['auth_profile'][$i] == '1') && ($properties['vlan'][$i]))
      {
         set_port_as_static($switch, $port, $properties['vlan'][$i], $port_index);
         $dont_restart++;
      }
      else if ($properties['auth_profile'][$i] == '2')
      {
         set_port_as_dynamic($switch, $port, $port_index);
         $dont_restart++;
      }

      # Shut down the port
      if ($properties['shutdown'][$i])
      {
         #Try to turn it off
         if (turn_off_port($switch, $port, $port_index))
         {
            $string="Port $port on switch $switch({$properties['switch_name'][$i]}) was successfully shutdown";
            $dont_restart++;
         }
         else
         {
            $string="Port $port on switch $switch({$properties['switch_name'][$i]}) could not be shutdown";
         }
         $logger->logit($string);
         log2db('info', $string);
      }

      #Restart port
      if ( ! $dont_restart)
      {
         if (turn_off_port($switch, $port, $port_index))
         {
            if (turn_on_port($switch, $port, $port_index))
            {
               $string="Port $port successfully restarted on switch $switch({$properties['switch_name'][$i]})";
            }
            else
            {
               $string="Port $port on switch $switch({$properties['switch_name'][$i]}) couldn't be restarted";
            }
         }
         else
         {
            $string="Port $port on switch $switch({$properties['switch_name'][$i]}) couldn't be restarted";
         }
         $logger->logit($string);
         log2db('info',$string);
      }
   
   }
}

if ( mysql_num_rows($res) )
{
   # Ok, we are done, reset the restart_now flag, for ALL ports
   $query = "UPDATE port SET restart_now=0;";
   $logger->debug($query, 3);
   $result = mysql_query($query);
   if ( ! $result)
   {
      $logger->logit(mysql_error(),LOG_ERROR);
   }
}

?>
