<?php
class CachedGitArchiverTest extends SapphireTest {

	/**
	 * Tests {@link CachedSvnArchiver->svnParts()}
	 */
	function testGitParts() {
		$cases = array(
			'git://github.com/silverstripe/silverstripe-userforms.git' => array(
				'branch' => 'master',
				'tag' => 'HEAD',
				'reponame'=>'silverstripe-userforms',
			),
			'git://github.com/silverstripe/silverstripe-mollom.git' => array(
				'branch' => '0.3',
				'tag' => 'HEAD',
				'reponame'=>'silverstripe-mollom',
			),
			'git://github.com/silverstripe/silverstripe-mollom.git' => array(
				'branch' => 'master',
				'tag' => 'HEAD',
				'reponame'=>'silverstripe-mollom',
			),
			'git://github.com/sminnee/brat.git' => array(
				'branch' => 'master',
				'tag' => 'HEAD',
				'reponame'=>'brat',
			),
		);

		foreach($cases as $url => $info) {
			$archiver = new CachedGitArchiver(null, $info['branch'], $info['tag'], $url);

			unlink($archiver->fullFilename());   //remove any existing files so they are regenerated

			$this->assertNotNull($archiver->URL(),"The URL is not null");
			$this->assertTrue(strlen($archiver->URL()) > 5,"The URL is more than a few characters long");
			
			$this->assertEquals($archiver->gitRepo(), $info['reponame'],"Repository name is extracted correctly");

			$createdFile = $archiver->createFile();    //test creating the file
			$this->assertTrue($createdFile, "File creation method returned true");
			$this->assertTrue(file_exists($archiver->fullFilename()),"Created file actually exists");

			$revision = $archiver->currentRev();
			$this->assertNotNull($revision);
			$this->assertEquals(strlen($revision),40,"SHA has is the correct length");

			unset($archiver);
			unset($revision);
		}
	}

	function testURLFilter() {
		$test1 = "git@github.com:candidasa/gitcachedarchiver.git";
		$solution1 = "git://github.com/candidasa/gitcachedarchiver.git";

		$test2 = "https://candidasa@github.com/candidasa/gitcachedarchiver.git";
		$solution2 = "https://github.com/candidasa/gitcachedarchiver.git";

		$test3 = "candidasa@github.com/candidasa/gitcachedarchiver.git";
		$solution3 = "git://github.com/candidasa/gitcachedarchiver.git";

		$this->assertEquals(CachedGitArchiver::filterGitURL($test1),$solution1,"Solution 1 git URL transform correctly executed");
		$this->assertEquals(CachedGitArchiver::filterGitURL($test2),$solution2,"Solution 2 git URL transform correctly executed");
		$this->assertEquals(CachedGitArchiver::filterGitURL($test3),$solution3,"Solution 3 git URL transform correctly executed");
	}

}
?>