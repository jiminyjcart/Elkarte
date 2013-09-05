<?php

/**
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * This software is a derived product, based on:
 *
 * Simple Machines Forum (SMF)
 * copyright:	2011 Simple Machines (http://www.simplemachines.org)
 * license:  	BSD, See included LICENSE.TXT for terms and conditions.
 *
 * @version 1.0 Alpha
 *
 * This file contains functions for dealing with topics. Low-level functions,
 * i.e. database operations needed to perform.
 * These functions do NOT make permissions checks. (they assume those were
 * already made).
 *
 */

if (!defined('ELK'))
	die('No access...');

/**
 * Removes the passed id_topic's. (permissions are NOT checked here!).
 *
 * @param array/int $topics The topics to remove (can be an id or an array of ids).
 * @param bool $decreasePostCount if true users' post count will be reduced
 * @param bool $ignoreRecycling if true topics are not moved to the recycle board (if it exists).
 */
function removeTopics($topics, $decreasePostCount = true, $ignoreRecycling = false)
{
	global $modSettings;

	$db = database();

	// Nothing to do?
	if (empty($topics))
		return;

	// Only a single topic.
	if (is_numeric($topics))
		$topics = array($topics);

	// Decrease the post counts for members.
	if ($decreasePostCount)
	{
		$requestMembers = $db->query('', '
			SELECT m.id_member, COUNT(*) AS posts
			FROM {db_prefix}messages AS m
				INNER JOIN {db_prefix}boards AS b ON (b.id_board = m.id_board)
			WHERE m.id_topic IN ({array_int:topics})
				AND m.icon != {string:recycled}
				AND b.count_posts = {int:do_count_posts}
				AND m.approved = {int:is_approved}
			GROUP BY m.id_member',
			array(
				'do_count_posts' => 0,
				'recycled' => 'recycled',
				'topics' => $topics,
				'is_approved' => 1,
			)
		);
		if ($db->num_rows($requestMembers) > 0)
		{
			while ($rowMembers = $db->fetch_assoc($requestMembers))
				updateMemberData($rowMembers['id_member'], array('posts' => 'posts - ' . $rowMembers['posts']));
		}
		$db->free_result($requestMembers);
	}

	// Recycle topics that aren't in the recycle board...
	if (!empty($modSettings['recycle_enable']) && $modSettings['recycle_board'] > 0 && !$ignoreRecycling)
	{
		$request = $db->query('', '
			SELECT id_topic, id_board, unapproved_posts, approved
			FROM {db_prefix}topics
			WHERE id_topic IN ({array_int:topics})
				AND id_board != {int:recycle_board}
			LIMIT ' . count($topics),
			array(
				'recycle_board' => $modSettings['recycle_board'],
				'topics' => $topics,
			)
		);
		if ($db->num_rows($request) > 0)
		{
			// Get topics that will be recycled.
			$recycleTopics = array();
			while ($row = $db->fetch_assoc($request))
			{
				if (function_exists('apache_reset_timeout'))
					@apache_reset_timeout();

				$recycleTopics[] = $row['id_topic'];

				// Set the id_previous_board for this topic - and make it not sticky.
				$db->query('', '
					UPDATE {db_prefix}topics
					SET id_previous_board = {int:id_previous_board}, is_sticky = {int:not_sticky}
					WHERE id_topic = {int:id_topic}',
					array(
						'id_previous_board' => $row['id_board'],
						'id_topic' => $row['id_topic'],
						'not_sticky' => 0,
					)
				);
			}
			$db->free_result($request);

			// Mark recycled topics as recycled.
			$db->query('', '
				UPDATE {db_prefix}messages
				SET icon = {string:recycled}
				WHERE id_topic IN ({array_int:recycle_topics})',
				array(
					'recycle_topics' => $recycleTopics,
					'recycled' => 'recycled',
				)
			);

			// Move the topics to the recycle board.
			require_once(SUBSDIR . '/Topic.subs.php');
			moveTopics($recycleTopics, $modSettings['recycle_board']);

			// Close reports that are being recycled.
			require_once(SUBSDIR . '/Moderation.subs.php');

			$db->query('', '
				UPDATE {db_prefix}log_reported
				SET closed = {int:is_closed}
				WHERE id_topic IN ({array_int:recycle_topics})',
				array(
					'recycle_topics' => $recycleTopics,
					'is_closed' => 1,
				)
			);

			updateSettings(array('last_mod_report_action' => time()));
			recountOpenReports();

			// Topics that were recycled don't need to be deleted, so subtract them.
			$topics = array_diff($topics, $recycleTopics);
		}
		else
			$db->free_result($request);
	}

	// Still topics left to delete?
	if (empty($topics))
		return;

	$adjustBoards = array();

	// Find out how many posts we are deleting.
	$request = $db->query('', '
		SELECT id_board, approved, COUNT(*) AS num_topics, SUM(unapproved_posts) AS unapproved_posts,
			SUM(num_replies) AS num_replies
		FROM {db_prefix}topics
		WHERE id_topic IN ({array_int:topics})
		GROUP BY id_board, approved',
		array(
			'topics' => $topics,
		)
	);
	while ($row = $db->fetch_assoc($request))
	{
		if (!isset($adjustBoards[$row['id_board']]['num_posts']))
		{
			$adjustBoards[$row['id_board']] = array(
				'num_posts' => 0,
				'num_topics' => 0,
				'unapproved_posts' => 0,
				'unapproved_topics' => 0,
				'id_board' => $row['id_board']
			);
		}
		// Posts = (num_replies + 1) for each approved topic.
		$adjustBoards[$row['id_board']]['num_posts'] += $row['num_replies'] + ($row['approved'] ? $row['num_topics'] : 0);
		$adjustBoards[$row['id_board']]['unapproved_posts'] += $row['unapproved_posts'];

		// Add the topics to the right type.
		if ($row['approved'])
			$adjustBoards[$row['id_board']]['num_topics'] += $row['num_topics'];
		else
			$adjustBoards[$row['id_board']]['unapproved_topics'] += $row['num_topics'];
	}
	$db->free_result($request);

	// Decrease number of posts and topics for each board.
	foreach ($adjustBoards as $stats)
	{
		if (function_exists('apache_reset_timeout'))
			@apache_reset_timeout();

		$db->query('', '
			UPDATE {db_prefix}boards
			SET
				num_posts = CASE WHEN {int:num_posts} > num_posts THEN 0 ELSE num_posts - {int:num_posts} END,
				num_topics = CASE WHEN {int:num_topics} > num_topics THEN 0 ELSE num_topics - {int:num_topics} END,
				unapproved_posts = CASE WHEN {int:unapproved_posts} > unapproved_posts THEN 0 ELSE unapproved_posts - {int:unapproved_posts} END,
				unapproved_topics = CASE WHEN {int:unapproved_topics} > unapproved_topics THEN 0 ELSE unapproved_topics - {int:unapproved_topics} END
			WHERE id_board = {int:id_board}',
			array(
				'id_board' => $stats['id_board'],
				'num_posts' => $stats['num_posts'],
				'num_topics' => $stats['num_topics'],
				'unapproved_posts' => $stats['unapproved_posts'],
				'unapproved_topics' => $stats['unapproved_topics'],
			)
		);
	}

	// Remove polls for these topics.
	$request = $db->query('', '
		SELECT id_poll
		FROM {db_prefix}topics
		WHERE id_topic IN ({array_int:topics})
			AND id_poll > {int:no_poll}
		LIMIT ' . count($topics),
		array(
			'no_poll' => 0,
			'topics' => $topics,
		)
	);
	$polls = array();
	while ($row = $db->fetch_assoc($request))
		$polls[] = $row['id_poll'];
	$db->free_result($request);

	if (!empty($polls))
	{
		$db->query('', '
			DELETE FROM {db_prefix}polls
			WHERE id_poll IN ({array_int:polls})',
			array(
				'polls' => $polls,
			)
		);
		$db->query('', '
			DELETE FROM {db_prefix}poll_choices
			WHERE id_poll IN ({array_int:polls})',
			array(
				'polls' => $polls,
			)
		);
		$db->query('', '
			DELETE FROM {db_prefix}log_polls
			WHERE id_poll IN ({array_int:polls})',
			array(
				'polls' => $polls,
			)
		);
	}

	// Get rid of the attachment(s).
	require_once(SUBSDIR . '/Attachments.subs.php');
	$attachmentQuery = array(
		'attachment_type' => 0,
		'id_topic' => $topics,
	);
	removeAttachments($attachmentQuery, 'messages');

	// Delete search index entries.
	if (!empty($modSettings['search_custom_index_config']))
	{
		$customIndexSettings = unserialize($modSettings['search_custom_index_config']);

		$words = array();
		$messages = array();
		$request = $db->query('', '
			SELECT id_msg, body
			FROM {db_prefix}messages
			WHERE id_topic IN ({array_int:topics})',
			array(
				'topics' => $topics,
			)
		);
		while ($row = $db->fetch_assoc($request))
		{
			if (function_exists('apache_reset_timeout'))
				@apache_reset_timeout();

			$words = array_merge($words, text2words($row['body'], $customIndexSettings['bytes_per_word'], true));
			$messages[] = $row['id_msg'];
		}
		$db->free_result($request);
		$words = array_unique($words);

		if (!empty($words) && !empty($messages))
			$db->query('', '
				DELETE FROM {db_prefix}log_search_words
				WHERE id_word IN ({array_int:word_list})
					AND id_msg IN ({array_int:message_list})',
				array(
					'word_list' => $words,
					'message_list' => $messages,
				)
			);
	}

	// Reuse the message array if available
	if (empty($messages))
		$messages = messagesInTopics($topics);

	// Remove all likes now that the topic is gone
	$db->query('', '
		DELETE FROM {db_prefix}message_likes
		WHERE id_msg IN ({array_int:messages})',
		array(
			'messages' => $messages,
		)
	);

	// Delete messages in each topic.
	$db->query('', '
		DELETE FROM {db_prefix}messages
		WHERE id_topic IN ({array_int:topics})',
		array(
			'topics' => $topics,
		)
	);

	// Remove linked calendar events.
	// @todo if unlinked events are enabled, wouldn't this be expected to keep them?
	$db->query('', '
		DELETE FROM {db_prefix}calendar
		WHERE id_topic IN ({array_int:topics})',
		array(
			'topics' => $topics,
		)
	);

	// Delete log_topics data
	$db->query('', '
		DELETE FROM {db_prefix}log_topics
		WHERE id_topic IN ({array_int:topics})',
		array(
			'topics' => $topics,
		)
	);

	// Delete notifications
	$db->query('', '
		DELETE FROM {db_prefix}log_notify
		WHERE id_topic IN ({array_int:topics})',
		array(
			'topics' => $topics,
		)
	);

	// Delete the topics themselves
	$db->query('', '
		DELETE FROM {db_prefix}topics
		WHERE id_topic IN ({array_int:topics})',
		array(
			'topics' => $topics,
		)
	);

	// Remove data from the subjects for search cache
	$db->query('', '
		DELETE FROM {db_prefix}log_search_subjects
		WHERE id_topic IN ({array_int:topics})',
		array(
			'topics' => $topics,
		)
	);
	require_once(SUBSDIR . '/FollowUps.subs.php');
	removeFollowUpsByTopic($topics);

	// Maybe there's an add-on that wants to delete topic related data of its own
	call_integration_hook('integrate_remove_topics', array($topics));

	// Update the totals...
	updateStats('message');
	updateStats('topic');
	updateSettings(array(
		'calendar_updated' => time(),
	));

	require_once(SUBSDIR . '/Post.subs.php');
	$updates = array();
	foreach ($adjustBoards as $stats)
		$updates[] = $stats['id_board'];
	updateLastMessages($updates);
}

