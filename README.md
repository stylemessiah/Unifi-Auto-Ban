# Unifi-Auto-Ban
A php script to automatically ban/unban users who trigger an IPS filter on Unifi networks


Unifi Auto-Ban/Auto-Unban


What is it?


Its a PHP script that uses IPS logs to find users who trigger the emerging-p2p category and then automatically ban their MAC
for a user selectable period, after which they will be automatically unbanned.


While its written specifically for my needs, to block P2P users, it can of course be adapted for other uses.


Why did you write it?


Because while we dont censor any traffic on our wifi networks, we do object to apps and traffic that swamps and adversely affects traffic, and P2P by its nature opening hundreds of connections, is the one thing we police so the network is available to all fairly. Up until i wrote this script, i was playing whack a mole manually banning people, and even worse were those who knew how to spoof their MAC, meaning i was often banning someone, only to have to ban their spoofed MAC 5 minutes later, and for hours on end.....


Hopefully with the script running every x minutes via cron, people begin to correlate their behaviour with the ban and stop trying to outsmart you, if not they just continue to get banned....i do have plans to notify the user via captive portal, but thats on the todo list for the moment as we dont currently use the captive portal....


I tried to keep this as simple as possible, what info is stored is done in plain old flat (text) files, no databases etc. Though others may feel free to take this script in that or other directions...after all i wrote this to fill my needs, and im not a professional coder, up until recently i hant touched php since about v4 :) So any code suggestions are appreciated. Ive commented the code as well as i can as not only does it help me remember how it functions, but hopefully it helps anyone wanting to improve/reuse it or parts of it.


How does it work?


Since the MAC address is the base method one can ban a user on networks, if a user spoofs their MAC address, we would normally have to catch them manually and ban their MAC again, but with IPS (and the remerging-p2p category) enabled, it doesnt matter if a user spoofs their MAC, as long as they trigger the IPS P2P filter, we can catch them, grab their MAC from the log and ban them. Let them spoof their MAC, only to get caught again, the ban is automatic, and no way of circumventing it.


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


Script is multi-site friendly, you just add the sites to an array at top of script and it loops through them. i currently have script checking 3 sites with no issues so far.


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


What is required to run it?


* IPS enabled on your Controller - goes without saying if you dont have this enabled on or dont want to enable it, then you can stop reading right now....


IMPORTANT NOTE: Im not going to get into conversatiosn on IPS and whether it should and shouldnt be turned on, or if you have issues with it. Or whether you think it slows down traffic....theres other threads for that stuff. Please do not try and have discussions about pro's and con's and issues with IPS here. All i can say is ive had IPS turned on since beta testing and never had a single issue with it, before, or after, enabling this script. Its been a total plus for us in our environment.


* A server with webserver and php-fpm (developed and tested on Ubuntu 18.04. lighttpd, and php7.2 on my DO droplet VPS, which also hosts our controller)

* Access to the controller

* A writeable folder under webroot. Its up to you to best configure permissions for the folders under webroot. For Ubuntu 18.04 im using all i did was issue the commands below:


mkdir -p /var/www/html/autoban

chown -R www-data:www-data /var/www/html/autoban


Please note: Its up to you to check your OS's requirements for setitng permissions for webroot. This thread is not an OS or web server support thread.Please use the support forum of the vendor of your OS if you have any questions. No discussions will be entertained on basic OS or web server
configuration.


Installation:


1) Download and copy the attached file autoban.php to a directory under webroot
2) Edit the variables at the to of the file as needed - pay special attention tot he already mentioned $site_id and $site_friendly variables
3) Make sure the folder has the correct permissions
4) Run the php script manually to check it works - check on screen report and check autoban.log in the same directory
5) If all looks good, add the script to cron -


For me on Ubuntu 18.04:

crontab -u www-data -e

Adding at end of file:

*/x * * * * /usr/bin/php <path to script under webroot> > /dev/null 2>&1

where x is the number of minutes to run it on schedule, i.e for every 10 minutes

*/10 * * * * /usr/bin/php /var/www/html/autoban/autoban.php > /dev/null 2>&1

Please note the interaction with the variable $script_schedule which by default is set at 9 minutes (1 minute less than the cron schedule. If you change the cron schedule, also change the $script_schedule......


Known issues:


1) MAC address not blocked/unblocked


On occasion, A MAC address may not be banned or unbanned. initially i thought this might be happenign when mutiple MAC's were being banned or unbanned, which is why i added a 5 second sleep i the block_sta() and unblock_sta() calls, but ive still seen this happen. One thing anyone who has interacted with the Unifi code is that it can sometimes return inconsistent data. What happens with the MAC's not being banned or unbanned - if we look at the unban example is that the call is made and a true is returned to my script and the MAC is rmeoved form the bannedmac_<siteid>.txt file which tracks banned MAC's...only on the system, despite the unblock_sta(MAC) call returnign a true, the MAC is not actually unblocked in the controller.


Currently an api call to list the currently blocked MAC's is not in slooffmasters api, but thats what ill be looking for next to double check the unblock_sta call against that....


But for the bulk of the time, the code does its job. The worst that can happen is that a MAC doesnt get blocked - so youre no worse off than before), or isnt unblocked - and i personally am not losing sleep over bans longer than $ban_time (default), as before this script i would leave them banned permanently.





