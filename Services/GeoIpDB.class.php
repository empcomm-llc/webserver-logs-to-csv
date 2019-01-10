<?php

namespace AR\Services;

class GeoIpDB
{
	const LocalIpDB = "geo-ip.db.json";

	private $DB = false;

	public function __construct()
	{
		$this->loadDB();
	}

	public function checkIfExists($ip)
	{
		return array_key_exists($ip, (array)$this->DB);
	}

	public function get($ip)
	{
		return $this->checkIfExists($ip) ? $this->getFromLocal($ip) : $this->getFromRemote($ip);
	}

	public function getFromLocal($ip)
	{
		return $this->DB[$ip];
	}

	public function getFromRemote($ip)
	{
		// Fetch from remote
		$ch = curl_init("http://www.geoplugin.net/php.gp?".$ip);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
		$chResults = curl_exec($ch);

		if($chResults)
		{
			$geo = unserialize($chResults);

			// Add to local DB array.
			$this->DB[$ip] = [
				"geo_city" => $geo["geoplugin_city"],
				"geo_region" => $geo["geoplugin_region"],
				"geo_country" => $geo["geoplugin_countryCode"],
				"geo_continent" => $geo["geoplugin_continentName"],
				"geo_lat" => $geo["geoplugin_latitude"],
				"geo_lng" => $geo["geoplugin_longitude"],
				"geo_tz" => $geo["geoplugin_timezone"],

				// Timestamp the new IP address.
				// This will make it easy for us to
				// clean the database over time.
				"_timestamp" => strtotime("NOW")
			];

			return $this->getFromLocal($ip);
		}

		return false;
	}

	private function loadDB()
	{
		$this->DB = file_exists(self::LocalIpDB) ? file_get_contents(self::LocalIpDB) : false;
		$this->DB = $this->DB && is_string($this->DB) ? json_decode($this->DB, TRUE) : false;
		$this->DB = is_array($this->DB) ? $this->DB : [];
	}

	public function saveDB()
	{
		return is_array($this->DB) ? file_put_contents(self::LocalIpDB, json_encode($this->DB)) : false;
	}
}