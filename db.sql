 CREATE DB mycld;

 USE mycld;

  CREATE TABLE `spot_instance_requests` (
  `id` varchar(45) NOT NULL,
  `auto_scaling_group` varchar(45) NOT NULL,
  `instance_id` varchar(45) DEFAULT NULL,
  `state` varchar(45) DEFAULT NULL,
  `spot_price` float unsigned DEFAULT '0',
  `create_dt` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `valid_until` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `state_changed_dt` timestamp NULL DEFAULT NULL,
  `ec2_request_id` varchar(45) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `spot_instance_request_id_UNIQUE` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8


