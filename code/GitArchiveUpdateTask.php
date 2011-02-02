<?php
/** Is different from SvnInfoCache because on-demand generation of the git downloads led to a large number of
 * queued up Apache processes on the server, completely blocking Apache and bringing the server down. Running this
 * task every hour provides a better way of controlling the execution of when and how we create archives from git repositories.
 * Several safe-guards are built in to prevent this task from creating an archive when it is not necessary. It also does not
 * generate an archive if the attempt to do so failed more than 24 times (one day) in the past.
 *
 * You can optionally specify the branch to be updated and if all possible git projects should rebuild their archives
 */
class GitArchiveUpdateTask extends HourlyTask {
	function process($branch = "master", $rebuildAll = false) {
		if ($branch) $where = "Branch = '$branch'";
		else $where = '';

		if ($rebuildAll) {
			foreach(DataObject::get("ModulePage", $where) as $page) {   //update only those
				if ($page->GitRepo && strlen($page->GitRepo) > 1) {
					echo "Queuing task to re-generate ModulePage: $page->GitRepo<br>\n";
					$invo = new MethodInvocationMessage("CachedGitArchiver", "generateGitArchive", $page->GitRepo);  //build master HEAD
					MessageQueue::send("generateReleaseQueue", $invo);
				}
			}
			foreach(DataObject::get("AddOnRelease", $where) as $release) {   //update only those
				if ($release->VersionControlChoice == "Git" && $release->GitURL != null) {
					echo "Queuing task to re-generate AddOnRelease: $release->GitURL, branch $release->GitBranch, tag $release->GitTag<br>\n";
					$invo = new MethodInvocationMessage("CachedGitArchiver", "generateGitArchive", $release->GitURL, $release->GitBranch, $release->GitTag, "assets/modules/stable");
					MessageQueue::send("generateReleaseQueue", $invo);
				}
			}
		} else {    //only rebuild the archives that have changed
			foreach(DataObject::get("GitInfoCache", $where) as $cache) {   //update only those
				if ($branch == "master") $dir = "assets/modules/master";
				else $dir = "assets/modules/stable";

				//check number of failed attempts and don't re-try generating if we failed more than 20 times
				if ($cache->Attempts > 24) {
					echo "Skipping $cache->URL, generating the archive failed more than 24 times.<br>\n";
					continue;
				}

				//check rev here and don't update if the rev is the same
				$cga = new CachedGitArchiver(null, $cache->Branch, $cache->Tag, $cache->URL);
				$currentRevision = $cga->calculateCurrentRev();
				$lastRevision = $cache->CurrentRev;
				if ($currentRevision == $lastRevision) {
					echo "Skipping $cache->URL, SHA hash has not changed from last archive.<br>\n";
					continue;     //no need to re-generate archive if the sha hashes are the same for the current revision and the revision of the current file
				}

				//generate the actual file (using a message queue)
				echo "Queuing task to re-generate: $cache->URL<br>\n";
				$invo = new MethodInvocationMessage("CachedGitArchiver", "generateGitArchive", $cache->URL, $cache->Branch, $cache->Tag, $dir);  //build master HEAD
				MessageQueue::send("generateReleaseQueue", $invo);

				$cache->destroy();
			}
		}
	}
}

class GitArchiveUpdateTask_Manual extends BuildTask {

	function run($request) {
		echo "Running Git Module Update - Updating master branch of modules if there is a new commit\n\n";

		$update = new GitArchiveUpdateTask();
		$update->process();
	}
}

class GitArchiveUpdateTask_RebuildAll extends BuildTask {

	function run($request) {
		echo "Running Git Module Update - Rebuilding the archive of all modules\n\n";

		$update = new GitArchiveUpdateTask();
		$update->process(null,true);
	}
}