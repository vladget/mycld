# mycld
tools what help automate &amp; manage Amazon AWS cloud. (import from code.google.com, developed at 2012)

SPOTSCALE:
---------
TODO: 
http://code.google.com/p/mycld
http://mycld.blogspot.com
crontab:
*/5 * * * *  root /root/mycld/mycld.cli.runner.php -s spotscale -a run -r "us-east-1" -d >> /root/mycld/logs/spotscale-error.log 2>&1


EC2BACKUP:
---------
TODO:
crontab:
15 * * * *  root /root/mycld/mycld.cli.runner.php -s ec2backup -a run -r "us-east-1" -d >> /root/mycld/logs/ec2backup-error.log 2>&1