/**
 * Moves one or more topics to a specific board.
 * Determines the source boards for the supplied topics
 * Handles the moving of mark_read data
 * Updates the posts count of the affected boards
 * This function doesn't check permissions.
 *
 * @param array $topics
 * @param int $toBoard
 */
function moveTopics($topics, $toBoard)
{
	global $user_info, $modSettings;

	$db = database();

	// Empty array?
	if (empty($topics))
		return;

	// Only a single topic.
	if (is_numeric($topics))
		$topics = array($topics);

	$fromBoards = array();

	// Destination board empty or equal to 0?
	if (empty($toBoard))
		return;

	// Are we moving to the recycle board?
	$isRecycleDest = !empty($modSettings['recycle_enable']) && $modSettings['recycle_board'] == $toBoard;

	// Determine the source boards...
	$request = $db->query('', '
		SELECT id_board, approved, COUNT(*) AS num_topics, SUM(unapproved_posts) AS unapproved_posts,
			SUM(num_replies) AS num_replies
		FROM {db_prefix}topics
		WHERE id_topic IN ({array_int:topics})
		GROUP BY id_board, approved',
		array(
			'topics' => $topics,
		)
	);
	// Num of rows = 0 -> no topics found. Num of rows > 1 -> topics are on multiple boards.
	if ($db->num_rows($request) == 0)
		return;
	while ($row = $db->fetch_assoc($request))
	{
		if (!isset($fromBoards[$row['id_board']]['num_posts']))
		{
			$fromBoards[$row['id_board']] = array(
				'num_posts' => 0,
				'num_topics' => 0,
				'unapproved_posts' => 0,
				'unapproved_topics' => 0,
				'id_board' => $row['id_board']
			);
		}
		// Posts = (num_replies + 1) for each approved topic.
		$fromBoards[$row['id_board']]['num_posts'] += $row['num_replies'] + ($row['approved'] ? $row['num_topics'] : 0);
		$fromBoards[$row['id_board']]['unapproved_posts'] += $row['unapproved_posts'];

		// Add the topics to the right type.
		if ($row['approved'])
			$fromBoards[$row['id_board']]['num_topics'] += $row['num_topics'];
		else
			$fromBoards[$row['id_board']]['unapproved_topics'] += $row['num_topics'];
	}
	$db->free_result($request);

	// Move over the mark_read data. (because it may be read and now not by some!)
	$SaveAServer = max(0, $modSettings['maxMsgID'] - 50000);
	$request = $db->query('', '
		SELECT lmr.id_member, lmr.id_msg, t.id_topic, IFNULL(lt.disregarded, 0) as disregarded
		FROM {db_prefix}topics AS t
			INNER JOIN {db_prefix}log_mark_read AS lmr ON (lmr.id_board = t.id_board
				AND lmr.id_msg > t.id_first_msg AND lmr.id_msg > {int:protect_lmr_msg})
			LEFT JOIN {db_prefix}log_topics AS lt ON (lt.id_topic = t.id_topic AND lt.id_member = lmr.id_member)
		WHERE t.id_topic IN ({array_int:topics})
			AND lmr.id_msg > IFNULL(lt.id_msg, 0)',
		array(
			'protect_lmr_msg' => $SaveAServer,
			'topics' => $topics,
		)
	);
	$log_topics = array();
	while ($row = $db->fetch_assoc($request))
	{
		$log_topics[] = array($row['id_member'], $row['id_topic'], $row['id_msg'], $row['disregarded']);

		// Prevent queries from getting too big. Taking some steam off.
		if (count($log_topics) > 500)
		{
			markTopicsRead($log_topics, true);
			$log_topics = array();
		}
	}
	$db->free_result($request);

	// Now that we have all the topics that *should* be marked read, and by which members...
	if (!empty($log_topics))
	{
		// Insert that information into the database!
		markTopicsRead($log_topics, true);
	}

	// Update the number of posts on each board.
	$totalTopics = 0;
	$totalPosts = 0;
	$totalUnapprovedTopics = 0;
	$totalUnapprovedPosts = 0;
	foreach ($fromBoards as $stats)
	{
		$db->query('', '
			UPDATE {db_prefix}boards
			SET
				num_posts = CASE WHEN {int:num_posts} > num_posts THEN 0 ELSE num_posts - {int:num_posts} END,
				num_topics = CASE WHEN {int:num_topics} > num_topics THEN 0 ELSE num_topics - {int:num_topics} END,
				unapproved_posts = CASE WHEN {int:unapproved_posts} > unapproved_posts THEN 0 ELSE unapproved_posts - {int:unapproved_posts} END,
				unapproved_topics = CASE WHEN {int:unapproved_topics} > unapproved_topics THEN 0 ELSE unapproved_topics - {int:unapproved_topics} END
			WHERE id_board = {int:id_board}',
			array(
				'id_board' => $stats['id_board'],
				'num_posts' => $stats['num_posts'],
				'num_topics' => $stats['num_topics'],
				'unapproved_posts' => $stats['unapproved_posts'],
				'unapproved_topics' => $stats['unapproved_topics'],
			)
		);
		$totalTopics += $stats['num_topics'];
		$totalPosts += $stats['num_posts'];
		$totalUnapprovedTopics += $stats['unapproved_topics'];
		$totalUnapprovedPosts += $stats['unapproved_posts'];
	}
	$db->query('', '
		UPDATE {db_prefix}boards
		SET
			num_topics = num_topics + {int:total_topics},
			num_posts = num_posts + {int:total_posts},' . ($isRecycleDest ? '
			unapproved_posts = {int:no_unapproved}, unapproved_topics = {int:no_unapproved}' : '
			unapproved_posts = unapproved_posts + {int:total_unapproved_posts},
			unapproved_topics = unapproved_topics + {int:total_unapproved_topics}') . '
		WHERE id_board = {int:id_board}',
		array(
			'id_board' => $toBoard,
			'total_topics' => $totalTopics,
			'total_posts' => $totalPosts,
			'total_unapproved_topics' => $totalUnapprovedTopics,
			'total_unapproved_posts' => $totalUnapprovedPosts,
			'no_unapproved' => 0,
		)
	);

	// Move the topic.  Done.  :P
	$db->query('', '
		UPDATE {db_prefix}topics
		SET id_board = {int:id_board}' . ($isRecycleDest ? ',
			unapproved_posts = {int:no_unapproved}, approved = {int:is_approved}' : '') . '
		WHERE id_topic IN ({array_int:topics})',
		array(
			'id_board' => $toBoard,
			'topics' => $topics,
			'is_approved' => 1,
			'no_unapproved' => 0,
		)
	);

	// If this was going to the recycle bin, check what messages are being recycled, and remove them from the queue.
	if ($isRecycleDest && ($totalUnapprovedTopics || $totalUnapprovedPosts))
	{
		$request = $db->query('', '
			SELECT id_msg
			FROM {db_prefix}messages
			WHERE id_topic IN ({array_int:topics})
				and approved = {int:not_approved}',
			array(
				'topics' => $topics,
				'not_approved' => 0,
			)
		);
		$approval_msgs = array();
		while ($row = $db->fetch_assoc($request))
			$approval_msgs[] = $row['id_msg'];
		$db->free_result($request);

		// Empty the approval queue for these, as we're going to approve them next.
		if (!empty($approval_msgs))
			$db->query('', '
				DELETE FROM {db_prefix}approval_queue
				WHERE id_msg IN ({array_int:message_list})
					AND id_attach = {int:id_attach}',
				array(
					'message_list' => $approval_msgs,
					'id_attach' => 0,
				)
			);

		// Get all the current max and mins.
		$request = $db->query('', '
			SELECT id_topic, id_first_msg, id_last_msg
			FROM {db_prefix}topics
			WHERE id_topic IN ({array_int:topics})',
			array(
				'topics' => $topics,
			)
		);
		$topicMaxMin = array();
		while ($row = $db->fetch_assoc($request))
		{
			$topicMaxMin[$row['id_topic']] = array(
				'min' => $row['id_first_msg'],
				'max' => $row['id_last_msg'],
			);
		}
		$db->free_result($request);

		// Check the MAX and MIN are correct.
		$request = $db->query('', '
			SELECT id_topic, MIN(id_msg) AS first_msg, MAX(id_msg) AS last_msg
			FROM {db_prefix}messages
			WHERE id_topic IN ({array_int:topics})
			GROUP BY id_topic',
			array(
				'topics' => $topics,
			)
		);
		while ($row = $db->fetch_assoc($request))
		{
			// If not, update.
			if ($row['first_msg'] != $topicMaxMin[$row['id_topic']]['min'] || $row['last_msg'] != $topicMaxMin[$row['id_topic']]['max'])
				$db->query('', '
					UPDATE {db_prefix}topics
					SET id_first_msg = {int:first_msg}, id_last_msg = {int:last_msg}
					WHERE id_topic = {int:selected_topic}',
					array(
						'first_msg' => $row['first_msg'],
						'last_msg' => $row['last_msg'],
						'selected_topic' => $row['id_topic'],
					)
				);
		}
		$db->free_result($request);
	}

	$db->query('', '
		UPDATE {db_prefix}messages
		SET id_board = {int:id_board}' . ($isRecycleDest ? ',approved = {int:is_approved}' : '') . '
		WHERE id_topic IN ({array_int:topics})',
		array(
			'id_board' => $toBoard,
			'topics' => $topics,
			'is_approved' => 1,
		)
	);
	$db->query('', '
		UPDATE {db_prefix}log_reported
		SET id_board = {int:id_board}
		WHERE id_topic IN ({array_int:topics})',
		array(
			'id_board' => $toBoard,
			'topics' => $topics,
		)
	);
	$db->query('', '
		UPDATE {db_prefix}calendar
		SET id_board = {int:id_board}
		WHERE id_topic IN ({array_int:topics})',
		array(
			'id_board' => $toBoard,
			'topics' => $topics,
		)
	);

	// Mark target board as seen, if it was already marked as seen before.
	$request = $db->query('', '
		SELECT (IFNULL(lb.id_msg, 0) >= b.id_msg_updated) AS isSeen
		FROM {db_prefix}boards AS b
			LEFT JOIN {db_prefix}log_boards AS lb ON (lb.id_board = b.id_board AND lb.id_member = {int:current_member})
		WHERE b.id_board = {int:id_board}',
		array(
			'current_member' => $user_info['id'],
			'id_board' => $toBoard,
		)
	);
	list ($isSeen) = $db->fetch_row($request);
	$db->free_result($request);

	if (!empty($isSeen) && !$user_info['is_guest'])
	{
		$db->insert('replace',
			'{db_prefix}log_boards',
			array('id_board' => 'int', 'id_member' => 'int', 'id_msg' => 'int'),
			array($toBoard, $user_info['id'], $modSettings['maxMsgID']),
			array('id_board', 'id_member')
		);
	}

	// Update the cache?
	if (!empty($modSettings['cache_enable']) && $modSettings['cache_enable'] >= 3)
		foreach ($topics as $topic_id)
			cache_put_data('topic_board-' . $topic_id, null, 120);

	require_once(SUBSDIR . '/Post.subs.php');

	$updates = array_keys($fromBoards);
	$updates[] = $toBoard;

	updateLastMessages(array_unique($updates));

	// Update 'em pesky stats.
	updateStats('topic');
	updateStats('message');
	updateSettings(array(
		'calendar_updated' => time(),
	));
}

