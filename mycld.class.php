<?php

// Enviroment
define('APPLICATION_ENV', 'development');

// README: DO NOT CHANGE IT
date_default_timezone_set('UTC');

// PHP error reporting
ini_set('error_reporting', E_ALL);
ini_set('display_errors', 1);



/**
 * @link http://pear.github.com/Log/ "PEAR PHP Log"
 */
require_once 'Log.php';

/**
 * @link http://docs.amazonwebservices.com/AWSSDKforPHP/latest/index.html "AWS SDK for PHP"
 */
require_once 'AWSSDKforPHP/sdk.class.php';

/**
 * Basic class autoloader 
 */
class myloader {

    public static function autoloader($class) {
        $path = dirname(__FILE__) . DIRECTORY_SEPARATOR;
        require_once $path . $class . '.class.php';
        return true;
    }

}

// Register the autoloader.
spl_autoload_register(array('myloader', 'autoloader'));

/**
 * mycld class - parent of all services (toolss)
 * @package mycld
 * @version 0.0.8
 * @author Vladimir Getmanshchuk <vladget@gmail.com>
 */
class mycld {

    // default aws region
    public $region;
    // Mask
    public $mask;
    //Regions
    public static $regionsMap = array(
        'eu-west-1' => 'REGION_EU_W1',
        'sa-east-1' => 'REGION_SA_E1',
        'us-east-1' => 'REGION_US_E1',
        'ap-northeast-1' => 'REGION_APAC_NE1',
        'us-west-2' => 'REGION_US_W2',
        'us-west-1' => 'REGION_US_W1',
        'ap-southeast-1' => 'REGION_APAC_SE1',
    );
    // Log levels
    public static $loglevels = array(
        'PEAR_LOG_EMERG', // System is unusable  //0
        'PEAR_LOG_ALERT', // Immediate action required
        'PEAR_LOG_CRIT', // Critical conditions
        'PEAR_LOG_ERR', // Error conditions
        'PEAR_LOG_WARNING', // Warning conditions
        'PEAR_LOG_NOTICE', // Normal but significant
        'PEAR_LOG_INFO', // Informational
        'PEAR_LOG_DEBUG'        // Debug-level messages  //7
    );

    /**
     * Provide logger functionality to services
     * @param string $service - service name
     * @param constant of Log::class $level 
     * @return Log object 
     */
    static function getLogger($service, $level = null) {


        // Set level if correct argument value given
        if (is_null($level) || !in_array($level, $this->levels))
            $level = PEAR_LOG_DEBUG;

        $logsdir = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'logs/';
        $log_file_path = $logsdir . $service . '.log';
        $log_handler_conf = array('mode' => 0600, 'timeFormat' => '%X %x');
        return Log::singleton('file', $log_file_path, $service, $log_handler_conf, $level);
    }

    /**
     * Provide config functionality for services
     * @param string $service - service name
     * @param boolean $process_sections - process or do not process config sections
     * @return array - parsed config for given service 
     */
    static function getConfig($service, $process_sections = true) {
        $confdir = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'conf/';

        $conf_file_path = $confdir . $service . '.ini';
        if (!is_file($conf_file_path)) {
            if (!is_file($conf_file_path . 'sample')) {
                $this->logger->log('Can\'t get config file ' . $conf_file_path . '. Exiting...', PEAR_LOG_CRIT);
                return false;
            } else {
                $conf_file_path .= 'sample';
            }
        }
        $process_sections = true;
        $config = parse_ini_file($conf_file_path, $process_sections);

        return $config;
    }

    static function setRegion($sdkObject, $region, $logger) {

        $region_map = self::$regionsMap[$region];

        $r = new ReflectionObject($sdkObject);
        $region_const = $r->getConstant($region_map);

        $sdkObject->hostname = $region_const;
        $logger->log('AWS region for service ' . $sdkObject->service . ' defined. Working with region: ' . $region, PEAR_LOG_DEBUG);
    }

    static function printStartingMessage($logger, $service) {
        $logger->log('    SERVICE ' . $service . ' STARTED AT: ' . date(DATE_ISO8601), PEAR_LOG_INFO);
    }

    static function printStoppingMessage($logger, $service) {
        $logger->log('    SERVICE ' . $service . ' STOPED AT: ' . date(DATE_ISO8601), PEAR_LOG_INFO);
        $logger->log(' - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - ', PEAR_LOG_INFO);
    }

}

?>
