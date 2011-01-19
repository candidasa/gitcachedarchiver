<?php

class CachedDownloadLink extends RequestHandler {
	protected $parent, $branch = "master", $tag = "HEAD";

	protected $url;
	protected $baseFilename = null;

	/**
	 * @param $url The git URL to enable for download
	 */
	function __construct($parent, $url) {
		parent::__construct();

		$this->parent = $parent;
		$this->url = $url;
	}

	function Link() {
		return $this->url;
	}

	function URL() {
		return $this->url;
	}

	function Filename() {
		if (isset($this->url)) {
			if (preg_match('/.*\/([\w\.]+)$/',$this->url, $matches));
				return $matches[1];
		} else {
			return "Download";
		}
	}

	function Name() {
		return $this->Filename();
	}

}
?>