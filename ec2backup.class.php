<?php

/**
 * ec2backup class
 * @package mycld
 * @version 0.0.4
 * @author Vladimir Getmanshchuk <vladget@gmail.com>
 * 
 */
class ec2backup extends mycld {

    // Timestamp
    public $timestamp;
    public $service = 'ec2backup';
    public $logger = null;
    public $config = null;
    public $db = null;
    public $ec2;
    public $as;
    public $config_defaults = array(
        //'prefix' => MUST_BE,
        'no_reboot' => true,
        'keep_daily' => 14,
        'keep_weekly' => 8,
        'keep_monthly' => 12,
        'keep_yearly' => 1,
        'backup_hour' => 10,
        'keep_weekly_day' => 5,
        'keep_monthly_date' => 28,
        'keep_yearly_month' => 11,
        'update_as_group' => null,
    );

    // constructor
    function __construct($region, $mask) {
        $this->mask = $mask;
        $this->logger = self::getLogger($this->service);
        $this->logger->setMask($this->mask);

        self::printStartingMessage($this->logger, $this->service);
        $this->region = $region;
        $this->ec2 = new AmazonEC2();
        self::setRegion($this->ec2, $this->region, $this->logger);
        $this->as = new AmazonAS();
        self::setRegion($this->as, $this->region, $this->logger);
//        $this->cw = new AmazonCloudWatch();
//        self::setRegion($this->cw, $this->region, $this->logger);
//        $this->elb = new AmazonELB();
//        self::setRegion($this->elb, $this->region, $this->logger);

        $this->config = self::getConfig($this->service);

        $this->timestamp = (int) time();

        self::printStartingMessage($this->logger, $this->service);
    }

    function __destruct() {
        self::printStoppingMessage($this->logger, $this->service);
    }

    function _checkMandatoryOpts($array) {
        if (array_key_exists('prefix', $array))
            return true;
        else
            return false;
    }

    private function _tagInstance($instanceId, $imageId) {
        $response = $this->ec2->create_tags($instanceId, array(
            array('Key' => 'mycld.LastBackupDate', 'Value' => date(DATE_ISO8601, $this->timestamp)),
            array('Key' => 'mycld.LastBackupTimeStamp', 'Value' => $this->timestamp),
            array('Key' => 'mycld.LastBackupAMI', 'Value' => $imageId),
                ));
        if ($response->isOK())
            return true;

        return false;
    }

    function _getInstanceTags($InstanceId) {
        $filter = array(
            'Filter' => array(
                array('Name' => 'resource-id', 'Value' => $InstanceId),
            )
        );

        $response = $this->ec2->describe_tags($filter);
        if ($response->isOK()) {
            $response_array = $this->ec2->util->convert_response_to_array($response);
            return $response_array['body']['tagSet']['item'];
        }

        return false;
    }

    function _setDefaults($array) {
        foreach ($this->config_defaults as $key => $value) {
            if (!array_key_exists($key, $array))
                $array[$key] = $value;
        }

        return $array;
    }

    function _isItTimeForRun($instanceConf) {
        
        $backupHours = explode(',', $instanceConf['backup_hour']);
        
        foreach ($backupHours as $backupHour) {
        if (date("H", $this->timestamp) == $backupHour)
            return true;
        }
        
        return false;
    }

    function _backupInstance($instanceId, $instanceConf) {
        $name = "" . $instanceConf['prefix'] . "." . $this->timestamp . "";
        $opt = array(
            'Description' => 'AMI created by mycld ec2backup at: '
            . date(DATE_ISO8601, $this->timestamp)
            . '. From Instance Id: '
            . $instanceId,
            'NoReboot' => $instanceConf['no_reboot'],
        );

        // creating image
        $response = $this->ec2->create_image($instanceId, $name, $opt);
        if ($response->isOK()) {
            $result_array = $this->ec2->util->convert_response_to_array($response);
            $imageId = $result_array['body']['imageId'];

            // update autoscaling if it needed
            if ($instanceConf['update_as_group'] != null)
                $this->_updateAsGroup($imageId, $instanceConf);

            //tagging instance
            if ($this->_tagInstance($instanceId, $imageId))
                return true;
        }
        // return false by default 
        return false;
    }

