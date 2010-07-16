<?php
/* vim: set expandtab tabstop=4 shiftwidth=4 softtabstop=4: */
/**
 * Movie_HelioviewerMovie Class Definition
 *
 * PHP version 5
 *
 * @category Movie
 * @package  Helioviewer
 * @author   Jaclyn Beck <jabeck@nmu.edu>
 * @license  http://www.mozilla.org/MPL/MPL-1.1.html Mozilla Public License 1.1
 * @link     http://launchpad.net/helioviewer.org
 */
require_once HV_ROOT_DIR . '/api/src/Movie/FFMPEGWrapper.php';
require_once HV_ROOT_DIR . '/api/src/Image/ImageMetaInformation.php';
require_once HV_ROOT_DIR . '/api/src/Helper/DateTimeConversions.php';
/**
 * Represents a static (e.g. ogv/mp4) movie generated by Helioviewer
 *
 * Note: For movies, it is easiest to work with Unix timestamps since that is what is returned
 *       from the database. To get from a javascript Date object to a Unix timestamp, simply
 *       use "date.getTime() * 1000." (getTime returns the number of miliseconds)
 *
 * @category Movie
 * @package  Helioviewer
 * @author   Jaclyn Beck <jabeck@nmu.edu>
 * @license  http://www.mozilla.org/MPL/MPL-1.1.html Mozilla Public License 1.1
 * @link     http://launchpad.net/helioviewer.org
 */
class Movie_HelioviewerMovie
{
    private $_images = array ();
    private $_metaInfo;
    private $_maxFrames;
    private $_startTime;
    private $_endTime;
    private $_timeStep;
    private $_numFrames;
    private $_frameRate;
    private $_db;
    private $_baseScale = 2.63;
    private $_baseZoom = 10;
    private $_tileSize = 512;
    private $_filetype = "flv";
    private $_highQualityLevel = 100;
    private $_highQualityFileType;
    private $_watermarkOptions = "-x 720 -y 965 ";
    private $_filename;
    private $_options;
    private $_quality;
    private $_padDimensions;

    /**
     * HelioviewerMovie Constructor
     *
     * @param int    $startTime Requested movie start time (unix timestamp)
     * @param int    $numFrames Number of frames to include
     * @param int    $frameRate Number of frames per second
     * @param string $hqFormat  Format to use for high-quality version of the movie
     * @param array  $options   An array with ["edges"] => true/false, ["sharpen"] => true/false
     * @param int    $timeStep  Desired timestep between movie frames in seconds. Default is 86400 seconds, or 1 day.
     * @param string $filename  Desired filename for the movie
     * @param int    $quality   Movie quality
     * @param Object $meta      An ImageMetaInformation object with width, height, and imageScale
     * @param String $tmpDir    the directory where the movie will be stored
     */
    public function __construct(
        $startTime, $numFrames, $frameRate, $hqFormat,
        $options, $timeStep, $filename, $quality, $meta, $tmpDir
    ) {
        $this->_metaInfo = $meta;
        
        // working directory
        $this->tmpDir = $tmpDir; 

        // _startTime is a Unix timestamp in seconds.
        $this->_startTime  = $startTime;
        $this->_numFrames  = $numFrames;
        $this->_frameRate  = $frameRate;
        $this->_quality    = $quality;
        $this->_options    = $options;

        // _timeStep is in seconds
        $this->_timeStep = $timeStep;
        $this->_filename = $filename;

        $this->_endTime = $startTime + ($numFrames * $timeStep);

        $this->_padDimensions = $this->_setAspectRatios();
        $this->_highQualityFiletype = $hqFormat;
    }

    /**
     * TODO: implement
     *
     * @return void
     */
    public function toMovie()
    {

    }

    /**
     * TODO: implement
     *
     * @return void
     */
    public function toArchive()
    {

    }

    /**
     * TODO: implement
     *
     * @return void
     */
    public function getNumFrames()
    {

    }
    
    /**
     * Get width
     * 
     * @return int width
     */
    public function width()
    {
        return $this->_metaInfo->width();
    }
    
    /**
     * Get height
     * 
     * @return int height
     */
    public function height()
    {
        return $this->_metaInfo->height();
    }

    /**
     * Builds the requested movie
     *
     * Makes a temporary directory to store frames in, calculates a timestamp for every frame, gets the closest
     * image to each timestamp for each layer. Then takes all layers belonging to one timestamp and makes a movie frame
     * out of it. When done with all movie frames, phpvideotoolkit is used to compile all the frames into a movie.
     * 
     * @param array $builtImages An array of built movie frames (in the form of HelioviewerScreenshot objects)
     *
     * @return void
     */
    public function buildMovie($builtImages)
    {
        $this->_images = $builtImages;
        $movieName = $this->_filename;

        // Need to do something slightly different to get the video to be iPod compatible
        $ffmpeg = new Movie_FFMPEGWrapper($this->_frameRate);
        
        // Width and height must be divisible by 2 or ffmpeg will throw an error.
        $width  = round($this->_metaInfo->width());
        $height = round($this->_metaInfo->height());
        
        $width  += ($width  % 2 === 0? 0 : 1);
        $height += ($height % 2 === 0? 0 : 1);        
        
        if ($this->_highQualityFiletype === "ipod") {
        	$hq_filename = "$movieName.mp4";
            return $ffmpeg->createIpodVideo($hq_filename, $this->tmpDir, $width, $height);
        }
        
        $flash_filename = "$movieName." . $this->_filetype;
        $hq_filename    = "$movieName." . $this->_highQualityFiletype;
        
        // Create flash video
        $ffmpeg->createVideo($flash_filename, $this->tmpDir, $width, $height);

        $ffmpeg->createVideo($hq_filename, $this->tmpDir, $width, $height);
        $this->_cleanup();
        return $this->tmpDir . $flash_filename;
    }
    
    /**
     * Unlinks all images except the first frame used to create the video.
     * 
     * @return void
     */
    private function _cleanup ()
    {
        // Clean up png/tif images that are no longer needed. Leave the first frame for previews.
        foreach (array_slice($this->_images, 1) as $image) {
            if (file_exists($image)) {
                unlink($image);
            }     
        }    	
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
        $width  = $this->_metaInfo->width();
        $height = $this->_metaInfo->height();

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
     * Displays movie in a Flash player along with a link to the high-quality version
     *
     * @param string $url    The URL for the movie to be displayed
     * @param int    $width  Movie width
     * @param int    $height Movie Height
     *
     * @return void
     */
    public static function showMovie($url, $width, $height)
    {
        ?>
        <!-- MC Media Player -->
        <script type="text/javascript">
            playerFile = "http://www.mcmediaplayer.com/public/mcmp_0.8.swf";
            fpFileURL = "<?php print $url?>";
            playerSize = "<?php print $width . 'x' . $height?>";
        </script>
        <script type="text/javascript" src="http://www.mcmediaplayer.com/public/mcmp_0.8.js">
        </script>
        <!-- / MC Media Player -->
        <?php
    }
}
?>
