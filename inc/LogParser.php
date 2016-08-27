<?php
class ZNC_Log_Parser {
	/*
		Authour:	Chrolo
		Purpose:	A Class to read and process ZNC logs, primarily for later display in a webpage like scenario.
	*/
	
	
	/** Class variables **/
	private $highlight_name = array();
	private $user_Highlight_exclusions = array();
	private $unix_time_stamp_offset = false; //the unix timestamp for the day of the log.
	
	
	/*Log file variable*/
	private $log_file_path;
	public $log_file = false;
	
	//Line data
	public $line_classes = array();
	public $line_count;
	
	//--------------------------
	// Constructor
	//--------------------------
	function __construct($file_path = "") 
	{
		//init file path:
		$this->setFilepath($file_path);		
	}
	
	//--------------------------
	// Parser
    //--------------------------
	function parse_ZNC_log()
	//Purpose:
	{
		//Make sure file is open
		if($this->log_file === false)
		{
			throw new Exception('Log file not instantiated.');
		}
		
		//Start file parse:
		
		//Split by newlines:
		$exploded_file = explode("\n",$this->log_file);
			
		$this->line_count = 0;
		foreach ($exploded_file as $line)
		{
			$line = trim($line);
			//disregard empty lines
			if($line!="")
			{
				//make new line_class
				$this->line_classes[$this->line_count] = new ZNC_Log_Line($line);		
				
				if(!empty($this->highlight_name))	//Set highlight
					$this->line_classes[$this->line_count]->setHighlights($this->highlight_name);

				if($this->unix_time_stamp_offset!== false)	//Set timestamp if not false
					$this->line_classes[$this->line_count]->setTimestamp($this->unix_time_stamp_offset);
				
				//update line_count
				$this->line_count++;
			}
		}
		
		//return the line count
		return $this->line_count;
	}
	
	
	//--------------------------
	// Parser Wrappers
	//--------------------------
	function getLog_HTMLTable()
	//Get the log file as HTML enities
	{
		$html = "<table>";
		
		for($i = 0;($i < $this->line_count);$i++)
		{
			$html .= "<tr";
			if($this->line_classes[$i]->flags[highlight])
				$html .=' style="color:green;" ';
			$html .=">";
			
			$html .= "<td>".date("Y-m-d H:i:s",$this->line_classes[$i]->unix_timestamp)."(".$this->line_classes[$i]->timestamp.")</td>";
			$html .= "<td>".$this->line_classes[$i]->user."</td>";
			$html .= "<td>".$this->line_classes[$i]->getHTMLFormattedText()."</td>";

			$html .= "</tr>\n";
		}
		
		$html .= "</table>";
		
		return $html;
	}
	
	function getLog_Array()
	//Purpose:	Get the log file as an array of values
	{
		$temp = array();
		for($i=0;$i < $this->line_count;$i++)
		{
			$temp[$i] =	$this->line_classes[$i]->getDataAsArray();
		}
		return $temp;
	}
	
	function reOpenLog()
	//Purpose:	Reopen the previously specified log file.
	{
		if(file_exists($this->log_file_path))
		{
			$this->log_file = file_get_contents($this->log_file_path);
			if($this->log_file === false)
			{
				throw new Exception('File '.$this->file_path.' couldn\'t be opened');
			}
		}
		else
		{
			throw new Exception('File '.$this->log_file_path.' couldn\'t be found and/or opened');
		}
	}
	//------------------------------
	/** Class variable setters **/
	//-----------------------------
	
	function setUsername($names,$COPY_TO_HIGHLIGHTS = true)
	//Purpose:	The username (or names) set here will not generate highlights (You can't highlight yourself)
	//If "COPY_TO_HIGHLIGHTS" is true, the values given are also copied over the highlights. 
	{
		if(!is_array($names))
			$names = array($names);
		
		$this->user_Highlight_exclusions = $names;
		
		if($COPY_TO_HIGHLIGHTS)
			$this->setHighlights($names);
	}
	