/**
 * Called after a topic is moved to update $board_link and $topic_link to point to new location
 */
function moveTopicConcurrence()
{
	global $board, $topic, $scripturl;

	$db = database();

	if (isset($_GET['current_board']))
		$move_from = (int) $_GET['current_board'];

	if (empty($move_from) || empty($board) || empty($topic))
		return true;

	if ($move_from == $board)
		return true;
	else
	{
		$request = $db->query('', '
			SELECT m.subject, b.name
			FROM {db_prefix}topics as t
				LEFT JOIN {db_prefix}boards AS b ON (t.id_board = b.id_board)
				LEFT JOIN {db_prefix}messages AS m ON (t.id_first_msg = m.id_msg)
			WHERE t.id_topic = {int:topic_id}
			LIMIT 1',
			array(
				'topic_id' => $topic,
			)
		);
		list($topic_subject, $board_name) = $db->fetch_row($request);
		$db->free_result($request);

		$board_link = '<a href="' . $scripturl . '?board=' . $board . '.0">' . $board_name . '</a>';
		$topic_link = '<a href="' . $scripturl . '?topic=' . $topic . '.0">' . $topic_subject . '</a>';
		fatal_lang_error('topic_already_moved', false, array($topic_link, $board_link));
	}
}

/**
 * Increase the number of views of this topic.
 *
 * @param int $id_topic, the topic being viewed or whatnot.
 */
function increaseViewCounter($id_topic)
{
	$db = database();

	$db->query('', '
		UPDATE {db_prefix}topics
		SET num_views = num_views + 1
		WHERE id_topic = {int:current_topic}',
		array(
			'current_topic' => $id_topic,
		)
	);
}

/**
 * Mark topic(s) as read by the given member, at the specified message.
 *
 * @param array $mark_topics array($id_member, $id_topic, $id_msg)
 * @param bool $was_set = false - whether the topic has been previously read by the user
 */
function markTopicsRead($mark_topics, $was_set = false)
{
	$db = database();

	if (!is_array($mark_topics))
		return;

	$db->insert($was_set ? 'replace' : 'ignore',
		'{db_prefix}log_topics',
		array(
			'id_member' => 'int', 'id_topic' => 'int', 'id_msg' => 'int', 'disregarded' => 'int',
		),
		$mark_topics,
		array('id_member', 'id_topic')
	);
}

/**
 * Update user notifications for a topic... or the board it's in.
 * @todo look at board notification...
 *
 * @param int $id_topic
 * @param int $id_board
 */
function updateReadNotificationsFor($id_topic, $id_board)
{
	global $user_info, $context;

	$db = database();

	// Check for notifications on this topic OR board.
	$request = $db->query('', '
		SELECT sent, id_topic
		FROM {db_prefix}log_notify
		WHERE (id_topic = {int:current_topic} OR id_board = {int:current_board})
			AND id_member = {int:current_member}
		LIMIT 2',
		array(
			'current_board' => $id_board,
			'current_member' => $user_info['id'],
			'current_topic' => $id_topic,
		)
	);

	while ($row = $db->fetch_assoc($request))
	{
		// Find if this topic is marked for notification...
		if (!empty($row['id_topic']))
			$context['is_marked_notify'] = true;

		// Only do this once, but mark the notifications as "not sent yet" for next time.
		if (!empty($row['sent']))
		{
			$db->query('', '
				UPDATE {db_prefix}log_notify
				SET sent = {int:is_not_sent}
				WHERE (id_topic = {int:current_topic} OR id_board = {int:current_board})
					AND id_member = {int:current_member}',
				array(
					'current_board' => $id_board,
					'current_member' => $user_info['id'],
					'current_topic' => $id_topic,
					'is_not_sent' => 0,
				)
			);
			break;
		}
	}
	$db->free_result($request);
}

/**
 * How many topics are still unread since (last visit)
 *
 * @param int $id_msg_last_visit
 * @return int
 */
