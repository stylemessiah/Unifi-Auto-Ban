<?php
// Set current working directory - where script is run from, and add a trailing slash,
// eliminates setting long paths for variables that include a path....
//
// change to match where you place the script, dont forget to add the trailing slash folks

$script_path = "/var/www/html/autoban/";


/////////////////////////////////////////////
///////// Includes/Requires (Start) ////////
/////////////////////////////////////////////

// change to be the path to slooffmasters Client.php file,
// i grabbed is zip file and unzipped to /var/www/html//autoban/uniapi
// adjust to suit where you place the Client.php file
// you can of course place it in the same folder, but if you do, use the full path, not relative!

require_once("/var/www/html/autoban/uniapi/src/Client.php");

///////////////////////////////////
///////// Includes (End) //////////
///////////////////////////////////


///////////////////////////////////
//////// Variables (Start) ////////
///////////////////////////////////

// general connection variables

$controller_user     = "user";
$controller_password = "password";
$controller_url      = "url:8443";
$controller_version  = "ver";


//script specific variables

// $date_format 
//
// Because im in Australia and we use date correctly here, i developed the
// script using day/month/Year hour:minute:seconds AM/PM
// Feel free to ruin this if you live in the USA :)
//
// Literally no help offered if you change this and it doesnt work, just
// as im not flying over to show you how to drive on the correct side of
// the road. Edit it and you're on your own :)

$date_format = "d-m-Y h:i:s A";


// $ban_time - by default set at 2 hours

$ban_time = "2 hours";

// $script_schedule - the frequency at which you run the script via cron
// I set this to 1 minute less than cron runs the script. i.e. of cron is 
// running the script every 10 minutes, i set this to 9
//
// Only MAC addresses from the IPS log newer than this (script runtime - 9 minutes)
// are evaluated for banning. 
//
//As the IPS log timestamps last for 12+ hours if we used the IPS log we would
// just keep banning the same MAC's over and over. on its original timestamp

$script_schedule = "9 minutes";


// site variables

// IMPORTANT!!!! - PLEASE READ CAREFULLY
//
// the next 2 variables  should be edited in parallel, the first entry in each variable 
// should relate to  the same site and onwards for each site you are adding, and 
// siteid's separated by commas, no whitespace before or after commas folks

// edit to include siteid from controller URL

$sites = "default";

// edit to include friendly site names in same order as sites (site id) above!, and again 
// separated by commas, no whitespace before or after commas folks

$sites_friendly = "Home Wifi";


// ubiquiti oui file variable
// name of file containing ubiquiti oui's - this is downloaded via script via cron (see documentation)

$oui_file = $script_path . "ubiquitioui.txt";


//log file related variables

// name of log file for general autoban logging - you may need to change owner/group/permissions on the 
// folder (see documentation)

$log_file = $script_path . "autoban.log";

// log reset time - since we're unlikely to want to keep logs, set a reset time - default 24 hours

$log_reset = "24 hours";


// email related variables

// set whether to send an email report or not...
//
// valid options are "yes" (default) or "no"

$report_email = "yes";

// your own email in case you want the logs emailed to you

$to_email = "email@gmail.com";

// email subject

$subject = "Unifi - Wifi User Autobanned/Autounbanned";

// from email header

$headers = "From: email@server.com";


// usually editing is not needed for these.....

$headers .= "MIME-Version: 1\.0\r\n";
$headers .= "Content-Type: text/html; charset=ISO-8859-1\r\n";
$send_email = "";

///////////////////////////////////
//////// Variables (End) //////////
///////////////////////////////////

///////////////////////////////////
//////// Functions (Start) ////////
///////////////////////////////////

//simple function to format time stored in $timestamp passed to function

function nice_time($timestamp) {
    global $date_format;
    $result = date($date_format, $timestamp);
    return $result;
}


// simple function to format time stored in $timestamp passed to function, while added bantime
function nice_unban_time($timestamp) {
    global $date_format;
    global $ban_time;
    $result = date($date_format, strtotime('+' . $ban_time, $timestamp));
    return $result;
}