	function setHighlights($names)
	//Purpose: Set patterns of text to be used for highlights
	{
		//Make sure it's wrapped into an array
		if(!is_array($names))
			$names = array($names);
		
		$this->highlight_name = $names;
	}
	
	
	function setUnixTimestamp($unix_time)
	//Purpose:	Set the unixTimestamp from the beginning of this file. ie/ if the log file is for 12th March 2012.
	//Note:		You must pass it a unix time stamp, not a string representing a date.
	{
		$this->unix_time_stamp_offset = $unix_time;
	}
	
	
	function setFilepath($file_path)
	{
		if(file_exists($file_path))
		{
			$this->log_file = file_get_contents($file_path);
			if($this->log_file === false)
			{
				throw new Exception('File '.$this->file_path.' couldn\'t be opened');
			}
		}
		else
		{
			throw new Exception('File '.$file_path.' couldn\'t be found/opened');
		}
		//add file path to globals 
		$this->log_file_path = $file_path;
	}
}

class ZNC_Log_Line
{
	//Purpose:	A class to extract and contain data from lines of a ZNC log.
	
	/** Class variables **/
	
	//Line data:
	public $raw_line;		// raw line text
	public $text;
	public $timestamp;		// timestamp of line
	public $unix_timestamp;	//unix time stamp of the line (only valid if offset is set)
	public $user;			// who the line was from
	public $type;			//	type of line (chat, sys_msg, action)
	public $flags = array( "highlight" => false, "system" => false);
	
	
	//parsing data
	private $highlights = array();
	private $user_highlight_exclusions = array();
	private $unix_time_stamp_offset = false;	//used to set a full date/time stamp for the line
	
	/** Constructor: **/
	function __construct($raw_line_input) 
	{
		//put raw text into class variable:
		$this->raw_line = $raw_line_input;
		
		//Parse the line
		$this->line_parser();
	}
	
	//Processing functions
	function line_parser()
	{
		//Get timestamp of line:
		preg_match("|\[(\d\d:\d\d:\d\d)\]|",$this->raw_line,$matches);
		if(isset($matches[1]))
			$this->timestamp = $matches[1];
		
		$this->determine_unix_timestamp();
		
		//Determine type of line
			// 'chat', 'sys_msg', '/me', ,'priv_msg'
		if(substr($this->raw_line,11,1) == '*') //system messages start with '***' and '\me's have '*' prefixes
		{
			if(substr($this->raw_line,12,1) == '*')
				$this->type = 'sys_msg';
			else
				$this->type = 'action';
		}
		elseif(substr($this->raw_line,11,1) == '-')
		{	//
			$this->type = 'priv_msg';
		}
		else // '<'
			$this->type = 'chat';	//default to assuming it's a chat message
		
		//Determine user (if applicable)
		if( $this->type == 'chat' )
		{
			//here i'm assuming you can't have a username with '>' in it. This *might* not be a good assumption.
				//seems to hold up: tried joining with nickname with '>' and was refused.
			preg_match("|<([^>]+?)>|",substr($this->raw_line,11),$matches);
			$this->user = $matches[1];
		}
		elseif( $this->type == 'priv_msg')
		{
			preg_match("|-([^\s]+?)-|",substr($this->raw_line,11),$matches);
			$this->user = $matches[1];
		}
		elseif( $this->type == 'action')
		{
			preg_match("|([\S]+)|",substr($this->raw_line,13),$matches); //matches till first whitespace
			if(isset($matches[1]))
				$this->user = $matches[1];
		}
		
		//Get line text:
		switch($this->type)
		{
			default:
				$this->text = substr($this->raw_line,11);	//default to the line minus timestamp
			break;
			
			case 'chat':
			case 'priv_msg':
				$this->text = substr($this->raw_line,12+(strlen($this->user)+2));	//start from just after the user name.
			break;
			
			case 'action':
				$this->text = substr($this->raw_line,13);	//timestamp + space + '*'
			break;
			
			case 'sys_message':
				$this->text = substr($this->raw_line,11);
			break;
			
		}
		
		
		//Check for a highlight:
		$this->check_for_highlights();
		
		
	}
	
	//sub parsers
	private function determine_unix_timestamp()
	{
		if($this->unix_time_stamp_offset)
		{
			$this->unix_timestamp = strtotime($this->timestamp) - strtotime(date("Y-m-d")) + $this->unix_time_stamp_offset;
		}
	}
	
