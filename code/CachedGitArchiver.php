<?php

/**
 * This class turns a git URL into a downloadable .tar.gz file,
 * caching it intelligently to prevent needless regeneration whilst still
 * ensuring that the file returned is always up-to-date
 * 
 * Usage:
 *
 * Within a Controller or RequestHandler:
 * <code>
 * function MasterDownload() {
 *     return new CachedGitArchiver($this, "master", "v1.0", "git://github.com/candidasa/svn2git.git", "assets/downloads");
 * }
 * </code>
 *
 * Within your template:
 * <code>
 * <a href="$MasterDownload.URL">$MasterDownload.Title</a>
 * </code>
 */
class CachedGitArchiver extends RequestHandler {
	protected $parent, $branch = "master", $tag = "HEAD";
	
	protected $cacheDir, $cacheURL, $url;
	protected $_cache = array();
	
	protected $baseFilename = null;
	
	static $allowed_actions = array(
		'generate',
	);
	
	/**
	 * @param $url The git URL to enable for download
	 */
	function __construct($parent, $branch = "master", $tag = "HEAD", $url, $cacheDir = 'assets/downloads') {
		parent::__construct();
		$this->parent = $parent;
		$this->branch = $branch;
		$this->tag  = $tag;

		if($cacheDir[0] == '/') user_error("Please supply a relative directory for the cacheDir - it needs to be in the web root", E_USER_ERROR);
		$this->cacheDir = BASE_PATH . '/' . $cacheDir;
		$this->cacheURL = (BASE_URL=='/' ? BASE_URL : BASE_URL.'/') . $cacheDir;

		$this->url = $url;
	}
	
	function Link() {
		if ($this->parent && $this->parent->Link()) {
			$link = $this->parent->Link();
		} else {
			$link = Director::absoluteBaseURL();
		}
		return Controller::join_links($link, "MasterDownload");
	}
	
	function URL() {
		if (file_exists($this->fullFilename()) && $this->tag != "HEAD") {    //no need to rebuild the cache for non-head releases, return those immediately if they are packaged
			return $this->fullURL();
		}

		//$cachedObject = DataObject::get_one("GitInfoCache","URL = '$this->url' AND Branch = '$this->branch' AND Tag = '$this->tag'");

		//use the current cached version, if it is present, and has a GitInfoCache object from less than an hour ago
		//if(file_exists($this->fullFilename()) && $cachedObject != null && $cachedObject->Timestamp >= (time() - 60*60)) {

		if(file_exists($this->fullFilename())) {
			return $this->fullURL();
		} else {
			//never generate directly, instead redirect to error page explaining to wait
			return "no-download-available";
			/*$urladdition = "?";
			$escaped_branch = Convert::raw2xml($this->branch);
			$escaped_tag = Convert::raw2xml($this->tag);

			if ($escaped_branch) {
				$urladdition .= "branch=$escaped_branch";
			} else {
				$urladdition .= "branch=";
			}

			if ($escaped_tag) {
				$urladdition .= "&tag=$escaped_tag";
			} else {
				$urladdition .= "&tag=";
			}

			return Controller::join_links($this->Link(), 'generate'. $urladdition );*/
		}
	}

	function Filename($withExtension = true) {
		$filename = null;

		$repo = $this->gitRepo();
		$baseFilename = $this->baseFilename ? $this->baseFilename : $repo;

		if ($baseFilename) {
			$filename = $baseFilename;

			if ($this->branch) $filename .= "-$this->branch";
			if ($this->tag) $filename .= "-$this->tag";
			if ($withExtension) $filename .= ".zip";
		}

		return $filename;
	}
	
	function Name() {
		if(file_exists($this->fullFilename())) {
			return $this->Filename();
		} else {
			return "Download not yet available";
		}
	}
	
	function FileSize() {
		$this->createFile();
		$size = filesize($this->fullFilename());
		return File::format_size($size);
	}
	
	/**
	 * Set the base filename to which "-v1.2.3" or "-trunk-r123424" is suffixed
	 */
	function setBaseFilename($baseFilename) {
		$this->baseFilename = $baseFilename;
	}
	

	function fullFilename() {
		return $this->cacheDir . '/' . $this->Filename();
	}
	function fullURL() {
		return $this->cacheURL . '/' . $this->Filename();
	}

	/**
	 * Returns the latest revision # in the git repo (cached in the template for update every hour)
	 */
	function calculateCurrentRev() {
		$CLI_url = escapeshellarg($this->url);
		$CLI_branch = escapeshellarg($this->branch);
		exec("git ls-remote --heads $CLI_url refs/heads/$CLI_branch",$output,$returnVal);
		if ($returnVal == 0 && is_array($output) && isset($output[0])) {
			if (preg_match('/^([\w]*).*?/',$output[0], $matches)) {
				return $matches[1];
			}
		}
	}

	/** Returns a cached version of the current revision */
	function currentRev() {
		$cache = DataObject::get_one("GitInfoCache","URL = '$this->url' AND Branch = '$this->branch' AND Tag = '$this->tag'");
		if ($cache) return $cache->CurrentRev;
	}
	/**
	 * Returns the latest revision date of the file
	 */
	function currentDate() {
		if(file_exists($this->fullFilename())) {
			return date("Y-m-d", filemtime($this->fullFilename()));
		} else {
			return date("Y-m-d", time());   //return 'now', if the file does not yet exist
		}
	}

	/** Cache key for the results of the currentRev function. This saves us querying the git repo for the latest SHA hash
	 * every time this page is accessed */
	function HourlyCacheKey() {
		return date("Y-m-d H", time()); //change they key every hour of every day
	}
	
