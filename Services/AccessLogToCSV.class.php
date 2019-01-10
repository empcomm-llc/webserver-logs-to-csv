<?php

namespace AR\Services;

require_once(ROOT_PATH."Services/GeoIpDB.class.php");

use AR\Services\GeoIpDB;

class AccessLogToCSV
{
	const LogReportCSV = ROOT_PATH."reports/log-report.csv";

	private $file = false;
	private $date = false;
	private $contents = false;

	private $IPDB;

	private $access = false;
	private $csvRows = [];
	private $csvRow = [];

	// Use this to set the column order of the CSV output.
	public $csvFields = [
		"IP Address" => "ip_addr",
		"Endpoint" => "http_endpoint",
		"HTTP Status" => "http_status",
		"HTTP Method" => "http_method",
		"HTTP Protocol" => "http_protocol",
		"Requesting City" => "geo_city", 
		"Requesting Region" => "geo_region", 
		"Requesting Country" => "geo_country", 
		"Requesting Continent" => "geo_continent", 
		"Requesting Latitude" => "geo_lat", 
		"Requesting Longitude" => "geo_lng", 
		"Requesting Timezone" => "geo_tz",
		"Request Date" => "date",
		"Request Time" => "time",
		"Request Timezone" => "tz_offset",
		"unused" => "extra_1",
		"Raw Entry" => "raw"
	];


	function __construct($opts = [])
	{
		$this->setParams($opts);

		$this->IPDB = new GeoIpDB();

		$this->start();
	}

	private function setParams($opts)
	{
		$f = array_key_exists("f", (array)$opts) ? $opts["f"] : false;
		$this->file = $f && file_exists($f) ? $f : false;

		$d = array_key_exists("d", (array)$opts) ? $opts["d"] : false;
		$this->date = $d && strtotime($d) ? date("Y-m-d",strtotime($d)) : false;
	}

	public function start()
	{
		if(!$this->file)
			throw new \Exception("Invalid access log file.");

		if(!$this->date)
			$this->date = date("Y-m-d");

		// Get the access log contents.
		$this->getAccessLog();

		// Run line-by-line to collect required data.
		$this->iterate();
	}

	private function getAccessLog()
	{
		$this->contents = explode("\n", file_get_contents($this->file));

		$this->contents = $this->contents && is_array($this->contents) ? $this->contents : false;

		if(!$this->contents)
			throw new \Exception("Empty access log file.");
	}

	private function filterByDate()
	{
		if(!$this->access) { return false; }


		// Get Timestamp
		$timestamp = preg_match('/\d{1,2}\/\D{1,4}\/\d{4}\:\d{1,2}\:\d{1,2}\:\d{1,2}\ (\-|\+)?\d{0,4}/', $this->access, $matches)
			? date("Y-m-d H:i:s O",strtotime($matches[0])) : false;



		// If timestamp exists, remove it from the access log line.
		if($timestamp)
			$this->access = trim(str_replace($matches[0], "", $this->access));



		// Set the timestamp to the csv array.
		$this->csvRow["date"] = date("Y-m-d", strtotime($timestamp));
		$this->csvRow["time"] = date("H:i:s", strtotime($timestamp));
		$this->csvRow["tz_offset"] = date("0", strtotime($timestamp));



		// Filter the date.  Should we let it pass?
		return $this->csvRow["date"] == $this->date;
	}

	private function extractIP()
	{
		// Skip internal dummy connections.
		if(strpos($this->access, "::1") === 0 || strpos($this->access, "127.0.0.1") === 0) {
			return false;
		}


		// Get IP Address
		$ip = preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}/', $this->access, $matches)
			? $matches[0] : false;


		// Remove the ip address from the access log line.
		if($ip)
			$this->access = trim(str_replace($matches[0], "", $this->access));


		$this->csvRow["ip_addr"] = $ip;