	private function check_for_highlights()
	{
		$this->flags['highlight'] = false;
		
		if( ($this->type == 'chat' || $this->type == 'action' )&&(array_search($this->user, $this->user_highlight_exclusions)!==false) )	//only highlight chat or actions that aren't by the user.
		{			
			foreach($this->highlights as $hl)
			{
				if ( preg_match("|".preg_quote($hl)."|", $this->text) && ($this->user != $hl) )	//matches instances of highlight
				{
					$this->flags['highlight'] = true;
				}
			}
		}
	}
	
	
	//Parse parameter modifiers
	function setHighlights($highlights)
	{
		//Make sure it's wrapped into an array
		if(!is_array($highlights))
			$highlights = array($highlights);
		
		//set line highlights
		$this->highlights = $highlights;	
		
		//re-parse line:
		$this->check_for_highlights();
	}
	
	function setUser($names)
	{
		//Make sure it's wrapped into an array
		if(!is_array($names))
			$names = array($names);
		
		//set line highlight exclusions
		$this->user_highlight_exclusions = $names;	
		
		//re-parse line:
		$this->check_for_highlights();
	}
	
	function setTimestamp($unix_stamp)
	//Purpose:	Set class parameter "$unix_time_stamp_offset" used to set a full unix timestamp.
	{
		//Set the offset:
		$this->unix_time_stamp_offset = $unix_stamp;
		//run the timestamp parser:
		$this->determine_unix_timestamp();
	}
	
	function setDateOffset($date)
	//
	{
		$this->setTimestamp(strtotime($date));
	}
	
	/***************************/
	//Data retreival functions://
	/***************************/
	function getDataAsArray()
	//Purpose:	Returns the line data as an array of values
	{
		$table = array();
		$table['raw_line']			= $this->raw_line;			// raw line text
		$table['text'] 				= $this->text;				// 
		$table['timestamp']			= $this->timestamp;			// timestamp of line
		$table['unix_timestamp']	= $this->unix_timestamp;	// The unix timestamp of line
		$table['user']				= $this->user;				// who the line was from
		$table['type']				= $this->type;				//	type of line (chat, sys_msg, priv_msg, etc)
		$table['flags']				= $this->flags;				// Line flags: is this a highlight, etc
		
		return $table;
	}
	
	
	function getHTMLFormattedText()
	//Purpose:	output a version of the line with control characters replaced with appropriate HTML tags
	//returns:	str.
	{
		//$control_replace=array(''=>"bold", ''=>"colour", ''=>"underline", '' => "italics", '' => "override");
		$control_replace = array(
			"bold"=>		array("code" => chr(2),		"start" => "<b>",	"end" => "</b>"	), 
			"underline"=>	array("code" => chr(31),	"start" => "<u>",	"end" => "</u>"	), 
			"italics" =>	array("code" => chr(29),	"start" => "<i>",	"end" => "</i>"	), 
			"override"=>	array("code" => chr(15),	"start" => "|Override|",	"end" => "|#Override|"	),
			"colour"=>		array("code" => chr(3),		"start" => "<span style=\"color:#PLACEHOLDER#;\">",	"end" => "</span>"	)
		);
		
		//copy of line text
		$line = htmlspecialchars($this->text);

		//sort out special characters:
		foreach( $control_replace as $key => $val)
		{
			//explode by character:
			$line_split = explode($val["code"],$line);
			
			//insert the new tags:
			$temp = $line_split[0];
			$flag = false ;
			for($i=1 ; $i<count($line_split) ; $i++)
			{
				if(!$flag) // start tag
				{
					$temp.= $val["start"]; //temp version
					$flag = true;
				}
				else	//end tag
				{
					$temp.= $val["end"]; //temp version
					$flag = false;
				}
				
				//add next part of string
				$temp .= $line_split[$i];
			}
			//close any unfinished tags:
			if($flag)
				$temp .= $val["end"]; //temp version
			
			//update the line:
			$line = $temp;
		}
		
		return $line;
	}
}

class MyIRCSQL
{
	//Purpose:	This handles all the SQL interactions for the ZNC log database.
	
	//class variables:
	private $SQL_con = false;
	private $table_name = "znc_logs";	//defaults to "znc_logs"
	//Database Structure:
	private $DB_struct = array(
		//'key' => array("desc"=>"", "type"=>"","properties"=>""),
		'id' =>				array("desc"=>"Primary key", "type"=>"primary_key","properties"=>"UNIQUE"),
		'raw_line' => 		array("desc"=>"Raw Line text", "type"=>"text","properties"=>""),
		'text' => 			array("desc"=>"Text for the line", "type"=>"text","properties"=>""),
		'unix_timestamp' => array("desc"=>"Timestamp for the line in 'YYYY-MM-DD HH:MM:SS[.fraction]'", "type"=>"TIMESTAMP", "properties"=>""),
		'user' =>			array("desc"=>"Person the line is about", "type"=>"text","properties"=>""),
		'type' =>			array("desc"=>"Type of line (chat,sys_msg,etc)", "type"=>"text","properties"=>""),
	);
	
