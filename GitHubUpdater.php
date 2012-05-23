<?php

if ($_SERVER['REMOTE_ADDR'] != "176.9.149.130")
	die("403 FROM " . $_SERVER['REMOTE_ADDR']);

/**
 * Git Hub Updater script
 * Fetch changes from GitHub and pull them into a local database
 *
 * Copyright (c) The BBQTeam 2012
 *
 */

// Configs
set_time_limit(0);
require_once("config.php");

// Helper functions
/**
 * Escape the string to be given to MySQL
 */
function esc($txt) {
  return mysql_real_escape_string($txt);
}

/**
 * Output a message with the time it took relative to the latest action done
 * and flush buffers
 */
function actionDone($msg) {
	global $startTime;	
	echo "$msg (" . number_format(floatval(microtime(true) - $startTime), 5) . "s) <br />\n";
	$startTime = microtime(true);
	echo str_repeat(' ',256);
	flush_buffers();
}
function actionStart($msg) {
	echo $msg . "<br />\n";
}

/**
 * Flush OB buffers
 */
function flush_buffers(){
	ob_flush();
	flush();
}

function githubDate($date) {
	$a = new DateTime($date);
	$a->setTimezone(new DateTimeZone('UTC'));
	return $a->format("Y-m-d H:i:s");
}


ob_start();

$nbGitHubRequests = 0;

// Connect to MySQL
mysql_connect($CFG['SQL']['Host'], $CFG['SQL']['User'], $CFG['SQL']['Pass']) or die(mysql_error());
mysql_select_db($CFG['SQL']['DB']) or die(mysql_error());

mysql_query("START TRANSACTION;") or die(mysql_error());

$startTime = microtime(true);


// Start import
actionStart("Starting repositories processing...");

// If a specific repo is passed in GET, update only this repository
if (!empty($_GET['repo']))
	$repositories = mysql_query("SELECT * FROM repositories WHERE Repository='".mysql_real_escape_string($_GET['repo'])."' ");
else
	$repositories = mysql_query("SELECT DISTINCT * FROM repositories GROUP BY GitUsername,Repository;") or die(mysql_error());

while ($repo = mysql_fetch_assoc($repositories)) {
	// load last commit of the branch
	$branches_json = json_decode(file_get_contents("https://api.github.com/repos/".$repo['GitUsername']."/".$repo['Repository']."/branches"), true);
	$nbGitHubRequests++;
	
	$branches_sql = mysql_query("SELECT Branch FROM repositories WHERE GitUsername='".mysql_real_escape_string($repo['GitUsername'])."' AND Repository='".mysql_real_escape_string($repo['Repository'])."'");
	
	while ($branch = mysql_fetch_assoc($branches_sql)) {
		// find the last commit
		$lastCommitSHA = "";
		
		foreach($branches_json as $gitBranch) {
			if ($gitBranch['name'] == $branch['Branch']) {
				$lastCommitSHA = $gitBranch['commit']['sha'];
				echo "Found branch " . $branch['Branch'] . " (last commit: $lastCommitSHA)<br>";
				break;
			}
		}
		
		// get the last commit in db
		$query = mysql_query("SELECT SHA FROM commits WHERE GitUsername='".esc($repo['GitUsername'])."' AND Repository='".esc($repo['Repository'])."' AND Branch='".esc($branch['Branch'])."' ORDER BY CommitDate DESC LIMIT 1");
		$fetch = mysql_fetch_assoc($query);
		
		$lastCommitDB = $fetch['SHA'];
		
		if ($lastCommitSHA == $lastCommitDB) {
			// the last commit in DB is already the latest one of the branch, skipping
			continue;
		}
	
		actionStart("Fetching branch " . $branch['Branch'] . " of repo " . $repo['Repository'] . "...");
		$commitSHA = $lastCommitSHA;
		$nbFetched = 0;
		$previousFetchedCommit = "";

		while ($nbFetched < 20 && $previousFetchedCommit != $commitSHA) { // we limit max 20 requests per branch (thats 2000 commits)
			$previousFetchedCommit = $commitSHA;
			
			actionStart("Grabbing https://api.github.com/repos/".$repo['GitUsername']."/".$repo['Repository']."/commits?per_page=100&sha=$commitSHA ...");
			
			$commits_json = json_decode(file_get_contents("https://api.github.com/repos/".$repo['GitUsername']."/".$repo['Repository']."/commits?per_page=100&sha=$commitSHA"),true);
			$nbGitHubRequests++;
			$lastReached = false;
			
			foreach($commits_json as $commit) {
				if ($commit['sha'] == $lastCommitDB || strtotime(githubDate($commit['commit']['committer']['date'])) < time()-3600*24*31*4) { // max 4 months
					$lastReached = true;
					break;
				}
				
				mysql_query("INSERT IGNORE INTO commits(SHA,GitUsername,Repository,Branch,Author,Message,CommitDate) VALUES('" . esc($commit['sha']) ."', '".esc($repo['GitUsername'])."' ,'".esc($repo['Repository'])."', '".esc($branch['Branch'])."', '".esc($commit['committer']['login'])."', '".esc($commit['commit']['message'])."', '".esc(githubDate($commit['commit']['committer']['date']))."');") or die(mysql_error());
				
				$commitSHA = $commit['sha'];
			}
			
			if ($lastReached)
				break;
				
			actionDone("Commits batch imported");
			$nbFetched++;
		}
		
		actionDone("Done fetching branch " . $branch['Branch'] . " of repo " . $repo['Repository'] . "...");
	}  
}

actionDone("Commits updated");

// We keep only the commits of the last 4 months
actionStart("Cleaning 4+months old commits");
mysql_query("COMMIT;") or die(mysql_error());
mysql_query("DELETE FROM commits WHERE CommitDate < '".date("Y-m-d H:i:s", time()-3600*24*31*4). "'") or die(mysql_error());
actionDone("Cleaned " . mysql_affected_rows() . " commits");

actionDone("GITHUB REQUESTS: " . $nbGitHubRequests);
?>
