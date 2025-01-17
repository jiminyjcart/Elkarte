<?php

/**
 * Part of the files dealing with preparing the content for display posts
 * via callbacks (Display, PM, Search).
 *
 * @package   ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause (see accompanying LICENSE.txt file)
 *
 * This file contains code covered by:
 * copyright: 2011 Simple Machines (http://www.simplemachines.org)
 *
 * @version 2.0 dev
 *
 */

namespace ElkArte\MessagesCallback;

use ElkArte\MembersList;

/**
 * DisplayRenderer
 * The class prepares the details of a message so that they can be used
 * to display it in the template.
 */
class DisplayRenderer extends Renderer
{
	public const BEFORE_PREPARE_HOOK = 'integrate_before_prepare_display_context';

	public const CONTEXT_HOOK = 'integrate_prepare_display_context';

	/**
	 * {@inheritDoc}
	 */
	protected function _setupPermissions()
	{
		global $context, $modSettings;

		// Are you allowed to remove at least a single reply?
		$context['can_remove_post'] |= allowedTo('delete_own')
			&& (empty($modSettings['edit_disable_time']) || $this->_this_message['poster_time'] + $modSettings['edit_disable_time'] * 60 >= time())
			&& (int) $this->_this_message['id_member'] === (int) $this->user->id;

		// Have you liked this post, can you?
		$this->_this_message['you_liked'] = !empty($context['likes'][$this->_this_message['id_msg']]['member'])
			&& isset($context['likes'][$this->_this_message['id_msg']]['member'][$this->user->id]);
		$this->_this_message['use_likes'] = allowedTo('like_posts') && empty($context['is_locked'])
			&& ($this->_this_message['id_member'] != $this->user->id || !empty($modSettings['likeAllowSelf']))
			&& (empty($modSettings['likeMinPosts']) || $modSettings['likeMinPosts'] <= $this->user->posts);
		$this->_this_message['like_count'] = empty($context['likes'][$this->_this_message['id_msg']]['count']) ? 0 : $context['likes'][$this->_this_message['id_msg']]['count'];
	}