function getUnreadCountSince($id_board, $id_msg_last_visit)
{
	global $user_info;

	$db = database();

	$request = $db->query('', '
		SELECT COUNT(*)
		FROM {db_prefix}topics AS t
			LEFT JOIN {db_prefix}log_boards AS lb ON (lb.id_board = {int:current_board} AND lb.id_member = {int:current_member})
			LEFT JOIN {db_prefix}log_topics AS lt ON (lt.id_topic = t.id_topic AND lt.id_member = {int:current_member})
		WHERE t.id_board = {int:current_board}
			AND t.id_last_msg > IFNULL(lb.id_msg, 0)
			AND t.id_last_msg > IFNULL(lt.id_msg, 0)' .
				(empty($id_msg_last_visit) ? '' : '
			AND t.id_last_msg > {int:id_msg_last_visit}'),
		array(
			'current_board' => $id_board,
			'current_member' => $user_info['id'],
			'id_msg_last_visit' => (int) $id_msg_last_visit,
		)
	);
	list ($unread) = $db->fetch_row($request);
	$db->free_result($request);

	return $unread;
}

/**
 * Returns whether this member has notification turned on for the specified topic.
 *
 * @param int $id_member
 * @param int $id_topic
 * @return bool
 */
function hasTopicNotification($id_member, $id_topic)
{
	$db = database();

	// Find out if they have notification set for this topic already.
	$request = $db->query('', '
		SELECT id_member
		FROM {db_prefix}log_notify
		WHERE id_member = {int:current_member}
			AND id_topic = {int:current_topic}
		LIMIT 1',
		array(
			'current_member' => $id_member,
			'current_topic' => $id_topic,
		)
	);
	$hasNotification = $db->num_rows($request) != 0;
	$db->free_result($request);

	return $hasNotification;
}

/**
 * Set topic notification on or off for the given member.
 *
 * @param int $id_member
 * @param int $id_topic
 * @param bool $on
 */
function setTopicNotification($id_member, $id_topic, $on = false)
{
	$db = database();

	if ($on)
	{
		// Attempt to turn notifications on.
		$db->insert('ignore',
			'{db_prefix}log_notify',
			array('id_member' => 'int', 'id_topic' => 'int'),
			array($id_member, $id_topic),
			array('id_member', 'id_topic')
		);
	}
	else
	{
		// Just turn notifications off.
		$db->query('', '
			DELETE FROM {db_prefix}log_notify
			WHERE id_member = {int:current_member}
				AND id_topic = {int:current_topic}',
			array(
				'current_member' => $id_member,
				'current_topic' => $id_topic,
			)
		);
	}
}

/**
 * Get the previous topic from where we are.
 *
 * @param int $id_topic origin topic id
 * @param int $id_board board id
 * @param int $id_member = 0 member id
 * @param bool $includeUnapproved = false whether to include unapproved topics
 * @param bool $includeStickies = true whether to include sticky topics
 */
function previousTopic($id_topic, $id_board, $id_member = 0, $includeUnapproved = false, $includeStickies = true)
{
	return topicPointer($id_topic, $id_board, false, $id_member = 0, $includeUnapproved = false, $includeStickies = true);
}

/**
 * Get the next topic from where we are.
 *
 * @param int $id_topic origin topic id
 * @param int $id_board board id
 * @param int $id_member = 0 member id
 * @param bool $includeUnapproved = false whether to include unapproved topics
 * @param bool $includeStickies = true whether to include sticky topics
 */
function nextTopic($id_topic, $id_board, $id_member = 0, $includeUnapproved = false, $includeStickies = true)
{
	return topicPointer($id_topic, $id_board, true, $id_member = 0, $includeUnapproved = false, $includeStickies = true);
}

/**
 * Advance topic pointer.
 * (in either direction)
 * This function is used by previousTopic() and nextTopic()
 * The boolean parameter $next determines direction.
 *
 * @param int $id_topic origin topic id
 * @param int $id_board board id
 * @param bool $next = true whether to increase or decrease the pointer
 * @param int $id_member = 0 member id
 * @param bool $includeUnapproved = false whether to include unapproved topics
 * @param bool $includeStickies = true whether to include sticky topics
 */
function topicPointer($id_topic, $id_board, $next = true, $id_member = 0, $includeUnapproved = false, $includeStickies = true)
{
	$db = database();

	$request = $db->query('', '
		SELECT t2.id_topic
		FROM {db_prefix}topics AS t
		INNER JOIN {db_prefix}topics AS t2 ON (' .
			(empty($includeStickies) ? '
				t2.id_last_msg {raw:strictly} t.id_last_msg' : '
				(t2.id_last_msg {raw:strictly} t.id_last_msg AND t2.is_sticky {raw:strictly_equal} t.is_sticky) OR t2.is_sticky {raw:strictly} t.is_sticky')
			. ')
		WHERE t.id_topic = {int:current_topic}
			AND t2.id_board = {int:current_board}' .
			($includeUnapproved ? '' : '
				AND (t2.approved = {int:is_approved} OR (t2.id_member_started != {int:id_member_started} AND t2.id_member_started = {int:current_member}))'
				) . '
		ORDER BY' . (
			$includeStickies ? '
				t2.is_sticky {raw:sorting},' :
				 '') .
			' t2.id_last_msg {raw:sorting}
		LIMIT 1',
		array(
			'strictly' => $next ? '<' : '>',
			'strictly_equal' => $next ? '<=' : '>=',
			'sorting' => $next ? 'DESC' : '',
			'current_board' => $id_board,
			'current_member' => $id_member,
			'current_topic' => $id_topic,
			'is_approved' => 1,
			'id_member_started' => 0,
		)
	);

	// Was there any?
	if ($db->num_rows($request) == 0)
	{
		$db->free_result($request);

		// Roll over - if we're going prev, get the last - otherwise the first.
		$request = $db->query('', '
			SELECT id_topic
			FROM {db_prefix}topics
			WHERE id_board = {int:current_board}' .
			($includeUnapproved ? '' : '
				AND (approved = {int:is_approved} OR (id_member_started != {int:id_member_started} AND id_member_started = {int:current_member}))') . '
			ORDER BY' . (
				$includeStickies ? ' is_sticky {raw:sorting},' :
				'').
				' id_last_msg {raw:sorting}
			LIMIT 1',
			array(
				'sorting' => $next ? 'DESC' : '',
				'current_board' => $id_board,
				'current_member' => $id_member,
				'is_approved' => 1,
				'id_member_started' => 0,
			)
		);
	}
	// Now you can be sure $topic is the id_topic to view.
	list ($topic) = $db->fetch_row($request);
	$db->free_result($request);

	return $topic;
}

/**
 * Set off/on unread reply subscription for a topic
 *
 * @param int $id_member
 * @param int $topic
 * @param bool $on = false
 */
function setTopicRegard($id_member, $topic, $on = false)
{
	global $user_info;

	$db = database();

	// find the current entry if it exists that is
	$was_set = getLoggedTopics($user_info['id'], array($topic));

	// Set topic disregard on/off for this topic.
	$db->insert(empty($was_set) ? 'ignore' : 'replace',
		'{db_prefix}log_topics',
		array('id_member' => 'int', 'id_topic' => 'int', 'id_msg' => 'int', 'disregarded' => 'int'),
		array($id_member, $topic, !empty($was_set['id_msg']) ? $was_set['id_msg'] : 0, $on ? 1 : 0),
		array('id_member', 'id_topic')
	);
}

/**
 * Get all the details for a given topic
 * - returns the basic topic information when $full is false
 * - returns topic details, subject, last message read, etc when full is true
 * - uses any integration information (value selects, tables and parameters) if passed and full is true
 *
 * @param array $topic_parameters can also accept a int value for a topic
 * @param string $full defines the values returned by the function:
 *             - if empty returns only the data from {db_prefix}topics
 *             - if 'message' returns also informations about the message (subject, body, etc.)
 *             - if 'all' returns additional infos about the read/disregard status
 * @param array $selects (optional from integation)
 * @param array $tables (optional from integation)
 */
function getTopicInfo($topic_parameters, $full = '', $selects = array(), $tables = array())
{
	global $user_info, $modSettings, $board;

	$db = database();

	// Nothing to do
	if (empty($topic_parameters))
		return false;

	// Build what we can with what we were given
	if (!is_array($topic_parameters))
		$topic_parameters = array(
			'topic' => $topic_parameters,
			'member' => $user_info['id'],
			'board' => (int) $board,
		);

	$messages_table = $full === 'message' || $full === 'all';
	$follow_ups_table = $full === 'follow_up' || $full === 'all';
	$logs_table = $full === 'all';

	// Create the query, taking full and integration in to account
	$request = $db->query('', '
		SELECT
			t.id_topic, t.is_sticky, t.id_board, t.id_first_msg, t.id_last_msg,
			t.id_member_started, t.id_member_updated, t.id_poll,
			t.num_replies, t.num_views, t.num_likes, t.locked, t.redirect_expires,
			t.id_redirect_topic, t.unapproved_posts, t.approved' . ($messages_table ? ',
			ms.subject, ms.body, ms.id_member, ms.poster_time, ms.approved as msg_approved' : '') . ($follow_ups_table ? ',
			fu.derived_from' : '') .
			($logs_table ? ',
			' . ($user_info['is_guest'] ? 't.id_last_msg + 1' : 'IFNULL(lt.id_msg, IFNULL(lmr.id_msg, -1)) + 1') . ' AS new_from
			' . (!empty($modSettings['recycle_board']) && $modSettings['recycle_board'] == $board ? ', t.id_previous_board, t.id_previous_topic' : '') . '
			' . (!$user_info['is_guest'] ? ', IFNULL(lt.disregarded, 0) as disregarded' : '') : '') .
			(!empty($selects) ? ', ' . implode(', ', $selects) : '') . '
		FROM {db_prefix}topics AS t' . ($messages_table ? '
			INNER JOIN {db_prefix}messages AS ms ON (ms.id_msg = t.id_first_msg)' : '') . ($follow_ups_table ? '
			LEFT JOIN {db_prefix}follow_ups AS fu ON (fu.follow_up = t.id_topic)' : '') .
			($logs_table && !$user_info['is_guest'] ? '
			LEFT JOIN {db_prefix}log_topics AS lt ON (lt.id_topic = {int:topic} AND lt.id_member = {int:member})
			LEFT JOIN {db_prefix}log_mark_read AS lmr ON (lmr.id_board = {int:board} AND lmr.id_member = {int:member})' : '') .
			(!empty($tables) ? implode("\n\t\t\t", $tables) : '') . '
		WHERE t.id_topic = {int:topic}
		LIMIT 1',
			$topic_parameters
	);
	$topic_info = array();
	if ($request !== false)
		$topic_info = $db->fetch_assoc($request);
	$db->free_result($request);

	return $topic_info;
}

/**
 * So long as you are sure... all old posts will be gone.
 * Used in Maintenance.controller.php to prune old topics.
 */
function removeOldTopics()
{
	$db = database();

	isAllowedTo('admin_forum');
	checkSession('post', 'admin');

	// No boards at all?  Forget it then :/.
	if (empty($_POST['boards']))
		redirectexit('action=admin;area=maintain;sa=topics');

	// This should exist, but we can make sure.
	$_POST['delete_type'] = isset($_POST['delete_type']) ? $_POST['delete_type'] : 'nothing';

	// Custom conditions.
	$condition = '';
	$condition_params = array(
		'boards' => array_keys($_POST['boards']),
		'poster_time' => time() - 3600 * 24 * $_POST['maxdays'],
	);

	// Just moved notice topics?
	if ($_POST['delete_type'] == 'moved')
	{
		$condition .= '
			AND m.icon = {string:icon}
			AND t.locked = {int:locked}';
		$condition_params['icon'] = 'moved';
		$condition_params['locked'] = 1;
	}
	// Otherwise, maybe locked topics only?
	elseif ($_POST['delete_type'] == 'locked')
	{
		$condition .= '
			AND t.locked = {int:locked}';
		$condition_params['locked'] = 1;
	}

	// Exclude stickies?
	if (isset($_POST['delete_old_not_sticky']))
	{
		$condition .= '
			AND t.is_sticky = {int:is_sticky}';
		$condition_params['is_sticky'] = 0;
	}

	// All we're gonna do here is grab the id_topic's and send them to removeTopics().
	$request = $db->query('', '
		SELECT t.id_topic
		FROM {db_prefix}topics AS t
			INNER JOIN {db_prefix}messages AS m ON (m.id_msg = t.id_last_msg)
		WHERE
			m.poster_time < {int:poster_time}' . $condition . '
			AND t.id_board IN ({array_int:boards})',
		$condition_params
	);
	$topics = array();
	while ($row = $db->fetch_assoc($request))
		$topics[] = $row['id_topic'];
	$db->free_result($request);

	removeTopics($topics, false, true);

	// Log an action into the moderation log.
	logAction('pruned', array('days' => $_POST['maxdays']));

	redirectexit('action=admin;area=maintain;sa=topics;done=purgeold');
}

/**
 * Retrieve all topics started by the given member.
 *
 * @param int $memberID
 */
function topicsStartedBy($memberID)
{
	$db = database();

	// Fetch all topics started by this user.
	$request = $db->query('', '
		SELECT t.id_topic
		FROM {db_prefix}topics AS t
		WHERE t.id_member_started = {int:selected_member}',
			array(
				'selected_member' => $memberID,
			)
		);
	$topicIDs = array();
	while ($row = $db->fetch_assoc($request))
		$topicIDs[] = $row['id_topic'];
	$db->free_result($request);

	return $topicIDs;
}

/**
 * Retrieve the messages of the given topic, that are at or after
 * a message.
 * Used by split topics actions.
 *
 * @param int $id_topic
 * @param int $id_msg
 * @param bool $include_current = false
 * @param bool $only_approved = false
 *
 * @return array message ids
 */
function messagesSince($id_topic, $id_msg, $include_current = false, $only_approved = false)
{
	$db = database();

	// Fetch the message IDs of the topic that are at or after the message.
	$request = $db->query('', '
		SELECT id_msg
		FROM {db_prefix}messages
		WHERE id_topic = {int:current_topic}
			AND id_msg ' . ($include_current ? '>=' : '>') . ' {int:last_msg}' . ($only_approved ? '
			AND approved = {int:approved}' : ''),
		array(
			'current_topic' => $id_topic,
			'last_msg' => $id_msg,
			'approved' => 1,
		)
	);
	while ($row = $db->fetch_assoc($request))
		$messages[] = $row['id_msg'];
	$db->free_result($request);

	return $messages;
}

/**
 * This function returns the number of messages in a topic,
 * posted after $last_msg.
 *
 * @param int $id_topic
 * @param int $last_msg
 * @param bool $include_current = false
 * @param bool $only_approved = false
 *
 * @return int
 */
function countMessagesSince($id_topic, $id_msg, $include_current = false, $only_approved = false)
{
	$db = database();

	// Give us something to work with
	if (empty($id_topic) || empty($id_msg))
		return false;

	$request = $db->query('', '
		SELECT COUNT(*)
		FROM {db_prefix}messages
		WHERE id_topic = {int:current_topic}
			AND id_msg ' . ($include_current ? '>=' : '>') . ' {int:last_msg}' . ($only_approved ? '
			AND approved = {int:approved}' : '') . '
		LIMIT 1',
		array(
			'current_topic' => $id_topic,
			'last_msg' => $id_msg,
			'approved' => 1,
		)
	);
	list ($count) = $db->fetch_row($request);
	$db->free_result($request);

	return $count;
}

/**
 * Returns how many messages are in a topic before the specified message id.
 * Used in display to compute the start value for a specific message.
 *
 * @param int $id_topic
 * @param int $id_msg
 * @param bool $include_current = false
 * @param bool $only_approved = false
 * @param bool $include_own = false
 * @return int
 */
function countMessagesBefore($id_topic, $id_msg, $include_current = false, $only_approved = false, $include_own = false)
{
	global $user_info;

	$db = database();

	$request = $db->query('', '
		SELECT COUNT(*)
		FROM {db_prefix}messages
		WHERE id_msg ' . ($include_current ? '<=' : '<') . ' {int:id_msg}
			AND id_topic = {int:current_topic}' . ($only_approved ? '
			AND (approved = {int:is_approved}' . ($include_own ? '
			OR id_member = {int:current_member}' : '') . ')' : ''),
		array(
			'current_member' => $user_info['id'],
			'current_topic' => $id_topic,
			'id_msg' => $id_msg,
			'is_approved' => 1,
		)
	);
	list ($count) = $db->fetch_row($request);
	$db->free_result($request);

	return $count;
}

/**
 * Retrieve a few data on a particular message.
 * Slightly different from basicMessageInfo, this one inner joins {db_prefix}topics
 * and doesn't use {query_see_board}
 *
 * @param int $topic topic ID
 * @param int $message message ID
 * @param bool $topic_approved if true it will return the topic approval status, otherwise the message one (default false)
 */
function messageTopicDetails($topic, $message, $topic_approved = false)
{
	global $modSettings;

	$db = database();

	// @todo isn't this a duplicate?

	// Retrieve a few info on the specific message.
	$request = $db->query('', '
		SELECT m.id_member, m.subject,' . ($topic_approved ? ' t.approved,' : 'm.approved,') . '
			t.num_replies, t.unapproved_posts, t.id_first_msg, t.id_member_started
		FROM {db_prefix}messages AS m
			INNER JOIN {db_prefix}topics AS t ON (t.id_topic = {int:current_topic})
		WHERE m.id_msg = {int:message_id}' . (!$modSettings['postmod_active'] || allowedTo('approve_posts') ? '' : '
			AND m.approved = 1') . '
			AND m.id_topic = {int:current_topic}
		LIMIT 1',
		array(
			'current_topic' => $topic,
			'message_id' => $message,
		)
	);

	$messageInfo = $db->fetch_assoc($request);
	$db->free_result($request);

	return $messageInfo;
}

/**
 * Select a part of the messages in a topic.
 *
 * @param int $topic
 * @param int $start
 * @param int $per_page
 * @param array $messages
 * @param bool $only_approved
 */
function selectMessages($topic, $start, $per_page, $messages = array(), $only_approved = false)
{
	$db = database();

	// Get the messages and stick them into an array.
	$request = $db->query('', '
		SELECT m.subject, IFNULL(mem.real_name, m.poster_name) AS real_name, m.poster_time, m.body, m.id_msg, m.smileys_enabled, m.id_member
		FROM {db_prefix}messages AS m
			LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = m.id_member)
		WHERE m.id_topic = {int:current_topic}' . (empty($messages['before']) ? '' : '
			AND m.id_msg < {int:msg_before}') . (empty($messages['after']) ? '' : '
			AND m.id_msg > {int:msg_after}') . (empty($messages['excluded']) ? '' : '
			AND m.id_msg NOT IN ({array_int:no_split_msgs})') . (empty($messages['included']) ? '' : '
			AND m.id_msg IN ({array_int:split_msgs})') . (!$only_approved ? '' : '
			AND approved = {int:is_approved}') . '
		ORDER BY m.id_msg DESC
		LIMIT {int:start}, {int:messages_per_page}',
		array(
			'current_topic' => $topic,
			'no_split_msgs' => !empty($messages['excluded']) ? $messages['excluded'] : array(),
			'split_msgs' => !empty($messages['included']) ? $messages['included'] : array(),
			'is_approved' => 1,
			'start' => $start,
			'messages_per_page' => $per_page,
			'msg_before' => !empty($messages['before']) ? (int) $messages['before'] : 0,
			'msg_after' => !empty($messages['after']) ? (int) $messages['after'] : 0,
		)
	);
	$messages = array();
	for ($counter = 0; $row = $db->fetch_assoc($request); $counter ++)
	{
		censorText($row['subject']);
		censorText($row['body']);

		$row['body'] = parse_bbc($row['body'], $row['smileys_enabled'], $row['id_msg']);

		$messages[$row['id_msg']] = array(
			'id' => $row['id_msg'],
			'alternate' => $counter % 2,
			'subject' => $row['subject'],
			'time' => relativeTime($row['poster_time']),
			'timestamp' => forum_time(true, $row['poster_time']),
			'body' => $row['body'],
			'poster' => $row['real_name'],
			'id_poster' => $row['id_member'],
		);
	}
	$db->free_result($request);

	return $messages;
}

/**
 * Retrieve unapproved posts of the member
 * in a specific topic
 *
 * @param int $id_topic topic id
 * @param int $id_member member id
 */
function unapprovedPosts($id_topic, $id_member)
{
	$db = database();

	// not all guests are the same!
	if (empty($id_member))
		return array();

	$request = $db->query('', '
			SELECT COUNT(id_member) AS my_unapproved_posts
			FROM {db_prefix}messages
			WHERE id_topic = {int:current_topic}
				AND id_member = {int:current_member}
				AND approved = 0',
			array(
				'current_topic' => $id_topic,
				'current_member' => $id_member,
			)
		);
	list ($myUnapprovedPosts) = $db->fetch_row($request);
	$db->free_result($request);

	return $myUnapprovedPosts;
}

/**
 * Update topic info after a successful split of a topic.
 *
 * @param array $options
 * @param int $id_board
 */
function updateSplitTopics($options, $id_board)
{
	$db = database();

	// Any associated reported posts better follow...
	$db->query('', '
		UPDATE {db_prefix}log_reported
		SET id_topic = {int:id_topic}
		WHERE id_msg IN ({array_int:split_msgs})',
		array(
			'split_msgs' => $options['splitMessages'],
			'id_topic' => $options['split2_ID_TOPIC'],
		)
	);

	// Mess with the old topic's first, last, and number of messages.
	$db->query('', '
		UPDATE {db_prefix}topics
		SET
			num_replies = {int:num_replies},
			id_first_msg = {int:id_first_msg},
			id_last_msg = {int:id_last_msg},
			id_member_started = {int:id_member_started},
			id_member_updated = {int:id_member_updated},
			unapproved_posts = {int:unapproved_posts}
		WHERE id_topic = {int:id_topic}',
		array(
			'num_replies' => $options['split1_replies'],
			'id_first_msg' => $options['split1_first_msg'],
			'id_last_msg' => $options['split1_last_msg'],
			'id_member_started' => $options['split1_firstMem'],
			'id_member_updated' => $options['split1_lastMem'],
			'unapproved_posts' => $options['split1_unapprovedposts'],
			'id_topic' => $options['split1_ID_TOPIC'],
		)
	);

	// Now, put the first/last message back to what they should be.
	$db->query('', '
		UPDATE {db_prefix}topics
		SET
			id_first_msg = {int:id_first_msg},
			id_last_msg = {int:id_last_msg}
		WHERE id_topic = {int:id_topic}',
		array(
			'id_first_msg' => $options['split2_first_msg'],
			'id_last_msg' => $options['split2_last_msg'],
			'id_topic' => $options['split2_ID_TOPIC'],
		)
	);

	// If the new topic isn't approved ensure the first message flags
	// this just in case.
	if (!$options['split2_approved'])
		$db->query('', '
			UPDATE {db_prefix}messages
			SET approved = {int:approved}
			WHERE id_msg = {int:id_msg}
				AND id_topic = {int:id_topic}',
			array(
				'approved' => 0,
				'id_msg' => $options['split2_first_msg'],
				'id_topic' => $options['split2_ID_TOPIC'],
			)
		);

	// The board has more topics now (Or more unapproved ones!).
	$db->query('', '
		UPDATE {db_prefix}boards
		SET ' . ($options['split2_approved'] ? '
			num_topics = num_topics + 1' : '
			unapproved_topics = unapproved_topics + 1') . '
		WHERE id_board = {int:id_board}',
		array(
			'id_board' => $id_board,
		)
	);
}

/**
 * Find out who started a topic, and the lock status
 *
 * @param int $topic
 * @return array with id_member_started and locked
 */
function topicStatus($topic)
{
	$db = database();

	// Find out who started the topic, and the lock status.
	$request = $db->query('', '
		SELECT id_member_started, locked
		FROM {db_prefix}topics
		WHERE id_topic = {int:current_topic}
		LIMIT 1',
		array(
			'current_topic' => $topic,
		)
	);
	$starter = $db->fetch_row($request);
	$db->free_result($request);

	return $starter;
}

/**
 * Set attributes for a topic, i.e. locked, sticky.
 * Parameter $attributes is an array with:
 *  - 'locked' => lock_value,
 *  - 'sticky' => sticky_value
 * It sets the new value for the attribute as passed to it.
 *
 * @param int $topic
 * @param array $attributes
 */
function setTopicAttribute($topic, $attributes)
{
	$db = database();

	if (isset($attributes['locked']))
		// Lock the topic in the database with the new value.
		$db->query('', '
			UPDATE {db_prefix}topics
			SET locked = {int:locked}
			WHERE id_topic = {int:current_topic}',
			array(
				'current_topic' => $topic,
				'locked' => $attributes['locked'],
			)
		);
	if (isset($attributes['sticky']))
		// Set the new sticky value.
		$db->query('', '
			UPDATE {db_prefix}topics
			SET is_sticky = {int:is_sticky}
			WHERE id_topic = {int:current_topic}',
			array(
				'current_topic' => $topic,
				'is_sticky' => empty($attributes['sticky']) ? 0 : 1,
			)
		);
}

/**
 * Retrieve the locked or sticky status of a topic.
 *
 * @param string $attribute 'locked' or 'sticky'
 */
function topicAttribute($id_topic, $attribute)
{
	$db = database();

	$attributes = array(
		'locked' => 'locked',
		'sticky' => 'is_sticky',
	);

	if (isset($attributes[$attribute]))
	{
		// check the lock status
		$request = $db->query('', '
			SELECT {raw:attribute}
			FROM {db_prefix}topics
			WHERE id_topic = {int:current_topic}
			LIMIT 1',
			array(
				'attribute' => $attributes[$attribute],
			)
		);
		list ($status) = $db->fetch_row($request);
		$db->free_result($request);

		return $status;
	}
}

/**
 * Retrieve some details about the topic
 *
 * @param array $topics an array of topic id
 */
function topicsDetails($topics)
{
	$db = database();

	$request = $db->query('', '
		SELECT id_topic, id_member_started, id_board, locked, approved, unapproved_posts
		FROM {db_prefix}topics
		WHERE id_topic IN ({array_int:topic_ids})
		LIMIT ' . count($topics),
		array(
			'topic_ids' => $topics,
		)
	);

	$topics = array();
	while ($row = $db->fetch_assoc($request))
		$topics[] = $row;
	$db->free_result($request);

	return $topics;
}

/**
 * Toggle sticky status for the passed topics.
 *
 * @param array $topics
 */
function toggleTopicSticky($topics)
{
	$db = database();

	$topics = is_array($topics) ? $topics : array($topics);

	$db->query('', '
		UPDATE {db_prefix}topics
		SET is_sticky = CASE WHEN is_sticky = 1 THEN 0 ELSE 1 END
		WHERE id_topic IN ({array_int:sticky_topic_ids})',
		array(
			'sticky_topic_ids' => $topics,
		)
	);

	return $db->affected_rows();
}

/**
 * Get topics from the log_topics table belonging to a certain user
 *
 * @param int $member a member id
 * @param array $topics an array of topics
 * @return array an array of topics in the table (key) and its disregard status (value)
 *
 * @todo find a better name
 */
function getLoggedTopics($member, $topics)
{
	$db = database();

	$request = $db->query('', '
		SELECT id_topic, disregarded, id_msg
		FROM {db_prefix}log_topics
		WHERE id_topic IN ({array_int:selected_topics})
			AND id_member = {int:current_user}',
		array(
			'selected_topics' => $topics,
			'current_user' => $member,
		)
	);
	$logged_topics = array();
	while ($row = $db->fetch_assoc($request))
		$logged_topics[$row['id_topic']] = $row['disregarded'];
	$db->free_result($request);

	return $logged_topics;
}

/**
 * Returns a list of topics ids and their subjects
 *
 * @param array $topic_ids
 */
function topicsList($topic_ids)
{
	global $modSettings;

	// you have to want *something* from this function
	if (empty($topic_ids))
		return array();

	$db = database();

	$topics = array();

	$result = $db->query('', '
		SELECT t.id_topic, m.subject
		FROM {db_prefix}topics AS t
			INNER JOIN {db_prefix}boards AS b ON (b.id_board = t.id_board)
			INNER JOIN {db_prefix}messages AS m ON (m.id_msg = t.id_first_msg)
		WHERE {query_see_board}
			AND t.id_topic IN ({array_int:topic_list})' . ($modSettings['postmod_active'] ? '
			AND t.approved = {int:is_approved}' : '') . '
		LIMIT {int:limit}',
		array(
			'topic_list' => $topic_ids,
			'is_approved' => 1,
			'limit' => count($topic_ids),
		)
	);
	while ($row = $db->fetch_assoc($result))
	{
		$topics[$row['id_topic']] = array(
			'id_topic' => $row['id_topic'],
			'subject' => censorText($row['subject']),
		);
	}
	$db->free_result($result);

	return $topics;
}

/**
 * Get each post and poster in this topic and take care of user settings such as
 * limit or sort direction.
 *
 * @param int $topic
 * @param array $limit
 * @param string $sort
 * @return array
 */
function getTopicsPostsAndPoster($topic, $limit, $sort)
{
	global $modSettings, $user_info;

	$db = database();

	$topic_details = array(
		'messages' => array(),
		'all_posters' => array(),
	);

	$request = $db->query('display_get_post_poster', '
		SELECT id_msg, id_member, approved
		FROM {db_prefix}messages
		WHERE id_topic = {int:current_topic}' . (!$modSettings['postmod_active'] || allowedTo('approve_posts') ? '' : (!empty($modSettings['db_mysql_group_by_fix']) ? '' : '
		GROUP BY id_msg') . '
		HAVING (approved = {int:is_approved}' . ($user_info['is_guest'] ? '' : ' OR id_member = {int:current_member}') . ')') . '
		ORDER BY id_msg ' . ($sort ? '' : 'DESC') . ($limit['messages_per_page'] == -1 ? '' : '
		LIMIT ' . $limit['start'] . ', ' . $limit['offset']),
		array(
			'current_member' => $user_info['id'],
			'current_topic' => $topic,
			'is_approved' => 1,
			'blank_id_member' => 0,
		)
	);
	while ($row = $db->fetch_assoc($request))
	{
		if (!empty($row['id_member']))
			$topic_details['all_posters'][$row['id_msg']] = $row['id_member'];
			$topic_details['messages'][] = $row['id_msg'];
	}
	$db->free_result($request);

	return $topic_details;
}

/**
 * Remove a batch of messages (or topics)
 *
 * @param array $messages
 * @param array $messageDetails
 * @param string $type = replies
 */
function removeMessages($messages, $messageDetails, $type = 'replies')
{
	global $modSettings;

	// @todo something's not right, removeMessage() does check permissions,
	// removeTopics() doesn't
	if ($type == 'topics')
	{
		removeTopics($messages);

		// and tell the world about it
		foreach ($messages as $topic)
		{
			// Note, only log topic ID in native form if it's not gone forever.
			logAction('remove', array(
				(empty($modSettings['recycle_enable']) || $modSettings['recycle_board'] != $messageDetails[$topic]['board'] ? 'topic' : 'old_topic_id') => $topic, 'subject' => $messageDetails[$topic]['subject'], 'member' => $messageDetails[$topic]['member'], 'board' => $messageDetails[$topic]['board']));
		}
	}
	else
	{
		require_once(SUBSDIR . '/Messages.subs.php');
		foreach ($messages as $post)
		{
			removeMessage($post);
			logAction('delete', array(
				(empty($modSettings['recycle_enable']) || $modSettings['recycle_board'] != $messageDetails[$post]['board'] ? 'topic' : 'old_topic_id') => $messageDetails[$post]['topic'], 'subject' => $messageDetails[$post]['subject'], 'member' => $messageDetails[$post]['member'], 'board' => $messageDetails[$post]['board']));
		}
	}
}

/**
 * Approve a batch of posts (or topics in their own right)
 *
 * @param array $messages
 * @param array $messageDetails
 * @param (string) $type = replies
 */
function approveMessages($messages, $messageDetails, $type = 'replies')
{
	if ($type == 'topics')
	{
		approveTopics($messages);

		// and tell the world about it
		foreach ($messages as $topic)
			logAction('approve_topic', array('topic' => $topic, 'subject' => $messageDetails[$topic]['subject'], 'member' => $messageDetails[$topic]['member'], 'board' => $messageDetails[$topic]['board']));
	}
	else
	{
		require_once(SUBSDIR . '/Post.subs.php');
		approvePosts($messages);

		// and tell the world about it again
		foreach ($messages as $post)
			logAction('approve', array('topic' => $messageDetails[$post]['topic'], 'subject' => $messageDetails[$post]['subject'], 'member' => $messageDetails[$post]['member'], 'board' => $messageDetails[$post]['board']));
	}
}

/**
 * Approve topics, all we got.
 *
 * @param array $topics array of topics ids
 * @param bool $approve = true
 */
function approveTopics($topics, $approve = true)
{
	$db = database();

	if (!is_array($topics))
		$topics = array($topics);

	if (empty($topics))
		return false;

	$approve_type = $approve ? 0 : 1;

	// Just get the messages to be approved and pass through...
	$request = $db->query('', '
		SELECT id_msg
		FROM {db_prefix}messages
		WHERE id_topic IN ({array_int:topic_list})
			AND approved = {int:approve_type}',
		array(
			'topic_list' => $topics,
			'approve_type' => $approve_type,
		)
	);
	$msgs = array();
	while ($row = $db->fetch_assoc($request))
		$msgs[] = $row['id_msg'];
	$db->free_result($request);

	require_once(SUBSDIR . '/Post.subs.php');
	return approvePosts($msgs, $approve);
}

/**
 * Post a message at the end of the original topic
 *
 * @param string $reason, the text that will become the message body
 * @param string $subject, the text that will become the message subject
 * @param string $board_info, some board informations (at least id, name, if posts are counted)
 */
function postSplitRedirect($reason, $subject, $board_info, $new_topic)
{
	global $scripturl, $user_info, $language, $txt, $user_info, $topic, $board;

	// Should be in the boardwide language.
	if ($user_info['language'] != $language)
		loadLanguage('index', $language);

	preparsecode($reason);

	// Add a URL onto the message.
	$reason = strtr($reason, array(
		$txt['movetopic_auto_board'] => '[url=' . $scripturl . '?board=' . $board_info['id'] . '.0]' . $board_info['name'] . '[/url]',
		$txt['movetopic_auto_topic'] => '[iurl]' . $scripturl . '?topic=' . $new_topic . '.0[/iurl]'
	));

	$msgOptions = array(
		'subject' => $txt['moved'] . ': ' . strtr(Util::htmltrim(Util::htmlspecialchars($subject)), array("\r" => '', "\n" => '', "\t" => '')),
		'body' => $reason,
		'icon' => 'moved',
		'smileys_enabled' => 1,
	);

	$topicOptions = array(
		'id' => $topic,
		'board' => $board,
		'mark_as_read' => true,
	);

	$posterOptions = array(
		'id' => $user_info['id'],
		'update_post_count' => empty($board_info['count_posts']),
	);

	createPost($msgOptions, $topicOptions, $posterOptions);
}

/**
 * General function to split off a topic.
 * creates a new topic and moves the messages with the IDs in
 * array messagesToBeSplit to the new topic.
 * the subject of the newly created topic is set to 'newSubject'.
 * marks the newly created message as read for the user splitting it.
 * updates the statistics to reflect a newly created topic.
 * logs the action in the moderation log.
 * a notification is sent to all users monitoring this topic.
 * @param int $split1_ID_TOPIC
 * @param array $splitMessages
 * @param string $new_subject
 * @return int the topic ID of the new split topic.
 */
function splitTopic($split1_ID_TOPIC, $splitMessages, $new_subject)
{
	global $txt;

	$db = database();

	// Nothing to split?
	if (empty($splitMessages))
		fatal_lang_error('no_posts_selected', false);

	// Get some board info.
	$request = $db->query('', '
		SELECT id_board, approved
		FROM {db_prefix}topics
		WHERE id_topic = {int:id_topic}
		LIMIT 1',
		array(
			'id_topic' => $split1_ID_TOPIC,
		)
	);
	list ($id_board, $split1_approved) = $db->fetch_row($request);
	$db->free_result($request);

	// Find the new first and last not in the list. (old topic)
	$request = $db->query('', '
		SELECT
			MIN(m.id_msg) AS myid_first_msg, MAX(m.id_msg) AS myid_last_msg, COUNT(*) AS message_count, m.approved
		FROM {db_prefix}messages AS m
			INNER JOIN {db_prefix}topics AS t ON (t.id_topic = {int:id_topic})
		WHERE m.id_msg NOT IN ({array_int:no_msg_list})
			AND m.id_topic = {int:id_topic}
		GROUP BY m.approved
		ORDER BY m.approved DESC
		LIMIT 2',
		array(
			'id_topic' => $split1_ID_TOPIC,
			'no_msg_list' => $splitMessages,
		)
	);
	// You can't select ALL the messages!
	if ($db->num_rows($request) == 0)
		fatal_lang_error('selected_all_posts', false);

	$split1_first_msg = null;
	$split1_last_msg = null;

	while ($row = $db->fetch_assoc($request))
	{
		// Get the right first and last message dependant on approved state...
		if (empty($split1_first_msg) || $row['myid_first_msg'] < $split1_first_msg)
			$split1_first_msg = $row['myid_first_msg'];

		if (empty($split1_last_msg) || $row['approved'])
			$split1_last_msg = $row['myid_last_msg'];

		// Get the counts correct...
		if ($row['approved'])
		{
			$split1_replies = $row['message_count'] - 1;
			$split1_unapprovedposts = 0;
		}
		else
		{
			if (!isset($split1_replies))
				$split1_replies = 0;
			// If the topic isn't approved then num replies must go up by one... as first post wouldn't be counted.
			elseif (!$split1_approved)
				$split1_replies++;

			$split1_unapprovedposts = $row['message_count'];
		}
	}
	$db->free_result($request);
	$split1_firstMem = getMsgMemberID($split1_first_msg);
	$split1_lastMem = getMsgMemberID($split1_last_msg);

	// Find the first and last in the list. (new topic)
	$request = $db->query('', '
		SELECT MIN(id_msg) AS myid_first_msg, MAX(id_msg) AS myid_last_msg, COUNT(*) AS message_count, approved
		FROM {db_prefix}messages
		WHERE id_msg IN ({array_int:msg_list})
			AND id_topic = {int:id_topic}
		GROUP BY id_topic, approved
		ORDER BY approved DESC
		LIMIT 2',
		array(
			'msg_list' => $splitMessages,
			'id_topic' => $split1_ID_TOPIC,
		)
	);
	while ($row = $db->fetch_assoc($request))
	{
		// As before get the right first and last message dependant on approved state...
		if (empty($split2_first_msg) || $row['myid_first_msg'] < $split2_first_msg)
			$split2_first_msg = $row['myid_first_msg'];

		if (empty($split2_last_msg) || $row['approved'])
			$split2_last_msg = $row['myid_last_msg'];

		// Then do the counts again...
		if ($row['approved'])
		{
			$split2_approved = true;
			$split2_replies = $row['message_count'] - 1;
			$split2_unapprovedposts = 0;
		}
		else
		{
			// Should this one be approved??
			if ($split2_first_msg == $row['myid_first_msg'])
				$split2_approved = false;

			if (!isset($split2_replies))
				$split2_replies = 0;
			// As before, fix number of replies.
			elseif (!$split2_approved)
				$split2_replies++;

			$split2_unapprovedposts = $row['message_count'];
		}
	}
	$db->free_result($request);
	$split2_firstMem = getMsgMemberID($split2_first_msg);
	$split2_lastMem = getMsgMemberID($split2_last_msg);

	// No database changes yet, so let's double check to see if everything makes at least a little sense.
	if ($split1_first_msg <= 0 || $split1_last_msg <= 0 || $split2_first_msg <= 0 || $split2_last_msg <= 0 || $split1_replies < 0 || $split2_replies < 0 || $split1_unapprovedposts < 0 || $split2_unapprovedposts < 0 || !isset($split1_approved) || !isset($split2_approved))
		fatal_lang_error('cant_find_messages');

	// You cannot split off the first message of a topic.
	if ($split1_first_msg > $split2_first_msg)
		fatal_lang_error('split_first_post', false);

	// We're off to insert the new topic!  Use 0 for now to avoid UNIQUE errors.
	$db->insert('',
		'{db_prefix}topics',
		array(
			'id_board' => 'int',
			'id_member_started' => 'int',
			'id_member_updated' => 'int',
			'id_first_msg' => 'int',
			'id_last_msg' => 'int',
			'num_replies' => 'int',
			'unapproved_posts' => 'int',
			'approved' => 'int',
			'is_sticky' => 'int',
		),
		array(
			(int) $id_board, $split2_firstMem, $split2_lastMem, 0,
			0, $split2_replies, $split2_unapprovedposts, (int) $split2_approved, 0,
		),
		array('id_topic')
	);
	$split2_ID_TOPIC = $db->insert_id('{db_prefix}topics', 'id_topic');
	if ($split2_ID_TOPIC <= 0)
		fatal_lang_error('cant_insert_topic');

	// Move the messages over to the other topic.
	$new_subject = strtr(Util::htmltrim(Util::htmlspecialchars($new_subject)), array("\r" => '', "\n" => '', "\t" => ''));

	// Check the subject length.
	if (Util::strlen($new_subject) > 100)
		$new_subject = Util::substr($new_subject, 0, 100);
	// Valid subject?
	if ($new_subject != '')
	{
		$db->query('', '
			UPDATE {db_prefix}messages
			SET
				id_topic = {int:id_topic},
				subject = CASE WHEN id_msg = {int:split_first_msg} THEN {string:new_subject} ELSE {string:new_subject_replies} END
			WHERE id_msg IN ({array_int:split_msgs})',
			array(
				'split_msgs' => $splitMessages,
				'id_topic' => $split2_ID_TOPIC,
				'new_subject' => $new_subject,
				'split_first_msg' => $split2_first_msg,
				'new_subject_replies' => $txt['response_prefix'] . $new_subject,
			)
		);

		// Cache the new topics subject... we can do it now as all the subjects are the same!
		updateStats('subject', $split2_ID_TOPIC, $new_subject);
	}

	// Any associated reported posts better follow...
	require_once(SUBSDIR . '/Topic.subs.php');
	updateSplitTopics(array(
		'splitMessages' => $splitMessages,
		'split2_ID_TOPIC' => $split2_ID_TOPIC,
		'split1_replies' => $split1_replies,
		'split1_first_msg' => $split1_first_msg,
		'split1_last_msg' => $split1_last_msg,
		'split1_firstMem' => $split1_firstMem,
		'split1_lastMem' => $split1_lastMem,
		'split1_unapprovedposts' => $split1_unapprovedposts,
		'split1_ID_TOPIC' => $split1_ID_TOPIC,
		'split2_first_msg' => $split2_first_msg,
		'split2_last_msg' => $split2_last_msg,
		'split2_ID_TOPIC' => $split2_ID_TOPIC,
		'split2_approved' => $split2_approved,
	), $id_board);

	require_once(SUBSDIR . '/FollowUps.subs.php');
	// Let's see if we can create a stronger bridge between the two topics
	// @todo not sure what message from the oldest topic I should link to the new one, so I'll go with the first
	linkMessages($split1_first_msg, $split2_ID_TOPIC);

	// Copy log topic entries.
	// @todo This should really be chunked.
	$request = $db->query('', '
		SELECT id_member, id_msg, disregarded
		FROM {db_prefix}log_topics
		WHERE id_topic = {int:id_topic}',
		array(
			'id_topic' => (int) $split1_ID_TOPIC,
		)
	);
	if ($db->num_rows($request) > 0)
	{
		$replaceEntries = array();
		while ($row = $db->fetch_assoc($request))
			$replaceEntries[] = array($row['id_member'], $split2_ID_TOPIC, $row['id_msg'], $row['disregarded']);

		require_once(SUBSDIR . '/Topic.subs.php');
		markTopicsRead($replaceEntries, false);
		unset($replaceEntries);
	}
	$db->free_result($request);

	// Housekeeping.
	updateStats('topic');
	updateLastMessages($id_board);

	logAction('split', array('topic' => $split1_ID_TOPIC, 'new_topic' => $split2_ID_TOPIC, 'board' => $id_board));

	// Notify people that this topic has been split?
	sendNotifications($split1_ID_TOPIC, 'split');

	// If there's a search index that needs updating, update it...
	require_once(SUBSDIR . '/Search.subs.php');
	$searchAPI = findSearchAPI();
	if (is_callable(array($searchAPI, 'topicSplit')))
		$searchAPI->topicSplit($split2_ID_TOPIC, $splitMessages);

	// Return the ID of the newly created topic.
	return $split2_ID_TOPIC;
}

/**
 * If we are also moving the topic somewhere else, let's try do to it
 * Includes checks for permissions move_own/any, etc.
 *
 * @param array $boards an array containing basic info of the origin and destination boards (from splitDestinationBoard)
 * @param int $totopic id of the destination topic
 */
function splitAttemptMove($boards, $totopic)
{
	global $board, $user_info, $context;

	$db = database();

	// If the starting and final boards are different we have to check some permissions and stuff
	if ($boards['destination']['id'] != $board)
	{
		$doMove = false;
		$new_topic = array();
		if (allowedTo('move_any'))
			$doMove = true;
		else
		{
			$new_topic = getTopicInfo($totopic);
			if ($new_topic['id_member_started'] == $user_info['id'] && allowedTo('move_own'))
				$doMove = true;
		}

		if ($doMove)
		{
			// Update member statistics if needed
			// @todo this should probably go into a function...
			if ($boards['destination']['count_posts'] != $boards['current']['count_posts'])
			{
				$request = $db->query('', '
					SELECT id_member
					FROM {db_prefix}messages
					WHERE id_topic = {int:current_topic}
						AND approved = {int:is_approved}',
					array(
						'current_topic' => $totopic,
						'is_approved' => 1,
					)
				);
				$posters = array();
				while ($row = $db->fetch_assoc($request))
				{
					if (!isset($posters[$row['id_member']]))
						$posters[$row['id_member']] = 0;

					$posters[$row['id_member']]++;
				}
				$db->free_result($request);

				foreach ($posters as $id_member => $posts)
				{
					// The board we're moving from counted posts, but not to.
					if (empty($boards['current']['count_posts']))
						updateMemberData($id_member, array('posts' => 'posts - ' . $posts));
					// The reverse: from didn't, to did.
					else
						updateMemberData($id_member, array('posts' => 'posts + ' . $posts));
				}
			}

			// And finally move it!
			moveTopics($totopic, $boards['destination']['id']);
		}
		else
			$boards['destination'] = $boards['current'];
	}

	// Create a link to this in the old topic.
	// @todo Does this make sense if the topic was unapproved before? We are not yet sure if the resulting topic is unapproved.
	if (!empty($_POST['messageRedirect']))
		postSplitRedirect($context['reason'], $_POST['subname'], $boards['destination'], $context['new_topic']);
}

/**
 * Retrives informations of the current and destination board of a split topic
 *
 * @return array
 */
function splitDestinationBoard()
{
	global $board, $topic;

	$current_board = boardInfo($board, $topic);
	if (empty($current_board))
		fatal_lang_error('no_board');

	if (!empty($_POST['move_new_topic']))
	{
		$toboard =  !empty($_POST['board_list']) ? (int) $_POST['board_list'] : 0;
		if (!empty($toboard) && $board !== $toboard)
		{
			$destination_board = boardInfo($toboard);
			if (empty($destination_board))
				fatal_lang_error('no_board');
		}
	}

	if (!isset($destination_board))
		$destination_board = array_merge($current_board, array('id' => $board));
	else
		$destination_board['id'] = $toboard;

	return array('current' => $current_board, 'destination' => $destination_board);
}

/**
 * Retrieve topic notifications count.
 * (used by createList() callbacks, amongst others.)
 *
 * @param int $memID id_member
 * @return string
 */
function topicNotificationCount($memID)
{
	global $user_info, $modSettings;

	$db = database();

	$request = $db->query('', '
		SELECT COUNT(*)
		FROM {db_prefix}log_notify AS ln' . (!$modSettings['postmod_active'] && $user_info['query_see_board'] === '1=1' ? '' : '
			INNER JOIN {db_prefix}topics AS t ON (t.id_topic = ln.id_topic)') . ($user_info['query_see_board'] === '1=1' ? '' : '
			INNER JOIN {db_prefix}boards AS b ON (b.id_board = t.id_board)') . '
		WHERE ln.id_member = {int:selected_member}' . ($user_info['query_see_board'] === '1=1' ? '' : '
			AND {query_see_board}') . ($modSettings['postmod_active'] ? '
			AND t.approved = {int:is_approved}' : ''),
		array(
			'selected_member' => $memID,
			'is_approved' => 1,
		)
	);
	list ($totalNotifications) = $db->fetch_row($request);
	$db->free_result($request);

	return (int)$totalNotifications;
}

/**
 * Retrieve all topic notifications for the given user.
 * (used by createList() callbacks)
 *
 * @param int $start
 * @param int $items_per_page
 * @param string $sort
 * @param int $memID id_member
 * @return array
 */
function topicNotifications($start, $items_per_page, $sort, $memID)
{
	global $scripturl, $user_info, $modSettings;

	$db = database();

	// All the topics with notification on...
	$request = $db->query('', '
		SELECT
			IFNULL(lt.id_msg, IFNULL(lmr.id_msg, -1)) + 1 AS new_from, b.id_board, b.name,
			t.id_topic, ms.subject, ms.id_member, IFNULL(mem.real_name, ms.poster_name) AS real_name_col,
			ml.id_msg_modified, ml.poster_time, ml.id_member AS id_member_updated,
			IFNULL(mem2.real_name, ml.poster_name) AS last_real_name
		FROM {db_prefix}log_notify AS ln
			INNER JOIN {db_prefix}topics AS t ON (t.id_topic = ln.id_topic' . ($modSettings['postmod_active'] ? ' AND t.approved = {int:is_approved}' : '') . ')
			INNER JOIN {db_prefix}boards AS b ON (b.id_board = t.id_board AND {query_see_board})
			INNER JOIN {db_prefix}messages AS ms ON (ms.id_msg = t.id_first_msg)
			INNER JOIN {db_prefix}messages AS ml ON (ml.id_msg = t.id_last_msg)
			LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = ms.id_member)
			LEFT JOIN {db_prefix}members AS mem2 ON (mem2.id_member = ml.id_member)
			LEFT JOIN {db_prefix}log_topics AS lt ON (lt.id_topic = t.id_topic AND lt.id_member = {int:current_member})
			LEFT JOIN {db_prefix}log_mark_read AS lmr ON (lmr.id_board = b.id_board AND lmr.id_member = {int:current_member})
		WHERE ln.id_member = {int:selected_member}
		ORDER BY {raw:sort}
		LIMIT {int:offset}, {int:items_per_page}',
		array(
			'current_member' => $user_info['id'],
			'is_approved' => 1,
			'selected_member' => $memID,
			'sort' => $sort,
			'offset' => $start,
			'items_per_page' => $items_per_page,
		)
	);
	$notification_topics = array();
	while ($row = $db->fetch_assoc($request))
	{
		censorText($row['subject']);

		$notification_topics[] = array(
			'id' => $row['id_topic'],
			'poster_link' => empty($row['id_member']) ? $row['real_name_col'] : '<a href="' . $scripturl . '?action=profile;u=' . $row['id_member'] . '">' . $row['real_name_col'] . '</a>',
			'poster_updated_link' => empty($row['id_member_updated']) ? $row['last_real_name'] : '<a href="' . $scripturl . '?action=profile;u=' . $row['id_member_updated'] . '">' . $row['last_real_name'] . '</a>',
			'subject' => $row['subject'],
			'href' => $scripturl . '?topic=' . $row['id_topic'] . '.0',
			'link' => '<a href="' . $scripturl . '?topic=' . $row['id_topic'] . '.0">' . $row['subject'] . '</a>',
			'new' => $row['new_from'] <= $row['id_msg_modified'],
			'new_from' => $row['new_from'],
			'updated' => relativeTime($row['poster_time']),
			'new_href' => $scripturl . '?topic=' . $row['id_topic'] . '.msg' . $row['new_from'] . '#new',
			'new_link' => '<a href="' . $scripturl . '?topic=' . $row['id_topic'] . '.msg' . $row['new_from'] . '#new">' . $row['subject'] . '</a>',
			'board_link' => '<a href="' . $scripturl . '?board=' . $row['id_board'] . '.0">' . $row['name'] . '</a>',
		);
	}
	$db->free_result($request);

	return $notification_topics;
}

/**
 * Get a list of posters in this topic, and their posts counts in the topic.
 * Used to update users posts counts when topics are moved or are deleted.
 */
function postersCount($id_topic)
{
	$db = database();

	// we only care about approved topics, the rest don't count.
	$request = $db->query('', '
		SELECT id_member
		FROM {db_prefix}messages
		WHERE id_topic = {int:current_topic}
			AND approved = {int:is_approved}',
		array(
			'current_topic' => $id_topic,
			'is_approved' => 1,
		)
	);
	$posters = array();
	while ($row = $db->fetch_assoc($request))
	{
		if (!isset($posters[$row['id_member']]))
			$posters[$row['id_member']] = 0;

		$posters[$row['id_member']]++;
	}
	$db->free_result($request);

	return $posters;
}

/**
 * Counts topics from the given id_board.
 *
 * @param int $board
 * @param bool $approved
 * @return int
 */
function countTopicsByBoard($board, $approved = false)
{
	$db = database();

	// How many topics are on this board?  (used for paging.)
	$request = $db->query('', '
		SELECT COUNT(*)
		FROM {db_prefix}topics AS t
		WHERE t.id_board = {int:id_board}' . (empty($approved) ? '
			AND t.approved = {int:is_approved}' : ''),
		array(
			'id_board' => $board,
			'is_approved' => 1,
		)
	);
	list ($topics) = $db->fetch_row($request);
	$db->free_result($request);

	return $topics;
}

/**
 * Determines topics which can be merged from a specific board.
 *
 * @param int $id_board
 * @param int $id_topic
 * @param bool $approved
 * @param int $offset
 * @return array
 */
function mergeableTopics($id_board, $id_topic, $approved, $offset)
{
	global $modSettings, $scripturl;

	$db = database();

	// Get some topics to merge it with.
	$request = $db->query('', '
		SELECT t.id_topic, m.subject, m.id_member, IFNULL(mem.real_name, m.poster_name) AS poster_name
		FROM {db_prefix}topics AS t
			INNER JOIN {db_prefix}messages AS m ON (m.id_msg = t.id_first_msg)
			LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = m.id_member)
		WHERE t.id_board = {int:id_board}
			AND t.id_topic != {int:id_topic}' . (empty($approved) ? '
			AND t.approved = {int:is_approved}' : '') . '
		ORDER BY {raw:sort}
		LIMIT {int:offset}, {int:limit}',
		array(
			'id_board' => $id_board,
			'id_topic' => $id_topic,
			'sort' => (!empty($modSettings['enableStickyTopics']) ? 't.is_sticky DESC, ' : '') . 't.id_last_msg DESC',
			'offset' => $offset,
			'limit' => $modSettings['defaultMaxTopics'],
			'is_approved' => 1,
		)
	);
	$topics = array();
	while ($row = $db->fetch_assoc($request))
	{
		censorText($row['subject']);

		$topics[] = array(
			'id' => $row['id_topic'],
			'poster' => array(
				'id' => $row['id_member'],
				'name' => $row['poster_name'],
				'href' => empty($row['id_member']) ? '' : $scripturl . '?action=profile;u=' . $row['id_member'],
				'link' => empty($row['id_member']) ? $row['poster_name'] : '<a href="' . $scripturl . '?action=profile;u=' . $row['id_member'] . '" target="_blank" class="new_win">' . $row['poster_name'] . '</a>'
			),
			'subject' => $row['subject'],
			'js_subject' => addcslashes(addslashes($row['subject']), '/')
		);
	}
	$db->free_result($request);

	return $topics;
}

/**
 * Determines all messages from a given array of topics.
 *
 * @param array int $topics
 * @return array
 */
function messagesInTopics($topics)
{
	$db = database();

	// Obtain all the message ids we are going to affect.
	$messages = array();
	$request = $db->query('', '
		SELECT id_msg
		FROM {db_prefix}messages
		WHERE id_topic IN ({array_int:topic_list})',
		array(
			'topic_list' => $topics,
	));
	while ($row = $db->fetch_row($request))
		$messages[] = $row['id_msg'];
	$db->free_result($request);

	return $messages;
}