		return $ip;
	}

	private function extractHTTP()
	{
		$this->csvRow["http_method"] = false;
		$this->csvRow["http_endpoint"] = false;
		$this->csvRow["http_protocol"] = false;


		// Get HTTP method, request endpoint & HTTP protocol.
		if(preg_match('/"([^"]+)"/', $this->access, $matches))
		{
			$http = explode(" ",$matches[1]);

			if(!is_array($http) || count($http) < 2) {

				$this->csvRow["http_method"] = "[unknown]";
				$this->csvRow["http_endpoint"] = $http[0] ? $http[0] : "[unknown]";
				$this->csvRow["http_protocol"] = "[unknown]";

				// echo " ------------------------------ \n";
				// var_dump($matches);
				// echo "\n";

				// var_dump($http);
				// echo "\n";

				// var_dump($this->access);
				// echo "\n";

				// echo "... \n\n\n";
			}

			else {
				$this->csvRow["http_method"] = $http[0];
				$this->csvRow["http_endpoint"] = $http[1];
				$this->csvRow["http_protocol"] = $http[2];
			}

			// Remove HTTP properties from access log line.
			$this->access = str_replace($matches[0], "", $this->access);
		}


		// Get HTTP status result.
		if((preg_match('/\ \d{3}\ /', $this->access, $matches)))
		{ 
			$this->csvRow["http_status"] = trim($matches[0]);

			// Remove HTTP status from the access log line.
			$this->access = str_replace($matches[0], "", $this->access);
		}
	}

	private function geolocate()
	{
		$geo = false;
		$this->csvRow["geo_city"] = false; 
		$this->csvRow["geo_region"] = false; 
		$this->csvRow["geo_country"] = false; 
		$this->csvRow["geo_continent"] = false; 
		$this->csvRow["geo_lat"] = false; 
		$this->csvRow["geo_lng"] = false; 
		$this->csvRow["geo_tz"] = false; 


		// Do we have an IP address to attempt to locate?
		$ip = $this->csvRow["ip_addr"];
		if(!$ip) return;


		// Is this a local or loop IP?
		// We wont waste our time scanning these.
		if($ip == "127.0.0.1" || strpos($ip, "192.") === 0) { return; }


		// Fetch the geolocation data from the GeoIpDB service.
		$geo = $this->IPDB->get($ip);
		if($geo)
			$this->csvRow = array_merge($this->csvRow, $geo);
	}

	private function updateCSV()
	{
		// Check if the file exists.
		// If it doesn't, we'll include headers.
		$filename = str_replace(".csv", ".".$this->date.".csv", self::LogReportCSV);
		$isNew = !file_exists($filename);

		// Open the log file csv.
		$csvFile = fopen($filename,"a");

		if($isNew)
			array_unshift($this->csvRows, array_keys($this->csvFields));

		// Put CSV contents.
		foreach($this->csvRows as $row)
			fputcsv($csvFile,str_replace("\"","",$row));

		fclose($csvFile);
	}

	private function iterate()
	{

		echo "Ready to iterate: \n";
		echo "File: " . $this->file . "\n";
		echo "Date: " . $this->date . "\n";
		echo " ------------------------  \n\n";

		foreach($this->contents as $access) 
		{

			// Store the access log line & start a new csv row.
			$this->access = $access;
			$this->csvRow = array_fill_keys(array_values($this->csvFields), null);


			// Filter the access log entries by it's date
			if(!$this->filterByDate()) { continue; }


			// Store the raw access line for manual analysis.
			$this->csvRow["raw"] = $this->access;


			// Extract the IP Address.
			if(!$this->extractIP()) { continue; }


			// Extract the HTTP params.
			$this->extractHTTP();


			// Store the remaining values in the access log line.
			$this->csvRow["extra_1"] = $this->access;


			// Get the IP GeoLocation
			$this->geolocate();


			// Append the csv row to the master csv array.
			$this->csvRows[] = $this->csvRow;
		}

		// Store the access log data to the csv files.
		$this->updateCSV();

		// Save the latest IP DB.
		$this->IPDB->saveDB();
	}
}