	/**
	 * Returns the git repository name from a git url
	 * @return repo name string
	 */
	function gitRepo() {
		if (preg_match('/^.+[\/\:](.*?)\.git.*/', $this->url, $matches)) {
			return $matches[1];
		} else {
			return null;
		}
	}
	
	/**
	 * Actually create the .tar.gz file 
	 */
	/*function generateGit($branch = null, $tag = null) {
		$request = $this->getRequest();

		//variable passed in using the method call take priority over request variables
		if (!$branch) {
			if ($request->getVar('branch')) {
				$branch = Convert::raw2sql($request->getVar('branch'));
				if ($branch && strlen($branch) > 0) $this->branch = $branch;
			}
		} else {    //set branch from method attribute variables
			$this->branch = $branch;
		}
		if (!$tag) {
			if ($request->getVar('tag')) {
				$tag = Convert::raw2sql($request->getVar('tag'));
				if ($tag && strlen($tag) > 0) $this->tag = $tag;
			}
		} else {
			$this->tag = $tag;
		}

		// Give ourselves a reasonable amount of time

		//ini_set('memory_limit', '512M');
		
		$folder = str_replace('.tar.gz','', $this->Filename());

		// If the file has been generated since we clicked the link, then just redirect there
		if(file_exists($this->fullFilename())) {
			Director::redirect($this->fullURL());
			return;

		// If someone else has started producing the file, then wait for them to finish.
		// Wait for 10 seconds and if it's still not ready, then build it ourselves
		} else if(file_exists(TEMP_FOLDER . '/' . $folder)) {
			for($i=0;$i<10;$i++) {
				sleep(1);
				if(file_exists($this->fullFilename())) {
					Director::redirect($this->fullURL());
					return;
				}
			}
		}
		
		// Otherwise, let's do the build.
		if($this->createFile()) {
			Director::redirect($this->fullURL());
		}
	}*/
	
	/**
	 * Actually create the file, if it doesn't already exist. Optionally overwrites the current file
	 */
	function createFile($overwrite = false) {
		set_time_limit(300);   //always time out after 5 minutes

		$fileExists = file_exists($this->fullFilename());
		/*if ($fileExists && !$overwrite) {   //only case where we return existing file, otherwise always generate and overwrite existing file
			return true;
		} else {
		*/	$CLI_tmp = escapeshellarg(TEMP_FOLDER);
			$CLI_outputFile = escapeshellarg($this->fullFilename());

			$destDir = dirname($this->fullFilename());
			if(!is_dir($destDir) && !mkdir($destDir, 0777, true)) {
				user_error("Couldn't create directory: " . $destDir, E_USER_ERROR);
			}

			$retVal = 0;
			$output = array();

			//cache object saves (among other things) the timestamp of the last generation attempt
			$cache = DataObject::get_one("GitInfoCache","URL = '$this->url' AND Branch = '$this->branch' AND Tag = '$this->tag'");

			//save a notice that this package was successfulled created and cached (for future updates)
			if (!$cache) {
				$cache = new GitInfoCache();
				$cache->URL = $this->url;
				$cache->Branch = $this->branch;
				$cache->Tag = $this->tag;
				$cache->FailedAttempts = 0;
			} else {
				//check that we aren't generating since the last minute
				$timeSinceLastCreateFile = time() - strtotime($cache->Timestamp);
				if ($timeSinceLastCreateFile <= 60) user_error("It has been less than 60 seconds since the last time this file was generated. Aborting CreateFile attempt for: $this->url", E_USER_ERROR);
			}

			//update the cached object
			$cache->Timestamp = date('Y-m-d H:i:s');    //now
			$cache->CurrentRev = $this->calculateCurrentRev();  //update git revision sha
			$infoID = $cache->write();

			$filename = $this->Filename(false); //get filename but without the tar.gz at the end
			if ($filename && $this->url) {    //only create the file if we have a valid filename
				$CLI_filename = escapeshellarg($filename);
				$CLI_url = escapeshellarg($this->url);
				$CLI_branch = escapeshellarg($this->branch);
				$CLI_tag = escapeshellarg($this->tag);

				if ($overwrite && $fileExists) unlink($CLI_outputFile); //delete existing file to be replaced by new version
				exec("cd $CLI_tmp && rm -R -f $CLI_filename && git clone -b $CLI_branch $CLI_url $CLI_filename && cd $CLI_filename && git archive --format=zip $CLI_tag -o $CLI_outputFile && cd .. && rm -r -f $CLI_filename", $output, $retVal);
				if (!file_exists($CLI_outputFile)) $retVal = 100;
				elseif (filesize($CLI_outputFile) <= 512) {
					$retVal = 100;
				}
			}

			if($retVal == 0) {
				if ($cache->FailedAttempts >= 1) { //reset the failed attempt counter
					$cache = DataObject::get_by_id("GitInfoCache", $infoID);
					$cache->FailedAttempts = 0;
					$cache->write();
				}

				return true;
			} else {
				exec("rm $CLI_outputFile"); //delete the failed file (tried unlink here, but it didn't work)
				$cache = DataObject::get_by_id("GitInfoCache", $infoID);
				$cache->FailedAttempts = $cache->FailedAttempts + 1;
				$cache->write();

				if ($retVal == 100) $includeError = "- Invalid git branch or tag name";
				else $includeError = "";
				user_error("Couldn't produce .tar.gz of output (return val $retVal $includeError): " . implode("\n", $output), E_USER_ERROR);
			}
		/*}*/
	}

	/** Creates a git archive (called from a message queue) */
	static function generateGitArchive($url, $branch = "master", $tag = "HEAD", $dir = 'assets/modules/master') {
		$cga = new CachedGitArchiver(null, $branch, $tag, $url, $dir);
		$cga->createFile(true); //overwrite, if necessary
	}
}

?>
