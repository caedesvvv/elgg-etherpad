<?php
/**
 * Elgg EtherPad
 *
 *
 */
class ElggPad extends ElggObject {
	
	protected $pad;
	public $groupID;
	public $authorID;
	
	/**
	 * Initialise the attributes array to include the type,
	 * title, and description.
	 *
	 * @return void
	 */
	protected function initializeAttributes() {
		parent::initializeAttributes();
		
		$this->attributes['subtype'] = "etherpad";
	}
	
	function save(){
		$guid = parent::save();
		
		try {
			$sessionID = $this->startSession();
			$groupID = $this->groupID;
			
			// Create a pad if not exists
			if (!$this->pname) {
				$name = uniqid();
				$this->get_pad_client()->createGroupPad($groupID, $name, elgg_get_plugin_setting('new_pad_text', 'etherpad'));
				$this->setMetaData('pname', $groupID . "$" . $name);
			}
			
			$padID = $this->getMetadata('pname');
			
			//set etherpad permissions
			if($this->access_id == ACCESS_PUBLIC) {
				$this->get_pad_client()->setPublicStatus($padID, "true");
			} else {
				$this->get_pad_client()->setPublicStatus($padID, "false");
			}
			
			$this->get_pad_client()->deleteSession($sessionID);
			
		} catch (Exception $e){
			return false;
		}
		
		return $guid;
	}
	
	function delete(){
		try {
			$this->startSession();
			$this->get_pad_client()->deletePad($this->getMetaData('pname'));
		} catch(Exception $e) {
			return false;
		}
		return parent::delete();
	}
	
	function get_pad_client(){
		if($this->pad){
			return $this->pad;
		}
		
		require_once(elgg_get_plugins_path() . 'etherpad/vendors/etherpad-lite-client.php');
		 
		// Etherpad: Create an instance
		$apikey = elgg_get_plugin_setting('etherpad_key', 'etherpad');
		$apiurl = elgg_get_plugin_setting('etherpad_host', 'etherpad') . "/api";
		$this->pad = new EtherpadLiteClient($apikey, $apiurl);
		return $this->pad;
	}
	
	function startSession(){
		if (isset($this->container_guid)) {
			$container = get_entity($this->container_guid);
		} else {
			$container = elgg_get_logged_in_user_entity();
		}

		if (isset($this->owner_guid)) {
			$user = get_entity($this->owner_guid);
		} else {
			$user = elgg_get_logged_in_user_entity();
		}

		//$site_mask = preg_replace('https?://', '@', elgg_get_site_url());
		$site_mask = str_replace('http://', '@', elgg_get_site_url());
		$site_mask = str_replace('https://', '@', $site_mask);

		//Etherpad: Create an etherpad group for the elgg container
	//	if (!isset($container->etherpad_group_id)) {
			$mappedGroup = $this->get_pad_client()->createGroupIfNotExistsFor($container->guid . $site_mask); 
			$container->etherpad_group_id = $mappedGroup->groupID;
	//	}
		$this->groupID = $container->etherpad_group_id;

		//Etherpad: Create an author(etherpad user) for logged in user
		//if (!isset($user->etherpad_author_id)) {
			$author = $this->get_pad_client()->createAuthorIfNotExistsFor($user->username . $site_mask);
			$user->etherpad_author_id = $author->authorID;
	//	}
		$this->authorID = $user->etherpad_author_id;

		//error_log("e $this->groupID $this->authorID");
		//Etherpad: Create session
		$validUntil = mktime(date("H"), date("i")+5, 0, date("m"), date("d"), date("y")); // 5 minutes in the future
		$session = $this->get_pad_client()->createSession($this->groupID, $this->authorID, $validUntil);
		$sessionID = $session->sessionID;
		
		$domain = "." . parse_url(elgg_get_site_url(), PHP_URL_HOST);
		
		if(!setcookie('sessionID', $sessionID, $validUntil, '/', $domain)){
			throw new Exception(elgg_echo('etherpad:error:cookies_required'));
		}
		
		return $sessionID;
	}
	
	protected function getAddress(){
		return elgg_get_plugin_setting('etherpad_host', 'etherpad') . "/p/". $this->getMetadata('pname');
	}
	
	protected function getTimesliderAddress(){
		return $this->getAddress() . "/timeslider";
	}
	
	protected function getReadOnlyAddress(){
		if($this->getMetadata('readOnlyID')){
			$readonly = $this->getMetadata('readOnlyID');
		} else {
			$padID = $this->getMetadata('pname');
			$readonly = $this->get_pad_client()->getReadOnlyID($padID)->readOnlyID;
			$this->setMetaData('readOnlyID', $readonly);
		}
		return elgg_get_plugin_setting('etherpad_host', 'etherpad') . "/ro/". $readonly;
	}
	
	function getPadPath($timeslider = false){
		$settings = array('show_controls', 'monospace_font', 'show_chat', 'line_numbers');
		
		if(elgg_is_logged_in()) {
			$name = elgg_get_logged_in_user_entity()->name;
		} else {
			$name = 'undefined';
		}
		
		array_walk($settings, function(&$setting) {
			if(elgg_get_plugin_setting($setting, 'etherpad') == 'no') {
				$setting = 'false';
			} else {
				$setting = 'true';
			}
		});
		
		$options = '?' . http_build_query(array(
			'userName' => $name,
			'showControls' => $settings[0],
			'useMonospaceFont' => $settings[1],
			'showChat' => $settings[2],
			'showLineNumbers' => $settings[3],
		));
		
		$this->startSession();
		
		if($this->canEdit() && !$timeslider) {
			return $this->getAddress() . $options;
		} elseif ($this->canEdit() && $timeslider) {
			return $this->getTimesliderAddress() . $options;
		} else {
			return $this->getReadOnlyAddress() . $options;
		}
	}
}
