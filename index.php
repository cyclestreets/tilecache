<?php

# Load the settings
require_once ('./.config.php');

# Set request timeout
ini_set ('default_socket_timeout', $timeout);

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

# If a scale is defined, ensure it is supported, either as a native retina tile or a supported upscaling factor
$retinaNative = false;
$upscale = false;
if (isSet ($_GET['scale'])) {
	
	# Cast the defined scales for strict matching
	foreach ($scales as $index => $scale) {
		if ($scale == 1) {unset ($scales[$index]); continue;}	// Disallow URL @1x.png
		$scales[$index] = (string) $scale;
	}
	
	# Ensure the scale is supported
	if (!in_array ($_GET['scale'], $scales, true)) {	// Strict matching so that e.g. 1.50 does not match permitted 1.5
		error404 ("Unsupported scale value {$_GET['scale']} in URL");
		return false;
	}
	$scale = $_GET['scale'];
	
	# Determine if the server for this layer supports native retina tiles
	if ($scale != 1) {
		$upscale = $scale;	// Assume upscaling by default
		if (isSet ($nativeRetinaTiles[$layer])) {
			if (in_array ($scale, $nativeRetinaTiles[$layer])) {
				$retinaNative = $scale;
				$upscale = false;		// Avoid later auto-upscaling
			}
		}
	}
	
	# If using upscaling, ensure that image resizing is supported on the server
	if ($upscale) {
		if (!extension_loaded ('imagick')) {
			error404 ();	// Should probably technically be 500
			return false;
		}
	}
}

# If a variant is specified, prefix this
$variant = '';
if (isSet ($_GET['variant'])) {
	$variant = '/' . $_GET['variant'];
}

# Define the location
$path = $variant . '/' . $z . '/' . $x . '/';
$location = $path . $y . ($retinaNative ? "@{$retinaNative}x" : '') . '.png';

# Set a user-agent so that tile providers know who we are
ini_set ('user_agent', $userAgent);

# Define a function for throwing a 404
function error404 ($message = '404 Resource not found')
{
	header ('HTTP/1.0 404 Not Found');
	echo $message;
	error_log ($message);
	return false;
}

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
# NB This function only needs to deal with retrieval from an external source - locally-cached tiles are served by Apache natively
function getTile ($layers, $layer, $location, $apiKeyParameters)
{
	# Define the tileserver URL
	$tileserver = getTileserverUrl ($layers, $layer);
	
	# Construct the URL
	$url = $tileserver . $location;
	
	# If the tileserver URL has explicit x,y,z parameter placeholders, use that instead of the standard /{z}/{x}/{y}.png layout
	if (substr_count ($tileserver, '{x}') && (substr_count ($tileserver, '{y}') || substr_count ($tileserver, '{-y}')) && substr_count ($tileserver, '{z}')) {
		preg_match ('|^/(.+)/(.+)/(.+)\.png$|', $location, $matches);
		list ($all, $z, $x, $y) = $matches;
		$url = str_replace (array ('{x}', '{y}', '{-y}', '{z}'), array ($x, $y, $y, $z), $tileserver);
	}
	
	# Determine if an API key parameter is required for this layer, and add the key if required
	$apiKeyParameter = (isSet ($apiKeyParameters[$layer]) ? $apiKeyParameters[$layer] : false);
	$url .= ($apiKeyParameter ? '?' . $apiKeyParameter : '');
	
	# Get the tile
	if (!$binary = @file_get_contents ($url)) {		// Error 404 or empty file
		error_log ("Remote tile failed {$url}");
		return false;
	}

  if (isGzipHeaderSet(transformIntoHeaderMap($http_response_header))) {
        $binary = gzdecode($binary);
  }
	return $binary;
}

function transformIntoHeaderMap(array $headers)
{
  $headersWithValues = array_filter($headers, function ($header) { return strpos($header, ':') !== false; });
  $headerMap = [];
  foreach ($headersWithValues as $header) {
    list($key, $value) = explode(':', $header);
    $headerMap[$key] = trim($value);
  }
  return $headerMap;
}

function isGzipHeaderSet(array $headerMap)
{
  return isset($headerMap['Content-Encoding']) && $headerMap['Content-Encoding'] == 'gzip';
}


# Define a function for multiple tries of getting a tile
function getTileWithRetries ($layers, &$layer, $location, $apiKeyParameters, $fallback)
{
	# Get the tile
	if ($binary = getTile ($layers, $layer, $location, $apiKeyParameters)) {return $binary;}
	
	# Try once more if the first attempt failed
	if ($binary = getTile ($layers, $layer, $location, $apiKeyParameters)) {return $binary;}
	
	# Determine the fallback layer; use specified if present, otherwise use the first layer
	$fallbackLayer = (isSet ($fallback[$layer]) ? $fallback[$layer] : key ($layers));
	
	# Try the fallback layer if the requested layer failed
	if ($binary = getTile ($layers, $fallbackLayer, $location, $apiKeyParameters)) {
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
	
	# Signal success by returning the filename
	return $file;
}

# Get the tile
$binary = getTileWithRetries ($layers, $layer, $location, $apiKeyParameters, $fallback);

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

# Cache (write) the tile to disk
if (!$file = cacheTile ($binary, $layer, $path, $location)) {
	return false;
}

# If upscaling is required, scale up the tile and cache the upscaled tile
if ($upscale) {
	$originalSize = 256;	// Original tiles assumed always to be 256
	$scaledSize = $originalSize * $upscale;	// e.g. 512 for 2x
	$scaledFile = preg_replace ('/.png$/', "@{$upscale}x.png", $file);
	$scaledImage = new Imagick ($file);
	//$scaledImage->resizeImage ($scaledSize, $scaledSize, imagick::FILTER_LANCZOS, 1);
	$scaledImage->scaleImage ($scaledSize, $scaledSize);
	$scaledImage->writeImage ($scaledFile);
	$binary = file_get_contents ($scaledFile);
}

# Send cache headers; see https://developers.google.com/speed/docs/best-practices/caching
header ('Expires: ' . gmdate ('D, d M Y H:i:s', strtotime ("+{$expiryDays} days")) . ' GMT');
header ('Last-Modified: ' . gmdate ('D, d M Y H:i:s'));

# Serve the file
echo $binary;

# Define the file used to schedule next clearout, so they are limited to once per day
# !! $cache should be used here but has become encapsulated within cacheTile() so this is a temp fix.
$touchFile = $_SERVER['DOCUMENT_ROOT'] . '/' . 'nextclearout.touch';

# At the garbage collection hour a clean out of old tiles is triggered once
if (date('G') == $garbageCollectionHour && (!file_exists ($touchFile) || time () > filemtime ($touchFile))) {

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
