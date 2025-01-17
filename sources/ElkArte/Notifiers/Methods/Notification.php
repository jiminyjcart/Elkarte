<?php

/**
 * This class takes care of sending a notification as internal ElkArte notification
 *
 * @package   ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause (see accompanying LICENSE.txt file)
 *
 * @version 2.0 dev
 *
 */

namespace ElkArte\Notifiers\Methods;

use ElkArte\Database\QueryInterface;
use ElkArte\Helper\DataValidator;
use ElkArte\Mentions\Mentioning;
use ElkArte\Mentions\MentionType\NotificationInterface;
use ElkArte\Notifications\NotificationsTask;
use ElkArte\Notifiers\AbstractNotifier;
use ElkArte\UserInfo;

/**
 * Class Notifications
 *
 * Core area for notifications, defines the abstract model
 */
class Notification extends AbstractNotifier
{
	/** @var string[] Hash defining what is needed to build the message */
	public $lang_data;

	/**
	 * Notifications constructor.
	 *
	 * Registers the known notifications to the system, allows for integration to add more
	 *
	 * @param QueryInterface $db
	 * @param UserInfo|null $user
	 */
	public function __construct($db, $user)
	{
		parent::__construct($db, $user);

		$this->lang_data = [];
	}

	/**
	 * {@inheritDoc}
	 */
	public function send(NotificationInterface $obj, NotificationsTask $task, $bodies)
	{
		$this->_send_notification($obj, $task, $bodies);
	}

	/**
	 * Inserts a new mention in the database (those that appear in the mentions area).
	 *
	 * @param NotificationInterface $obj
	 * @param NotificationsTask $task
	 * @param array $bodies
	 */
	protected function _send_notification($obj, $task, $bodies)
	{
		global $modSettings;

		$mentioning = new Mentioning($this->db, $this->user, new DataValidator(), $modSettings['enabled_mentions']);
		foreach ($bodies as $body)
		{
			$mentioning->create($obj, array(
				'id_member_from' => $task['id_member_from'],
				'id_member' => $body['id_member_to'],
				'id_msg' => $task['id_target'],
				'type' => $task['notification_type'],
				'log_time' => $task['log_time'],
				'status' => $task['source_data']['status'],
			));
		}
	}
}
