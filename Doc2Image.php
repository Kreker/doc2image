<?php
/**
* Converter 

//Doc2Image::setLogPath('/var/www/kicha/http/protected/runtime/Doc2Image.log');
//Doc2Image::load('Статистика телефонии 10.06.2015 - 20.07.2015.xlsx')->convert('/var/www/kicha/http/Converted/', 0);

Doc2Image::getInstance()->getFormats();
*/
class Doc2Image {
    
    /**
     * Cache array of available formats
     * @access private
     * @var array
     */
    private $availableFormats = array();
    
    /**
     * Instance of Doc2Image object
     * @access private 
     * @var object
     */
    private static $instance;
    
    /**
     * Path to logfile. Maybe full dirname, full path with file
     * @access private 
     * @var string
     */
    private static $logPath = './Doc2Image.log';
    
    /**
     * Path to pdf-file (step of convert)
     * @access private 
     * @var string
     */
    private $pdfPath;
    
    /**
     * Inner timer
     * @access private 
     * @var int
     */
    private $startTime;
    
    /**
     * Inner sessionID for logfile
     * @access private 
     * @var int
     */
    private $sessionID;
    
    public function __construct() {
        
        $this->startTime = microtime(true);
        $this->sessionID = mt_rand(4343141, PHP_INT_MAX);
        //Checking components
        
        //Set logPath & create if it not exists
        self::setLogPath(self::$logPath);
        $logString = '[START] Inizialized';
        $this->setLog($logString);
        
        if (strpos(ini_get('disable_functions'), 'exec') !== false)
            throw new Exception('Function exec is disabled. Doc2Image cannot work.');
            
        $checkUnoconv = $this->execute('which unoconv | rpm -sq unoconv');
        if (!$checkUnoconv || strpos($checkUnoconv, 'no unoconv') !== false || mb_strpos($checkUnoconv, 'не установлен') !== false)
            throw new Exception('unoconv not installed. Doc2Image cannot work.');
        
        if (!extension_loaded('imagick'))
            throw new Exception('imagick php extension not installed. Doc2Image cannot work.');
    
        //setting available formats
        $this->getAvailableFormats();
    }
    
    /**
     * Main singletone function for getting instace
     * @throw Exception
     * @access public
     * @return Doc2Image object
     */
    public static function getInstance() {
        
        if (self::$instance)
            return self::$instance;
        
        return self::$instance = new Doc2Image;       
    }
    
    /**
     * Print available inpit and output formats
     * @access public
     * @return void
     */
     public function getFormats() {
         
         echo 'Input Formats: '.PHP_EOL.implode(PHP_EOL, $this->getAvailableFormats()).PHP_EOL.PHP_EOL
               , 'Output Formats: '.PHP_EOL.implode(PHP_EOL, Imagick::queryFormats()).PHP_EOL.PHP_EOL;
         
     }
    
    /**
     * Loaded file from src. This method convert file into pdf as intermediate step
     * @throw Exception
     * @access public
     * @param string $src - fullpath to file (see self::getFormats())
     * @return Doc2Image object
     */
    public static function load($src) {
        
        $startTime = microtime(true);

        $Doc2Image = self::getInstance();
        
        $logString = '[LOAD] File load "'.$src.'"';
        $Doc2Image->setLog($logString);
        
        //clear srcPath
        $src = realpath(strtr($src, array('..' => '')));
        
        //Checking file
        if (!file_exists($src))
            throw new Exception('Cannot load file from this path!');

        //Checking mime-type
        $dotPos = strrpos($src, '.');
        $mime = substr($src, $dotPos + 1);

        if (!in_array($mime, $Doc2Image->getAvailableFormats()))
            throw new Exception('Filetype unsopported!');
        
        //Convert to pdf
        $result = $Doc2Image->execute('unoconv -v -f pdf', $src);
        
        if (strpos($result, 'UnoException') !== false || strpos($result, 'Unable') !== false)
            throw new Exception('This file cannot be convert to pdf');
        
        //$pdfPath
        $Doc2Image->filePathWithoutType = substr($src, 0, $dotPos);
        
        $logString = '[POINT] File "'.$src.'" converted to pdf ('.(microtime(true) - $startTime).'s)';
        $Doc2Image->setLog($logString);
        
        return $Doc2Image;
    }
    
