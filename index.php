<?php
###
# @name			Generate Missing Thumbnails
# @author		Dmitrii Turygin
# @description	This file creates the missing thumbnails.
#	Largely reuses code by Quentin Bramas and his Missing Medium Photos generator plugin.
# https://github.com/Bramas/lychee-create-medium

use Mysqli;
use Lychee\Modules\Database;
use Lychee\Modules\Settings;

$lychee = __DIR__ . '/../../';
$startTime = microtime(true);

require($lychee . 'php/define.php');
require($lychee . 'php/autoload.php');
require($lychee . 'php/helpers/hasPermissions.php');

# Set content
header('content-type: text/plain');

# Load config
if (!file_exists(LYCHEE_CONFIG_FILE)) exit('Error 001: Configuration not found. Please install Lychee first.');
require(LYCHEE_CONFIG_FILE);

# Declare
$result = '';

# Load settings
$settings = new Settings();
$settings = $settings->get();

# Ensure that user is logged in
session_start();
if ((isset($_SESSION['login'])&&$_SESSION['login']===true)&&
	(isset($_SESSION['identifier'])&&$_SESSION['identifier']===$settings['identifier'])) {

	# Function taken from Lychee Photo Module
	function createThumb($url, $filename, $type, $width, $height) {

		// Quality of thumbnails
		$quality = 90;

		// Size of the thumbnail
		$newWidth  = 200;
		$newHeight = 200;

		$photoName = explode('.', $filename);
		$newUrl    = LYCHEE_UPLOADS_THUMB . $photoName[0] . '.jpeg';
		$newUrl2x  = LYCHEE_UPLOADS_THUMB . $photoName[0] . '@2x.jpeg';

		// Create thumbnails with Imagick
		if(Settings::hasImagick()) {

			// Read image
			$thumb = new Imagick();
			$thumb->readImage(LYCHEE.$url);
			$thumb->setImageCompressionQuality($quality);
			$thumb->setImageFormat('jpeg');

			// Remove metadata to save some bytes
			$thumb->stripImage();

			// Copy image for 2nd thumb version
			$thumb2x = clone $thumb;

			// Create 1st version
			$thumb->cropThumbnailImage($newWidth, $newHeight);
			$thumb->writeImage($newUrl);
			$thumb->clear();
			$thumb->destroy();

			// Create 2nd version
			$thumb2x->cropThumbnailImage($newWidth*2, $newHeight*2);
			$thumb2x->writeImage($newUrl2x);
			$thumb2x->clear();
			$thumb2x->destroy();

		} else {

			// Create image
			$thumb   = imagecreatetruecolor($newWidth, $newHeight);
			$thumb2x = imagecreatetruecolor($newWidth*2, $newHeight*2);

			// Set position
			if ($width<$height) {
				$newSize     = $width;
				$startWidth  = 0;
				$startHeight = $height/2 - $width/2;
			} else {
				$newSize     = $height;
				$startWidth  = $width/2 - $height/2;
				$startHeight = 0;
			}

			// Create new image
			switch($type) {
				case 'image/jpeg': $sourceImg = imagecreatefromjpeg($url); break;
				case 'image/png':  $sourceImg = imagecreatefrompng($url); break;
				case 'image/gif':  $sourceImg = imagecreatefromgif($url); break;
				default:           Log::error(Database::get(), __METHOD__, __LINE__, 'Type of photo is not supported');
				                   return false;
				                   break;
			}

			// Create thumb
			fastImageCopyResampled($thumb, $sourceImg, 0, 0, $startWidth, $startHeight, $newWidth, $newHeight, $newSize, $newSize);
			imagejpeg($thumb, $newUrl, $quality);
			imagedestroy($thumb);

			// Create retina thumb
			fastImageCopyResampled($thumb2x, $sourceImg, 0, 0, $startWidth, $startHeight, $newWidth*2, $newHeight*2, $newSize, $newSize);
			imagejpeg($thumb2x, $newUrl2x, $quality);
			imagedestroy($thumb2x);

			// Free memory
			imagedestroy($sourceImg);

		}

		return $newUrl;

	}

	function getAllPhotos() {
		# Functions returns the list of photos
		# Get all photos
		$query	= Database::prepare(Database::get(), "SELECT id, width, height, url, type FROM ? WHERE 1", array(LYCHEE_TABLE_PHOTOS));
		$photos	= Database::get()->query($query);

		$data = array();

		while ($photo = $photos->fetch_assoc()) {	# Parse photo

			$photo['filename'] =   $photo['url'];
			$photo['url']      = LYCHEE_URL_UPLOADS_BIG . $photo['url'];
			$data[] = $photo;

		}
		return $data;
	}

	# for each photo we create the two thumbnails using createThumb()
	# one is 200x200 pixels and another one 400x400 pixels
	# then we write url of 200x200 thumbnail to the database

	$executionLimit = ini_get('max_execution_time');
	set_time_limit(0);
	echo "Maximum Execution Time for PHP is set to unlimited.\r\n\r\n";

	$photos = getAllPhotos();

	$photosDone = 1;
	$photosTotal = count($photos);

	foreach($photos as $photo) {
		$newUrl = createThumb($photo['url'], $photo['filename'], $photo['type'], $photo['width'], $photo['height']);
		if($newUrl) {
			$query  = Database::prepare(Database::get(), "UPDATE ? SET thumbUrl=? WHERE id=?", array(LYCHEE_TABLE_PHOTOS, $newUrl, $photo['id']));
			$result	= Database::get()->query($query);
			echo 'success: '.$photo['id']. ' '.$photo['filename'] . " [" . $photosDone . "/" . $photosTotal  . "]\r\n";
			$photosDone++;
			ob_flush();
    	flush();
		}
		else {
			set_time_limit($executionLimit);
			echo "Maximum Execution Time for PHP is set back to " . $executionLimit . " seconds.";
			exit('error:   '.$photo['id'] . ' '.$photo['filename']);
		}
	}

	set_time_limit($executionLimit);
	echo "Maximum Execution Time for PHP is set back to " . $executionLimit . " seconds.";

} else {
	# Don't go further if the user is not logged in
	echo('You have to be logged in to see the log.');
	exit();
}
?>