    function _getImagesByPrefix($prefix) {
        //$response = $this->ec2->describe_images();
        //if ($response->isOK())
        //    $list = $response->body->query('descendant-or-self::item[name[contains(., "' . $prefix . '")]]/imageLocation');
        $response = $this->ec2->describe_images(array('Owner' => 'self'));
        if ($response->isOK()) {
            $images = $this->ec2->util->convert_response_to_array($response);
            $images = $images['body']['imagesSet']['item'];
            foreach ($images as $key => $image) {
                if (strtoupper($this->_getPrefixFromName($image['name'])) != strtoupper($prefix))
                    unset($images[$key]);
            }
//        $result = $list->map(function($node) {
//                    return (array) $node->parent();
//                });
//                

            return $images;
        }
        return array();
    }

    function _getImageById($imageId) {
        $response = $this->ec2->describe_images();
        if ($response->isOK())
            $list = $response->body->query('descendant-or-self::item[imageId[contains(., "' . $imageId . '")]]/imageLocation');

        $result = $list->map(function($node) {
                    return (array) $node->parent();
                });

        // TODO: fixit
        $image = $result;
        foreach ($result as $i) {
            $image = $i;
        }
        return $image;
    }

    // returns false if we dont need to keep it
    function _keep($instanceConf, $creationDate) {
        $todayDate = (int) floor($this->timestamp / 86400) * 86400;
        $creationDate = (int) floor($creationDate / 86400) * 86400;

        //Cut-offs for yearly, monthly, weekly, and daily backup dates
        $cutOffYear = (int) floor(strtotime(
                                ("" . date("Y", $todayDate) - $instanceConf['keep_yearly']) . "/"
                                . $instanceConf['keep_yearly_month'] . "/"
                                . $instanceConf['keep_monthly_date'] . ""
                        ) / 86400) * 86400;
        $cutOffMonth = (int) floor(strtotime(" -" . $instanceConf['keep_monthly'] . " month") / 86400) * 86400;
        $cutOffWeek = (int) floor(strtotime(" -" . $instanceConf['keep_weekly'] . " week") / 86400) * 86400;
        $cutOffDay = (int) floor(strtotime(" -" . $instanceConf['keep_daily'] . " day") / 86400) * 86400;

        // Cutting off dates oldester then older ever yearly backup date
        if ($creationDate < $cutOffYear) {
            $this->logger->Log('Cutted off as oldester then older ever backup. false', PEAR_LOG_DEBUG);
            return false;
        }
        // it it yearly backup: if month and day of month equal it is yearly backup, so keep it
        elseif (date("n", $creationDate) == $instanceConf['keep_yearly_month'] &&
                date("j", $creationDate) == $instanceConf['keep_monthly_date']) {
            $this->logger->Log('It is yearly backup. true', PEAR_LOG_DEBUG);
            return true;
        }
        // Cutting off dates oldester then even older monthly backup date
        elseif ($creationDate < $cutOffMonth) {
            $this->logger->Log('Cutted off as oldester then ever older monthly backup. false', PEAR_LOG_DEBUG);
            return false;
        }
        // is it montly backup?
        elseif (date("j", $creationDate) == $instanceConf['keep_monthly_date']) {
            $this->logger->Log('It is montly backup. true', PEAR_LOG_DEBUG);
            return true;
        }
        // Cutting off dates oldester then even older weekly backup date
        elseif ($creationDate < $cutOffWeek) {
            $this->logger->Log('Cutted off as oldester then ever older weekly backup. false', PEAR_LOG_DEBUG);
            return false;
        }
        // is it weekly backup?
        elseif (date("w", $creationDate) == $instanceConf['keep_weekly_day']) {
            $this->logger->Log('It is weekly backup. true', PEAR_LOG_DEBUG);
            return true;
        }
        // Cutting off dates oldester then even older daily backup date
        elseif ($creationDate < $cutOffDay) {
            $this->logger->Log('Cutted off as oldester then ever older daily backup. false', PEAR_LOG_DEBUG);
            return false;
        }
        // is it daily backup?
        elseif ($creationDate > $cutOffDay) {
            $this->logger->Log('It is daily backup. true', PEAR_LOG_DEBUG);
            return true;
        }


        return false;
    }