// function to download file via curl
function curl_download($url, $file) {
    // is cURL installed yet?
    if (!function_exists('curl_init')) {
        die('Sorry cURL is not installed!');
    }
    // create a new cURL resource handle
    $ch = curl_init();
    // Set URL to download
    curl_setopt($ch, CURLOPT_URL, $url);
    // open file handle
    $fh = fopen($file, 'w+');
    //If $fp is FALSE, something went wrong.
    if ($fh === false) {
        throw new Exception('Could not open: ' . $file);
    }
    //Pass our file handle to cURL.
    curl_setopt($ch, CURLOPT_FILE, $fh);
    // Timeout in seconds
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    // Download the given URL, and return output
    $output     = curl_exec($ch);
    $statuscode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    // Close the cURL resource, and free system resources
    curl_close($ch);
    // close file handle
    fclose($fh);
    // return statuscode
    return $statuscode;
}


// function to write to log file
function write_to_log($filename, $msg) {
    global $date_format;
    $fd  = fopen($filename, "a");
    $str = "[" . date($date_format, mktime()) . "] " . $msg;
    fwrite($fd, $str . "\n");
    fclose($fd);
}


// function to read file into array
function file_to_array($filename) {
    $lines = file($filename, FILE_IGNORE_NEW_LINES);
    if (empty($lines)) {
        write_to_log($log_file, "File is empty or does not exist: " . $filename);
        exit("File is empty or does not exist: " . $filename);
    } else {
        $array = array();
        foreach ($lines as $value) {
            $array[] = $value;
        }
        return $array;
    }
}


// function to read banned mac files into array (csv style - # though WITHOUT headers) 
// as they contain the <timstamp,banned mac (src_mac)> lines which we make into a 
// key (timestamp) ->  value (src_mac) pair
function ban_file_to_array($filename) {
    $array = array();
    $csv   = array_map('str_getcsv', file($filename));
    foreach ($csv as $line) {
        $array[$line[0]] = $line[1];
    }
    return $array;
}


// function to write MAC's to file - this will be used when banning new MAC's, as well as unbanning MAC's
function write_banned_macs($filename, $array) {
    $file = fopen($filename, "w");
    foreach ($array as $key => $value) {
        $row = array(
            $key,
            $value
        );
        fputcsv($file, $row);
    }
    fclose($file);
}


// function to compare oui & mac arrays - to remove instances of known MAC's from
// say LAN2 port of a USG from being considered as a client MAC and therefore being 
// banned :)
function compare_oui_mac_arrays($query_array, $ref_array) {
    // convert both arrays values into lower case
    $query_array = array_map('strtolower', $query_array);
    $ref_array   = array_map('strtolower', $ref_array);
    // start looping through $query_array
    foreach ($query_array as $value) {
        // select only the first 8 chars (substr($value, 0, 8)), as we are going to
        // compare against the known 8 char oui prefixes in $ref_array
        $results = in_array(substr($value, 0, 8), $ref_array);
        // if our mac prefix (8 chars) matches a known Ubiquiti oui prefix...
        if ($results == true) {
            // if a match against the oui prefixes in $ref_array, do an array_search in $query_array
            // and get the index if it exists - should be there, we used it to match
            $index = array_search($value, $query_array);
            // if found in $query_array...
            if ($index !== false) {
                // unset (remove) the mac address from $query_array
                unset($query_array[$index]);
            }
        }
    }
    return $query_array;
}


// this is the MAIN function, where all the important stuff happens, MAC's are checked
// and categorized....they are added to one of the 3 arrays depending  on what needs to
// happen the the MAC -
//
// unban_array = any MAC's which have expired their bantime and need to be unbanned
// banned_array = any MAC's which havent expoired their bantiume and dont need any
//                   processing this script execution
// ban_array = new MAC's to be banned
//
// Actual unbanning/banning is done in upstream functions to hopefully make code more
// manageable/maintainable

