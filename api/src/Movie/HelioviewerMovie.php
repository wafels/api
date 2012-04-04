<?php
/* vim: set expandtab tabstop=4 shiftwidth=4 softtabstop=4: */
/**
 * Movie_HelioviewerMovie Class Definition
 * 
 * 2011/05/24: http://flowplayer.org/plugins/streaming/pseudostreaming.html#prepare
 *
 * PHP version 5
 *
 * @category Movie
 * @package  Helioviewer
 * @author   Keith Hughitt <keith.hughitt@nasa.gov>
 * @author   Jaclyn Beck <jaclyn.r.beck@gmail.com>
 * @license  http://www.mozilla.org/MPL/MPL-1.1.html Mozilla Public License 1.1
 * @link     http://launchpad.net/helioviewer.org
 */
require_once HV_API_ROOT_DIR . '/src/Helper/HelioviewerLayers.php';
require_once HV_API_ROOT_DIR . '/src/Helper/RegionOfInterest.php';
require_once HV_API_ROOT_DIR . '/src/Helper/DateTimeConversions.php';
require_once HV_API_ROOT_DIR . '/src/Database/ImgIndex.php';
require_once HV_API_ROOT_DIR . '/lib/alphaID/alphaID.php';

/**
 * Represents a static (e.g. mp4/webm) movie generated by Helioviewer
 *
 * Note: For movies, it is easiest to work with Unix timestamps since that is what is returned
 *       from the database. To get from a javascript Date object to a Unix timestamp, simply
 *       use "date.getTime() * 1000." (getTime returns the number of miliseconds)
 * 
 * Movie Status:
 *  0   QUEUED
 *  1   PROCESSING
 *  2   COMPLETED
 *  3   ERROR
 *
 * @category Movie
 * @package  Helioviewer
 * @author   Keith Hughitt <keith.hughitt@nasa.gov>
 * @author   Jaclyn Beck <jaclyn.r.beck@gmail.com>
 * @license  http://www.mozilla.org/MPL/MPL-1.1.html Mozilla Public License 1.1
 * @link     http://launchpad.net/helioviewer.org
 */
class Movie_HelioviewerMovie
{
    public $id;
    public $frameRate;
    public $movieLength;
    public $maxFrames;
    public $numFrames;
    public $reqStartDate;
    public $reqEndDate;
    public $startDate;
    public $endDate;
    public $width;
    public $height;
    public $directory;
    public $filename;
    public $format;
    public $status;
    public $timestamp;
    public $watermark;

    private $_db;
    private $_layers;
    private $_roi;
    private $_timestamps = array();
    private $_frames     = array();

    /**
     * Prepares the parameters passed in from the api call and makes a movie from them.
     *
     * @return {String} a url to the movie, or the movie will display.
     */
    public function __construct($publicId, $format="mp4")
    {
        $this->_db = new Database_ImgIndex();
        
        $id = alphaID($publicId, true, 5, HV_MOVIE_ID_PASS);
        $info = $this->_db->getMovieInformation($id);
        
        if (is_null($info)) {
             throw new Exception("Unable to find the requested movie.");
        }
        
        $this->publicId     = $publicId;
        $this->format       = $format;
        $this->reqStartDate = $info['reqStartDate'];
        $this->reqEndDate   = $info['reqEndDate'];
        $this->startDate    = $info['startDate'];
        $this->endDate      = $info['endDate'];
        $this->timestamp    = $info['timestamp'];
        $this->imageScale   = (float) $info['imageScale'];
        $this->frameRate    = (float) $info['frameRate'];
        $this->movieLength  = (float) $info['movieLength'];
        $this->id           = (int) $id;
        $this->status       = (int) $info['status'];
        $this->numFrames    = (int) $info['numFrames'];
        $this->width        = (int) $info['width'];
        $this->height       = (int) $info['height'];
        $this->watermark    = (bool) $info['watermark'];
        $this->maxFrames    = min((int) $info['maxFrames'], HV_MAX_MOVIE_FRAMES);
        
        // Data Layers
        $this->_layers = new Helper_HelioviewerLayers($info['dataSourceString']);
        
        // Regon of interest
        $this->_roi = Helper_RegionOfInterest::parsePolygonString($info['roi'], $info['imageScale']);
    }
    
