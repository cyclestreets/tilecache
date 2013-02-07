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

# Define the tileserver URL
$tileserver = $layers[$layer];
$serverLetter = chr (97 + rand (0,2));	// i.e. a, b, or c
$tileserver = str_replace ('(a|b|c)', $serverLetter, $tileserver);

# Get the tile
$path = '/' . $z . '/' . $x . '/';
$location = $path . $y . '.png';
$url = $tileserver . $location;
ini_set ('user_agent', $userAgent);
if (!$binary = @file_get_contents ($url)) {		// Error 404 or empty file
	error_log ("Remote tile failed {$url}");
	return false;
}

# Send cache headers; see https://developers.google.com/speed/docs/best-practices/caching
header ('Expires: ' . gmdate ('D, d M Y H:i:s', strtotime ("+{$expiryDays} days")) . ' GMT');
header ('Last-Modified: ' . gmdate ('D, d M Y H:i:s'));

# Allow cross-site HTTP requests
header ('Access-Control-Allow-Origin: *');

# Serve the file
header ('Content-Type: image/png');
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