function process_valid_macs($query_array, $ref_array) {
    // we need some variable set be global for this to work...
    global $ban_time;
    global $script_schedule;
    global $unban_array;
    global $banned_array;
    global $ban_array;
    // create new array for MACs to unban $unban_array
    $unban_array  = array();
    // create new array for MAC's that need to stay banned for now
    $banned_array = array();
    // create new array for MAC's to be banned
    $ban_array    = array();
    // convert array contents to lower case
    $ref_array    = array_map('strtolower', $ref_array);
    // iterate through array pulling out $value (MAC) from $query_array
    foreach ($query_array as $key => $value) {
        // check to see if MAC exists  in $ref_array
        $results = in_array($value, $ref_array);
        // if MAC is found
        if ($results == true) {
            // find MACs index
            $index = array_search($value, $ref_array);
            //echo "2:" . $index . "<br>";
            // if index is found
            if ($index !== false) {
                // #check bantime - $index is the timestamp from the key=>value pair
                $ban_time_result = check_bantime($index);
                // if $ban_time is 2 hours or more
                if ($ban_time_result === false) {
                    // MAC is ready to be unbanned
                    // put MAC in unban_array to be unbanned
                    $unban_array[$index] = $ref_array[$index];
                    // search for MAC in $query_array as we want to remove it from there too
                    // we dont want to  ban it again
                    $resultsq            = in_array($ref_array[$index], $query_array);
                    // if MAC is found
                    if ($resultsq == true) {
                        // finds MACs index
                        $indexq = array_search($ref_array[$index], $query_array);
                        // if index is found
                        if ($indexq !== false) {
                            // remove the MAC from the $query_array as we dont want to ban it again!
                            // anything remaining in the $query_array will be soon banned......
                            unset($query_array[$indexq]);
                            // remove the MAC from the $ref_array as we're unbanning it, we didn't
                            // remove it ealier than this, in its own codeblock above as we needed it
                            // to search for the $ref_array[$index] in the $query_array :)
                            unset($ref_array[$index]);
                            continue;
                        }
                    }
                } else {
                    // MAC isnt ready to be unbanned yet...	
                    // check to see if MAC exists  in $ref_array
                    $results = in_array($value, $query_array);
                    // if MAC is found
                    if ($results == true) {
                        // find MACs index
                        $indexq = array_search($value, $query_array);
                        // if index is found
                        if ($indexq !== false) {
                            // add MAC to $banned_array	
                            $banned_array[$indexq] = $value;
                            // remove MAC from $query_array 
                            unset($query_array[$indexq]);
                            continue;
                        }
                    }
                }
            }
        }
        // MAC's arriving here are evaluated for banning
        // if the MAC arriving here is newer than script runtime - $script_schedule, then its eligible for banning
        if ($key >= strtotime('-' . $script_schedule, time())) {
            // add MAC to $ban_array
            $ban_array[time()] = $value;
        }
    }
}


// function to check whether the timestamp fed to it is equal to or greater than $ban_time
function check_bantime($timestamp) {
    global $ban_time;
    if ($timestamp >= strtotime('-' . $ban_time)) {
        // less than 2 hour
        return true;
    } else {
        //more than 2 hours;
        return false;
    }
}


///////////////////////////////////
//////// Functions (End) //////////
///////////////////////////////////



///////////////////////////////////
//////// Main Code (Start) ////////
///////////////////////////////////


// opening html header
$message = "<html><body><h3><b>Script running from:</b></h3><p>" . $script_path;

// reset log if older than $log_reset
if (file_exists($log_file)) {
    if (filemtime($log_file) < strtotime('-' . $log_reset)) {
        unlink($log_file);
    }
}


/////////////////////
// update oui file //
/////////////////////

// oui URL  - from Wireshark
$oui_url = 'https://code.wireshark.org/review/gitweb?p=wireshark.git;a=blob_plain;f=manuf;hb=HEAD';
// temp oui file
$oui_file_temp = $script_path . "manuf.txt";

// download oui file if older than ....
$oui_file_check_schedule = "1 week";

if (!file_exists($oui_file_temp)) {
    // download the latest oui list
    $download_oui = curl_download($oui_url, $oui_file_temp);
    if ($download_oui == 200) {
        $message .= "<h3><b>OUI file updated successfully</b></h3><p>";
        write_to_log($log_file, $oui_file . " updated successfully");
    } else {
        $message .= "<h3><b>OUI file update FAILED  </b></h3><p>";
        write_to_log($log_file, $oui_file . " updated FAILED");
    }
} else {
    $oui_file_date = filemtime($oui_file_temp);
    // do the math to figure out if we need to download a newer oui file
    if ($oui_file_date > strtotime('+' . $oui_file_check_schedule, time())) {
        // download the latest oui list
        $download_oui = curl_download($oui_url, $oui_file_temp);
        if ($download_oui == 200) {
            $message .= "<h3><b>OUI file updated successfully</b></h3><p>";
            write_to_log($log_file, $oui_file . " updated successfully");
        } else {
            $message .= "<h3><b>OUI file update FAILED  </b></h3><p>";
            write_to_log($log_file, $oui_file . " updated FAILED\n");
            
        }
    } else {
        $message .= "<h3><b>OUI file not updated - not needed</b></h3><p>";
        write_to_log($log_file, $oui_file . " not updated - not needed");
        
    }
}

