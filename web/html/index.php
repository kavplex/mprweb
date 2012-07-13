<?php
set_include_path(get_include_path() . PATH_SEPARATOR . '../lib');

include_once("config.inc.php");
include_once("routing.inc.php");

$path = rtrim($_SERVER['PATH_INFO'], '/');

if (get_route($path) !== NULL) {
	include get_route($path);
} else {
	switch ($path) {
	case "/css/archweb.css":
	case "/css/aur.css":
	case "/css/archnavbar/archnavbar.css":
		header("Content-Type: text/css");
		include "./$path";
		break;
	case "/css/archnavbar/archlogo.gif":
	case "/images/new.gif":
		header("Content-Type: image/gif");
		include "./$path";
		break;
	case "/css/archnavbar/archlogo.png":
	case "/images/AUR-logo-80.png":
	case "/images/AUR-logo.png":
	case "/images/favicon.ico":
	case "/images/feed-icon-14x14.png":
	case "/images/titlelogo.png":
	case "/images/x.png":
		header("Content-Type: image/png");
		include "./$path";
		break;
	}
}