	private $highlight_name = array();
	private $user_Highlight_exclusions = array();
	
	/** Constructor: **/
	function __construct($SQL_Connection,$table_name="") 
	{
		//Check and store the connection.
		if($mysqli->connect_error)
		{
			throw new Exception("The MYSQL Connection given was not valid.");
		}
		else
			$this->SQL_con = $SQL_Connection;
		
		//get Table_name
		if($table_name!=""&&is_string($table_name))
			$this->table_name = $table_name;
		
		//check if a valid table exists.
		if(!$this->checkTableValidity())
		{
			$this->createTable();	//create the table.
		}
		
	}
	//-----------------------------
	// Parser Options setters:
	//-----------------------------
	function setTable($table)
	{
		$this->table_name=$table;
	}
	

	
	function setUsername($names,$COPY_TO_HIGHLIGHTS = true)
	//Purpose:	The username (or names) set here will not generate highlights (You can't highlight yourself)
	//If "COPY_TO_HIGHLIGHTS" is true, the values given are also copied over the highlights. 
	{
		if(!is_array($names))
			$names = array($names);
		
		$this->user_Highlight_exclusions = $names;
		
		if($COPY_TO_HIGHLIGHTS)
			$this->setHighlights($names);
	}
	
	function setHighlights($names)
	//Purpose: Set patterns of text to be used for highlights
	{
		//Make sure it's wrapped into an array
		if(!is_array($names))
			$names = array($names);
		
		$this->highlight_name = $names;
	}
	
	//-----------------------------------
	// Database checking functions
	//-----------------------------------
	
	private function checkTableValidity()
	//Purpose:	Checks if the table exists and contains the correct fields.
	{
		//check if the table already exists and that correct collumns are present
		$check=true;
		foreach($this->DB_struct as $key => $val)
		{
			$col_exists = mysqli_query($this->SQL_con, "DESCRIBE `".$this->table_name."` ".$key.";");
			$check &= $col_exists;
			if(!$col_exists)
				echo "'$this->table_name' was missing '$key' field.\n";	//trigger_error("'$this->table_name' was missing '$key' field.",E_USER_WARNING);
		}
		return $check; //True if table exists and have correct fields, False if table doesn't exist or has missing fields. 
	}
	
	private function createTable()
	//Purpose:	Create the table to store data.
	{
		if(mysqli_query($this->SQL_con,"DESCRIBE `".$this->table_name."`;"))//if the table exists
		{	
			//delete table first:
			$this->deleteTable();
		}
		
		//create SQL query for table creation
		$sql='CREATE TABLE `'.$this->table_name.'` ( ';
		foreach($this->DB_struct as $key => $val)
		{
			$sql.=$key.' ';
			if($val[type]=='primary_key')
			{
				$sql.=' INT( 11 ) NOT NULL AUTO_INCREMENT, ';
				$sql_end='PRIMARY KEY ('.$key.') );';
			}
			elseif($val[type]=='TIMESTAMP')
			{
				$sql.='TIMESTAMP, ';
			}
			elseif($val[type]=='INT(11)')
			{
				$sql.= 'INT(11)';
			}
			else	//default to text
			{
				$sql.='TEXT, ';
			}
		}
		$sql.=$sql_end;

		//create table:
		if(!mysqli_query($this->SQL_con,$sql))
		{
			throw new Exception("Table '".$this->table_name."' could not be created.");
		}
	}
	private function deleteTable()
	//Purpose:	Delete the current existence of the table
	{
		if(!mysqli_query($this->SQL_con,"DROP table `".$this->table_name."`;"))
			throw new Exception('Failed to Drop table '.$this->table_name);
	}
	
	//-----------------------------
	// Misc Type handlers
	//-----------------------------
	private function encodeDecodeDBFlagVars($var)
	//purpose encode or decode rows in the database that contain delimited "flags"
	//if the input var is an array, will encode. else it decodes.
	{
			if(is_array($var))
			{
				//encode
				$str_out="";
				foreach($var as $key => $val)
				{
					if($val&&$key!="")
						$str_out.=$this->flag_delimiter.$key.$this->flag_delimiter;
				}
				
				return $str_out;
			}
			else
			{
				//decode
				$array=explode($this->flag_delimiter,$var);
				foreach($array as $val)
				{
					$out_array[$val]=true;
				}
				
				return $out_array;
			}
	}
	
