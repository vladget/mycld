# mycld
tools what help automate &amp; manage Amazon AWS cloud. (import from code.google.com, developed at 2012)

## SPOTSCALE ##
---------
fetch code and add to crontab:
`*/5 * * * *  root /root/mycld/mycld.cli.runner.php -s spotscale -a run -r "us-east-1" -d >> /root/mycld/logs/spotscale-error.log 2>&1`


## EC2BACKUP ##
fetch code and add to crontab:
`15 * * * *  root /root/mycld/mycld.cli.runner.php -s ec2backup -a run -r "us-east-1" -d >> /root/mycld/logs/ec2backup-error.log 2>&1`
