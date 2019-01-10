<?php

namespace AR;

define("ROOT_PATH", __DIR__.DIRECTORY_SEPARATOR);

require_once(ROOT_PATH."Services/AccessLogToCSV.class.php");

new \Ar\Services\AccessLogToCSV(getopt("f:d::"));