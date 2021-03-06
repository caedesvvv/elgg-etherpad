<?php

$options = array(
        'type' => 'object',
        'subtypes' => array('page', 'page_top'),
        'limit' => 1,
        'metadata_name' => 'ispad',
        'metadata_value' => 1,
);

$topics = elgg_get_entities_from_metadata($options);

// if not topics, no upgrade required
if (!$topics) {
	error_log("etherpad upgrade no elements!!");
	return;
}

$apikey = elgg_get_plugin_setting('etherpad_key', 'etherpad');

if (!$apikey) {
	error_log("etherpad not configured, can't upgrade");
	return;
}


/**
 * Save previous author id
 */
function etherpad_user_2012100501($user) {
	$user->etherpad_author_id = $user->username;
	return true;
}

/**
 * Save previous pad name, adapt subtype, river, clean up.
 */
function etherpad_pad_2012100501($pad_entity) {
	require_once(elgg_get_plugins_path() . 'upgrade-tools/lib/upgrade_tools.php');

	global $DB_QUERY_CACHE;
	error_log("pad $pad_entity->guid");

	// pad name
	$site_name = elgg_get_site_url();
	$pname = 'elgg-entitypad-'.md5($site_name).'-'.$pad_entity->guid;
	$pad_entity->pname = $pname;

	// adapt river
	$options = array('object_guid' => $pad_entity->guid);
	$items = elgg_get_river($options);
	foreach($items as $item) {
		if ($item->action_type == 'create') {
			upgrade_update_river($item->id, 'river/object/etherpad/create', $pad_entity->guid, 0);
		}
		elseif ($item->action_type == 'update') {
			elgg_delete_river(array('id' => $item->id));
		}
	}

	// clean up
	$pad_entity->deleteMetadata('ispad');
	$pad_entity->deleteAnnotations('page');

	// copy to a brand new pad
	$container = $pad_entity->getContainerEntity();

	// get as pad
	elgg_reset_system_cache();
	 if ($DB_QUERY_CACHE) {
                $DB_QUERY_CACHE->clear();
        }

	// set subtype
	if ($pad_entity->getSubtype() == 'page_top') {
		upgrade_change_subtype($pad_entity, 'etherpad');
	}
	elseif($pad_entity->getSubtype() == 'page') {
		upgrade_change_subtype($pad_entity, 'subpad');
	} else {
		return true;
	}

	$pad = new ElggPad($pad_entity->guid);

	// prepare session
	$session_id = $pad->startSession();
	$pad_client = $pad->get_pad_client();
	$old_id = $pad->pname;

	// get old text
	try {
		$result = $pad_client->getText($old_id);
	} catch(Exception $e) {
	}

	if ($result && $result->text) {
		$text = $result->text;
	}
	else {
		error_log("problems bailing out");
		return;
	}

	// create new pad
	$name = uniqid();
	$pad_client->createGroupPad($pad->groupID, $name, $text);
	$pad->pname = $pad->groupID . "$" . $name;

	// delete old pad
	$pad_client->deletePad($old_id);

	// end session
	$pad_client->deleteSession($session_id);

	// now save to apply permissions
	$pad->save();

	return true;
}

/*
 * Run upgrade. First users, then pads
 */
// users
$options = array('type' => 'user', 'limit' => 0);

$previous_access = elgg_set_ignore_access(true);
$batch = new ElggBatch('elgg_get_entities', $options, "etherpad_user_2012100501", 100);
elgg_set_ignore_access($previous_access);

if ($batch->callbackResult) {
	error_log("Elgg Etherpad users upgrade (201210050) succeeded");
} else {
	error_log("Elgg Etherpad users upgrade (201210050) failed");
}

// pads
$options = array(
        'type' => 'object',
        'subtypes' => array('page', 'page_top'),
        'limit' => 0,
        'metadata_name' => 'ispad',
        'metadata_value' => 1,
);

$previous_access = elgg_set_ignore_access(true);
$batch = new ElggBatch('elgg_get_entities_from_metadata', $options, "etherpad_pad_2012100501", 100);
elgg_set_ignore_access($previous_access);

if ($batch->callbackResult) {
	error_log("Elgg Etherpad pads upgrade (201210050) succeeded");
} else {
	error_log("Elgg Etherpad pads upgrade (201210050) failed");
}


