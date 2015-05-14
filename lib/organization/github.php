<?php
/*******************************************************************************
* Copyright (c) 2015 Eclipse Foundation and others.
* All rights reserved. This program and the accompanying materials
* are made available under the terms of the Eclipse Public License v1.0
* which accompanies this distribution, and is available at
* http://www.eclipse.org/legal/epl-v10.html
*
* Contributors:
*    Denis Roy (Eclipse Foundation) - initial API and implementation
*    Zak James (zak.james@gmail.com)
*******************************************************************************/

# Basic functions for an Eclipse forge
class Github extends Organization {

	private $GHTeamsjson;  ## See below for visual example
	private $teamList;
	private $users = array(
		'validCommitter' => array(),
		'validCLA' => array(),
		'invalidCLA' => array(),
		'validSignedOff' => array(),
		'invalidSignedOff' => array(),
		'unknownSignedOff' => array()
	);
	private $ldap_client;
	private $debug;
	private $repo_prefix = "https://github.com/";

	function __construct($debug) {
		$this->debug = $debug;
		# Fetch list of Organization teams, the repos and users in each
		global $github_organization;
		$client = new RestClient(GITHUB_ENDPOINT_URL);
		$this->logger = new Logger();

		$this->teamList = array();

		$url = implode('/', array(
				GITHUB_ENDPOINT_URL,
				'orgs',
				$github_organization,
				'teams'
		));
		# TODO: quick fix until we resolve Bug 461914 - API calls to lists must deal with pagination - in $client->get?
		$url .= "?per_page=100";
		if($this->debug) echo "GH Org: calling org teams api $url \n"; 
		$this->GHTeamsjson = $client->get($url);

		if (defined('LDAP_HOST')) {
			include_once('../lib/ldapclient.php');
			$this->ldap_client = new LDAPClient(LDAP_HOST, LDAP_DN);
		}

		foreach($this->GHTeamsjson as $GHteam) {
			# No sense in loading up the owners team
			if($GHteam->slug == "owners") {
				continue;
			}
			$team = new Team($GHteam->slug);
			$team->setTeamID($GHteam->id);
			if($this->debug) echo "    Found Github team [" . $GHteam->slug . "] \n";

			# get list of repos and users
			# TODO: deal with pages...  in $client->get?
			$url = GITHUB_ENDPOINT_URL . '/teams/' . $GHteam->id . '/members?per_page=100';
			if($this->debug) echo "    GH Org: calling members api: $url \n";
			$GHTeamMembersjson = $client->get($url);
			foreach($GHTeamMembersjson as $GHTeamMember) {
				# Convert GitHub's login to an email address
				# GitHub users don't necessarily expose their email addresses
				# But Eclipse LDAP has that mapping
				if($this->debug) echo "        Found team member [" . $GHTeamMember->login . "]... looking up email... [";
				if(defined('LDAP_HOST')) {
					$email = $this->ldap_client->getMailFromGithubLogin($GHTeamMember->login);
					if($email != "") {
						if($this->debug) echo $email;
						$team->addCommitter($email);
					}
					else {
						$team->addCommitter($GHTeamMember->login);
						if($this->debug) echo "NO EMAIL FOUND";
					}
					if($this->debug) echo "]\n";
				}
				else {
					$team->addCommitter($GHTeamMember->login);
				}
			}
			
			# TODO: deal with pages...  in $client->get?
			$url = GITHUB_ENDPOINT_URL . '/teams/' . $GHteam->id . '/repos?per_page=100';
			if($this->debug) echo "    GH Org: calling team repos api $url \n";
			$GHTeamReposjson = $client->get($url);
			foreach($GHTeamReposjson as $GHTeamRepo) {
				if($this->debug) echo "        Found team repo [" . $GHTeamRepo->html_url . "]\n";
				$team->addRepo($GHTeamRepo->html_url);
			}


			array_push($this->teamList, $team);
			if($this->debug) echo "====== END OF TEAM\n\n";
			
		}

		if($this->debug) $this->debug();
	}




	/** Get Team object based on its name (eclipse-birt)
	 * 
	 * @param string $teamName
	 * @return Team object, or false if not found
	 * @author droy
	 * @since 2015-05-06
	 */
	public function getTeamByName($teamName) {
		$rValue = false;
		foreach ($this->teamList as $team) {
			if($team->getTeamName() == $teamName) {
				$rValue = $team;
				break;
			}
		}
		
		return $rValue;
	}

	/** Get team object based on the repo name (eclipse/birt)
	 * 
	 * @param string $repoName
	 * @return Team object, or false if not found
	 * @author droy
	 * @since 2015-05-06
	 */
	public function getTeamByRepoName($repoName) {
		$rValue = false;
		foreach ($this->teamList as $team) {
			# We are looking for $repoName (eclipse/birt) within the list of repo URLs (https://github.com/eclipse/birt).
			foreach($team->getRepoList() as $repo) {
				if($this->strEndsWith($repo, $repoName)) {
					$rValue = $team;
					break;
				}
			}
		}
		return $rValue;
	}

	/** Get team object based on the repo url
	 *
	 * @param string $repoUrl
	 * @return Team object, or false if not found
	 * @author droy
	 * @since 2015-05-13
	 */
	public function getTeamByRepoUrl($repoUrl) {
		$rValue = false;
		if($repoUrl != "") {
			foreach ($this->teamList as $team) {
				# We are looking for $repoName (eclipse/birt) within the list of repo URLs (https://github.com/eclipse/birt).
				foreach($team->getRepoList() as $repo) {
					if($repo == $repoUrl) {
						$rValue = $team;
						break;
					}
				}
			}
		}
		return $rValue;
	}
	