	//-----------------------------
	// Add / Remove entry functions
	//-----------------------------
	private function SQL_sanatize_str($str)
	//sanatises strs for SQL where apostrophe is used as the escape.
	{
		/*
		$str=str_replace("\\","\\\\",$str);
		$str= str_replace("'","\'",$str);
		*/
		return mysqli_real_escape_string($this->SQL_con,$str);
	}
	
	private function checkForExistingLine($line_array)
	//Purpose:	Checks if the data of the line is already on the database
	{
		$res = mysqli_query($this->SQL_con,"SELECT * FROM `$this->table_name` WHERE unix_timestamp='".date("Y-m-d H:i:s",$line_array[unix_timestamp])."' AND raw_line ='$line_array[raw_line]';");
		if($res!==false)
		{
			if(mysqli_num_rows($res)>0)
				return true;
			else
				return false;
		}
		else
			trigger_error("[MyIRCSQL] MySQL query returned false (Invalid response).\n",E_USER_WARNING);
		
	}
	
	function checkTableForDuplicates($delete=false)
	//Purpose:	Does a query to look for (and possibly delete) any duplicate entries in the table
	//Args:		Set delete = true to flush the duplicate entries.
	//Returns:	True if duplicates found. False if no duplicates (or if the duplicates have been deleted).
	{
		//Check for duplicates
		$res= mysqli_query($this->SQL_con,"SELECT raw_line, unix_timestamp, COUNT(*) AS n FROM `".$this->table_name."`  GROUP BY raw_line, unix_timestamp HAVING n>1 ;");
		
		if(mysqli_num_rows($res)>0)
		{
			//Are we deleting them?
			if($delete)
			{
				$success = true;
				//create the select and delete statements:
				$select_statement = $this->SQL_con->prepare("SELECT id FROM `".$this->table_name."` WHERE raw_line= ? AND unix_timestamp= ? ;");
				$delete_statement = $this->SQL_con->prepare("DELETE FROM `".$this->table_name."` WHERE id= ? ;");
				
				for( $i=0; $i < mysqli_num_rows($res);$i++)
				{
					//Details of duplicates:
					$row = mysqli_fetch_array($res);
					//Search for all the duplicates:
					//set params for this select:
					$select_statement->bind_param("ss",$row[raw_line],$row[unix_timestamp]);
					//echo "Statement is \ tSELECT id FROM `".$this->table_name."` WHERE raw_line= '$row[raw_line]' AND unix_timestamp= '$row[unix_timestamp]' ;\n";
					$select_statement->bind_result($id);
					if(!$select_statement->execute())
					{	
						echo mysqli_error ( $this->SQL_con );
						trigger_error("[MyIRCSQL] System reported duplicates, but they couldn't be found.\n",E_USER_WARNING);
						//echo "\tAttempted Query: SELECT id FROM `".$this->table_name."` WHERE raw_line='$row[raw_line]' AND unix_timestamp='$row[unix_timestamp]';\n";
						$success = false;
					}
					else
					{
						$select_statement->store_result();
						echo $select_statement->num_rows." duplicates found\n";
						$select_statement->fetch(); //leave the first result alone
						for( $j=1; $j < $select_statement->num_rows;$j++)	//Delete the rest.
						{
							$select_statement->fetch();
							echo "Id to delete is: ".$id."\n";
							$delete_statement->bind_param("i",$id);
							//$temp = mysqli_query($this->SQL_con,"DELETE FROM `".$this->table_name."` WHERE id='$dupe[id]';");
							$temp = $delete_statement->execute();
							if(!$temp)
								trigger_error("[MyIRCSQL] Delete operation failed for row id `$dupe[id]`.\n",E_USER_WARNING);
							$success &= $temp;
						}
					}
				}
				return !$success; //if the queries completed successfully, the result will still be true. As this means there are no more duplicates, we return 'false'
			}
			else	//if we're not deleting, return true
				return true;
		}
		else
			return false;
	}
	
