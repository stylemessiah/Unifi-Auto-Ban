# Unifi-Auto-Ban

A php script to automatically ban/unban users who trigger an IPS filter on Unifi networks

Unifi Auto-Ban/Auto-Unban

**What is it?**

Its a PHP script that uses slooffmasters PHP class (API) and IPS logs to find users who trigger the emerging-p2p category and then automatically ban their MAC for a user selectable period, after which they will be automatically unbanned.

While its written specifically for my needs, to block P2P users, it can of course be adapted for other uses.

Extra notes about slooffmasters Client API - You can read and find more info about it and what its capable of (much more than my little script) either by:

Reading about it here first in his thread: https://community.ubnt.com/t5/UniFi-Wireless/PHP-class-to-access-the-UniFi-controller-API-updates-and/m-p/1512870

Or going directly to his github here: https://github.com/Art-of-WiFi/UniFi-API-client

**Why did you write it?**

Because while we dont censor any traffic on our wifi networks, we do object to apps and traffic that swamps and adversely affects traffic, and P2P by its nature opening hundreds of connections, is the one thing we police so the network is available to all fairly. Up until i wrote this script, i was playing whack a mole manually banning people, and even worse were those who knew how to spoof their MAC, meaning i was often banning someone, only to have to ban their spoofed MAC 5 minutes later, and for hours on end.....

Hopefully with the script running every x minutes via cron, people begin to correlate their behaviour with the ban and stop trying to outsmart you, if not they just continue to get banned....i do have plans to notify the user via captive portal, but thats on the todo list for the moment as we dont currently use the captive portal....

I tried to keep this as simple as possible, what info is stored is done in plain old flat (text) files, no databases etc. Though others may feel free to take this script in that or other directions...after all i wrote this to fill my needs, and im not a professional coder, up until recently i hant touched php since about v4 :) So any code suggestions are appreciated. Ive commented the code as well as i can as not only does it help me remember how it functions, but hopefully it helps anyone wanting to improve/reuse it or parts of it.

**How does it work?**

Since the MAC address is the base method one can ban a user on networks, if a user spoofs their MAC address, we would normally have to catch them manually and ban their MAC again, but with IPS (and the emerging-p2p category) enabled, it doesnt matter if a user spoofs their MAC, as long as they trigger the IPS P2P filter (emerging-p2p), we can catch them, grab their MAC from the log and ban them. Let them spoof their MAC, only to get caught again, the ban is automatic, and no way of circumventing it.

So the basic code flow is (via cron every x minutes (default 10)):

* check and if theyre older than a week, download latest Ubiquiti OUI's from the wireshark project - as in testing we found that sometimes a LAN port on the USG can trigger the emerging-p2p filter
* connect to controller
* connect to siteid
* pull IPS log for siteid from controller
* check IPS log for emerging-p2p events
* if emerging-p2p event found, pull timestamp and MAC from it
* check MAC against known Ubiquiti MAC OUI's to remove any LAN ports that may have triggered emerging-p2p
* read previously banned macs from flat file - bannedmacs_(siteid).txt (these files are create dynamically if they dont exist)
* categorize MAC's from banned macs file and IPS logs
* if previously banned MAC has passed bantime (default 2 hours) unban it, otherwise leave banned
* ban new MAC addresses for x hours (default 2 hours)
* write banned MAC's back out to flat file - bannedmacs_(siteid).txt (these files are create dynamically if they dont exist)
* display report and send report via email if set, and only if a ban or unban has taken place - no point sending an email every x minutes if nothing has happened

Script is multi-site friendly, you just add the sites to an array at top of script and it loops through them. i currently have script checking 10 sites with no issues so far.

Note: there are 2 variables that relate to the site(s):

$sites
$sites_friendly

They should be edited as a pair...

$sites holds the siteid, the next part of the URL after /site/ in the controller URL for the site(s) youre adding
$sites_friendly holds the human readable name used in Settings -> Site -> Site Name