	/**
	 * Given a repo URL, return the Github-friendly name
	 * @param String $repoUrl
	 * @return String Repo name
	 */
	public function getRepoName($repoUrl) {
		$rValue = "";
		$pattern = "|^(" . $this->repo_prefix . ")(.*)|";
		if(preg_match($pattern, $repoUrl, $matches)) {
			$rValue = $matches[2];
		}
		return $rValue;
	}

	/**
	 * Given a Team object, check if it has a given repo URL
	 * @param Team $team
	 * @param String $repoUrl
	 * @return boolean
	 * @since 2015-05-14
	 * @author droy
	 */
	public function teamHasRepoUrl($team, $repoUrl) {
		$rValue = false;
		if(isset($team) && $repoUrl != "") {
			foreach ($team->getRepoList() as $repo) {
				# We are looking for $repoName (eclipse/birt) within the list of repo URLs (https://github.com/eclipse/birt).
				if($repo == $repoUrl) {
					$rValue = true;
					break;
				}
			}
		}
		return $rValue;
	}
	
	/** Is Committer in a team
	 * 
	 * @param string $committerEMail
	 * @param string $teamName
	 * @return boolean
	 * @author droy
	 * @since 2015-05-06
	 */
	public function isCommitterInTeam($committerEMail, $teamName) {
		$rValue = false;
		$team = $this->getTeamByName($teamName);
		
		if($team !== FALSE) {
			foreach ($team->getCommitterList() as $committer) {
				if($committer == $committerEMail) {
					$rValue = true;
					break;
				}
			}
		}
		return $rValue;
	}

	/** Is committer of a repo
	 * 
	 * @param string $committerEMail
	 * @param string $repoName
	 * @return boolean
	 * @author droy
	 * @since 2015-05-06
	 */
	public function isCommitterOfRepo($committerEMail, $repoName) {
		$rValue = false;
		$this->logger->info("Checking $repoName for $committerEMail");
		$team = $this->getTeamByRepoName($repoName);
		if($team !== FALSE) {
			foreach ($team->getCommitterList() as $committer) {
				if($committer == $committerEMail) {
					$rValue = true;
					break;
				}
			}
		}
		return $rValue;
	}
	
	/**
	 * Return list of teams (array of Team objects)
	 * @return multitype:
	 */
	public function getTeamList() {
		return $this->teamList;
	}

	public function addTeam($team) {
		$rValue = FALSE;

		global $github_organization;
		$url = implode('/', array(
				GITHUB_ENDPOINT_URL,
				'orgs',
				$github_organization,
				'teams'
		));
		
		# create team on GitHub, then snag the ID
		global $client;
		echo "[Info] creating new team " . $team->getTeamName() . " at $url\n";
		$this->logger->info("Creating new team for project " . $team->getTeamName());
		$payload = new stdClass();
		$payload->name = $team->getTeamName();
		$payload->permission = "push";
		$payload->repo_names = $team->getRepoList();
		$resultObj = $client->post($url, $payload);
		print_r($resultObj);
		$team->setTeamID($resultObj->id);
		
		if(is_a($team, "Team")) {
			array_push($this->teamList, $team);
			$rValue = TRUE;
		}
		
		return $rValue;
	}

	/**
	 * Get Github Username From EMail
	 * @param string $committerEmail
	 * @return string Login name
	 * @author droy
	 */
	function getGithubLoginFromEMail($committerEmail) {
		return $this->ldap_client->getGithubLoginFromMail($committerEmail);
	}

}






/*
GH team json sample output:
Array
(
    [0] => stdClass Object
        (
            [Omitted]
        )

    [3] => stdClass Object
        (
            [name] => eclipse-birt
            [id] => 673977
            [slug] => eclipse-birt
            [description] => Birt Project Team
            [permission] => push
            [url] => https://api.github.com/teams/673977
            [members_url] => https://api.github.com/teams/673977/members{/member}
            [repositories_url] => https://api.github.com/teams/673977/repos
        )



GHTeamMembersjson:

Processing [eclipse-birt]
Array
(
    [0] => stdClass Object
        (
            [login] => droy
            [id] => 1234567
            [avatar_url] => 
            [gravatar_id] => 
            [url] => https://api.github.com/users/droy
            [html_url] => https://github.com/droy
            [followers_url] => https://api.github.com/users/droy/followers
            [following_url] => https://api.github.com/users/droy/following{/other_user}
            [repos_url] => https://api.github.com/users/droy/repos
            [type] => User
            [site_admin] => 
        )



GHTeamRepos:
Processing [eclipse-ponte]
Array
(
    [0] => stdClass Object
        (
            [id] => 18887610
            [name] => ponte
            [full_name] => eclipse/ponte
            [owner] => stdClass Object
                (
                )

            [private] => 
            [html_url] => https://github.com/eclipse/ponte
            [description] => Ponte Project
            [fork] => 
            [url] => https://api.github.com/repos/eclipse/ponte
            [forks_url] => https://api.github.com/repos/eclipse/ponte/forks
            [keys_url] => https://api.github.com/repos/eclipse/ponte/keys{/key_id}
            [collaborators_url] => https://api.github.com/repos/eclipse/ponte/collaborators{/collaborator}
            [teams_url] => https://api.github.com/repos/eclipse/ponte/teams
            [hooks_url] => https://api.github.com/repos/eclipse/ponte/hooks
            [watchers_count] => 115
            [language] => JavaScript
            [has_issues] => 
            [has_downloads] => 1
            [has_wiki] => 
            [has_pages] => 
            [forks_count] => 29
            [mirror_url] => 
            [open_issues_count] => 6
            [forks] => 29
            [open_issues] => 6
            [watchers] => 115
            [default_branch] => master
            [permissions] => stdClass Object
                (
                    [admin] => 1
                    [push] => 1
                    [pull] => 1
                )

        )

)


*/



?>