	function AddLineToDatabase($line_array)
	//Purpose: Add the data from an array of line data to the database
	{
		//Sanatise the data:
		foreach($this->DB_struct as $key => $val)
		{	
			$line_array[$key] = $this->SQL_sanatize_str($line_array[$key]);
		}
		
		//check if line already exists:
		if($this->checkForExistingLine($line_array))
			return 0;	//Line already on database.
		
		//Now with prepared Statments!
			//prepare the prepared string bits:
		$fields = "";
		$params = "";
		$values = array();
		$param_types="";
		$flag=false;
		foreach($this->DB_struct as $key => $val)
		{
			if($val['type']!='primary_key') 	//we don't insert the primary key.
			{
				if($flag) // we add comma before each field, but not before the first one.
				{
					$params.=", ";
					$fields.=", ";
				}
				$flag=TRUE;
				$params .=" ?";
				$fields .="`".$key."`";
				$values[] = $line_array[$key];
				switch($val['type'])
				{
					default:
						$param_types .="s";
						break;
					case 'INT( 11 )':
						$param_types .="i";
						break;
				}
			}
		}
		//make the prepared statement.
		$query = $this->SQL_con->prepare("INSERT INTO `".$this->table_name."` ( $fields) VALUES ( $params );");
		//bind parameters:
		$query->bind_param($param_types,...$values);
		
		
		//Run the query:
		/*
		if(!mysqli_query($this->SQL_con,$query))
			trigger_error("[MyIRCSQL] Unable to Add line to database.",E_USER_WARNING);
		else
			return 1;
		*/
		$res = $query->execute();
		$query->close();
		if(1 != $query->affected_rows)
		{
			trigger_error("[MyIRCSQL] Unable to Add line to database.",E_USER_WARNING);
			return 0;
		}
		else
			return 1;
	}
	function AddLinesToDatabase($array_of_lines)
	//Purpose:	An insert optimised to add multiple lines of data at the same time.
	//Returns:	Count of lines added
	{	
		//Using a single prepared statment with multiple values:
		//*/
		
		//Figure out what fields are going in:
		$fieldStr = "";		// The fields we're inserting
		$params = "";		// This is a string that will have to be duplicated into the statement for each line we're adding.		
		
		$flag=false;
		
		$values = array(); //The values that will need to be bound.
		$param_types="";	// The parameters of the fields. Used in query->bind_param();
		
		
		//fields to update:
		$flag= false;
		foreach($this->DB_struct as $key => $val)
		{
			if($flag) // we add comma before each field, but not before the first one.
				$fieldStr.=", ";
			$flag=TRUE;
			
			$fieldStr .="`".$key."`";	
		}
		
		//Construct data dependent variables:
		$firstRow = true;
		$params = "(";
		foreach($array_of_lines as $line_array)
		{
			if(!$firstRow)
				$params.="), (";
			$flag = false;
			foreach($this->DB_struct as $key => $val)
			{

				if($flag) // we add comma before each field, but not before the first one.
				{
					$params.=", ";
				}
				$flag=TRUE;
				
				//For the prepared statement:
				$params .= "?";
				
				
				// For the bind_Params:
				//Set param_types
				switch($val['type'])
				{
					default:
						$param_types .="s";
						break;
					case 'INT( 11 )':
						$param_types .="i";
						break;
				}
				//Append the value:
				$values[] = $line_array[$key];


			}
			$firstRow = false;
		}
		$params.=")";
		
		
		//make the prepared statement.
		$statement = "INSERT INTO `".$this->table_name."` ( $fieldStr ) VALUES $params;";
		$query = $this->SQL_con->prepare($statement);
		if($query === false)
		{
			echo "[Log Parser] Error creating prepared statment:\nStatement was:\t".$statement."\nSQL error:\t".$this->SQL_con->error;
			return 0;
		}

		//Run the query:
		//bind parameters:
		$query->bind_param($param_types,...$values);
		$query->execute();
		
		if($query->affected_rows != count($array_of_lines))
		{
			echo "Error inserting lines: \n Expected to insert ".count($array_of_lines)." but saw only ".$query->affected_rows."\n".$query->error."\n";
		}
		$affectedRows =$query->affected_rows;
		$query->close();	
		
		
		return $affectedRows;
	}
	
	
	//-------------------------------------------
	// Database returning functions:
	//-------------------------------------------
	function databaseSelectQuery($WHERE)
	//Purpose:	Allows user to run an arbitary SELECT query against the database
	//Notes:	There is some injection prevention, but use of this function is risky
	{
		//clean the query:
		$WHERE = $this->SQL_sanatize_str($WHERE);
		
		//submit query
		$res = mysqli_query($this->SQL_con,"SELECT * FROM `$this->table_name` WHERE '$WHERE' ;");
		
		for($i=0;$i<mysqli_num_rows($res);$i++)
		{
			//Get the data:
			foreach(mysqli_fetch_array($res) as $key=>$val)
			{
				if($this->DB_struct[$key]['type']=='TIMESTAMP')
					$output[$i][$key] = strtotime($val);
				elseif($this->DB_struct[$key]['properties'] == 'FLAGS')
					$output[$i][$key] = $this->encodeDecodeDBFlagVars($val);
				else
					$output[$i][$key] = $val; 
			}
			
		}
		
		return $output;
	}
	