    static function _getCreationDateFromName($name) {
        $arr = explode('.', $name);
        if (is_array($arr)) {
            $creationDate = end($arr);
        } else {
            $creationDate = (int) floor(time() / 86400) * 86400;
            $this->logger->Log('Can\'t split name ' . $name . ' for prefix and timestamp, using delimeter "."', PEAR_LOG_WARNING);
        }

        return $creationDate;
    }

    static function _getPrefixFromName($name) {
        $arr = explode('.', $name);
        if (is_array($arr)) {
            $creationDate = $arr[0];
        } else {
            $creationDate = array();
            $this->logger->Log('Can\'t split name ' . $name . ' for prefix and timestamp, using delimeter "."', PEAR_LOG_WARNING);
        }

        return $creationDate;
    }

    function _cleanInstanceBackups($instanceConf) {

        $prefix = $instanceConf['prefix'];
        $images = $this->_getImagesByPrefix($prefix);

        foreach ($images as $image) {
            $creationDate = $this->_getCreationDateFromName($image['name']);

            $this->logger->Log('Do we need to keep image ' . $image['imageId'] . ' dated: ' . date(DATE_RFC1036, $creationDate) . ' ?', PEAR_LOG_DEBUG);
            $keep = $this->_keep($instanceConf, $creationDate);

            if (!$keep) {
                $this->logger->Log('Found old image: ' . $image['imageId'] . ' dated: ' . date(DATE_RFC1036, $creationDate), PEAR_LOG_DEBUG);
                $snaps = $image['blockDeviceMapping']['item'];

                // Deregister the image
                $response = $this->ec2->deregister_image($image['imageId']);
                if ($response->isOK()) {
                    $this->logger->Log('Image ' . $image['imageId'] . ' has been succefully deregistered from EC2!', PEAR_LOG_DEBUG);
                } else {
                    $this->logger->Log('Image ' . $image['imageId'] . ' deregistering FAILED!', PEAR_LOG_ERR);
                }

                if ($snaps) {
                    $this->logger->Log('Trying to delete snapshots of image ' . $image['imageId'] . '...', PEAR_LOG_DEBUG);

                    if (!isset($snaps[0]['deviceName']))
                        $snaps = array($snaps);

                    foreach ($snaps as $snap) {
                        $response = $this->ec2->delete_snapshot($snap['ebs']['snapshotId']);
                        if ($response->isOK())
                            $this->logger->Log('.....snapshot ' . $snap['ebs']['snapshotId'] . ' deleted!', PEAR_LOG_DEBUG);
                        else
                            $this->logger->Log('.....removing ' . $snap['ebs']['snapshotId'] . ' FAILED!', PEAR_LOG_WARNING);
                    }
                }
            }
        }
        return true;
    }

    function run() {
        foreach ($this->config as $instanceId => $instanceConf) {
            if (!$this->_checkMandatoryOpts($instanceConf)) {
                $this->logger->Log('Cant find mandatory option(s) for instance id: ' . $instanceId . ' Check config! Skipping...', PEAR_LOG_WARNING);
                break;
            }
            $instanceConf = $this->_setDefaults($instanceConf);
            if ($this->_isItTimeForRun($instanceConf)) {
                if ($this->_backupInstance($instanceId, $instanceConf)) {
                    $this->logger->Log('Backuping instance ' . $instanceId . ' successfully done.', PEAR_LOG_DEBUG);
                    if ($this->_cleanInstanceBackups($instanceConf)) {
                        $this->logger->Log('Cleaning instance ' . $instanceId . ' successfully done.', PEAR_LOG_INFO);
                    } else {
                        $this->logger->Log('Cleaning instance ' . $instanceId . ' FAILED!', PEAR_LOG_ERR);
                    }
                } else {
                    $this->logger->Log('Backuping instance ' . $instanceId . ' FAILED. Skipping cleaning.', PEAR_LOG_ERR);
                }
            } else {
                $this->logger->Log('No jobs at this hour! ' . 'Exiting...', PEAR_LOG_INFO);
            }
        }
    }