// search $oui_file_temp for "Ubiquiti" entries and output them into $oui_file
$search      = "Ubiquiti";
$matches     = array();
$ouifiletemp = @fopen($oui_file_temp, "r");
if ($ouifiletemp) {
    while (!feof($ouifiletemp)) {
        $buffer = fgets($ouifiletemp);
        if (strpos($buffer, $search) !== FALSE)
            $matches[] = substr($buffer, 0, 8);
    }
    $ouifile = fopen($oui_file, "w");
    foreach ($matches as $value) {
        fwrite($ouifile, $value . "\n");
    }
    fclose($ouifiletemp);
    fclose($ouifile);
}


// get all known ubiquiti ouis from text file and into an array
$oui_array = file_to_array($oui_file);
$message .= "<h3><b>Ubiquiti OUI file:</b></h3><p>";
$message .= $oui_file . " read into array successfully<p>";
write_to_log($log_file, "");
write_to_log($log_file, "Ubiquiti file  read into array:");
write_to_log($log_file, $oui_file . " read into array successfully\n");


// create a sites array (using the cryptic siteid from the URL in controller)
// for list of siteids for the $sites variable
$sites_array = explode(",", $sites);


// create a sites_friendly array, using the human friendly site names for logging
$sites_friendly_array = explode(",", $sites_friendly);
$message .= "<h3><b>Sites to be checked: </b></h3><p>";
write_to_log($log_file, "Sites to be checked:");
foreach ($sites_friendly_array as $value) {
    $message .= $value . "<br>";
    write_to_log($log_file, $value);
}


