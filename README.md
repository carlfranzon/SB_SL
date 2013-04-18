SB_SL
=====

Status Board panel for SL (Stockholm Buses and Metro)

# Status Board Panel for SL Departure Times (Stockholm Buses and Metro system)

## Introduction

This panel is mostly intended for Swedish users in Stockholm. In fact, it won't be of much use (more than possibly educational) for anyone else.

With that said, this panel will show all upcoming departures from any station in Stockholm in a table format.

## 3-step setup

1. Upload the files to your webserver.

2. Open Status Board on your iPad and add a new table panel. Tap on it and edit the url to point to your uploaded CFD_SB_SL.php file (don't forget to add all mandatory parameters) such as:

<pre>http://YOURDOMAIN/CFD_SB_SL.php?station=9001&dist=10&key=123654abdfgdfr34ss89700</pre>

3. Enjoy, and never miss the bus again!

## Parameters

The PHP script takes a number of parameters, one mandatory, two optional. Below is a description of all:

<table>
  <tr>
		<th>Parameter</th>
		<th>Type</th>
		<th>Mandatory</th>
		<th>Example value</th>
		<th>Description</th>
	</tr>
	<tr>
		<td>station</td>
		<td>integer</td>
		<td>yes</td>
		<td>9001</td>
		<td>The SL station ID. The easiest way to get this is to use the API Console provided by TrafikLab. Go to http://console.apihq.com/sl-realtidsinformation and make a test call to GetSite and searching for the station you want in text. The response will then show you the id for the station(s) that match your query. In the future, I will make a "lookup" form on my website for easier finding. "9001" is "T-Centralen"</td>
	</tr>
	<tr>
		<td>dist</td>
		<td>integer</td>
		<td>no</td>
		<td>10</td>
		<td>The minutes it takes for you to get to the station. If it takes you 10 minutes to get there it's not interesting to see the deapurtes that will leave before you get there.</td>
	</tr>
	<tr>
		<td>key</td>
		<td>string</td>
		<td>yes</td>
		<td>123654abdfgdfr34ss89700</td>
		<td>This is an api key you need to signup for at the trafik-lab site. http://trafiklab.se</td>
	</tr>
</table>

## Notes

If you find any bugs or issues, such as weird destination names or times, please file issues here at github. Due to the rather rudimentary API trafiklab supplies there is some RegEX magic that parses out destination names and times, all from one string(!), and as I haven't tested all stations, you might find something.



