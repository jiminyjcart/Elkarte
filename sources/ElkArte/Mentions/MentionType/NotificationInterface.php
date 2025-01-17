<?php

/**
 * Interface for notification objects
 *
 * @package   ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause (see accompanying LICENSE.txt file)
 *
 * @version 2.0 dev
 *
 */

namespace ElkArte\Mentions\MentionType;

use ElkArte\Notifications\NotificationsTask;

/**
 * Interface NotificationInterface
 *
 * This interface defines the methods that a notification class must implement.
 */
interface NotificationInterface
{
	/**
	 * Just returns the _type property.
	 */
	public static function getType();

	/**
	 * Used by the Notifications class to find the users that want a notification.
	 */
	public function setUsersToNotify();

	/**
	 * Used by the Notifications class to retrieve the notifications to send.
	 *
	 * @param array $lang_data
	 * @param int[] $members
	 *
	 * @return array with the following construction:
	 * array(array(
	 *   id_member_to (int),
	 *   email_address (text),
	 *   subject (text),
	 *   body (text),
	 *   last_id (int),
	 *   ???
	 * ))
	 */
	public function getNotificationBody($lang_data, $members);

	/**
	 * The \ElkArte\NotificationsTask contains data that may be necessary for the processing
	 * of the notification.
	 *
	 * @param NotificationsTask $task
	 */
	public function setTask(NotificationsTask $task);

	/**
	 * Used when sending an immediate email to get the last message id (email id)
	 * so that PbE can do its magic.
	 *
	 * @return string
	 */
	public function getLastId();

	/**
	 * Inserts a new notification into the database.
	 * Checks if the notification already exists (in any status) to prevent any duplicates
	 *
	 * @param int $member_from the id of the member mentioning
	 * @param int[] $members_to an array of ids of the members mentioned
	 * @param int $target the id of the target involved in the mention
	 * @param string|null $time optional value to set the time of the mention, defaults to now
	 * @param int|null $status optional value to set a status, defaults to 0
	 * @param bool|int|null $is_accessible optional if the mention is accessible to the user
	 *
	 * @return int[] An array of members id
	 * @package Mentions
	 */
	public function insert($member_from, $members_to, $target, $time = null, $status = null, $is_accessible = null);

	/**
	 * Provides a list of methods that should not be used by this notification type.
	 *
	 * @param string $method the Notifier method that is being considered
	 *
	 * @return bool
	 */
	public static function isNotAllowed($method);

	/**
	 * If needed checks for permissions to use this specific notification
	 *
	 * @return bool
	 */
	public static function canUse();

	/**
	 * Returns if the application should show settings in the admin interface
	 *
	 * @return bool
	 */
	public static function hasHiddenInterface();
}
