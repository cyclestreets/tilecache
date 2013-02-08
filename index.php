<?php

# Load the settings
require_once ('./.config.php');

# Ensure the layer is supported
if (!isSet ($_GET['layer'])) {return false;}
if (!isSet ($layers[$_GET['layer']])) {return false;}
$layer = $_GET['layer'];

# Ensure the x/y/z parameters are present and numeric
$parameters = array ('x', 'y', 'z');
foreach ($parameters as $parameter) {
	if (!isSet ($_GET[$parameter])) {return false;}
	if (!ctype_digit ($_GET[$parameter])) {return false;}
	${$parameter} = $_GET[$parameter];
}

# Define the location
$path = '/' . $z . '/' . $x . '/';
$location = $path . $y . '.png';

# Set a user-agent so that tile providers know who we are
ini_set ('user_agent', $userAgent);

# Define a function for getting the tileserver URL for a specified layer
function getTileserverUrl ($layers, $layer)
{
	$tileserver = $layers[$layer];
	$serverLetter = chr (97 + rand (0,2));	// i.e. a, b, or c
	$tileserver = str_replace ('(a|b|c)', $serverLetter, $tileserver);
	return $tileserver;
}

# Define a function for getting a tile
function getTile ($layers, $layer, $location)
{
	# Define the tileserver URL
	$tileserver = getTileserverUrl ($layers, $layer);
	
	$url = $tileserver . $location;
	if (!$binary = @file_get_contents ($url)) {		// Error 404 or empty file
		error_log ("Remote tile failed {$url}");
		return false;
	}
	return $binary;
}

# Define a function for multiple tries of getting a tile
function getTileWithRetries ($layers, $layer, $location)
{
	# Get the tile
	if ($binary = getTile ($layers, $layer, $location)) {return $binary;}
	
	# Try once more if the first attempt failed
	if ($binary = getTile ($layers, $layer, $location)) {return $binary;}
	
	# Try the first tileserver if the requested layer failed
	$fallbackLayer = key ($layers);
	if ($binary = getTile ($layers, $fallbackLayer, $location)) {return $binary;}
	
	# All attempts have failed
	return false;
}

# Get the tile
$binary = getTileWithRetries ($layers, $layer, $location);

# Allow cross-site HTTP requests
header ('Access-Control-Allow-Origin: *');

# Send the PNG header
header ('Content-Type: image/png');

# If no tile was retrieved, serve the null tile and end at this point
if (!$binary) {
	$binary = file_get_contents ('./nulltile.png');
	echo $binary;
	return;
}

# Send cache headers; see https://developers.google.com/speed/docs/best-practices/caching
header ('Expires: ' . gmdate ('D, d M Y H:i:s', strtotime ("+{$expiryDays} days")) . ' GMT');
header ('Last-Modified: ' . gmdate ('D, d M Y H:i:s'));

# Serve the file
echo $binary;

# Ensure the cache is writable
$cache = $_SERVER['DOCUMENT_ROOT'] . '/';
if (!is_writable ($cache)) {
	error_log ("Cannot write to cache $cache");
	return false;
}

# Ensure the directory for the file exists
$directory = $cache . $layer . $path;
if (!is_dir ($directory)) {
	mkdir ($directory, 0777, true);
}

# Ensure the directory is writable
if (!is_writable ($directory)) {
	error_log ("Cannot write file to directory $directory");
	return false;
}

# Save the file to disk
$file = $cache . $layer . $location;
file_put_contents ($file, $binary);

# Clean out old files periodically
if (rand (1, $garbageCollection1In) == 1) {
	$command = "find {$_SERVER['DOCUMENT_ROOT']} -name '.png' -mtime +{$expiryDays} -exec rm -f {} \;";
}

?>