// loop through each site, and run the main script on each....
foreach (array_combine($sites_array, $sites_friendly_array) as $site => $sitefriendly) {
    
    // connection string
    $unifi_connection = new UniFi_API\Client($controller_user, $controller_password, $controller_url, $site, $controller_version);
    
    //$set_debug_mode   = $unifi_connection->set_debug($debug);
    
    // connection result
    $loginresults = $unifi_connection->login();
    
    // pull ips event stats from site
    $ips_array = $unifi_connection->stat_ips_events();
    
    // start per site logging
    $message .= "<p><h3><b>Site:</b> " . $sitefriendly . "</h3><p>";
    write_to_log($log_file, "\n");
    write_to_log($log_file, "Site: " . $sitefriendly);
    
    // name of the log file for holding the banned MAC addresses only - you may need to create
    // this file manually and chmod it to make it writeable (see documentation)
    $banned_mac_log = $script_path . 'bannedmacs_' . $site . '.log';
    
    $message .= "<b>Loading currently banned MAC addresses:</b><p>";
    write_to_log($log_file, "Loading currently banned MAC addresses:");
    if (!file_exists($banned_mac_log)) {
        $message .= "Banned MAC's file does not exist: " . $banned_mac_log . "<br>";
        write_to_log($log_file, "Banned MAC's file does not exist: " . $banned_mac_log);
        $message .= "Creating banned MAC's file: " . $banned_mac_log . "<p>";
        write_to_log($log_file, "Creating banned MAC's file: " . $banned_mac_log);
        $create_banned_macs_file = touch($banned_mac_log);
        if (!file_exists($banned_mac_log)) {
            $message .= "Banned MAC's file does not exist: " . $banned_mac_log . "<br>";
            write_to_log($log_file, "Banned MAC's file does not exist: " . $banned_mac_log);
            exit("Banned MAC's file does not exist: " . $banned_mac_log);
        }
    } else {
        $message .= "Banned MAC's read from: " . $banned_mac_log . "<br>";
        write_to_log($log_file, "Banned MAC's read from: " . $banned_mac_log);
    }
    
    
    // open bannedmacs.log file and populate an array of banned macs
    $banned_macs = ban_file_to_array($banned_mac_log);
    
    
    // show currently banned MAC addresses
    $message .= "<p><b>Currently banned MAC addresses found in banned mac log file:</b> " . count($banned_macs) . "</p>";
    if (sizeof($banned_macs) === 0) {
    } else {
        $message .= "<table border='1' cellpadding='5'>";
        $message .= "<thead><tr><th>MAC Address</th><th>Timestamp</th></tr><tbody>";
        foreach ($banned_macs as $key => $value) {
            $message .= "<tr><td>" . $value . "</td><td> " . nice_time($key) . "</td></tr>";
        }
        $message .= "</table><p>";
    }
    
    
    // check IPS array
    if (empty($ips_array)) {
        $message .= "<b>IPS log found for site: </b> No<p>";
        write_to_log($log_file, "IPS log found for site: No");
        continue;
    } else {
        $message .= "<b>IPS log found for site: </b>Yes<p>";
        write_to_log($log_file, "IPS log found for site: Yes");
    }
    
    
    // filter for any IPS reports which mention emerging-p2p
    $ips_array = array_filter($ips_array, function($obj) {
        if (isset($obj->catname) && $obj->catname === "emerging-p2p") {
            return true;
        }
        return false;
    });
    
    
    // create new blank array for mac addresses which have tripped the emerging-p2p filter
    // which arent blank (ive seen it happen folks) and arent Windows 10 windows update
    // distribution (WUDO) related
    $final_ips_array = array();
    foreach ($ips_array as $item) {
            if (isset($item->catname) && $item->catname === "emerging-p2p") {
            if(isset($item->src_mac)){
                if (filter_var($item->src_mac, FILTER_VALIDATE_MAC) === false || strpos($item->msg, "WUDO") !== false) {
                    continue;
                } else {
                    $final_ips_array[$item->timestamp] = $item->src_mac;
                }
            }
        }
    }
        
    
    // "unique" the array to remove multiple identical mac entries
    $final_ips_array = array_unique(array_filter($final_ips_array));
    
    // sort the array by date (in array key)
    ksort($final_ips_array);
    
    // show the mac addresses
    if (sizeof($final_ips_array) === 0) {
        $message .= "<b>MAC addresses found in emerging-p2p IPS category:</b> 0<p>";
        write_to_log($log_file, "MAC addresses found in emerging-p2p IPS category: 0");
        continue;
    } else {
        $message .= "<b>MAC addresses found in emerging-p2p IPS category:</b> " . count($final_ips_array) . "<p>";
        write_to_log($log_file, "MAC addresses found in emerging-p2p IPS category: " . count($final_ips_array));
        
        $message .= "<table border='1' cellpadding='5'>";
        $message .= "<thead><tr><th>MAC Address</th><th>Timestamp</th></tr><tbody>";
        foreach ($final_ips_array as $key => $value) {
            $message .= "<tr><td>" . $value . "</td><td> " . nice_time($key) . "</td></tr>";
            write_to_log($log_file, $value . "");
        }
        $message .= "</table><p>";
    }
    

    // compare MAC's from IPS array ($final_ips_array) against known Ubiquiti MAC's in $oui_array
    $mac_array = compare_oui_mac_arrays($final_ips_array, $oui_array);
	if (sizeof($mac_array) !== 0) {
    $message .= "<p><b>Bannable MAC addresses found in emerging-p2p IPS category:</b> " . count($mac_array) . "<p>";
    write_to_log($log_file, "Bannable MAC addresses found in emerging-p2p IPS category: " . count($mac_array));
    $message .= "<table border='1' cellpadding='5'>";
    $message .= "<thead><tr><th>MAC Address</th><th>Timestamp</th><tr><tbody>";
    foreach ($mac_array as $key => $value) {
        write_to_log($log_file, $value . "");
        $message .= "<tr><td>" . $value . "</td><td> " . nice_time($key) . "</td></tr>";
    }
    $message .= "</table><p>";
    }
    
    // process the parsed and checked $mac_array by comparing it to the $banned_macs array
    // the process_valid_macs fcuntion categorizes the MAC's into one of 3 arrays:
    //
    // $banned_macs - previously banned MAC's that havetn reached ban expiry yet
    // $unban_macs - MAC's that have reached ban expiry and need to be unbanned
    // $ban_macs - new MAC's that need to be banned
    process_valid_macs($mac_array, $banned_macs);
        
    
    
    ////////////////////////////////
    // script output starts here //
    ///////////////////////////////
    
    
    // here we show EXISTING bans
    
    if (!empty($banned_macs)) {
        $message .= "<p><b>Existing banned MACs:</b> <p>";
        $message .= "<table border='1' cellpadding='5'>";
        $message .= "<thead><tr><th>MAC Address</th><th>Banned at</th><th>Banned Until</th></tr><tbody>";
        write_to_log($log_file, "Existing banned MACs: ");
        foreach ($banned_macs as $key => $value) {
            $message .= "<tr><td>" . $value . "</td><td> " . nice_time($key) . "</td><td>" . nice_unban_time($key) . "</td></tr>";
            write_to_log($log_file, "MAC: " . $value . " Banned at: " . nice_time($key) . " Banned Until: " . nice_unban_time($key));
        }
        $message .= "</table>";
    }
    
    
    // here we UNBAN existing banned MAC's
    
    if (!empty($unban_array)) {
        global $send_email;
        $message .= "<p><b>Unbanned MACs: <p></b>";
        $message .= "<table border='1' cellpadding='5'>";
        $message .= "<thead><tr><th>MAC Address</th><th>Banned at</th><th>Unbanned at</th></tr><tbody>";
        write_to_log($log_file, "Unbanned MAC's:");
        foreach ($unban_array as $key => $value) {
            $result = $unifi_connection->unblock_sta($value);
            switch ($result) {
                case true;
                    $message .= "<tr><td>" . $value . "</td><td> " . nice_time($key) . "</td><td>" . nice_time(time()) . "</td></tr>";
                    write_to_log($log_file, "MAC: " . $value . " Banned at: " . nice_time($key) . " Unbanned at: " . nice_time(time()));
                    // variable to let the email routine know to send emai as soemthig has happened - emails only sent when a MAC is banned/unbanned
                    // otherwise we'd get an email every time the script ran, regardless of anything happening, and that would be annoying!
                    $send_email = "y";
                    // search $unban_array for MAC
                    $index      = array_search($value, $unban_array);
                    // if index is found (should be:))
                    if ($index !== false) {
                        // remove from $unban_array	
                        unset($unban_array[$index]);
                        // sleep added to troubleshoot some MAC's not being unbanned at times,
                        // theory being that this was only happening when multiple MAC's were
                        // being unbanned, despite the return code from unblocksta(), the MAC's
                        // *may* have been being processed too quickly for the controller to have
                        // time to process after the return code was issued
                        sleep(5);
                    }
                    break;
                case false;
                    $message .= "<tr><td>" . $value . "</td><td></td><td>FAILED TO BE UNBANNED</tr>";
                    write_to_log($log_file, $value . " - FAILED TO BE UNBANNED");
                    break;
            }
        }
        $message .= "</table>";
        // write the array of banned MAC's, minus the the unbanned MAC's, back to the banned_mac_log file
        $write_unbanned_macs = write_banned_macs($banned_mac_log, $unban_array);
    }
    
    
    // here we create NEW bans
    
    if (!empty($ban_array)) {
        global $send_email;
        $message .= "<p><b>Newly Banned MACs:</b> <p>";
        $message .= "<table border='1' cellpadding='5'>";
        $message .= "<thead><tr><th>MAC Address</th><th>Banned at</th><th>Banned Until</th></tr><tbody>";
        write_to_log($log_file, "Banned MAC's:");
        // uncomment the commented lines below to actually ban the mac address(es)
        foreach ($ban_array as $value) {
            // the line below is the actual line of code that does the ban
            $result = $unifi_connection->block_sta($value);
            switch ($result) {
                case true;
                    $message .= "<tr><td>" . $value . "</td><td> " . nice_time(time()) . "</td><td>" . nice_unban_time(time()) . "</td></tr>";
                    write_to_log($log_file, "MAC: " . $value . " Banned at: " . nice_time(time()) . " Banned until: " . nice_unban_time(time()));
                    // variable to let the email routine know to send emai as soemthig has happened - emails only sent when a MAC is banned/unbanned
                    // otherwise we'd get an email every time the script ran, regardless of anything happening, and that would be annoying!
                    $send_email = "y";
                    // sleep added to troubleshoot some MAC's not being unbanned at times,
                    // theory being that this was only happening when multiple MAC's were
                    // being unbanned, despite the return code from unblocksta(), the MAC's
                    // *may* have been being processed too quickly for the controller to have
                    // time to process after the return code was issued
                    sleep(5);
                    break;
                case false;
                    $message .= "<tr><td>" . $value . "</td><td></td><td>FAILED TO BE BANNED</tr>";
                    write_to_log($log_file, $value . " - FAILED TO BE BANNED");
                    break;
            }
        }
        $message .= "</table>";
        // merge $banned_array and $ban_array together to give us the complete list of banned MAC's
        $merged_bans       = $banned_array + $ban_array;
        // write the banned MAC's to $banned_mac_log
        $write_banned_macs = write_banned_macs($banned_mac_log, $merged_bans);
    }
    
    
}

//closing html footer
$message .= "</body></html>";


// show html report on screen
echo $message;


// send email report if set
switch ($report_email) {
    case "yes";
        if ($send_email == "y") {
            mail($to_email, $subject, $message, $headers);
        }
        break;
    case "no";
        break;
}

###################################
######### Main Code (End) #########
###################################
?>
