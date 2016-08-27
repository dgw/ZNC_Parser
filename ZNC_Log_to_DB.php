<?php
/*	
This script utilises Chrolo's ZNC parser system to add ZNC log files to a database, which can be used later for easier viewing/searching.

args: (order not important)
	[-i <file>] 
	[-mysql <USER> <PASSWORD>]
	[-db <name_of_database>]
	[-channel <channel_name>] 
	[-date < "YYYY-MM-DD" (if different from file name)>]
	
	example:	ZNC_Log_to_DB -i 2016-02-26.log -mysql root password -channel "#DameDesuYo" -db ZNC_Database
				ZNC_Log_to_DB -i oldLog.log -channel "#Christmas_Chan" - date 2004-12-25  -mysql root password 
*/

include "inc/LogParser.php";

//PROCESS INPUT ARGS
$script_name = $argv[0];	//how the script was called
for($x=1;$x<$argc;$x++)
{
	switch($argv[$x])
	{
		default:
			break;
			
		case "-i":
			$log[file_path] = $argv[$x+1];
			$x++;
			break;
			
		case "-mysql":
			$mysql[usr] = $argv[$x+1];
			$mysql[pwd] = $argv[$x+2];
			$x+=2;
			break;
		case "-db":
			$mysql[db] = $argv[$x+1];
			$x++;
			break;
		
		case "-channel":
			$log[channel] = $argv[$x+1];
			$x++; 
			break;
		case "-date":
			if(preg_match("|\d{4}[-_/]\d{2}[-_/]\d{2}|", $argv[$x+1], $res))
			{
				$log["date"] = $res[0];
				$x++;
			}
			else
				echo "Date format is incorrect. Please use YYYY-MM-DD.\n";
			
			break;
			
		case '-validate':
			$validate = true;
			break;
			
		case '--help':
		case '-h':
			$help = true;
		break;
	}
}

if( ($help) || ($argc < 2) )
{
	$helpStr = <<<helptext

This script utilises Chrolo's ZNC parser system to add ZNC log files to a database, which can be used later for easier viewing/searching.

args: (order not important)
	[-i <file>] 
	[-mysql <USER> <PASSWORD>]
	[-db <name_of_database>]
	[-channel <channel_name>] 
	[-date < "YYYY-MM-DD" (if different from file name)>]
	
	example:	ZNC_Log_to_DB -i 2016-02-26.log -mysql root password -channel "#DameDesuYo" -db ZNC_Database
			ZNC_Log_to_DB -i oldLog.log -channel "#Christmas_Chan" - date 2004-12-25  -mysql root password 
helptext;

	echo $helpStr."\n";
	exit;
}






//attempt db connection:
$mysql[con] = new mysqli("localhost", $mysql[usr], $mysql[pwd], $mysql[db]);
if ($db_connection->connect_errno)
{
	echo "Failed to connect to MySQL: " . $db_connection->connect_error;
	exit;
}
//get file name from path:
$log[file_name] = basename($log[file_path]);

//load this file into the parser:
$log_c = new ZNC_Log_Parser($log[file_path]);


//determine unix time stamp for file:
if(!isset($log["date"]))
{
	preg_match("|(\d{4}-\d{2}-\d{2})|",$log[file_name],$reg_matches);
	$log["date"] = $reg_matches[0];
}
$log_c->setUnixTimestamp(strtotime($log["date"]));

//display the options given:
echo "Received the following parameters:\n";
foreach( array("InputFile"=>$log[file_path],"MysqlUser" => $mysql[usr], "Database"=>$mysql[db], "Table"=> $log[channel], "Log file date"=>$log["date"] ) as $key => $val)
{
	echo "$key = $val\n";
}


//Actually parse it:
$log[as_array] = $log_c->parse_ZNC_log();

//Creat New IRC database class to store this data
$IRC_Database = new MyIRCSQL($mysql[con],$log[channel]);
//add lines to table/database:
echo "Adding lines to database (this may take a little while)\n";
$count= $IRC_Database->AddLinesToDatabase($log_c->getLog_Array());
if($IRC_Database->checkTableForDuplicates(true))
	echo "\tThe process has added duplicates, but was unable to remove them.";

echo "----------FINISHED--------\n$count lines have been added to the $log[channel] table.\n";
?>
