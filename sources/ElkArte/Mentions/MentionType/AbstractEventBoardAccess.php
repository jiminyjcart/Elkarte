<?php

/**
 * Abstract class that handles checks for board access level, extends AbstractEventMessage
 *
 * @package   ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause (see accompanying LICENSE.txt file)
 *
 * @version 2.0 dev
 *
 */

namespace ElkArte\Mentions\MentionType;

use ElkArte\Helper\Util;
use ElkArte\Languages\Txt;

/**
 * Class AbstractEventBoardAccess
 */
abstract class AbstractEventBoardAccess extends AbstractEventMessage
{
	/**
	 * {@inheritDoc}
	 */
	public function view($type, &$mentions)
	{
		$boards = [];
		$unset_keys = [];

		foreach ($mentions as $key => $row)
		{
			// To ensure it is not done twice
			if (empty(static::$_type) || $row['mention_type'] !== static::$_type)
			{
				continue;
			}

			// These things are associated to message and require permission checks
			if (empty($row['id_board']))
			{
				$unset_keys[] = $key;
			}
			else
			{
				$boards[$key] = (int) $row['id_board'];
			}

			$mentions[$key]['message'] = $this->_replaceMsg($row);
		}

		// Drop those where they can't actually see the mention
		if (!empty($boards))
		{
			$this->_validateAccess($boards, $mentions, $unset_keys);
		}

		return false;
	}

	/**
	 * Verifies that the current user can access the boards where the messages are located.
	 *
	 * @param int[] $boards Array of board ids
	 * @param array $mentions
	 * @param int[] $unset_keys Array of board ids
	 *
	 * @return bool
	 */
	protected function _validateAccess($boards, &$mentions, $unset_keys)
	{
		global $modSettings;

		// Do the permissions checks and replace inappropriate messages
		require_once(SUBSDIR . '/Boards.subs.php');

		Txt::load('Mentions');

		$removed = false;
		$accessibleBoards = accessibleBoards($boards);

		foreach ($boards as $key => $board)
		{
			// You can't see the board where this mention is, so we drop it from the results
			if (!in_array($board, $accessibleBoards, true))
			{
				$unset_keys[] = $key;
			}
		}

		// If some of these mentions are no longer visible, we need to do some maintenance
		if (!empty($unset_keys))
		{
			$removed = true;
			foreach ($unset_keys as $key)
			{
				unset($mentions[$key]);
			}

			if (!empty($modSettings['user_access_mentions']))
			{
				$modSettings['user_access_mentions'] = Util::unserialize($modSettings['user_access_mentions']);
			}
			else
			{
				$modSettings['user_access_mentions'] = [];
			}

			$modSettings['user_access_mentions'][$this->user->id] = 0;
			updateSettings(['user_access_mentions' => serialize($modSettings['user_access_mentions'])]);
			scheduleTaskImmediate('user_access_mentions');
		}

		return $removed;
	}
}
