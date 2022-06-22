<?php
// error_reporting( error_reporting() & ~E_NOTICE );
require('vendor/autoload.php');

use \PhpMqtt\Client\MqttClient;
use \PhpMqtt\Client\ConnectionSettings;

$server   = '192.168.x.y';
$port     = 1883;
$clientId = rand(105, 150);
$username = 'Mqtt-username';
$password = 'password';
$clean_session = true;

$topicPrefix='supla/devices/zamel-mew-01-DeviceID/';
$chain='channels/0/state/';

$connectionSettings = (new ConnectionSettings)
  ->setUsername($username)
  ->setPassword($password)
  ->setKeepAliveInterval(60)
  ->setLastWillTopic('emqx/test/last-will')
  ->setLastWillMessage('client disconnect')
  ->setLastWillQualityOfService(1);


$mqtt = new MqttClient($server, $port, $clientId);

$mqtt->connect($connectionSettings, $clean_session);
printf("client connected\n");

$measurement = [];
$rssiValue = 0;
$total = 1;

//Get all data from mqtt
$mqtt->subscribe($topicPrefix.$chain.'#', function (string $topic, string $message) use($topicPrefix, $chain, &$measurement, $total, $mqtt): void {
  $short = str_replace($topicPrefix.$chain, "", $topic);

  if (isset($measurement[$short]) === false) {
    $measurement[$short] = [];
  }

  $measurement[$short][] = $message;

  if (count($measurement[$short]) > $total) {
    $mqtt->interrupt();
  }
}, 0);

//Get last wifi signal from mqtt
$mqtt->subscribe($topicPrefix.'state/wifi_signal_strength', function (string $topic, string $message) use(&$rssiValue, $mqtt): void {
  $rssiValue = round($message/10, 0);
}, 0);



$mqtt->loop(true);

//create average of measurment
$measurement = \array_map(static function (array $values):string {
  return array_sum($values) / count($values);
}, $measurement);

//var_dump($measurement);

usleep(3533000);

//Calculate Active Forward And Reverse Power
$totalForwardPowerActive = 0;
$totalReversePowerActive = 0;
$phasesForwardPower = [];
$phasesReversePower = [];
$phasesPowerMomentary = 0;
for ($x = 1; $x <= 3; $x++) {
  $phasesPowerMomentary = $measurement['phases/'.$x.'/power_active'];
  $phasesForwardPower[$x] = 0;
  $phasesReversePower[$x] = 0;
  if($phasesPowerMomentary>0){
    $totalForwardPowerActive+=$phasesPowerMomentary;
    $phasesForwardPower[$x] = $phasesPowerMomentary;
  }else{
    $totalReversePowerActive+=$phasesPowerMomentary;
    $phasesReversePower[$x] = abs($phasesPowerMomentary);
    //If power was reverse, changing sign of current
    //$measurement['phases/'.$x.'/current']=(-1)*$measurement['phases/'.$x.'/current'];
  }
  //Publish to domoticz power L1, L2, L3
 $mqtt->publish('domoticz/in', '{"idx":'.($x+150).',"nvalue":0,"svalue":"'.round($measurement['phases/'.$x.'/total_forward_active_energy']*1000,0).';0;'.round($measurement['phases/'.$x.'/total_reverse_active_energy']*1000,0).';0;'.round($phasesForwardPower[$x],0).';'.round(abs($phasesReversePower[$x]),0).'","Battery":100,"RSSI":'.$rssiValue.'}', 0);

 //Publish to domoticz voltage L1, L2, L3
 $mqtt->publish('domoticz/in', '{"idx":'.($x+153).',"nvalue":0,"svalue":"'.round($measurement['phases/'.$x.'/voltage'],1).'","Battery":100,"RSSI":'.$rssiValue.'}', 0);

 //Publish to domoticz friqency L1, L2, L3
 //$mqtt->publish('domoticz/in', '{"idx":'.($x+128).',"nvalue":0,"svalue":"'.round($measurement['phases/'.$x.'/frequency'],1).'","Battery":100,"RSSI":'.$rssiValue.'}', 0);

}
//Publish to domoticz friqency L1
$mqtt->publish('domoticz/in', '{"idx":157,"nvalue":0,"svalue":"'.round($measurement['phases/1/frequency'],1).'","Battery":100,"RSSI":'.$rssiValue.'}', 0);

//Publish to domoticz smart meter power
$mqtt->publish('domoticz/in', '{"idx":150,"nvalue":0,"svalue":"'.round($measurement['total_forward_active_energy']*1000,0).';0;'.round($measurement['total_reverse_active_energy']*1000,0).';0;'.round($totalForwardPowerActive,0).';'.round(abs($totalReversePowerActive),0).'","Battery":100,"RSSI":'.$rssiValue.'}', 0);

//Publish to domoticz current meter
$mqtt->publish('domoticz/in', '{"idx":149,"nvalue":0,"svalue":"'.round($measurement['phases/1/current'],1).';'.round($measurement['phases/2/current'],1).';'.round($measurement['phases/3/current'],1).'","Battery":100,"RSSI":'.$rssiValue.'}', 0);

$mqtt->disconnect();