For example if i have one site (with siteid "default", and the site name "Home Wifi" the variables would look like this:

$sites = "default";
$sites_friendly = "Home Wifi";

If i had 2 sites, with siteid's "default" and "zn7b0p4m", and site names "Home Wifi" and "Work Wifi" the variables would
look like this (siteid's and site names separated by commas (no spaces before are after commas thanks)):

$sites = "default,zn7b0p4m";
$sites_friendly = "Home Wifi,Work Wifi";

Hopefully this is clear....

Other user editable variables are available at the top of the script alongside the ones just mentionend, all are commented,
not covering all here, just the 2 main ones above that needed special explanation.

**Requirements**:

- IPS enabled on your Controller - goes without saying if you dont have this enabled on or dont want to enable it, then you can stop reading right now....

IMPORTANT NOTE: Im not going to get into conversatiosn on IPS and whether it should and shouldnt be turned on, or if you have issues with it. Or whether you think it slows down traffic....theres other threads for that stuff. Please do not try and have discussions about pro's and con's and issues with IPS here. All i can say is ive had IPS turned on since beta testing and never had a single issue with it, before, or after, enabling this script. Its been a total plus for us in our environment.

- A server with webserver and php-fpm php-curl php-cli (developed and tested on Ubuntu 18.04. lighttpd, and php7.2 on my DO droplet VPS, which also hosts our controller)

- Access to the controller

- A writeable folder under webroot. Its up to you to best configure permissions for the folders under webroot. 

Please note: Its up to you to check your OS's requirements for setitng permissions for webroot. This thread is not an OS or web server support thread.Please use the support forum of the vendor of your OS if you have any questions. No discussions will be entertained on basic OS or web server configuration.


**Installation:**

Some notes about my setup, i use nginx and lets encrypt for SSL bumping any web page connections to SSL for securrity - so you may need to adjust the steps below depending on your setup. I include a full nginx config file (before and after edits) just as an example if youre using the same setup.

1. Install the required software (PHP and lighttpd web server)

  apt install php7.2 php7.2-fpm php7.2-cli php7.2-curl lighttpd

2. Open the lighttpd configuration file

  nano /etc/lighttpd/lighttpd.conf

  Once opened, look for:

    server.port                 = 80
  
  Change to: 

    server.port                 = 81

  Save the file (CTRL and X, then Y, then press Enter at the prompt: Save modified buffer? (Answering "No" will DISCARD changes.))

3. Open and edit the lighttpd fastcgi-php.conf  file 
  
  nano /etc/lighttpd/conf-available/15-fastcgi-php.conf
  
  Look for:
 	
    "bin-path" => "/usr/bin/php-cgi",
    "socket" => "/var/run/lighttpd/php.socket",
  
  Change To: 
  
    #"bin-path" => "/usr/bin/php-cgi",
    #"socket" => "/var/run/lighttpd/php.socket",

  Press Enter for a new line and add:

    "host" => "127.0.0.1",
    "port" => "9000",

  Save the file (CTRL and X, then Y, then press Enter at the prompt: Save modified buffer? (Answering "No" will DISCARD changes.))

4. Enable fastcgi and fastcgi-php (pess Enter after each line)
       
    lighty-enable-mod fastcgi
    
    lighty-enable-mod fastcgi-php
  

5. Open and edit the php www.conf file

nano /etc/php/7.2/fpm/pool.d/www.conf

Look for:

    listen = /run/php/php7.2-fpm.sock

Change to:
    
    ;listen = /run/php/php7.2-fpm.sock

Press enter for a new line and add:

    listen = 127.0.0.1:9000

Save the file (CTRL and X, then Y, then press Enter at the prompt: Save modified buffer? (Answering "No" will DISCARD changes.))

6. Update nginx configuration

  nano /etc/nginx/sites-available/default

  Anywhere in the lower server section, and before the closing } copy and paste the following:

    location /autoban {
    include /etc/nginx/proxy_params;
    proxy_pass http://127.0.0.1:81$request_uri;
    proxy_set_header Upgrade $http_upgrade; 
    proxy_set_header Connection "upgrade";
    }

  Save the file (CTRL and X, then Y, then press Enter at the prompt: Save modified buffer? (Answering "No" will DISCARD changes.))

  The file before editing:

    server_tokens off;
    add_header X-Frame-Options SAMEORIGIN;
    add_header X-XSS-Protection "1; mode=block";

    # Expires map
    map $sent_http_content_type $expires {
    default off;
    text/html epoch;
    text/css max;
    application/javascript max;
    ~image/ max;
    }

    server {
    listen 80;
    server_nameexample.com.au;
    return 301 https://example.com.au$request_uri;
    expires $expires;
    }

    server {
    listen 443 ssl default_server http2;
    server_name example.com.au;
    ssl_dhparam /etc/ssl/certs/dhparam.pem;
    ssl_certificate /etc/letsencrypt/live/example.com.au/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/example.com.au/privkey.pem;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 10m;
    keepalive_timeout 300;
    ssl_protocols TLSv1 TLSv1.1 TLSv1.2;
    ssl_prefer_server_ciphers on;
    ssl_stapling on;
    ssl_ciphers  ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-AES256-SHA384:ECDHE-RSA-AES256-SHA384:ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES128-SHA256:ECDHE-RSA-AES128-SHA256:ECDHE-RSA-AES256-SHA:DHE-RSA-              AES256-SHA;
    add_header Strict-Transport-Security max-age=31536000;
    add_header X-Frame-Options DENY;
    client_max_body_size 8M;
    proxy_cache off;
    proxy_store off;
    expires $expires;

    location / {
    include /etc/nginx/proxy_params;
    proxy_pass https://127.0.0.1:8443$request_uri;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    }
    
    }

  The file after editing:
  
    server_tokens off;
    add_header X-Frame-Options SAMEORIGIN;
    add_header X-XSS-Protection "1; mode=block";

    # Expires map
    map $sent_http_content_type $expires {
    default off;
    text/html epoch;
    text/css max;
    application/javascript max;
    ~image/ max;
    }

    server {
    listen 80;
    server_name example.com.au;
    return 301 https://example.com.au$request_uri;
    expires $expires;
    }

    server {
    listen 443 ssl default_server http2;
    server_name example.com.au;
    ssl_dhparam /etc/ssl/certs/dhparam.pem;
    ssl_certificate /etc/letsencrypt/live/example.com.au/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/example.com.au/privkey.pem;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 10m;
    keepalive_timeout 300;
    ssl_protocols TLSv1 TLSv1.1 TLSv1.2;
    ssl_prefer_server_ciphers on;
    ssl_stapling on;
    ssl_ciphers  ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-AES256-SHA384:ECDHE-RSA-AES256-SHA384:ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES128-SHA256:ECDHE-RSA-AES128-SHA256:ECDHE-RSA-AES256-SHA:DHE-RSA-AES256-SHA;
    add_header Strict-Transport-Security max-age=31536000;
    add_header X-Frame-Options DENY;
    client_max_body_size 8M;
    proxy_cache off;
    proxy_store off;
    expires $expires;

    location / {
    include /etc/nginx/proxy_params;
    proxy_pass https://127.0.0.1:8443$request_uri;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    }

    location /autoban {
    include /etc/nginx/proxy_params;
    proxy_pass http://127.0.0.1:81$request_uri;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    }
    
    }

  Save the file (CTRL and X, then Y, then press Enter at the prompt: Save modified buffer? (Answering "No" will DISCARD changes.))
  
7. Create autoban directory

  mkdir -p /var/www/html/autoban
  
8. Set permissions on html folders:

  chown -R www-data:www-data /var/www/html/*

  chmod 755 /var/www/html/*
  
9. Create a test php page to confirm lighttpd and php are working fine:

  nano /var/www/html/autoban/test.php

  Copy and paste in the following
  
    <?php
    phpinfo();
    ?>
  
  Save the file (CTRL and X, then Y, then press Enter at the prompt: Save modified buffer? (Answering "No" will DISCARD changes.))

10.	Restart nginx

  service nginx restart

11.	Restart lighttpd

  service lighttpd restart

12.	Test the test page, open a browser and navigate to 
  
  http://yourdomain/autoban/test.php

  All things going well, you should see the Php Info page

13.	Download autoban.php from github:

  curl -s https://raw.githubusercontent.com/stylemessiah/Unifi-Auto-Ban/master/autoban.php -o /var/www/html/autoban/autoban.php

14.	Download slooffmasters Client.php file (php class file to interact with controller)

  curl -s https://raw.githubusercontent.com/Art-of-WiFi/UniFi-API-client/master/src/Client.php -o /var/www/html/autoban/Client.php

15.	Set variables in top of autoban.php - follow the notes in the script carefully, especially the $sites and $sites_friendly variables…

  nano /var/www/html/autoban/autoban.php

  When youre happy, save the file (CTRL and X, then Y, then press Enter at the prompt: Save modified buffer? (Answering "No" will DISCARD changes.))
  
16.	As we have downloaded files, and not created them, you will probably need to issue the following commands to set the permissions on Client.php and autoban.php

  chown -R www-data:www-data /var/www/html/*
  
  chmod 755 /var/www/html/*

17.	Unban any currently banned users before proceeding to the next step….

18.	Run autoban.php manually via browser to setup ancillary files. Open a browser and navigate to:

  http://yourdomaain/autoban/autoban.php

  You should get a report page listing all sites 

19.	Check the autoban directory, you should have the following files:

    Client.php (sloofmasters class php file)
    autoban.log (text based autoban log)
    autoban.php (the main file)
    bannedmacs_<siteid>.log  one of these for each site
    manuf.txt (the full OUI database from wireshark)
    ubiquitioui.txt (the Ubiquiti OUI’s pulled from manuf.txt)

20.	Set cron job to run autoban.php and blank the autoban.log daily

  crontab -u www-data -e

  Note: If it’s the first time you’ve run crontab, you’ll be asked to select an editor to use, the 1st choice (default) is usually nano,  I accept this option…and continue

  Add the following lines (1st line is to run the script, 2nd is to blank the log daily (lazy log rotate)):

    */10 * * * * /usr/bin/php -f /var/www/html/autoban/autoban.php > /dev/null 2>&1
    0 0 * * * echo "" > /var/www/html/autoban/autoban.log > /dev/null 2>&1

  Save the file (CTRL and X, then Y, then press Enter at the prompt: Save modified buffer?  (Answering "No" will DISCARD changes.))

21.	Sit back and wait for someone to trip the IPS P2P filter and get themselves banned, if you’ve chosen to enable email alerts in the autoban.php script, you’ll get an email report, if not, you’ll just get the usual banned user notification form the controller (if you have this enabled). If you have both email reports form the script AND then banned user notification set in the controller, you’ll get both….


**Known issues:**

1) MAC address not blocked/unblocked


On occasion, A MAC address may not be banned or unbanned. initially i thought this might be happening when multiple MAC's were being banned or unbanned, which is why i added a 5 second sleep in the block_sta() and unblock_sta() calls, but ive still seen this happen. One thing anyone who has interacted with the Unifi code is that it can sometimes return inconsistent data. What happens with the MAC's not being banned or unbanned - if we look at the unban example is that the call is made and a true is returned to my script and the MAC is removed from the bannedmac_<siteid>.txt file which tracks banned MAC's...only on the system, despite the unblock_sta(MAC) call returning a true, the MAC is not actually unblocked in the controller.


Currently an api call to list the currently blocked MAC's is not in slooffmasters api, but thats what ill be looking for next to double check the unblock_sta call against that....


But for the bulk of the time, the code does its job. The worst that can happen is that a MAC doesnt get blocked - so youre no worse off than before), or isnt unblocked - and i personally am not losing sleep over bans longer than $ban_time (default), as before this script i would leave them banned permanently.




![GitHub release (latest by date)](https://img.shields.io/github/v/release/stylemessiah/Unifi-Auto-Ban?label=Release&style=plastic)
![GitHub Release Date](https://img.shields.io/github/release-date/stylemessiah/Unifi-Auto-Ban?label=Release%20Date&style=plastic)
![GitHub Releases](https://img.shields.io/github/downloads/stylemessiah/Unifi-Auto-Ban/latest/total?label=Downloads%20%28Latest%20Release%29&style=plastic)
![GitHub All Releases](https://img.shields.io/github/downloads/stylemessiah/Unifi-Auto-Ban/total?label=Total%20Downloads%20%28All%20Releases%29&style=plastic)