	/**
	 * {@inheritDoc}
	 */
	protected function _adjustAllMembers($member_context)
	{
		global $context;

		$id_member = $this->_this_message[$this->_idx_mapper->id_member];
		$this_member = MembersList::get($id_member);
		$this_member->loadContext();

		$this_member['ip'] = $this->_this_message['poster_ip'] ?? '';
		$this_member['show_profile_buttons'] = (!empty($this_member['can_view_profile'])
			|| (!empty($this_member['website']['url']) && !isset($context['disabled_fields']['website']))
			|| $this_member['show_email']
			|| $context['can_send_pm']);

		$context['id_msg'] = $this->_this_message['id_msg'] ?? '';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function _buildOutputArray()
	{
		global $topic, $context, $modSettings, $txt;

		require_once(SUBSDIR . '/Attachments.subs.php');

		$output = parent::_buildOutputArray();
		$href = getUrl('topic', ['topic' => $topic, 'start' => 'msg' . $this->_this_message['id_msg'], 'subject' => $this->_this_message['subject']]) . '#msg' . $this->_this_message['id_msg'];
		$output += [
			'href' => $href,
			'link' => '<a href="' . $href . '" rel="nofollow">' . $this->_this_message['subject'] . '</a>',
			'icon' => $this->_options->icon_sources->getIconValue($this->_this_message['icon']),
			'icon_url' => $this->_options->icon_sources->getIconURL($this->_this_message['icon']),
			'modified' => [
				'time' => standardTime($this->_this_message['modified_time']),
				'html_time' => htmlTime($this->_this_message['modified_time']),
				'timestamp' => forum_time(true, $this->_this_message['modified_time']),
				'name' => $this->_this_message['modified_name']
			],
			'new' => empty($this->_this_message['is_read']),
			'approved' => $this->_this_message['approved'],
			'first_new' => isset($context['start_from']) && $context['start_from'] == $this->_counter,
			'is_ignored' => !empty($modSettings['enable_buddylist']) && in_array($this->_this_message['id_member'], $context['user']['ignoreusers']),
			'is_message_author' => (int) $this->_this_message['id_member'] === (int) $this->user->id,
			'can_approve' => !$this->_this_message['approved'] && $context['can_approve'],
			'can_unapprove' => !empty($modSettings['postmod_active']) && $context['can_approve'] && $this->_this_message['approved'],
			'can_modify' => (!$context['is_locked'] || allowedTo('moderate_board')) && (allowedTo('modify_any') || (allowedTo('modify_replies') && $context['user']['started']) || (allowedTo('modify_own') && $this->_this_message['id_member'] == $this->user->id && (empty($modSettings['edit_disable_time']) || !$this->_this_message['approved'] || $this->_this_message['poster_time'] + $modSettings['edit_disable_time'] * 60 > time()))),
			'can_remove' => allowedTo('delete_any') || (allowedTo('delete_replies') && $context['user']['started']) || (allowedTo('delete_own') && $this->_this_message['id_member'] == $this->user->id && (empty($modSettings['edit_disable_time']) || $this->_this_message['poster_time'] + $modSettings['edit_disable_time'] * 60 > time())),
			'can_like' => $this->_this_message['use_likes'] && !$this->_this_message['you_liked'],
			'can_unlike' => $this->_this_message['use_likes'] && $this->_this_message['you_liked'],
			'like_counter' => $this->_this_message['like_count'],
			'likes_enabled' => !empty($modSettings['likes_enabled']) && ($this->_this_message['use_likes'] || ($this->_this_message['like_count'] != 0)),
			'classes' => [],
		];

		if (!empty($output['modified']['name']))
		{
			$output['modified']['last_edit_text'] = sprintf($txt['last_edit_by'], $output['modified']['time'], $output['modified']['name'], standardTime($output['modified']['timestamp']));
		}

		if (!empty($output['member']['karma']['allow']))
		{
			$output['member']['karma'] += [
				'applaud_url' => getUrl('action', ['action' => 'karma', 'sa' => 'applaud', 'uid' => $output['member']['id'], 'topic' => $context['current_topic'] . '.' . $context['start'], 'm' => $output['id'], '{session_data}']),
				'smite_url' => getUrl('action', ['action' => 'karma', 'sa' => 'smite', 'uid' => $output['member']['id'], 'topic' => $context['current_topic'] . '.' . $context['start'], 'm' => $output['id'], '{session_data}'])
			];
		}

		// Build the per post buttons!
		$output += $this->_buildPostButtons($output);

		return $output;
	}

	/**
	 * Generates the available button array suitable for consumption by template_button_strip
	 *
	 * @param array $output The output array containing post details.
	 *
	 * @return array The array containing all the post buttons.
	 */
	protected function _buildPostButtons($output)
	{
		global $context, $txt, $topic, $options, $board;

		$postButtons = [
			// Can they reply? Have they turned on quick reply?
			'quote' => [
				'text' => 'quote',
				'url' => empty($options['display_quick_reply']) ? getUrl('action', ['action' => 'post', 'topic' => $topic . '.' . $context['start'], 'quote' => $output['id'], 'last_msg' => $context['topic_last_message']]) : null,
				'custom' => empty($options['display_quick_reply']) ? '' : 'onclick="return oQuickReply.quote(' . $output['id'] . ');"',
				'class' => 'quote_button last',
				'icon' => 'quote',
				'enabled' => !empty($context['can_quote']),
			],
			// Can the user quick modify the contents of this post?  Show the quick (inline) modify button.
			'quick_edit' => [
				'text' => 'quick_edit',
				'url' => 'javascript:void(0);',
				'custom' => 'onclick="oQuickModify.modifyMsg(\'' . $output['id'] . '\')"',
				'icon' => 'modify',
				'enabled' => $output['can_modify'],
			],
			// Can they like/unlike or just view counts
			'react' => [
				'text' => 'like_post',
				'url' => 'javascript:void(0);',
				'custom' => 'onclick="likePosts.prototype.likeUnlikePosts(event,' . $output['id'] . ',' . $context['current_topic'] . '); return false;"',
				'linkclass' => 'react_button',
				'icon' => 'thumbsup',
				'enabled' => $output['likes_enabled'] && $output['can_like'],
				'counter' => $output['like_counter'] ?? 0,
			],
			'unreact' => [
				'text' => 'unlike_post',
				'url' => 'javascript:void(0);',
				'custom' => 'onclick="likePosts.prototype.likeUnlikePosts(event,' . $output['id'] . ',' . $context['current_topic'] . '); return false;"',
				'linkclass' => 'unreact_button',
				'icon' => 'thumbsdown',
				'enabled' => $output['likes_enabled'] && $output['can_unlike'],
				'counter' => $output['like_counter'] ?? 0,
			],
			'liked' => [
				'text' => 'likes',
				'url' => 'javascript:void(0);',
				'custom' => 'onclick="this.blur();"',
				'icon' => 'thumbsup',
				'enabled' => $output['likes_enabled'] && !$output['can_unlike'] && !$output['can_like'],
				'counter' => $output['like_counter'] ?? 0,
			],
			// *** Submenu, aka "more", button items
			//
			// Can the user modify the contents of this post?
			'modify' => [
				'text' => 'modify',
				'url' => getUrl('action', ['action' => 'post', 'msg' => $output['id'], 'topic' => $context['current_topic'] . '.' . $context['start']]),
				'icon' => 'modify',
				'enabled' => $output['can_modify'],
				'submenu' => true,
			],
			// How about... even... remove it entirely?!
			'remove_topic' => [
				'text' => 'remove_topic',
				'url' => getUrl('action', ['action' => 'removetopic2', 'topic' => $topic . '.' . $context['start'], '{session_data}']),
				'custom' => 'onclick="return confirm(\'' . $txt['are_sure_remove_topic'] . '\');"',
				'icon' => 'warn',
				'enabled' => $context['can_delete'] && $context['topic_first_message'] === $output['id'],
				'submenu' => true,
			],
			// How about... remove the message
			'remove' => [
				'text' => 'remove',
				'url' => getUrl('action', ['action' => 'deletemsg', 'topic' => $topic . '.' . $context['start'], 'msg' => $output['id'], '{session_data}']),
				'custom' => 'onclick="return confirm(\'' . $txt['remove_message'] . '?\');"',
				'icon' => 'delete',
				'enabled' => $output['can_remove'] && ($context['topic_first_message'] !== $output['id']),
				'submenu' => true,
			],
			// Can they quote to a new topic? @todo - This needs rethinking for GUI layout.
			'followup' => [
				'text' => 'quote_new',
				'url' => getUrl('action', ['action' => 'post', 'board' => $board, 'quote' => $output['id'], 'followup' => $output['id']]),
				'icon' => 'quote',
				'enabled' => !empty($context['can_follow_up']),
				'submenu' => true,
			],
			// What about splitting it off the rest of the topic?
			'split' => [
				'text' => 'split_topic',
				'url' => getUrl('action', ['action' => 'splittopics', 'topic' => $topic . '.0', 'at' => $output['id']]),
				'icon' => 'split',
				'enabled' => $context['can_split'] && !empty($context['real_num_replies']) && $context['topic_first_message'] !== $output['id'],
				'submenu' => true,
			],
			// Can we restore topics?
			'restore' => [
				'text' => 'restore_message',
				'url' => getUrl('action', ['action' => 'restoretopic', 'msgs' => $output['id'], '{session_data}']),
				'icon' => 'recycle',
				'enabled' => $context['can_restore_msg'],
				'submenu' => true,
			],
			// Maybe we can approve it, maybe we should?
			'approve' => [
				'text' => 'approve',
				'url' => getUrl('action', ['action' => 'moderate', 'area' => 'postmod', 'sa' => 'approve', 'topic' =>  $topic . '.' . $context['start'], 'msg' => $output['id'], '{session_data}']),
				'icon' => 'check',
				'enabled' => $output['can_approve'],
				'submenu' => true,
			],
			// Maybe we can unapprove it?
			'unapprove' => [
				'text' => 'unapprove',
				'url' => getUrl('action', ['action' => 'moderate', 'area' => 'postmod', 'sa' => 'approve', 'topic' =>  $topic . '.' . $context['start'], 'msg' => $output['id'], '{session_data}']),
				'icon' => 'remove',
				'enabled' => $output['can_unapprove'],
				'submenu' => true,
			],
			// Maybe they want to report this post to the moderator(s)?
			'report' => [
				'text' => 'report_to_mod',
				'url' => getUrl('action', ['action' => 'reporttm', 'topic' =>  $topic . '.' . $output['counter'], 'msg' => $output['id']]),
				'icon' => 'comment',
				'enabled' => $context['can_report_moderator'],
				'submenu' => true,
			],
			// Can we issue a warning because of this post?
			'warn' => [
				'text' => 'issue_warning',
				'url' => getUrl('action', ['action' => 'profile', 'area' => 'issuewarning', 'u' =>  $output['member']['id'], 'msg' => $output['id']]),
				'icon' => 'warn',
				'enabled' => $context['can_issue_warning'] && !$output['is_message_author'] && !$output['member']['is_guest'],
				'submenu' => true,
			],
			// Quick Moderation Checkbox
			'inline_mod_check' => [
				'id' => 'in_topic_mod_check_' . $output['id'],
				'class' => 'inline_mod_check hide',
				'enabled' => !empty($options['display_quick_mod']) && $output['can_remove'],
				'checkbox' => 'check',
			]
		];

		// Drop any non-enabled ones
		$postButtons = array_filter($postButtons, static fn($button) => !isset($button['enabled']) || (bool) $button['enabled']);

		return ['postbuttons' => $postButtons];
	}
}
