<?php
/*=================================================================================*\
|| ############################################################################### ||
|| # Product Name: Periodic Prune Pms                             Version: 1.0.0 # ||
|| # Licence Number: Free License                                                # ||
|| # --------------------------------------------------------------------------- # ||
|| #                                                                             # ||
|| #            Copyright ©2005-2008 PHP KingDom. Some Rights Reserved.          # ||
|| #      This file may be redistributed in whole or significant part under      # ||
|| #   "Creative Commons - Attribution-Noncommercial-Share Alike 3.0 Unported"   # ||
|| # 																			 # ||
|| # ------------------ 'Periodic Prune Pms' IS FREE SOFTWARE ------------------ # ||
|| #        http://www.phpkd.net | http://www.phpkd.net/info/license/free        # ||
|| ############################################################################### ||
\*=================================================================================*/

// ######################## SET PHP ENVIRONMENT ###########################
error_reporting(E_ALL & ~E_NOTICE);
if (!is_object($vbulletin->db))
{
	exit;
}

// ########################################################################
// ######################### START MAIN SCRIPT ############################
// ########################################################################

if ($vbulletin->options['phpkd_periodic_prune_pms_active'])
{
	// ###################### Start pm update counters #######################
	// update the pm counters for $userinfo
	function build_pm_counters(&$userid)
	{
		global $vbulletin;

		$userinfo = fetch_userinfo($userid);

		$pmcount = $vbulletin->db->query_first("
			SELECT
				COUNT(pmid) AS pmtotal,
				SUM(IF(messageread = 0 AND folderid >= 0, 1, 0)) AS pmunread
			FROM " . TABLE_PREFIX . "pm AS pm
			WHERE pm.userid = '$userid'
		");

		$pmcount['pmtotal'] = intval($pmcount['pmtotal']);
		$pmcount['pmunread'] = intval($pmcount['pmunread']);

		if ($userinfo['pmtotal'] != $pmcount['pmtotal'] OR $userinfo['pmunread'] != $pmcount['pmunread'])
		{
			// init user data manager
			$userdata =& datamanager_init('User', $vbulletin, ERRTYPE_STANDARD);
			$userdata->set_existing($userinfo);
			$userdata->set('pmtotal', $pmcount['pmtotal']);
			$userdata->set('pmunread', $pmcount['pmunread']);
			$userdata->save();
		}
	}

	$pms_query_where_clouse = "";

	// Choose PM Folders to empty it (inbox/outbox/any foldeer other than in & out/ALL)
	if ($vbulletin->options['phpkd_periodic_prune_pms_folders'] > 0)
	{
		switch ($vbulletin->options['phpkd_periodic_prune_pms_folders'])
		{
			case '1':
				$pms_query_where_clouse .= " AND pm.folderid = '0'";
				break;
			case '2':
				$pms_query_where_clouse .= " AND pm.folderid = '-1'";
				break;
			case '3':
				$pms_query_where_clouse .= " AND pm.folderid NOT IN (0, -1)";
				break;
		}
	}

	// Choose Message Status to be deleted (read/unread/both)
	if ($vbulletin->options['phpkd_periodic_prune_pms_status'] != 2)
	{
		switch ($vbulletin->options['phpkd_periodic_prune_pms_status'])
		{
			case '0':
				$pms_query_where_clouse .= " AND pm.messageread = '1'";
				break;
			case '1':
				$pms_query_where_clouse .= " AND pm.messageread = '0'";
				break;
		}
	}

	// Determine usergroups that we should prune their PMs
	$groups = $vbulletin->db->query_read("SELECT usergroupid, phpkd_pmpruneperiod FROM " . TABLE_PREFIX . "usergroup WHERE phpkd_pmpruneperiod > 0");

	while ($group = $vbulletin->db->fetch_array($groups))
	{
		// Determine users that we should prune their PMs
		$users = $vbulletin->db->query_read("
			SELECT user.userid, user.usergroupid, usergroup.usergroupid, usergroup.phpkd_pmpruneperiod AS prunedays
			FROM " . TABLE_PREFIX . "user AS user
			INNER JOIN " . TABLE_PREFIX . "usergroup AS usergroup ON (usergroup.usergroupid = user.usergroupid)
			WHERE user.usergroupid = '" . $group['usergroupid'] . "'
		");

		while ($user = $vbulletin->db->fetch_array($users))
		{
			// get the pmid and pmtext id of messages to be pruned
			$pms = $vbulletin->db->query_read("
				SELECT pm.*, pmtext.pmtextid, pmtext.dateline
				FROM " . TABLE_PREFIX . "pm AS pm
				INNER JOIN " . TABLE_PREFIX . "pmtext AS pmtext ON (pmtext.pmtextid = pm.pmtextid)
				WHERE pm.userid = '" . $user['userid'] . "' AND pmtext.dateline <= '" . (TIMENOW - ($user['prunedays'] * 86400)) . "'
				$pms_query_where_clouse
			");

			while ($pm = $vbulletin->db->fetch_array($pms))
			{
				// delete from the pm table using the results from above
				$vbulletin->db->query_write("DELETE FROM " . TABLE_PREFIX . "pm WHERE pmid = '" . $pm['pmid'] . "'");

				// Update User's PM Counters
				build_pm_counters($pm['userid']);
			}
		}
	}
}

log_cron_action('', $nextitem, 1);

/*=================================================================================*\
|| ############################################################################### ||
|| # Version.: 1.0.0
|| # Revision: $Revision$
|| # Released: $Date$
|| ############################################################################### ||
\*=================================================================================*/
?>