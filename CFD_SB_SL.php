<?php

define('api_key', $_GET['key']);
define('station',$_GET['station']);

if(isset($_GET['dist'])):
$distance = $_GET['dist'];
else:
    $distance = 0;
endif;

$api_key = api_key;


require 'coreylib.php';
if($distance >= 30){
    $api_buses = new clApi('https://api.trafiklab.se/sl/realtid/GetDpsDepartures.xml?&siteId='.station.'&key='.$api_key.'&timeWindow=60', false);
} else {
    $api_buses = new clApi('https://api.trafiklab.se/sl/realtid/GetDpsDepartures.xml?&siteId='.station.'&key='.$api_key, false);
}

$slbuses = $api_buses->parse();

$api_metros = new clApi('https://api.trafiklab.se/sl/realtid/GetDepartures.xml?siteId='.station.'&key='.$api_key, false);
$slmetros = $api_metros->parse();


if ($slbuses or $slmetros) {

$api_station = new clApi('https://api.trafiklab.se/sl/realtid/GetSite.xml?stationSearch='.station.'&key='.$api_key);
$slstation = $api_station->parse();

$slbusesmetros = array();

foreach($slbuses->get('DpsBus') as $bus):
  $diff = strtotime($bus->get('ExpectedDateTime'))-mktime(date("s"), date("i"), date("h"), date("m")  , date("d"), date("Y"));
	$years   = floor($diff / (365*60*60*24)); 
	$months  = floor(($diff - $years * 365*60*60*24) / (30*60*60*24)); 
	$days    = floor(($diff - $years * 365*60*60*24 - $months*30*60*60*24)/ (60*60*24));

	$hours   = floor(($diff - $years * 365*60*60*24 - $months*30*60*60*24 - 	$days*60*60*24)/ (60*60)); 

	$minutes  = floor(($diff - $years * 365*60*60*24 - $months*30*60*60*24 - 	$days*60*60*24 - $hours*60*60)/ 60); 

	$seconds = floor(($diff - $years * 365*60*60*24 - $months*30*60*60*24 - 	$days*60*60*24 - $hours*60*60 - $minutes*60));
	
    array_push($slbusesmetros, array("transport" => $bus->get('TransportMode'),
                                     "name" => $bus->get('Destination'),
                                     "line" => $bus->get('LineNumber'),
                                     //"departure" => (date("i", strtotime($bus->get('ExpectedDateTime'))) - date("i"))-1
                                     "departure" => $minutes
                                    ));
endforeach;
foreach ($slmetros->get('Metro') as $metro):
    $disprow2 = $metro->get('DisplayRow2');
    $ptrn_times = "/[a-zA-ZåäöÅÄÖ .]+/"; //Gives times
    $ptrn_names = "/[0-9]+/"; //Gives names
    $matches_times = preg_split($ptrn_times, $disprow2, NULL, PREG_SPLIT_OFFSET_CAPTURE);
    $matches_names = preg_split($ptrn_names, $disprow2, NULL, PREG_SPLIT_OFFSET_CAPTURE);
    // $matches_times[1][0] and $matches_times[3][0] gives times
    // $matches_names[1][0] and $matches_names[3][0] gives names
    
    
    
    $pattern = "/.*[^min]/";
    $str = $metro->get('DisplayRow1');
    $matches= array();
    preg_match($pattern, $str, $matches);
    $newstr = preg_replace('/( min)/', '', $matches[0]);
    //array_push($slbusesmetros, array("transport" => $metro->get('TransportMode'),
      //                               "name" => preg_replace('/[^a-zA-Z åäöÅÄÖ]/', '', $newstr),
      //                               "line" => $metro->get('GroupOfLine'),
      //                               "departure" => (trim(preg_match('/[ ][^a-zA-Z åäöÅÄÖ]+[ ]/',$metro->get('DisplayRow1'))))
      //                              ));
    
    array_push($slbusesmetros, array("transport" => $metro->get('TransportMode'),
                                     "name" => $matches_names[1][0],
                                     "line" => $metro->get('GroupOfLine'),
                                     "departure" => $matches_times[1][0]
                                    ));
    
    array_push($slbusesmetros, array("transport" => $metro->get('TransportMode'),
                                     "name" => $matches_names[3][0],
                                     "line" => $metro->get('GroupOfLine'),
                                     "departure" => $matches_times[3][0]
                                    ));
endforeach;

foreach ($slbuses->get('DpsTram') as $tram):
	$diff = strtotime($tram->get('ExpectedDateTime'))-mktime(date("s"), date("i"), date("h"), date("m")  , date("d"), date("Y"));
	$years   = floor($diff / (365*60*60*24)); 
	$months  = floor(($diff - $years * 365*60*60*24) / (30*60*60*24)); 
	$days    = floor(($diff - $years * 365*60*60*24 - $months*30*60*60*24)/ (60*60*24));

	$hours   = floor(($diff - $years * 365*60*60*24 - $months*30*60*60*24 - 	$days*60*60*24)/ (60*60)); 

	$minutes  = floor(($diff - $years * 365*60*60*24 - $months*30*60*60*24 - 	$days*60*60*24 - $hours*60*60)/ 60); 

	$seconds = floor(($diff - $years * 365*60*60*24 - $months*30*60*60*24 - 	$days*60*60*24 - $hours*60*60 - $minutes*60));
	
    array_push($slbusesmetros, array("transport" => $tram->get('TransportMode'),
                                     "name" => $tram->get('Destination'),
                                     "line" => $tram->get('GroupOfLine'),
                                     //"departure" => (date("i", strtotime($bus->get('ExpectedDateTime'))) - date("i"))-1
                                     "departure" => $minutes
                                    ));
endforeach;

foreach ($slbuses->get('DpsTrain') as $train):
	$diff = strtotime($train->get('ExpectedDateTime'))-mktime(date("s"), date("i"), date("h"), date("m")  , date("d"), date("Y"));
	$years   = floor($diff / (365*60*60*24)); 
	$months  = floor(($diff - $years * 365*60*60*24) / (30*60*60*24)); 
	$days    = floor(($diff - $years * 365*60*60*24 - $months*30*60*60*24)/ (60*60*24));

	$hours   = floor(($diff - $years * 365*60*60*24 - $months*30*60*60*24 - 	$days*60*60*24)/ (60*60)); 

	$minutes  = floor(($diff - $years * 365*60*60*24 - $months*30*60*60*24 - 	$days*60*60*24 - $hours*60*60)/ 60); 

	$seconds = floor(($diff - $years * 365*60*60*24 - $months*30*60*60*24 - 	$days*60*60*24 - $hours*60*60 - $minutes*60));
	
    array_push($slbusesmetros, array("transport" => $train->get('TransportMode'),
                                     "name" => $train->get('Destination'),
                                     "line" => $train->get('GroupOfLine'),
                                     //"departure" => (date("i", strtotime($bus->get('ExpectedDateTime'))) - date("i"))-1
                                     "departure" => $minutes
                                    ));
endforeach;


function aasort (&$array, $key) {
    $sorter=array();
    $ret=array();
    reset($array);
    foreach ($array as $ii => $va) {
        $sorter[$ii]=$va[$key];
    }
    asort($sorter);
    foreach ($sorter as $ii => $va) {
        $ret[$ii]=$array[$ii];
    }
    $array=$ret;
}

aasort($slbusesmetros,"departure");

?>
<table id="SLRealtime">
    <tr>
        <th style="width:3%"></th>
        <th style="width:73px;text-align:center"><img src="http://images2.wikia.nocookie.net/__cb20100824161519/logopedia/images/c/ca/SL_logo.svg" height="30px" /></th>
        <th style="padding-left:20"><?php echo 'Avgångar för: '.$slstation->get('Name').' om '.$distance.' min'; ?></th>
        <th style="width:12%;text-align:center">min.</th>
    </tr>
<?php foreach($slbusesmetros as $dps): 
	//if ($dps['transport'] == 'METRO'){
	//	$distance = 0;
	//}
?>
<?php if ($dps['departure'] >= $distance){ ?>
    <tr>
        <?php switch ($dps['transport']){
        		case 'BLUEBUS': { ?>
        			<td style="background-color:blue"></td>
        			<td class="projectLine" style="color:lightGray"><?php echo $dps['line']; ?></td>
        <?php	} break;
        		case 'BUS': { ?> 
        			<td style="background-color:red"></td>
        			<td class="projectLine" style="color:lightGray"><?php echo $dps['line']; ?></td>
        <?php	} break;
        		case 'METRO': {
        			switch ($dps['line']){
	        			case 'Tunnelbanans gröna linje': { ?>
	        				<td style="background-color:green"></td>
                                                <td class="projectLine" style="color:lightGray"><img src="http://www.carlfranzon.com/wp-content/uploads/T-gron.png" height="40px" /></td>
	     <?php			} break;   				
	     				case 'Tunnelbanans röda linje': { ?>
	     					<td style="background-color:red"></td>
                                                <td class="projectLine" style="color:lightGray"><img src="http://www.carlfranzon.com/wp-content/uploads/T-rod.png" height="40px" /></td>
	     <?php			} break;
	     				case 'Tunnelbanans blå linje': { ?>
	     					<td style="background-color:blue"></td>
                                                <td class="projectLine" style="color:lightGray"><img src="http://www.carlfranzon.com/wp-content/uploads/T-bla.png" height="40px" /></td>
	     <?php			} break;
	     			}
                	} break;
	     		case 'TRAM': { ?>
	     			<td style="background-color:mediumGray"></td>
	     <?php 		switch ($dps['line']){
	     				case 'Spårväg City': { ?>
	     					<td class="projectLine" style="color:lightGray"><img src="http://www.carlfranzon.com/wp-content/uploads/S.png" height="40px" /></td>
	     <?php			} break;
	     				default: { ?>
	     					<td class="projectLine" style="color:lightGray"><img src="http://www.carlfranzon.com/wp-content/uploads/L.png" height="40px" /></td>
	     <?php			}
	     			}
	     		} break;
                        case 'TRAIN': { ?>
                                <td style="background-color:mediumGray"></td>
                                <td class="projectLine" style="color:lightGray"><img src="http://www.carlfranzon.com/wp-content/uploads/J.png" height="40px" /></td>
             <?php      }
	     		} ?>
	     
        
        <td class="projectDestination"><?php echo $dps['name']; ?></td>
        <td class="projectTime" style="text-align:center"><?php echo $dps['departure']; ?></td>
    </tr>
    <?php } //endif ?>
  <?php endforeach ?>
  

</table>
<?php

} else {
  // something went wrong
  echo 'Error';
}
?>
