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
		return Controller::join_links($this->parent->Link(), "MasterDownload");
	}
	
	function URL() {
		if(file_exists($this->fullFilename())) {
			return $this->fullURL();
		} else {
			$urladdition = "?";
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

			return Controller::join_links($this->Link(), 'generate'. $urladdition );
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
			if ($withExtension) $filename .= ".tar.gz";
		}

		return $filename;
	}
	
	function Name() {
		return $this->Filename();
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
	 * Returns the latest revision # in the git repo (cached every hour)
	 */
	function currentRev() {
		$CLI_url = escapeshellarg($this->url);
		$CLI_branch = escapeshellarg($this->branch);
		exec("git ls-remote --heads $CLI_url refs/heads/$CLI_branch",$output,$returnVal);
		if ($returnVal == 0 && is_array($output) && isset($output[0])) {
			if (preg_match('/^([\w]*).*?/',$output[0], $matches)) {
				return $matches[1];
			}
		}
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
	function generate() {
		$request = $this->getRequest();
		if ($request->getVar('branch')) {
			$branch = Convert::raw2sql($request->getVar('branch'));
			if ($branch && strlen($branch) > 0) $this->branch = $branch;
		}
		if ($request->getVar('tag')) {
			$tag = Convert::raw2sql($request->getVar('tag'));
			if ($tag && strlen($tag) > 0) $this->tag = $tag;
		}

		// Give ourselves a reasonable amount of time
		if(ini_get('max_execution_time') < 1000) set_time_limit(1000);
		//ini_set('memory_limit', '512M');
		
		$folder = str_replace('.tar.gz','', $this->Filename());

		// If the file has been generated since we clicked the link, then just redirect there
		if(file_exists($this->fullFilename())) {
			Director::redirect($this->fullURL());
			return;

		// If someone else has started producing the file, then wait for them to finish.
		// Wait for 120 seconds and if it's still not ready, then build it ourselves
		} else if(file_exists(TEMP_FOLDER . '/' . $folder)) {
			for($i=0;$i<120;$i++) {
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
	}
	
	/**
	 * Actually create the file, if it doesn't already exist
	 */
	private function createFile() {
		if(!file_exists($this->fullFilename())) {
			$CLI_tmp = escapeshellarg(TEMP_FOLDER);
			$CLI_outputFile = escapeshellarg($this->fullFilename());

			$destDir = dirname($this->fullFilename());
			if(!is_dir($destDir) && !mkdir($destDir, 0777, true)) {
				user_error("Couldn't create directory: " . $destDir, E_USER_ERROR);
			}

			$retVal = 0;
			$output = array();

			$filename = $this->Filename(false); //get filename but without the tar.gz at the end
			if ($filename && $this->url) {    //only create the file if we have a valid filename
				$CLI_filename = escapeshellarg($filename);
				$CLI_url = escapeshellarg($this->url);
				$CLI_branch = escapeshellarg($this->branch);
				$CLI_tag = escapeshellarg($this->tag);

				exec("cd $CLI_tmp && unset DYLD_LIBRARY_PATH && git clone -b $CLI_branch $CLI_url $CLI_filename && cd $CLI_filename && git archive --format=tar $CLI_tag | gzip > $CLI_outputFile && cd .. && rm -r -f $CLI_filename", $output, $retVal);
			}

			if($retVal == 0) {
				return true;
			} else {
				user_error("Couldn't produce .tar.gz of output (return val $retVal): " . implode("\n", $output), E_USER_ERROR);
			}
		} else {
			return true;
		}
	}
}

?>