    /**
     * Build the movie frames and movie
     */
    public function build()
    {
        date_default_timezone_set('UTC');
        
        // Check to make sure we have not already started processing the movie
        if ($this->status !== 0) {
            throw new Exception("The requested movie is either currently being built or has already been built");
        }

        $this->_db->markMovieAsProcessing($this->id, $this->format);
        
        try {
            $this->directory = $this->_buildDir();
    
            // If the movie frames have not been built create them
            if (!file_exists($this->directory . "frames")) {
                require_once HV_API_ROOT_DIR . '/src/Image/Composite/HelioviewerMovieFrame.php';
                
                $t1 = date("Y-m-d H:i:s");
                         
                $this->_getTimeStamps();      // Get timestamps for frames in the key movie layer
                $this->_setMovieProperties(); // Sets the actual start and end dates, frame-rate, movie length, numFrames and dimensions
                $this->_buildMovieFrames($this->watermark); // Build movie frames
                
                $t2 = date("Y-m-d H:i:s");

                $this->_db->finishedBuildingMovieFrames($this->id, $t1, $t2); // Update status and log time to build frames
            } else {
                $this->filename = $this->_buildFilename();
            }
        } catch (Exception $e) {
            $this->_abort("Error encountered during movie frame compilation: {$e->getMessage()}");
        }

        $t3 = time();

        // Compile movie
        try {
            $this->_encodeMovie();
        } catch (Exception $e) {
            $t4 = time();
            $this->_abort("Error encountered during video encoding. This may be caused
            by a FFmpeg configuration issue, or by insufficient permissions in the cache.", $t4 - $t3);
        }
		
		// Log buildMovie in statistics table
        if (HV_ENABLE_STATISTICS_COLLECTION) {
            include_once HV_API_ROOT_DIR . '/src/Database/Statistics.php';
            $statistics = new Database_Statistics();
            $statistics->log("buildMovie");
        }
        
        $this->_cleanUp();
    }
    
    /**
     * Returns information about the completed movie
     * 
     * @return array A list of movie properties and a URL to the finished movie
     */
    public function getCompletedMovieInformation($verbose=false) {
        $info = array(
            "frameRate"   => $this->frameRate,
            "numFrames"   => $this->numFrames,
            "startDate"   => $this->startDate,
            "status"      => $this->status,
            "endDate"     => $this->endDate,
            "width"       => $this->width,
            "height"      => $this->height,
			"title"       => $this->getTitle(),
            "thumbnails"  => $this->getPreviewImages(),
            "url"         => $this->getURL()
        );
        
        if ($verbose) {
            $extra = array(
                "timestamp"  => $this->timestamp,
                "duration"   => $this->getDuration(),
                "imageScale" => $this->imageScale,
                "layers"     => $this->_layers->serialize(),
                "x1"         => $this->_roi->left(),
                "y1"         => $this->_roi->top(),
                "x2"         => $this->_roi->right(),
                "y2"         => $this->_roi->bottom()                
            );
            $info = array_merge($info, $extra);
        }
        
        return $info;
    }
    
    /**
     * Returns an array of filepaths to the movie's preview images
     */
    public function getPreviewImages()
    {
        $rootURL = str_replace(HV_CACHE_DIR, HV_CACHE_URL, $this->_buildDir());
        
        $images = array();
        
        foreach (array("icon", "small", "medium", "large", "full")  as $size) {
            $images[$size] = $rootURL . "preview-$size.png";
        }
        
        return $images;
    }

    /**
     * Returns the base filepath for movie without any file extension
     */
    public function getFilepath($highQuality=false)
    {
        return $this->_buildDir() . $this->_buildFilename($highQuality);
    }
    
    public function getDuration()
    {
        return $this->numFrames / $this->frameRate;
    }
    
    public function getURL()
    {
        return str_replace(HV_CACHE_DIR, HV_CACHE_URL, $this->_buildDir()) .
                $this->_buildFilename();
    }
    
    /**
     * Cancels movie request
     * 
     * @param string $msg Error message
     */
    private function _abort($msg, $procTime=0) {
        $this->_db->markMovieAsInvalid($this->id, $procTime);
        $this->_cleanUp();
        throw new Exception("Unable to create movie: " . $msg);
    }
    
    /**
     * Determines the directory to store the movie in.
     * 
     * @return string Directory
     */
    private function _buildDir ()
    {
        $date = str_replace("-", "/", substr($this->timestamp, 0, 10));
        return sprintf("%s/movies/%s/%s/", HV_CACHE_DIR, $date, $this->publicId);
    }

    /**
     * Determines filename to use for the movie
     * 
     * @param string $extension Extension of the movie format to be created 
     *
     * @return string Movie filename
     */
    private function _buildFilename($highQuality=false) {
        $start = str_replace(array(":", "-", " "), "_", $this->startDate);
        $end   = str_replace(array(":", "-", " "), "_", $this->endDate);
        
        $suffix = ($highQuality && $this->format == "mp4") ? "-hq" : "";

        return sprintf("%s_%s_%s%s.%s", $start, $end, $this->_layers->toString(), $suffix, $this->format);
    }

    /**
     * Takes in meta and layer information and creates movie frames from them.
     * 
     * TODO: Use middle frame instead last one...
     * TODO: Create standardized thumbnail sizes (e.g. thumbnail-med.png = 480x320, etc)
     *
     * @return $images an array of built movie frames
     */
    private function _buildMovieFrames($watermark)
    {
        $frameNum = 0;

        // Movie frame parameters
        $options = array(
            'database'  => $this->_db,
            'compress'  => false,
            'interlace' => false,
            'watermark' => $watermark
        );
        
        // Index of preview frame
        $previewIndex = floor($this->numFrames / 2);
        
        // Add tolerance for single-frame failures
        $numFailures = 0;

        // Compile frames
        foreach ($this->_timestamps as $time) {
            $filepath =  sprintf("%sframes/frame%d.bmp", $this->directory, $frameNum);

            try {
	            $screenshot = new Image_Composite_HelioviewerMovieFrame($filepath, $this->_layers, $time, $this->_roi, $options);
	            
	            if ($frameNum == $previewIndex) {
	                $previewImage = $screenshot; // Make a copy of frame to be used for preview images
	            }

	            $frameNum++;
	            array_push($this->_frames, $filepath);
            } catch (Exception $e) {
                $numFailures += 1;
                
                if ($numFailures <= 3) {
                    // Recover if failure occurs on a single frame
                    $this->numFrames--;
                } else {
                    // Otherwise proprogate exception to be logged
                    throw $e;
                }
            }
        }
        $this->_createPreviewImages($previewImage);
    }
    
    /**
     * Remove movie frames and directory
     * 
     * @return void
     */
    private function _cleanUp()
    {
        $dir = $this->directory . "frames/";
        
        // Clean up movie frame images that are no longer needed
        if (file_exists($dir)) {
            foreach (glob("$dir*") as $image) {
                unlink($image);            
            }
            rmdir($dir);
        }   
    }
    
    /**
     * Creates preview images of several different sizes
     */
    private function _createPreviewImages(&$screenshot)
    {
        // Create preview image
        $preview = $screenshot->getIMagickImage();
        $preview->setImageCompression(IMagick::COMPRESSION_LZW);
        $preview->setImageCompressionQuality(PNG_LOW_COMPRESSION);
        $preview->setInterlaceScheme(IMagick::INTERLACE_PLANE);
        
        // Thumbnail sizes to create
        $sizes = array(
            "large"  => array(640, 480),
            "medium" => array(320, 240),
            "small"  => array(240, 180),
            "icon"   => array(64, 64)            
        );
        
        foreach ($sizes as $name=>$dimensions) {
            $thumb = $preview->clone();
            $thumb->thumbnailImage($dimensions[0], $dimensions[1], true);
            
            // Add black border to reach desired preview image sizes
            $borderWidth  = ceil(($dimensions[0] - $thumb->getImageWidth()) / 2);
            $borderHeight = ceil(($dimensions[1] - $thumb->getImageHeight()) / 2);
            
            $thumb->borderImage("black", $borderWidth, $borderHeight);
            $thumb->cropImage($dimensions[0], $dimensions[1], 0, 0);
            
            $thumb->writeImage($this->directory . "preview-$name.png");
            $thumb->destroy();
        } 
        $preview->writeImage($this->directory . "preview-full.png");  
        
        $preview->destroy();   
    }

    /**
     * Builds the requested movie
     *
     * Makes a temporary directory to store frames in, calculates a timestamp for every frame, gets the closest
     * image to each timestamp for each layer. Then takes all layers belonging to one timestamp and makes a movie frame
     * out of it. When done with all movie frames, phpvideotoolkit is used to compile all the frames into a movie.
     *
     * @return void
     */
    private function _encodeMovie()
    {
        require_once HV_API_ROOT_DIR . '/src/Movie/FFMPEGEncoder.php';
        
        // Compute movie meta-data
        $layerString = $this->_layers->toHumanReadableString();
        
        // Date string
        $dateString = $this->getDateString();
        
        // URLS
        $url1 = HV_WEB_ROOT_URL . "/?movieId={$this->publicId}";
        $url2 = HV_WEB_ROOT_URL . "/api/?action=downloadMovie&id={$this->publicId}&format={$this->format}";
        
        
        // Title
        $title = sprintf("%s (%s)", $layerString, $dateString);
        
        // Description
        $description = sprintf(
            "The Sun as seen through %s from %s.", 
            $layerString, str_replace("-", " to ", $dateString)
        );
        
        // Comment
        $comment = sprintf(
            "This movie was produced by Helioviewer.org. See the original " .
            "at %s or download a high-quality version from %s.", $url1, $url2
        );
        
        // MP4 filename
        $filename = str_replace("webm", "mp4", $this->filename);
        
        // Create and FFmpeg encoder instance
        $ffmpeg = new Movie_FFMPEGEncoder(
            $this->directory, $filename, $this->frameRate, $this->width, 
            $this->height, $title, $description, $comment
        );
        
        // Keep track of processing time for webm/mp4 encoding
        $t1 = time();

        // Create H.264 videos if they do not already exist
        if (!file_exists(realpath($this->directory . $filename))) {
            $ffmpeg->createVideo();
            $ffmpeg->createHQVideo();
            $ffmpeg->createFlashVideo();
            
            $t2 = time();
                    
            // Mark movie as completed
            $this->_db->markMovieAsFinished($this->id, "mp4", $t2 - $t1);
        }

        $t3 = time();
        
        //Create a Low-quality webm movie for in-browser use if requested
        $ffmpeg->setFormat("webm");
        $ffmpeg->createVideo();
        
        $t4 = time();
                
        // Mark movie as completed
        $this->_db->markMovieAsFinished($this->id, "webm", $t4 - $t3);
    }

    /**
     * Returns a human-readable title for the video
     */
    public function getTitle()
    {
        date_default_timezone_set('UTC');
        
        $layerString = $this->_layers->toHumanReadableString();
        $dateString  = $this->getDateString();
        
        return sprintf("%s (%s)", $layerString, $dateString);
    }
    
    /**
     * Returns a human-readable date string
     */
    public function getDateString()
    {
        date_default_timezone_set('UTC');

        if (substr($this->startDate, 0, 9) == substr($this->endDate, 0, 9)) {
            $endDate = substr($this->endDate, 11);
        } else {
            $endDate = $this->endDate;
        }
        
        return sprintf("%s - %s UTC", $this->startDate, $endDate);
    }
    
    /**
     * Returns an array of the timestamps for the key movie layer
     * 
     * For single layer movies, the number of frames will be either HV_MAX_MOVIE_FRAMES, or the number of
     * images available for the requested time range. For multi-layer movies, the number of frames included
     * may be reduced to ensure that the total number of SubFieldImages needed does not exceed HV_MAX_MOVIE_FRAMES
     */
    private function _getTimeStamps()
    {
        $layerCounts = array();

        // Determine the number of images that are available for the request duration for each layer
        foreach ($this->_layers->toArray() as $layer) {
            $n = $this->_db->getImageCount($this->reqStartDate, $this->reqEndDate, $layer['sourceId']);
            $layerCounts[$layer['sourceId']] = $n;
        }

        // Choose the maximum number of frames that can be generated without exceeded the server limits defined
        // by HV_MAX_MOVIE_FRAMES
        $numFrames       = 0;
        $imagesRemaining = $this->maxFrames;
        $layersRemaining = $this->_layers->length();
        
        // Sort counts from smallest to largest
        asort($layerCounts);
        
        // Determine number of frames to create
        foreach($layerCounts as $dataSource => $count) {
            $numFrames = min($count, ($imagesRemaining / $layersRemaining));
            $imagesRemaining -= $numFrames;
            $layersRemaining -= 1;
        }
        
        // Number of frames to use
        $numFrames = floor($numFrames);

        // Get the entire range of available images between the movie start and end time 
        $entireRange = $this->_db->getImageRange($this->reqStartDate, $this->reqEndDate, $dataSource);
        
        // Sub-sample range so that only $numFrames timestamps are returned
        for ($i = 0; $i < $numFrames; $i++) {
            $index = round($i * (sizeOf($entireRange) / $numFrames));
            array_push($this->_timestamps, $entireRange[$index]['date']);
        }       
    }

    /**
     * Determines dimensions to use for movie and stores them
     * 
     * @return void
     */
    private function _setMovieDimensions() {
        $this->width  = round($this->_roi->getPixelWidth());
        $this->height = round($this->_roi->getPixelHeight());

        // Width and height must be divisible by 2 or ffmpeg will throw an error.
        if ($this->width % 2 === 1) {
            $this->width += 1;
        }
        
        if ($this->height % 2 === 1) {
            $this->height += 1;
        } 
    }
    
    /**
     * Determines some of the movie details and saves them to the database record
     */
    private function _setMovieProperties()
    {
        // Store actual start and end dates that will be used for the movie
        $this->startDate = $this->_timestamps[0];
        $this->endDate   = $this->_timestamps[sizeOf($this->_timestamps) - 1];

        $this->filename = $this->_buildFilename();
        
        $this->numFrames = sizeOf($this->_timestamps);

        if ($this->numFrames == 0) {
            $this->_abort("No images available for the requested time range");
        }

        if ($this->frameRate) {
            $this->movieLength = $this->numFrames / $this->frameRate;
        } else {
            $this->frameRate = min(30, max(1, $this->numFrames / $this->movieLength));
        }

        $this->_setMovieDimensions();

        // Update movie entry in database with new details
        $this->_db->storeMovieProperties(
            $this->id, $this->startDate, $this->endDate, $this->numFrames, 
            $this->frameRate, $this->movieLength, $this->width, $this->height
        );
    }

    /**
     * Adds black border to movie frames if neccessary to guarantee a 16:9 aspect ratio
     *
     * Checks the ratio of width to height and adjusts each dimension so that the
     * ratio is 16:9. The movie will be padded with a black background in JP2Image.php
     * using the new width and height.
     *
     * @return array Width and Height of padded movie frames
     */
    private function _setAspectRatios()
    {
        $width  = $this->_roi->getPixelWidth();
        $height = $this->_roi->getPixelHeight();

        $ratio = $width / $height;

        // Commented out because padding the width looks funny.
        /*
        // If width needs to be adjusted but height is fine
        if ($ratio < 16/9) {
        $adjust = (16/9) * $height / $width;
        $width *= $adjust;
        }
        */
        // Adjust height if necessary
        if ($ratio > 16/9) {
            $adjust = (9/16) * $width / $height;
            $height *= $adjust;
        }

        $dimensions = array("width" => $width, "height" => $height);
        return $dimensions;
    }
    
    /**
     * Returns HTML for a video player with the requested movie loaded
     */
    public function getMoviePlayerHTML()
    {
        $filepath = str_replace(HV_ROOT_DIR, "../", $this->getFilepath());
        $css      = "width: {$this->width}px; height: {$this->height}px;";
        $duration = $this->numFrames / $this->frameRate;
        ?>
<!DOCTYPE html> 
<html> 
<head> 
    <title>Helioviewer.org - <?php echo $this -> filename;?></title>            
    <script type="text/javascript" src="http://html5.kaltura.org/js"></script> 
    <script src="http://ajax.googleapis.com/ajax/libs/jquery/1.4.2/jquery.js" type="text/javascript"></script>
</head> 
<body>
<div style="text-align: center;">
    <div style="margin-left: auto; margin-right: auto; <?php echo $css;?>";>
        <video style="margin-left: auto; margin-right: auto;" poster="<?php echo "$filepath.bmp"?>" durationHint="<?php echo $duration?>">
            <source src="<?php echo "$filepath.mp4"?>" /> 
            <source src="<?php echo "$filepath.webm"?>" />
            <source src="<?php echo "$filepath.flv"?>" /> 
        </video>
    </div>
</div>
</body> 
</html> 
        <?php
    }
    }
?>