    function _updateAsGroup($imageId, $instanceConf) {
        $spotscaler = new spotscale($this->region, $this->mask);
        $autoScalingGroupName = $instanceConf['update_as_group'];
        $autoScalingGroup = $spotscaler->_getAutoScalingGroupByName($autoScalingGroupName);
        $currLaunchConfigName = $autoScalingGroup['LaunchConfigurationName'];
        $currLaunchConfig = $spotscaler->_getLaunchConfigByName($currLaunchConfigName);
        // generate name for new launch config
        $LaunchConfigurationName = "" . $this->_getPrefixFromName($currLaunchConfig['LaunchConfigurationName'])
                . "." . $this->timestamp . "";

        $image = $this->_getImageById($imageId);

        $blockDeviceMapping = array();
        $image['blockDeviceMapping'] = (array) $image['blockDeviceMapping'];

        foreach ($image['blockDeviceMapping']['item'] as $key => $item) {
            // CFArray ->Array
            $item = (array) $item;
            if (!empty($item['ebs']))
                $item['ebs'] = (array) $item['ebs'];

            // filling array and config fucken diffrenta in returned keys and args
            if (!empty($item['virtualName']))
                $blockDeviceMapping[$key]['VirtualName'] = $item['virtualName'];
            if (!empty($item['deviceName']))
                $blockDeviceMapping[$key]['DeviceName'] = $item['deviceName'];
            if (!empty($item['ebs']['snapshotId']))
                $blockDeviceMapping[$key]['Ebs']['SnapshotId'] = $item['ebs']['snapshotId'];
            if (!empty($item['ebs']['volumeSize']))
                $blockDeviceMapping[$key]['Ebs']['VolumeSize'] = $item['ebs']['volumeSize'];
            // TODO: fixme
            //if (!empty($item['ebs']['deleteOnTermination']))
            //    $blockDeviceMapping[$key]['Ebs']['DeleteOnTermination'] = $item['ebs']['deleteOnTermination'];
        }

        // Setting opts for new launch configuration based on old
        $opt = array();
        if (!empty($blockDeviceMapping))
            $opt['BlockDeviceMappings'] = $blockDeviceMapping;
        if (!empty($currLaunchConfig['KeyName']))
            $opt['KeyName'] = $currLaunchConfig['KeyName'];
        if (!empty($currLaunchConfig['SecurityGroups']['member']))
            $opt['SecurityGroups'] = $currLaunchConfig['SecurityGroups']['member'];
        if (!empty($currLaunchConfig['RamdiskId']['member']))
            $opt['RamdiskId'] = $currLaunchConfig['RamdiskId']['member'];
        if (!empty($currLaunchConfig['KernelId']['member']))
            $opt['KernelId'] = $currLaunchConfig['KernelId']['member'];
        if (!empty($currLaunchConfig['UserData']))
            $opt['UserData'] = $currLaunchConfig['UserData'];
        $opt['InstanceMonitoring'] = $currLaunchConfig['InstanceMonitoring'];

        $response = $this->as->create_launch_configuration($LaunchConfigurationName, $imageId, $currLaunchConfig['InstanceType'], $opt);
        if ($response->isOK()) {
            // update auto-scaling group with new launch config
            $opt = array('LaunchConfigurationName' => $LaunchConfigurationName);
            $response = $this->as->update_auto_scaling_group($autoScalingGroupName, $opt);
            if ($response->isOK()) {
                // removing old launch config
                $response = $this->as->delete_launch_configuration($currLaunchConfigName);
                if ($response->isOK()) {
                    return true;
                }
            }
        }
        return false;
    }

    function test() {
        return true;
    }

}

?>