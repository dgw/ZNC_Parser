<?php
/*	
This is a wrapping script, designed to be called as a cron job or other such repetitive caller.
It will scan the given directory for any log files not yet uploaded and add them to the database.

args: (order not important)
	[-dir <directory to search>]	The directory from which to begin the search
	[-r [<level_of_recursion>] ]	Recursive search: looks in all sub directories aswell, the optional "recursion level" param can be set to limit how deep it searches.
	[-mysql <USER> <PASSWORD>]		the user name and pwd (this is passed onto the log_to_db script)
	[-db <name_of_database>]		The databse where the logs will be stored.
	[-times]						Shows how long the various operations took
	
	
example call: php ZNC_Daily_parse.php -dir /home/<user>/.znc/users/<IRC_User>/moddata/log/Rizon -mysql <usr> <pwd> -r -db <Database_name>
*/

//PROCESS INPUT ARGS
$script_name = $argv[0];	//how the script was called
for($x=1;$x<$argc;$x++)
{
	switch($argv[$x])
	{
		default:
			break;
			
		case "-dir":
			$dir = $argv[$x+1];
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
			
		case "-r":
		case "-R":
			if(is_numeric($argv[$x+1]))
			{
				$recursion_level = $argv[$x+1];
				$x++;
			}
			else
				$recursion_level=1;
			break;
			
			
		case "-debug":
			$debug = true;
			break;
		case "-times":
			$display_time= true;
			break;
	}
}

//Get a list of all the log files:
$log_files_on_system = List_All_Logs($dir,$recursion_level);
echo "Found ".count($log_files_on_system)." log files in $dir\n";

//FOR TESTING PURPOSES
/*
echo "For testing purposes, this script is limited to 20 files\n";
$log_files_on_system = array_slice($log_files_on_system,0,20);	//let's only do 20 files.
*/

$parsed_file_list = retreiveParsedFilesList();

$parse_count=0;	//This is used to checkpoint long batches.
$script_timer = microtime(true);	//Start of script timing
foreach($log_files_on_system as $log_file)
{
	//check for any files that were modified since their last parse, or which don't have a listing.
	$i = array_search($log_file, $parsed_file_list["files"]);
	if($i!==false)
	{
		//File exists in list, but has it been modified since last time?
		if($parsed_file_list["mod"][$i]<=filemtime($dir."/".$log_file))
			$parse=true;
		else
			$parse=false;
	}
	else
	{
		$parse=true;
		unset($i);
	}
	//Actually parse it:
	if($parse)
	{
		//Determine the channel for the log (assuming ZNC structure, this is the directory above the file)
		$channel = basename(substr($log_file, 0, -1*strlen( basename($dir."/".$log_file) ) ) );
		
		//run the parse script:
		echo "Parsing $dir/$log_file into channel '$channel'\t";
		$time_start = microtime(true);
		echo exec("php ZNC_Log_to_DB.php -i \"$dir/$log_file\" -mysql $mysql[usr] $mysql[pwd] -db $mysql[db] -channel \"$channel\"");
		if($display_time)
			echo "\t".(microtime(true)-$time_start)."s";
		echo "\n";
		
		
		$parse_count++;	//increment amount of files parsed.
		if($parse_count>20)	//if we've parsed 20 files, update log file as a checkpoint.
		{
			echo "\t20 files parsed. Checkpointing.\n";
			$parse_count = 0;
			if(isset($i))	//if file was already in the array
				$parsed_file_list["mod"][$i] = time();
			else
			{
				$parsed_file_list["files"][] = $log_file;
				$parsed_file_list["mod"][] = time();
			}
			storeParsedFilesList($parsed_file_list);
		}
			
	}
	elseif($debug)
		echo "'$log_file' was already in the database\n";
	
	//Log result:
	if(isset($i))	//if file was already in the array
		$parsed_file_list["mod"][$i] = time();
	else
	{
		$parsed_file_list["files"][] = $log_file;
		$parsed_file_list["mod"][] = time();
	}
}
//Store the list of parsed files again:
storeParsedFilesList($parsed_file_list);
if($display_time)
	echo "Script took ".(microtime(true)-$script_timer)."seconds to complete\n";

echo "---- Script finished ----\n\n";



//--------------------
//LOCAL FUNCTIONS
//--------------------

function List_All_Logs($init_directory,$recursionLimit="5")
//Purpose:	Gets the list of all log files found in the directory tree
//Inputs:	The initial directory to start from, a limit on how far down to traverse the directory (prevents infinite recursion)
{
	$top = scandir($init_directory);
	//remove the . and .. instances:
	unset($top[array_search('.',$top)]);
	unset($top[array_search('..',$top)]);
	//reindex:
	$top = array_values($top);
	
	//Check for sub_directories
	foreach($top as $i => $entry)
	{
		$path = $init_directory.'/'.$entry;
		if(is_dir($path) && $recursionLimit>0)
		{		
			unset($top[$i]);
			foreach( List_All_Logs($path,$recursionLimit-1) as $file_path)
			{
				if(strcmp(substr($file_path, -4),".log")==0)	//only list the .log files.
					$top[]=$entry.'/'.$file_path;
			}
		}
		else
		{
			if(strcmp(substr($path, -4),".log")!=0)	//only list the .log files.
				unset($top[$i]);
		}
	}
	return $top;
}

//Data storage functions
	/*For reference, the array is structures as array("files"=>array(),"mod"=>array()), where indexes are matched. This is array_searching.*/
function storeParsedFilesList($files_parsed, $file_name="ParsedLogs.ini")
//Purpose:	Stores the given array of files parsed by the script.
{
	//open the file (create if needed)
	$handle = fopen($file_name, "w+");
	if($handle===false)
		return false;
	
	//write the data to the file
	file_put_contents($file_name, serialize($files_parsed));
	
	return true;

}

function retreiveParsedFilesList($file_name="ParsedLogs.ini")
//Purpose:	Get a list of the files already added to the database and when they were added.
{
	//at a later point, you can convert it back to array like
	if(!($recoveredData = @file_get_contents($file_name)))
		return array("files"=>array(),"mod"=>array());
	
	//debug
	file_put_contents("Text.txt", print_r(unserialize($recoveredData),true));
	
	return unserialize($recoveredData);
}
?>