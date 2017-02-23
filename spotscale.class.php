<?php

/**
 * spotscale class
 * @package mycld
 * @version 0.0.7
 * @author Vladimir Getmanshchuk <vladget@gmail.com>
 */
class spotscale extends mycld {

    const PRICE_MULTIPLIER = 1.05;

    public $service = 'spotscale';
    public $logger;
    public $config;
    public $db;
    public $ec2;
    public $as;
    public $cw;
    public $elb;

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
        $this->cw = new AmazonCloudWatch();
        self::setRegion($this->cw, $this->region, $this->logger);
        $this->elb = new AmazonELB();
        self::setRegion($this->elb, $this->region, $this->logger);
    }

    function __destruct() {
        self::printStoppingMessage($this->logger, $this->service);
    }

    function _createDbObject() {
        $this->db = new db();
        $this->db->set_utf8 = true; //if it is utf-8
        $this->db->connect_when_query();

        return $this->db;
    }

    function _dbGetSpotRequestsForAutoScalingGroupInActiveState($autoScalingGroup) {
        $db = $this->_createDbObject();
        $spotInstanceRequest = $db->result_array("SELECT id, UNIX_TIMESTAMP(state_changed_dt) AS state_changed_dt, ec2_request_id, state
                                           FROM spot_instance_requests
                                           WHERE auto_scaling_group = '" . $autoScalingGroup . "'
                                               AND state IN ('active')
                                           ORDER BY create_dt DESC");
        if ($spotInstanceRequest)
            return $spotInstanceRequest;
        else
            return array();
    }

    function _dbGetSpotRequestsForAutoScalingGroupInOpenState($autoScalingGroup) {
        $db = $this->_createDbObject();
        $spotInstanceRequest = array();
        $spotInstanceRequest = $db->result_array("SELECT id, UNIX_TIMESTAMP(state_changed_dt) AS state_changed_dt, ec2_request_id, state
                                           FROM spot_instance_requests
                                           WHERE auto_scaling_group = '" . $autoScalingGroup . "'
                                               AND state IN ('open')
                                               AND create_dt < NOW() - INTERVAL 15 MINUTE
                                           ORDER BY create_dt DESC");

        return $spotInstanceRequest;
    }

    function _dbGetLastSpotRequestForAutoScalingGroup($autoScalingGroup) {
        $db = $this->_createDbObject();
        $spotInstanceRequest = $db->row_array("SELECT id, UNIX_TIMESTAMP(state_changed_dt) AS state_changed_dt, ec2_request_id, state
                                           FROM spot_instance_requests
                                           WHERE auto_scaling_group = '" . $autoScalingGroup . "'
                                               AND state NOT IN ('deleted', 'FAILED')
                                           ORDER BY create_dt DESC
                                           LIMIT 1");
        if ($spotInstanceRequest)
            return $spotInstanceRequest;
        else
            return false;
    }

    function _dbGetNOldestSpotRequestForAutoScalingGroup($autoScalingGroup, $requestQty) {
        $db = $this->_createDbObject();
        $spotInstanceRequests = array();
        $spotInstanceRequests = $db->result_array("SELECT id, UNIX_TIMESTAMP(create_dt) AS state_changed_dt, ec2_request_id, state
                                           FROM spot_instance_requests
                                           WHERE auto_scaling_group = '" . $autoScalingGroup . "'
                                               AND state NOT IN ('deleted', 'cancelled', 'failed') 
                                           ORDER BY create_dt ASC
                                           LIMIT " . $requestQty);

        return $spotInstanceRequests;
    }

    function _dbGetSpotRequestsForEc2RequestId($ec2RequestId) {
        $db = $this->_createDbObject();
        $spotInstanceRequests = $db->result_array("SELECT id, UNIX_TIMESTAMP(create_dt) AS state_changed_dt, ec2_request_id, state
                                           FROM spot_instance_requests
                                           WHERE ec2_request_id = '" . $ec2RequestId . "'
                                               AND state NOT IN ('deleted', 'cancelled', 'failed')
                                           ORDER BY create_dt ASC");
        if ($spotInstanceRequests)
            return $spotInstanceRequests;
        else
            return false;
    }

    function getAvgSpotPriceHistory($instanceType, $availabilityZone = 'us-east-1a') {

        $response = $this->ec2->describe_spot_price_history(array(
            'StartTime' => 'yesterday',
            'EndTime' => 'today',
            'InstanceType' => $instanceType,
            'AvailabilityZone' => $availabilityZone,
            'ProductDescription' => 'Linux/UNIX',
            'MaxResults' => 10
                ));

        if ($response->isOK()) {
            $bidCount = 0;
            $bidSum = 0;
            foreach ($response->body->spotPriceHistorySet->item as $bid) {
                $bidCount++;
                $bidSum += $bid->spotPrice->to_string();
            }
        }
        else
            return false;

        if ($bidCount != 0)
            return $bidSum / $bidCount * self::PRICE_MULTIPLIER;
        else
            return false;
    }

    function _getAlarms() {
        $this->logger->log('Getting all alarms...', PEAR_LOG_INFO);


        $response = $this->cw->describe_alarms();


        if ($response->isOK()) {
            $result_array = $this->cw->util->convert_response_to_array($response);

            $this->logger->log('Got alarms!', PEAR_LOG_INFO);
            return $result_array['body']['DescribeAlarmsResult']['MetricAlarms']['member'];
        } else {
            print_r($response);
            $this->logger->log('Getting alarms FAILED!', PEAR_LOG_ERR);
            return false;
        }
    }

    function check() {
        $this->logger->log('Before checking alarms DO update: sync requests and trace state changes...', PEAR_LOG_INFO);
        $this->update();

        $alarms = $this->_getAlarms();

        if (!is_array($alarms))
            return false;


        $this->logger->log('Starting alarms checks...', PEAR_LOG_INFO);
        foreach ($alarms as $alarm) {

            $this->logger->log('Checking alarm ' . $alarm['AlarmName'] . '... ', PEAR_LOG_INFO);
            if (!isset($alarm['OKActions']['member']) && !isset($alarm['AlarmActions']['member'])) {

                $this->logger->log('NO actions for alarm!', PEAR_LOG_INFO);
                return false;
            }

            if ($alarm['StateValue'] == 'OK' && isset($alarm['OKActions']['member'])) {
                $policyName = $this->_getPolicyNameFromAction($alarm['OKActions']['member']);

                $this->logger->log('GOT action for alarm state OK!', PEAR_LOG_INFO);
                $this->_executePolicy($policyName);
            } else {

                $this->logger->log('NO actions for alarm state OK!', PEAR_LOG_INFO);
            }
            if ($alarm['StateValue'] == 'ALARM' && isset($alarm['AlarmActions']['member'])) {
                $policyName = $this->_getPolicyNameFromAction($alarm['AlarmActions']['member']);

                $this->logger->log('GOT action for alarm state ALARM!', PEAR_LOG_INFO);
                $this->_executePolicy($policyName);
            } else {

                $this->logger->log('NO actions for alarm state ALARM!', PEAR_LOG_INFO);
            }
        }
    }

    function _getPolicyNameFromAction($actionMember) {
        if (!is_string($actionMember))
            return false;

        $actionMember = explode(":", $actionMember);
        $actionMember = explode("/", $actionMember[8]);

        return $actionMember[1];
    }

    function _getSpotInstanceRequestState($spotRequestId) {
        $spotRequestState = array();


        $response = $this->ec2->describe_spot_instance_requests(array(
            'SpotInstanceRequestId' => $spotRequestId
                ));
        if ($response->isOK()) {
            $spotRequestState = $this->ec2->util->convert_response_to_array($response);
            $spotRequestState = $spotRequestState['body']['spotInstanceRequestSet']['item'];
        }

        return $spotRequestState;
    }

    function _getRunnedInstancesForAutoScalingGroup($autoScalingGroupName) {
        $spotRequests = $this->_dbGetSpotRequestsForAutoScalingGroupInActiveState($autoScalingGroupName);
        $runnedInstances = array();

        foreach ($spotRequests as $spotRequest) {
            $spotRequestState = $this->_getSpotInstanceRequestState($spotRequest['id']);
            if (isset($spotRequestState['instanceId']))
                $runnedInstances[] = $spotRequestState['instanceId'];
        }
        return $runnedInstances;
    }

    function _getPolicyByName($policyName) {

        $response = $this->as->describe_policies(array('PolicyNames' => $policyName));

        if ($response->isOK()) {
            $result_array = $this->as->util->convert_response_to_array($response);
            return $result_array['body']['DescribePoliciesResult']['ScalingPolicies']['member'];
        }
        else
            return false;
    }

    function _getAutoScalingGroupByName($autoScalingGroupName) {

        $response = $this->as->describe_auto_scaling_groups(array('AutoScalingGroupNames' => $autoScalingGroupName));

        if ($response->isOK()) {
            $result_array = $this->as->util->convert_response_to_array($response);
            return $result_array['body']['DescribeAutoScalingGroupsResult']['AutoScalingGroups']['member'];
        }
        else
            return false;
    }

    function _getLaunchConfigByName($launchConfigName) {

        $response = $this->as->describe_launch_configurations(array('LaunchConfigurationNames' => $launchConfigName));

        if ($response->isOK()) {
            $result_array = $this->as->util->convert_response_to_array($response);
            return $result_array['body']['DescribeLaunchConfigurationsResult']['LaunchConfigurations']['member'];
        }
        else
            return false;
    }

    function _registerInstancesWithELBs($elbsNames, $instanceIds) {
        if (is_string($elbsNames))
            $elbsNames = array($elbsNames);

        if (is_string($instanceIds))
            $instanceIds = array(array('InstanceId' => $instanceIds));

        foreach ($elbsNames as $elbName) {

            $this->logger->log('Trying to register instances with ELB: ' . $elbName . '...', PEAR_LOG_INFO);

            $response = $this->elb->register_instances_with_load_balancer($elbName, $instanceIds);

            if ($response->isOK()) {

                $this->logger->log('Instance(s) succefuly registered with ELB: ' . $elbName, PEAR_LOG_INFO);
            } else {

                $this->logger->log('Instance(s) registration with ELB: ' . $elbName . ' FAILED!', PEAR_LOG_ERR);
            }
        }
    }

    function _deregisterInstancesFromELBs($elbsNames, $instanceIds) {
        if (is_string($elbsNames))
            $elbsNames = array($elbsNames);

        if (is_string($instanceIds))
            $instanceIds = array(array('InstanceId' => $instanceIds));

        foreach ($elbsNames as $elbName) {

            $this->logger->log('Trying to de-register instances from ELB: ' . $elbName . '...', PEAR_LOG_INFO);


            $response = $this->elb->deregister_instances_from_load_balancer($elbName, $instanceIds);

            if ($response->isOK()) {

                $this->logger->log('Instance(s) succefuly de-registered from ELB: ' . $elbName, PEAR_LOG_INFO);
            } else {

                $this->logger->log('Instance(s) de-registration from ELB: ' . $elbName . ' FAILED!', PEAR_LOG_ERR);
            }
        }
    }

    function _executePolicy($policyName) {

        $this->logger->log('Before executing policy DO update: sync requests and trace state changes...', PEAR_LOG_INFO);
        $this->update();

        $this->logger->log('Trying to execute policy: ' . $policyName . '... ', PEAR_LOG_INFO);
        $this->logger->log('Fetching info about policy, and last activity by this policy...', PEAR_LOG_INFO);

        $policy = $this->_getPolicyByName($policyName);
        $lastActivity = $this->_dbGetLastSpotRequestForAutoScalingGroup($policy['AutoScalingGroupName']);


        // fix last activity: if data has no info about last activity, set last activity = yesterday
        if ($lastActivity == false) {
            $yesterday = time() - (24 * 60 * 60);
            $lastActivity = array('id' => 0, 'state_changed_dt' => $yesterday);
            $this->logger->log('Last activity datetime unknown, using yesterday as last activity...', PEAR_LOG_WARNING);
        } else {
            $this->logger->log('Got last activity!', PEAR_LOG_INFO);
        }

        // checking for cooldown
        $this->logger->log('Checking cooldown...', PEAR_LOG_INFO);
        if ((time() - $lastActivity['state_changed_dt']) > $policy['Cooldown']) {
            $this->logger->log('Policy cooldown passed!', PEAR_LOG_INFO);
            $autoScalingGroup = $this->_getAutoScalingGroupByName($policy['AutoScalingGroupName']);

            // Put all capacity metrics together in one array
            $capacity = array(
                'min' => $autoScalingGroup['MinSize'],
                'max' => $autoScalingGroup['MaxSize'],
                'desired' => 0, //$autoScalingGroup['DesiredCapacity'],
                'current' => count($this->_getRunnedInstancesForAutoScalingGroup($policy['AutoScalingGroupName'])),
            );

            // if there are request(s) at open state and with creation date not older 15 min past
            //we add it to current capacity too.
            //But actually it will cuted off by cooldown checker if cooldown >= 15 min
            $capacity['current'] += count($this->_dbGetSpotRequestsForAutoScalingGroupInOpenState($policy['AutoScalingGroupName']));

            // Working around adjustment to get desired capacity
            switch ($policy['AdjustmentType']) {
                case 'PercentChangeInCapacity': $capacity['desired'] = $capacity['current'] + round($capacity['current'] * ($policy['ScalingAdjustment'] / 100));
                    break;
                case 'ExactCapacity': $capacity['desired'] = $policy['ScalingAdjustment'];
                    break;
                //ChangeInCapacity as default
                default: $capacity['desired'] = $capacity['current'] + $policy['ScalingAdjustment'];
                    break;
            }

            // just limiting desires :)
            // max limit
            if ($capacity['desired'] > $capacity['max'])
                $capacity['desired'] = $capacity['max'];
            if ($capacity['desired'] < $capacity['min'])
                $capacity['desired'] = $capacity['min'];

            $adjustment = $capacity['desired'] - $capacity['current'];

            // ScaleIN
            if ($adjustment > 0) {
                $this->logger->log('Increasing current capacity of autoscaling group "' . $policy['AutoScalingGroupName'] . '": ', PEAR_LOG_INFO);
                $this->logger->log('.....current->desired: ' . $capacity['current'] . '->' . $capacity['desired'], PEAR_LOG_INFO);
                $launchConfig = $this->_getLaunchConfigByName($autoScalingGroup['LaunchConfigurationName']);

                // We need at least one zone to run in.
                $availabilityZone = is_array($autoScalingGroup['AvailabilityZones']['member']) ?
                        $autoScalingGroup['AvailabilityZones']['member'][0] :
                        $autoScalingGroup['AvailabilityZones']['member'];

                // TODO: Request instance in zone with lowest price.
                // Spot instance will requested with price of $currentBid value
                $currentBid = $this->getAvgSpotPriceHistory($launchConfig['InstanceType'], $availabilityZone);

                $opt = array(
                    'InstanceCount' => $adjustment,
                    'Type' => 'one-time',
                    'ValidUntil' => '+2 weeks',
                    'LaunchSpecification' => array(
                        'InstanceType' => $launchConfig['InstanceType'],
                        'ImageId' => $launchConfig['ImageId'],
                        'KeyName' => $launchConfig['KeyName'],
                        'SecurityGroup' => $launchConfig['SecurityGroups']['member'],
                        'Placement' => array('AvailabilityZone' => $availabilityZone),
                        'Monitoring.Enabled' => $launchConfig['InstanceMonitoring']['Enabled'],
                        ));
                if (!empty($launchConfig['UserData']))
                    $opt['LaunchSpecification']['UserData'] = $launchConfig['UserData'];
                if (!empty($launchConfig['KernelId']))
                    $opt['LaunchSpecification']['KernelId'] = $launchConfig['KernelId'];
                if (!empty($launchConfig['RamdiskId']))
                    $opt['LaunchSpecification']['RamdiskId'] = $launchConfig['RamdiskId'];
                if (!empty($launchConfig['BlockDeviceMapping'])) {
                    $opt['BlockDeviceMapping'] = $launchConfig['BlockDeviceMapping'];
                    foreach ($opt['BlockDeviceMapping'] as $key => $value)
                        $opt['BlockDeviceMapping'][$key]['Ebs']['DeleteOnTermination'] = 'true';
                }
                // TODO: SubnetId 


                $response = $this->ec2->request_spot_instances($currentBid, $opt);
                if ($response->isOK()) {
                    $this->logger->log('.....spot instance requested!', PEAR_LOG_INFO);
                    $result_array = $this->ec2->util->convert_response_to_array($response);
                    $ec2RequestId = $result_array['body']['requestId'];
                    $result_array = $result_array['body']['spotInstanceRequestSet']['item'];

                    if (!isset($result_array[0]))
                        $result_array = array($result_array);

                    foreach ($result_array as $result) {
                        $db = $this->_createDbObject();
                        $insert_result = $db->query("INSERT INTO spot_instance_requests
                                             VALUES ('" . $result['spotInstanceRequestId'] . "','" .
                                $autoScalingGroup['AutoScalingGroupName'] . "','NULL','" .
                                $result['state'] . "'," .
                                $result['spotPrice'] . "," .
                                "FROM_UNIXTIME(" . strtotime($result['createTime']) . ")," .
                                "FROM_UNIXTIME(" . strtotime($result['validUntil']) . ")," .
                                "FROM_UNIXTIME(" . strtotime($result['createTime']) . ")," .
                                " '" . $ec2RequestId . "')");
                        if ($insert_result) {
                            $this->logger->log('.....and added to DB!', PEAR_LOG_INFO);
                        } else {
                            $this->logger->log('.....adding to DB FAILED!', PEAR_LOG_ERR);
                        }
                    }
                } else {
                    print_r($response);
                    $this->logger->log('.....spot instance request FAILED! POLICY EXECUTION FAILED!', PEAR_LOG_ERR);
                    return false;
                }

                $this->logger->log('Successfully executed! ', PEAR_LOG_INFO);

                // ScaleOUT
            } elseif ($adjustment < 0) {
                $this->logger->log('Decreasing current capacity of autoscaling group "' . $policy['AutoScalingGroupName'] . '": ', PEAR_LOG_INFO);
                $this->logger->log('.....current->desired: ' . $capacity['current'] . '->' . $capacity['desired'], PEAR_LOG_INFO);
                $oldestActivities = $this->_dbGetNOldestSpotRequestForAutoScalingGroup($policy['AutoScalingGroupName'], abs($adjustment));

                if (empty($oldestActivities)) {
                    $this->logger->log('Decreasing FAILED. Cant find activities for autoscaling group: "' . $policy['AutoScalingGroupName'] . '" at DB! ', PEAR_LOG_ERR);
                } else {

                    foreach ($oldestActivities as $spotInstanceRequest) {
                        $spotInstanceRequestState = $this->_getSpotInstanceRequestState($spotInstanceRequest['id']);
                        $instanceId = $spotInstanceRequestState['instanceId'];

                        //cancelling request at EC2

                        $response = $this->ec2->cancel_spot_instance_requests($spotInstanceRequest['id']);
                        //  at DB
                        if ($response->isOK()) {
                            $this->logger->log('.....spot instance succefully cancelled!', PEAR_LOG_INFO);
                            $db = $this->_createDbObject();
                            $update_result = $db->query("UPDATE spot_instance_requests
                                             SET state = 'cancelled', state_changed_dt = NOW()
                                             WHERE id = '" . $spotInstanceRequest['id'] . "'");
                            if ($update_result) {
                                $this->logger->log('.....and updated as cancelled at DB!', PEAR_LOG_INFO);
                            } else {
                                $this->logger->log('.....updating DB FAILED!', PEAR_LOG_ERR);
                            }
                        } else {
                            $this->logger->log('.....spot instance cancellation FAILED! POLICY EXECUTION FAILED! ', PEAR_LOG_ERR);
                        }

                        $autoScalingGroup = $this->_getAutoScalingGroupByName($policy['AutoScalingGroupName']);

                        // deregistering instance from ELB(s)
                        $elbsNames = $autoScalingGroup['LoadBalancerNames']['member'];
                        $this->_deregisterInstancesFromELBs($elbsNames, $instanceId);

                        // terminating instance

                        $response = $this->ec2->terminate_instances($instanceId);
                        if ($response->isOK()) {
                            $this->logger->log('.....instance ID: ' . $instanceId . ' succefuly terminated!', PEAR_LOG_INFO);
                        } else {
                            $this->logger->log('.....instance ID: ' . $instanceId . ' termination FAILED!', PEAR_LOG_ERR);
                        }
                    }
                    $this->logger->log('Successfully executed!', PEAR_LOG_INFO);
                }
            } elseif ($adjustment == 0) {
                $this->logger->log('Policy execution cancelled: based on capacity limits.', PEAR_LOG_INFO);
            }
        } else
            $this->logger->log('Policy execution delayed: cooldown timeout!', PEAR_LOG_INFO);
    }

    function update() {

        $this->logger->log('Updating DB records for spot requests, tagging spot instances, and registering its with ELBs...', PEAR_LOG_INFO);
        $db = $this->_createDbObject();
        $dbSpotInstanceRequests = $db->result_array("SELECT *
                                                 FROM spot_instance_requests
                                                 WHERE state NOT IN ('cancelled', 'deleted');");
        if (!is_array($dbSpotInstanceRequests)) {
            $this->logger->log('There arn\'t active or opened requests in the DB. Exiting...', PEAR_LOG_INFO);
            return true;
        }


        foreach ($dbSpotInstanceRequests as $dbSpotInstanceRequest) {
            $currentSpotInstanceRequest = $this->_getSpotInstanceRequestState($dbSpotInstanceRequest['id']);
            $sql = "";
            if (count($currentSpotInstanceRequest) > 0) {
                if (isset($currentSpotInstanceRequest['instanceId'])) {
                    // Add instance to ELB if it new
                    if ($dbSpotInstanceRequest['instance_id'] != $currentSpotInstanceRequest['instanceId']) {
                        $autoScalingGroup = $this->_getAutoScalingGroupByName($dbSpotInstanceRequest['auto_scaling_group']);

                        // Getting elbs names
                        $elbsNames = $autoScalingGroup['LoadBalancerNames']['member'];
                        $this->_registerInstancesWithELBs($elbsNames, $currentSpotInstanceRequest['instanceId']);

                        // Updating DB
                        $sql = "UPDATE spot_instance_requests SET instance_id = '" . $currentSpotInstanceRequest['instanceId'] . "'";

                        //tagging instances

                        $response = $this->ec2->create_tags($currentSpotInstanceRequest['instanceId'], array(
                            array('Key' => 'mycld.AutoScalingGroup', 'Value' => $dbSpotInstanceRequest['auto_scaling_group']),
                            array('Key' => 'mycld.LaunchConfig', 'Value' => $autoScalingGroup['LaunchConfigurationName']),
                            array('Key' => 'mycld.SpotRequestID', 'Value' => $dbSpotInstanceRequest['id']),
                            array('Key' => 'mycld.SpotRequestMaxPrice', 'Value' => $currentSpotInstanceRequest['spotPrice']),
                                ));
                        if ($response->isOK())
                            $this->logger->log('Instance ID: ' . $currentSpotInstanceRequest['instanceId'] . ' has been successfuly tagged!', PEAR_LOG_INFO);
                        else
                            $this->logger->log('Tagging  instance ID: ' . $currentSpotInstanceRequest['instanceId'] . ' FAILED!', PEAR_LOG_INFO);
                    }
                }
                if (isset($currentSpotInstanceRequest['state'])) {
                    if ($dbSpotInstanceRequest['state'] != $currentSpotInstanceRequest['state']) {
                        if (empty($sql)) {
                            $sql = "UPDATE spot_instance_requests
                                    SET state = '" . $currentSpotInstanceRequest['state'] . "', state_changed_dt = NOW()";
                        } else {
                            $sql .= ", state = '" . $currentSpotInstanceRequest['state'] . "', state_changed_dt = NOW()";
                        }
                    }
                }
                if (!empty($sql)) {
                    $update_result = $db->query($sql . " WHERE id = '" . $dbSpotInstanceRequest['id'] . "'");
                    if ($update_result)
                        $this->logger->log('Record for spot request id: ' . $dbSpotInstanceRequest['id'] . 'succefully updated!', PEAR_LOG_INFO);
                } else {
                    $this->logger->log('There is nothing to update. All records at DB in actual state.', PEAR_LOG_INFO);
                }
            }
        }
    }

    function test() {
        //$autoScalingGroup = $this->_getAutoScalingGroupByName('my_autoscaling_group');
        //$launchConfig = $this->_getLaunchConfigByName($autoScalingGroup['LaunchConfigurationName']);
        //$policy = $this->_getPolicyByName('myPolicy4ScaleIn');
        //print_r($autoScalingGroup);
        //print_r($launchConfig);
        //print_r($policy);
        //$this->_executePolicy('myPolicy4ScaleIn');
        //$this->_executePolicy('myPolicy4ScaleOut');
        //exit(0);
    }

}

?>
