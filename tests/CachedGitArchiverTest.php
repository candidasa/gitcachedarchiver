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
				'tag' => '0.2-rc1',
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

			$this->assertNotNull($archiver->URL(),"The URL is not null");
			$this->assertTrue(strlen($archiver->URL()) > 5,"The URL is more than a few characters long");
			
			$this->assertEquals($archiver->gitRepo(), $info['reponame'],"Repository name is extracted correctly");

			$revision = $archiver->currentRev();
			$this->assertNotNull($revision);
			$this->assertEquals(strlen($revision),40,"SHA has is the correct length");

			unset($archiver);
			unset($revision);
		}
	}

}
?>