#!/usr/bin/php
<?php
/**
 * CLI mycld runner 
 * @package mycld
 * @version 0.0.7
 * @author Vladimir Getmanshchuk <vladget@gmail.com>
 */
require_once 'mycld.class.php';


$service = null;

function printUsage() {
    echo "Usage: -s service_name -a action_name -r aws_region [-d debug level]\n";
    exit(1);
}

// Services map
$services = array(
    'spotscale' => array('update', 'check', 'test'),
    'ec2backup' => array('run', 'test'),
);

// Getting args
$shortopts = "";
$shortopts .= "s:"; // service name
$shortopts .= "a:"; // action
$shortopts .= "r:"; // regios
$shortopts .= "d::"; // debug level
$options = getopt($shortopts);

// Options checks
if (empty($options))
    printUsage();


// " -r region option"
if (empty($options['r']) || !array_key_exists($options['r'], mycld::$regionsMap))
    printUsage();
else
    $region = $options['r'];

// " -d option"
$mask = Log::MAX(PEAR_LOG_ERR);
if (array_key_exists('d', $options))
    $mask = $mask = Log::MIN(PEAR_LOG_EMERG);

// " -s service -a action" options check
if (!in_array($options['a'], $services[$options['s']]))
    printUsage();

switch ($options['s']) {
    case 'spotscale':
        switch ($options['a']) {
            case 'update':
                $service = new spotscale($region, $mask);
                $service->update();
                break;
            case 'check':
                $service = new spotscale($region, $mask);
                $service->check();
                break;
            case 'test':
                $service = new spotscale($region, $mask);
                $service->test();
                break;
            default:
                printUsage();
                break;
        }
        break;
    case 'ec2backup':
        switch ($options['a']) {
            case 'run':
                $service = new ec2backup($region, $mask);
                $service->run();
                break;
            case 'test':
                $service = new ec2backup($region, $mask);
                $service->test();
                break;
            default:
                printUsage();
                break;
        }
        break;
    default: printUsage();
        break;
}
?>

