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
	$tileserver = str_replace ('{s}', $serverLetter, $tileserver);
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
function getTileWithRetries ($layers, &$layer, $location, $fallback)
{
	# Get the tile
	if ($binary = getTile ($layers, $layer, $location)) {return $binary;}
	
	# Try once more if the first attempt failed
	if ($binary = getTile ($layers, $layer, $location)) {return $binary;}
	
	# Determine the fallback layer; use specified if present, otherwise use the first layer
	$fallbackLayer = (isSet ($fallback[$layer]) ? $fallback[$layer] : key ($layers));
	
	# Try the fallback layer if the requested layer failed
	if ($binary = getTile ($layers, $fallbackLayer, $location)) {
		$layer = $fallbackLayer;	// Cache fallback tiles in the fallback layer's own cache directory, not the originally-requested layer's cache
		return $binary;
	}
	
	# All attempts have failed
	return false;
}

# Function to cache (write) a tile to disk
function cacheTile ($binary, $layer, $path, $location)
{
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
}

# Get the tile
$binary = getTileWithRetries ($layers, $layer, $location, $fallback);

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

# Cache (write) the tile to disk
cacheTile ($binary, $layer, $path, $location);

# Define the file used to schedule next clearout, so they are limited to once per day
$touchFile = $cache . 'nextclearout.touch';

# At the garbage collection hour a clean out of old tiles is triggered once
if (date('G') == $garabageCollectionHour && (!file_exists ($touchFile) || time () > filemtime ($touchFile))) {

	// !! Despite this extra check multiple invocations do still occur - even if there's a short sleep.
	// A stronger way of locking is required.

	// Recheck
	if (!file_exists ($touchFile) || time () > filemtime ($touchFile)) {
		
		// Update the next clearout time to tomorrow
		touch ($touchFile, time () + 24 * 3600);

		// Command to clear out the tiles from subfolders
		$command = "find {$_SERVER['DOCUMENT_ROOT']} -mindepth 2 -type f -name '*.png' -mtime +{$expiryDays} -exec rm -f {} \;";
		error_log ('Starting tile clearance:' . $command);

		// A test of this on 22 Apr 2013 03:12:11 took five minutes to complete
		$lastLine = exec ($command);
		error_log ("Completed tile clearance: {$lastLine}");

		// Remove all empty folders
		$command = "find {$_SERVER['DOCUMENT_ROOT']} -type d -empty -exec rmdir {} \;";
		error_log ('Starting empty folder clearance:' . $command);

		// Run
		$lastLine = exec ($command);
		error_log ("Completed empty folder clearance: {$lastLine}");

	} else {

		// Output to check if this ever happens
		error_log ('Avoided duplicate garbage collection');
	}
}

# End of file