	function getLineByID($id)
	//Purpose:	Get a single lines data by the database line ID.
	{
		//Check the ID
		if(!is_numeric($id))
			throw new Exception("getLineByID called with Non-Numeric ID: $id");
		
		
		//submit query
		$res = mysqli_query($this->SQL_con,"SELECT * FROM `$this->table_name` WHERE id=$id;");
		
		if(mysqli_num_rows($res)==1)
		{
			//Get the data:
			foreach(mysqli_fetch_array($res) as $key=>$val)
			{
				if($this->DB_struct[$key]['type']=='TIMESTAMP')
					$output[$i][$key] = strtotime($val);
				elseif($this->DB_struct[$key]['properties'] == 'FLAGS')
					$output[$i][$key] = $this->encodeDecodeDBFlagVars($val);
				else
					$output[$i][$key] = $val; 
			}
			return $output;
		}
		else
			return 0;
	}
	
	function getLogDataBetweenTimes($start,$end="")
	//Purpose:	Return all the log data between to specified timestamps. Accepts strings or unix_timestamps
	{
		//Prep the start and end values:
		if(!is_int($start))
			$start = strtotime($start);
		$start = date("Y-m_d H:i:s",$start);
		
		if($end="")
			$end=time();
		elseif(!is_int($end))
			$end = strtotime($end);
		$end = date("Y-m_d H:i:s",$end);
		
		//submit query
		$res = mysqli_query($this->SQL_con,"SELECT * FROM `$this->table_name` WHERE unix_timestamp > '$start' AND unix_timestamp < '$end'  ORDER BY unix_timestamp;");
		
		for($i=0;$i<mysqli_num_rows($res);$i++)
		{
			//Get the data:
			foreach(mysqli_fetch_array($res) as $key=>$val)
			{
				if($this->DB_struct[$key]['type']=='TIMESTAMP')
					$output[$i][$key] = strtotime($val);
				elseif($this->DB_struct[$key]['properties'] == 'FLAGS')
					$output[$i][$key] = $this->encodeDecodeDBFlagVars($val);
				else
					$output[$i][$key] = $val; 
			}
			
		}
		
		return $output;
	}
	
	function getHighlightedLines()
	//Purpose:	Get a list of lines marked as "Highlighted"
	{
		//generate exclusions text:
		if(!empty($this->user_Highlight_exclusions))
		{
			$exclusion_str = "AND NOT ( ";
			$flag=false;
			foreach($this->user_Highlight_exclusions as $exclude)
			{
				if($flag)
					$exclusion_str .= "OR ";
				$flag=true;
				
				$exclusion_str .= 'user="'.$exclude.'" ';
			}
			$exclusion_str .= ") ";
		}
		else
			$exclusion_str="";
		
		//search and concatenate all results.
		$j=0;	//total result offset
		foreach($this->highlight_name as $highlight)
		{
			//submit query
			$res = mysqli_query($this->SQL_con,"SELECT * FROM `$this->table_name` WHERE text LIKE '%$highlight%' $exclusion_str  ORDER BY unix_timestamp;");
			
			for($i=0;$i<mysqli_num_rows($res);$i++)
			{
				//Get the data:
				foreach(mysqli_fetch_array($res) as $key=>$val)
				{
					if($this->DB_struct[$key]['type']=='TIMESTAMP')
						$output[$i+$j][$key] = strtotime($val);
					else
						$output[$i+$j][$key] = $val; 
				}
			}
			$j+= mysqli_num_rows($res); //set new offset.
		}
		
		return $output;
	}
}

?>