    /**
     * Convert to output format. This method convert from pdf to specified format with optimizing
     * @throw Exception
     * @access public
     * @param string $outputPath - path to file. May content only path or path with filename
     * @param int/string[=ALL] $page - number of document page wich will be converted into image. If specified 'ALL' - will be converted all pages.
     * @param string $format - output format (see self::getFormats())
     * @param array $resolution - array with x&y resolution DPI
     * @param int $depth - bit depth image
     * @return string/bool[false] - return image path of last converted page
     */
    public function convert($outputPath = '', $page = 'ALL', $format = 'png', $resolution = array('x' => 300, 'y' => 300), $depth = 8) {
        
        if (!Imagick::queryFormats(strtoupper($format)))
            throw new Exception('Unsupported format');
        
        $startTime = microtime(true);
        
        $im = new imagick();
        $im->setResolution($resolution['x'], $resolution['y']);

        $format = strtolower($format);
        $im->setFormat($format);
        
        
        if ($outputPath) {
            if (is_dir($outputPath))
                $outputPath = $outputPath.pathinfo($this->filePathWithoutType, PATHINFO_FILENAME);
            
            $outputFileName = $outputPath;
        }
        else
            $outputFileName = $this->filePathWithoutType;
        
        if ($page === 'ALL') {
            $im->readImage($this->filePathWithoutType.'.pdf');
            $im->setImageFormat($format); 
            $im->setImageAlphaChannel(11); // it's a new constant imagick::ALPHACHANNEL_REMOVE
            $im->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
            $im->setOption($format.':bit-depth', $depth);
            $im->writeImages($outputFileName.".".$format, false);
            $logString = '[POINT] File "'.$this->filePathWithoutType.'.pdf" converted to "'.$format.'" with '.$im->getNumberImages().' pages (ex: '.(microtime(true) - $startTime).'s)';
            $this->setLog($logString);
            //Optimizing
            if ($format == 'png' && $this->optipngChecking()) {
                $startTime = microtime(true);
                for ($page = $i = 0; $i < $im->getNumberImages(); $i++) {
                    $this->execute('optipng -o=5', $outputFileName."-".(int)$i.".".$format);
                }
                $logString = '[POINT] Files "'.$outputFileName.'-x.'.$format.'" optimized (ex: '.(microtime(true) - $startTime).'s)';
                $this->setLog($logString);
            }
        }
        else {
            $im->readImage($this->filePathWithoutType.'.pdf['.(int)$page.']');
            $im->setImageFormat($format); 
            $im->setImageAlphaChannel(11); // it's a new constant imagick::ALPHACHANNEL_REMOVE
            $im->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
            $im->setOption($format.':color-type', 2);
            $im->setOption($format.':bit-depth', $depth);
            $im->writeImage($outputFileName."-".(int)$page.".".$format);
            
            $logString = '[POINT] File "'.$outputFileName.'.pdf" converted to "'.$format.'" one page (ex: '.(microtime(true) - $startTime).'s)';
            $this->setLog($logString);
            
            //Optimizing
            if ($format == 'png' && $this->optipngChecking()) {
                $startTime = microtime(true);
                $this->execute('optipng -o=5', $outputFileName."-".(int)$page.".".$format);
                $logString = '[POINT] File "'.$outputFileName."-".(int)$page.".".$format.'" optimized (ex: '.(microtime(true) - $startTime).'s)';
                $this->setLog($logString);
            }
        }
        
        if (file_exists($outputFileName."-".(int)$page.".".$format))
            return $outputFileName."-".(int)$page.".".$format;
        else 
            return false;
    }
    
    /**
     * Setting path for logging
     * @throw Exception
     * @access public
     * @return bool
     */
    public static function setLogPath($path) {
        
        $clearPath = pathinfo($path , PATHINFO_DIRNAME);
        if (!file_exists($clearPath)) {
            $res = mkdir($clearPath, 0755, true);
            
            if (!$res)
                throw new Exception('Cannot create log path directory! Maybe i dont have permissions to create '.$clearPath);
        }
        
        if (is_dir($path))
            $path = $path.'/Doc2Image.log';
        
        self::$logPath = $path;
    }
    
    /**
     * Execute command into shell
     * @access private
     * @param string $command - from current code
     * @param string $arguments - from input params
     * @return string
     */
    private function execute($command, $argumentString = '') {

        $buffer = ob_start();
        $command = $command.' '.escapeshellarg($argumentString).' 2>&1';
        echo shell_exec($command);
        
        echo $logString = '[EXEC] "'.$command.'"';
        $this->setLog($logString);

        $result = ob_get_contents();
        ob_get_clean();

        return $result;
    }
    
    /**
     * Return available input formats 
     * @access private
     * @return array
     */
    private function getAvailableFormats() {
        
        if ($this->availableFormats)
            return $this->availableFormats;
        
        $result = $this->execute("unoconv --show");

        preg_match_all('~\s\s([\w\d]+)\s~is', $result, $matches);
        if ($matches[1])
            $this->availableFormats = $matches[1];
       
        return $this->availableFormats;
    }
    
    /**
     * Check exists optipng 
     * @throw Exception
     * @access private
     * @return bool
     */
    private function optipngChecking() {
        
        $checkOptipng = $this->execute('which optipng | rpm -sq optipng');
        if (!$checkOptipng || strpos($checkOptipng, 'no optipng') !== false || mb_strpos($checkOptipng, 'не установлен') !== false)
            throw new Exception('optipng not installed. Doc2Image cannot work.');
        
        return true;
    }
    
    /**
     * Check exists optipng 
     * @throw Exception
     * @access private
     * @return bool
     */
    private function setLog($string) {
        
        file_put_contents(self::$logPath, '['.date('d.m.Y H:i:s').' '.$this->sessionID.'] '.$string.PHP_EOL, FILE_APPEND);
    }
    
    public function __destruct() {
     
        if (file_exists($this->filePathWithoutType.'.pdf'))
            unlink($this->filePathWithoutType.'.pdf');
        
        $this->setLog('[END] Total Execution: '.(microtime(true) - $this->startTime).'s');
    }
}
?> 