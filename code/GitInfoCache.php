<?php

/**
 * DataObject to keep a cache of information about a given Git url.
 * Designed to be used in conjunction with GitUpdateTask, run once an hour, to keep relatively
 * up-to-date information about a Git repository without excessively hammering a server.
 */
class GitInfoCache extends DataObject {
	static $db = array(
		"URL" => "Varchar(255)",
		"Branch" => "Varchar(255)",
		"Tag" => "Varchar(255)",
		"Timestamp" => "Int",
	);	
}
?>