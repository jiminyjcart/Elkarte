<?php

/**
 * This file has the primary job of showing and editing people's profiles.
 * It also allows the user to change some of their or another preferences,
 * and such things
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

namespace ElkArte\Profile;

use ElkArte\AbstractController;
use ElkArte\Action;
use ElkArte\Cache\Cache;
use ElkArte\Exceptions\Exception;
use ElkArte\Languages\Txt;
use ElkArte\Member;
use ElkArte\MembersList;

/**
 * Options a user can set to customize their site experience
 *
 * - Does the job of showing and editing people's profiles.
 * - Interface to buddy list, ignore list, notifications, authentication options, forum profile
 * account settings, etc
 */
class ProfileOptions extends AbstractController
{
	/** @var int Member id for the profile being viewed */
	private $_memID = 0;

	/** @var Member The \ElkArte\Member object is stored here to avoid some global */
	private $_profile;

	/**
	 * Called before all other methods when coming from the dispatcher or
	 * action class.
	 *
	 * - If you initiate the class outside those methods, call this method.
	 * or setup the class yourself else a horrible fate awaits you
	 */
	public function pre_dispatch()
	{
		$this->_memID = currentMemberID();
		$this->_profile = MembersList::get($this->_memID);
	}

	/**
	 * Default method, if another action is not called by the menu.
	 *
	 * @see AbstractController::action_index()
	 */
	public function action_index()
	{
		// action_account() is the first to do
		// these subactions are mostly routed to from the profile
		// menu though.
	}

	/**
	 * Show all the users buddies, as well as a add/delete interface.
	 *
	 * @throws Exception
	 */
	public function action_editBuddyIgnoreLists()
	{
		global $context, $txt, $modSettings;

		// Do a quick check to ensure people aren't getting here illegally!
		if (!$context['user']['is_owner'] || empty($modSettings['enable_buddylist']))
		{
			throw new Exception('no_access', false);
		}

		theme()->getTemplates()->load('ProfileOptions');

		// Can we email the user direct?
		$context['can_moderate_forum'] = allowedTo('moderate_forum');
		$context['can_send_email'] = allowedTo('send_email_to_members');

		$subActions = [
			'buddies' => [$this, 'action_editBuddies'],
			'ignore' => [$this, 'action_editIgnoreList'],
		];

		// Set a subaction
		$action = new Action('buddy_actions');
		$subAction = $action->initialize($subActions, 'buddies');

		// Create the tabs for the template.
		$context[$context['profile_menu_name']]['object']->prepareTabData([
			'title' => $txt['editBuddyIgnoreLists'],
			'description' => $txt['buddy_ignore_desc'],
			'class' => 'i-user',
		]);

		// Pass on to the actual function.
		$action->dispatch($subAction);
	}

	/**
	 * Show all the users buddies, as well as an add/delete interface.
	 *
	 * @uses template_editBuddies()
	 */
	public function action_editBuddies()
	{
		global $context;

		theme()->getTemplates()->load('ProfileOptions');

		// We want to view what we're doing :P
		$context['sub_template'] = 'editBuddies';

		// Use suggest finding the right buddies
		loadJavascriptFile('suggest.js', array('defer' => true));

		// For making changes!
		$buddiesArray = array_map('intval', explode(',', $this->_profile['buddy_list']));
		$buddiesArray = array_filter($buddiesArray, static fn($value) => $value !== '');

		// Removing a buddy?
		$notMyBuddy = $this->_req->getQuery('remove', 'intval');
		if ($notMyBuddy !== null)
		{
			checkSession('get');

			call_integration_hook('integrate_remove_buddy', [$this->_memID]);

			$key = array_search($notMyBuddy, $buddiesArray, true);
			if ($key !== false)
			{
				unset($buddiesArray[$key]);
			}

			// Make the changes.
			$this->_profile['buddy_list'] = implode(',', $buddiesArray);
			require_once(SUBSDIR . '/Members.subs.php');
			updateMemberData($this->_memID, ['buddy_list' => $this->_profile['buddy_list']]);

			// Redirect off the page because we don't like all this ugly query stuff to stick in the history.
			redirectexit('action=profile;area=lists;sa=buddies;u=' . $this->_memID);
		}
		// Or adding a new one
		elseif (isset($this->_req->post->new_buddy))
		{
			checkSession();

			// Prepare the string for extraction...
			$new_buddy = strtr($this->_req->getPost('new_buddy', 'trim|htmlspecialchars[ENT_QUOTES]'), ['&quot;' => '"']);
			if ($new_buddy === '' || in_array($new_buddy, [$this->_profile['member_name'], $this->_profile['real_name']], true))
			{
				unset($new_buddy);
			}

			call_integration_hook('integrate_add_buddies', [$this->_memID, &$new_buddies]);

			if (!empty($new_buddy))
			{
				// Now find out the id_member of the buddy.
				require_once(SUBSDIR . '/ProfileOptions.subs.php');
				$new_buddiesArray = getBuddiesID([$new_buddy]);
				$old_buddiesArray = explode(',', $this->_profile['buddy_list']);

				// Now update the current users buddy list.
				$this->_profile['buddy_list'] = implode(',', array_filter(array_unique(array_merge($new_buddiesArray, $old_buddiesArray))));

				require_once(SUBSDIR . '/Members.subs.php');
				updateMemberData($this->_memID, ['buddy_list' => $this->_profile['buddy_list']]);
			}

			// Back to the buddy list!
			redirectexit('action=profile;area=lists;sa=buddies;u=' . $this->_memID);
		}

		// Get all the users "buddies"...
		$buddies = [];

		if (!empty($buddiesArray))
		{
			require_once(SUBSDIR . '/Members.subs.php');
			$result = getBasicMemberData($buddiesArray, ['sort' => 'real_name', 'limit' => substr_count($this->_profile['buddy_list'], ',') + 1]);
			foreach ($result as $row)
			{
				$buddies[] = (int) $row['id_member'];
			}
		}

		$context['buddy_count'] = count($buddies);

		// Load all the members up.
		MembersList::load($buddies, false, 'profile');

		// Set the context for each buddy.
		$context['buddies'] = [];
		foreach ($buddies as $buddy)
		{
			$context['buddies'][$buddy] = MembersList::get($buddy);
			$context['buddies'][$buddy]->loadContext();
		}

		call_integration_hook('integrate_view_buddies', [$this->_memID]);
	}

	/**
	 * Allows the user to view their ignore list,
	 *
	 * - Provides the option to manage members on it.
	 */
	public function action_editIgnoreList()
	{
		global $context;

		theme()->getTemplates()->load('ProfileOptions');

		// We want to view what we're doing :P
		$context['sub_template'] = 'editIgnoreList';
		loadJavascriptFile('suggest.js', array('defer' => true));

		// For making changes!
		$ignoreArray = array_map('intval', explode(',', $this->_profile['pm_ignore_list']));
		$ignoreArray = array_filter($ignoreArray, static fn($value) => $value !== '');

		// Removing a member from the ignore list?
		$id_remove = $this->_req->getQuery('remove', 'intval');
		if (isset($id_remove))
		{
			checkSession('get');

			// Heh, I'm lazy, do it the easy way...
			$key = array_search($id_remove, $ignoreArray, true);
			if ($key !== false)
			{
				unset($ignoreArray[$key]);
			}

			// Make the changes.
			$this->_profile['pm_ignore_list'] = implode(',', $ignoreArray);
			require_once(SUBSDIR . '/Members.subs.php');
			updateMemberData($this->_memID, ['pm_ignore_list' => $this->_profile['pm_ignore_list']]);

			// Redirect off the page because we don't like all this ugly query stuff
			// to stick in the history.
			redirectexit('action=profile;area=lists;sa=ignore;u=' . $this->_memID);
		}
		elseif (isset($this->_req->post->new_ignore))
		{
			checkSession();

			// Prepare the string for extraction...
			$new_ignore = strtr($this->_req->getPost('new_ignore', 'trim|htmlspecialchars[ENT_QUOTES]'), ['&quot;' => '"']);
			if ($new_ignore === '' || in_array($new_ignore, [$this->_profile['member_name'], $this->_profile['real_name']], true))
			{
				unset($new_ignore);
			}

			if (!empty($new_ignore))
			{
				// Now find out the id_member for the members in question.
				require_once(SUBSDIR . '/ProfileOptions.subs.php');
				$ignoreArray = array_merge($ignoreArray, getBuddiesID([$new_ignore], false));

				// Now update the current users buddy list.
				$this->_profile['pm_ignore_list'] = implode(',', $ignoreArray);
				require_once(SUBSDIR . '/Members.subs.php');
				updateMemberData($this->_memID, ['pm_ignore_list' => $this->_profile['pm_ignore_list']]);
			}

			// Back to the list of pitiful people!
			redirectexit('action=profile;area=lists;sa=ignore;u=' . $this->_memID);
		}

		// Initialise the list of members we're ignoring.
		$ignored = [];

		if (!empty($ignoreArray))
		{
			require_once(SUBSDIR . '/Members.subs.php');
			$result = getBasicMemberData($ignoreArray, ['sort' => 'real_name', 'limit' => substr_count($this->_profile['pm_ignore_list'], ',') + 1]);
			foreach ($result as $row)
			{
				$ignored[] = (int) $row['id_member'];
			}
		}

		$context['ignore_count'] = count($ignored);

		// Load all the members up.
		MembersList::load($ignored, false, 'profile');

		// Set the context for everyone we ignore.
		$context['ignore_list'] = [];
		foreach ($ignored as $ignore_member)
		{
			$context['ignore_list'][$ignore_member] = MembersList::get($ignore_member);
			$context['ignore_list'][$ignore_member]->loadContext();
		}
	}

	/**
	 * Allows the user to see or change their account info.
	 */
	public function action_account()
	{
		global $modSettings, $context, $txt;

		theme()->getTemplates()->load('ProfileOptions');
		$this->loadThemeOptions();

		if (allowedTo(['profile_identity_own', 'profile_identity_any']))
		{
			$profileFields = new ProfileFields();
			$profileFields->loadCustomFields($this->_memID, 'account');
		}

		$context['sub_template'] = 'edit_options';
		$context['page_desc'] = $txt['account_info'];

		if (!empty($modSettings['enableOTP']))
		{
			$fields = self::getFields('account_otp');
			setupProfileContext($fields['fields'], $fields['hook']);

			loadJavascriptFile('ext/qrcode.js');
			$context['load_google_authenticator'] = true;
		}
		else
		{
			$fields = self::getFields('account');
		}

		setupProfileContext($fields['fields'], $fields['hook']);
	}

	/**
	 * Load the options for a user.
	 */
	public function loadThemeOptions()
	{
		global $context, $cur_profile, $options;

		$default_options = $this->_req->getPost('default_options');
		$post_options = $this->_req->getPost('options');
		if (isset($default_options))
		{
			$post_options = isset($post_options) ? $post_options + $default_options : $default_options;
		}

		if ($context['user']['is_owner'])
		{
			$context['member']['options'] = $options + $this->_profile->options;

			if (isset($post_options) && is_array($post_options))
			{
				foreach ($post_options as $k => $v)
				{
					$context['member']['options'][$k] = $v;
				}
			}
		}
		else
		{
			require_once(SUBSDIR . '/Themes.subs.php');
			$context['member']['options'] = loadThemeOptionsInto(
				[1, (int) $cur_profile['id_theme']],
				[-1, $this->_memID], $context['member']['options']
			);

			if (isset($post_options))
			{
				foreach ($post_options as $var => $val)
				{
					$context['member']['options'][$var] = $val;
				}
			}
		}
	}

	/**
	 * Returns the profile fields for a given area
	 *
	 * @param string $area
	 * @return array
	 */
	public static function getFields($area)
	{
		global $modSettings;

		$fields = [
			'account' => [
				'fields' => [
					'member_name', 'real_name', 'date_registered', 'posts', 'lngfile', 'hr',
					'id_group', 'hr',
					'email_address', 'show_online', 'hr',
					'passwrd1', 'passwrd2', 'hr',
					'secret_question', 'secret_answer',
				],
				'hook' => 'account'
			],
			'account_otp' => [
				'fields' => [
					'member_name', 'real_name', 'date_registered', 'posts', 'lngfile', 'hr',
					'id_group', 'hr',
					'email_address', 'show_online', 'hr',
					'passwrd1', 'passwrd2', 'hr',
					'secret_question', 'secret_answer', 'hr',
					'enable_otp', 'otp_secret', 'hr'
				],
				'hook' => 'account'
			],
			'forumprofile' => [
				'fields' => [
					'avatar_choice', 'hr',
					'bday1', 'usertitle', 'hr',
					'signature', 'hr',
					'karma_good', 'hr',
					'website_title', 'website_url',
				],
				'hook' => 'forum'
			],
			'theme' => [
				'fields' => [
					'id_theme', 'smiley_set', 'hr',
					'time_format', 'time_offset', 'hr',
					'theme_settings',
				],
				'hook' => 'themepick'
			],
			'contactprefs' => [
				'fields' => [
					'receive_from',
					'hr',
					'pm_settings',
				],
				'hook' => 'pmprefs'
			],
			'registration' => [
				'fields' => empty($modSettings['registration_fields']) ? [] : explode(',', $modSettings['registration_fields']),
				'hook' => 'registration'
			]
		];

		return $fields[$area] ?? [];
	}

	/**
	 * Allow the user to change the forum options in their profile.
	 */
	public function action_forumProfile()
	{
		global $context, $txt;

		theme()->getTemplates()->load('ProfileOptions');
		$this->loadThemeOptions();

		if (allowedTo(['profile_extra_own', 'profile_extra_any']))
		{
			$profileFields = new ProfileFields();
			$profileFields->loadCustomFields($this->_memID, 'forumprofile');
		}

		$context['sub_template'] = 'edit_options';
		$context['page_desc'] = replaceBasicActionUrl($txt['forumProfile_info']);
		$context['show_preview_button'] = true;

		$fields = self::getFields('forumprofile');
		setupProfileContext($fields['fields'], $fields['hook']);
	}

	/**
	 * Allow the edit of *someone else's* personal message settings.
	 */
	public function action_pmprefs()
	{
		global $context, $txt;

		$this->loadThemeOptions();
		$profileFields = new ProfileFields();
		$profileFields->loadCustomFields($this->_memID, 'pmprefs');
		theme()->getTemplates()->load('ProfileOptions');

		$context['sub_template'] = 'edit_options';
		$context['page_desc'] = $txt['pm_settings_desc'];

		// Set up the profile context and call the 'integrate_pmprefs_profile_fields' hook
		$fields = self::getFields('contactprefs');
		setupProfileContext($fields['fields'], $fields['hook']);
	}

	/**
	 * Allow the user to pick a theme, Set time formats, and set
	 * overall look and layout options.
	 */
	public function action_themepick()
	{
		global $txt, $context;

		$this->loadThemeOptions();

		if (allowedTo(['profile_extra_own', 'profile_extra_any']))
		{
			$profileFields = new ProfileFields();
			$profileFields->loadCustomFields($this->_memID, 'theme');
		}

		theme()->getTemplates()->load('ProfileOptions');

		$context['sub_template'] = 'edit_options';
		$context['page_desc'] = $txt['theme_info'];

		// Set up profile look and layout, call 'integrate_themepick_profile_fields' hook
		$fields = self::getFields('theme');
		setupProfileContext($fields['fields'], $fields['hook']);
	}

	/**
	 * Choose a theme from a list of those that are available
	 *
	 * What it does:
	 *
	 * - Uses the Themes template. (pick sub template.)
	 * - Accessed with ?action=admin;area=theme;sa=pick.
	 * - Allows previewing of the theme and variants
	 */
	public function action_pick()
	{
		global $txt, $context, $modSettings, $scripturl;

		checkSession('get');

		// Basics
		Txt::load('ManageThemes');
		theme()->getTemplates()->load('ProfileOptions');
		require_once(SUBSDIR . '/Themes.subs.php');

		// Note JS values will be in post via the form, JS enabled they will be in get via link button
		$_SESSION['theme'] = 0;
		$_SESSION['id_variant'] = 0;
		$save = $this->_req->getPost('save');
		$u = $this->_req->getQuery('u', 'intval');
		$themePicked = $this->_req->getQuery('th', 'intval');
		$variant = $this->_req->getQuery('vrt', 'cleanhtml');

		// Build the link tree
		$context['breadcrumbs'][] = [
			'url' => $scripturl . '?action=profile;sa=pick;u=' . $this->_memID,
			'name' => $txt['theme_pick'],
		];
		$context['default_theme_id'] = $modSettings['theme_default'];

		// Saving a theme/variant cause JS doesn't work - pretend it did ;)
		if (isset($save))
		{
			// Which theme?
			foreach ($save as $k => $v)
			{
				$themePicked = (int) $k;
			}

			if (isset($this->_req->post->vrt[$k]))
			{
				$variant = $this->_req->post->vrt[$k];
			}
		}

		// Have we made a decision, or are we just previewing?
		if (isset($themePicked))
		{
			// Save the chosen theme.
			require_once(SUBSDIR . '/Members.subs.php');
			updateMemberData($this->_memID, ['id_theme' => $themePicked]);

			// Did they pick a variant as well?
			if (!empty($variant))
			{
				updateThemeOptions([$themePicked, $this->_memID, 'theme_variant', $variant]);
				Cache::instance()->remove('theme_settings-' . $themePicked . ':' . $this->_memID);
				$_SESSION['id_variant'] = 0;
			}

			redirectexit('action=profile;area=theme');
		}

		$context['current_member'] = $this->_memID;
		$current_theme = (int) $this->_profile['theme'];

		// Get all theme name and descriptions.
		[$context['available_themes'], $guest_theme] = availableThemes($current_theme, $context['current_member']);

		// As long as we're not doing the default theme...
		if ($guest_theme !== 0)
		{
			$context['available_themes'][0] = $context['available_themes'][$guest_theme];
		}

		$context['available_themes'][0]['id'] = 0;
		$context['available_themes'][0]['name'] = $txt['theme_forum_default'];
		$context['available_themes'][0]['selected'] = $current_theme === 0;
		$context['available_themes'][0]['description'] = $txt['theme_global_description'];

		ksort($context['available_themes']);

		$context['page_title'] = $txt['theme_pick'];
		$context['sub_template'] = 'pick';
	}

	/**
	 * Display the notification settings for the user and allow changes.
	 */
	public function action_notification()
	{
		global $txt, $context;

		theme()->getTemplates()->load('ProfileOptions');

		require_once(SUBSDIR . '/Profile.subs.php');

		$subActions = [
			'settings' => [$this, 'action_editNotificationSettings'],
			'boards' => [$this, 'action_editNotificationBoards'],
			'topics' => [$this, 'action_editNotificationTopics']
		];

		// Set a subaction
		$action = new Action('notification_actions');
		$subAction = $action->initialize($subActions, 'settings');

		// Create the header for the template.
		$context[$context['profile_menu_name']]['object']->prepareTabData([
			'title' => $txt['notify_settings'],
			'description' => $txt['notification_info'],
			'class' => 'i-contact',
		]);

		// Pass on to the actual function.
		$action->dispatch($subAction);
	}

	/**
	 * Generate the users existing notification options and allow for updates
	 */
	public function action_editNotificationSettings()
	{
		global $context;

		// Show the list of notification types and how they can subscribe to them
		$context['mention_types'] = getMemberNotificationsProfile($this->_memID);

		// What options are set?
		$context['member']['notify_announcements'] = $this->_profile['notify_announcements'];
		$context['member']['notify_send_body'] = $this->_profile['notify_send_body'];
		$context['member']['notify_types'] = $this->_profile['notify_types'];
		$context['member']['notify_regularity'] = $this->_profile['notify_regularity'];
		$context['member']['notify_from'] = $this->_profile['notify_from'];

		$this->loadThemeOptions();
	}

	/**
	 * Generate the users existing board notification list.
	 * Loads data into $context to be displayed wth template_board_notification_list
	 */
	public function action_editNotificationBoards()
	{
		global $txt, $scripturl, $context;

		require_once(SUBSDIR . '/Boards.subs.php');

		$context['mention_types'] = getMemberNotificationsProfile($this->_memID);
		unset($context['sub_template']);

		// Fine, start with the board list.
		$listOptions = [
			'id' => 'board_notification_list',
			'width' => '100%',
			'no_items_label' => $txt['notifications_boards_none'] . '<br /><br />' . $txt['notifications_boards_howto'],
			'no_items_align' => 'left',
			'base_href' => $scripturl . '?action=profile;u=' . $this->_memID . ';area=notification',
			'default_sort_col' => 'board_name',
			'get_items' => [
				'function' => fn($start, $items_per_page, $sort, $memID) => $this->list_getBoardNotifications($start, $items_per_page, $sort, $memID),
				'params' => [
					$this->_memID,
				],
			],
			'columns' => [
				'board_name' => [
					'header' => [
						'value' => $txt['notifications_boards'],
						'class' => 'lefttext',
					],
					'data' => [
						'function' => static function ($board) {
							global $txt;

							$link = $board['link'];
							if ($board['new'])
							{
								$link .= ' <a href="' . $board['href'] . '" class="new_posts">' . $txt['new'] . '</a>';
							}

							return $link;
						},
					],
					'sort' => [
						'default' => 'name',
						'reverse' => 'name DESC',
					],
				],
				'delete' => [
					'header' => [
						'value' => '<input type="checkbox" onclick="invertAll(this, this.form);" />',
						'class' => 'centertext',
						'style' => 'width:4%;',
					],
					'data' => [
						'sprintf' => [
							'format' => '<input type="checkbox" name="notify_boards[]" value="%1$d" %2$s />',
							'params' => [
								'id' => false,
								'checked' => false,
							],
						],
						'class' => 'centertext',
					],
				],
			],
			'form' => [
				'href' => $scripturl . '?action=profile;area=notification;sa=boards',
				'include_sort' => true,
				'include_start' => true,
				'hidden_fields' => [
					'u' => $this->_memID,
					'sa' => $context['menu_item_selected'],
					$context['session_var'] => $context['session_id'],
				],
				'token' => $context['token_check'],
			],
			'additional_rows' => [
				[
					'class' => 'submitbutton',
					'position' => 'bottom_of_list',
					'value' => '
						<input type="submit" name="edit_notify_boards" value="' . $txt['notifications_boards_update'] . '" />
						<input type="hidden" name="save" value="save" />',
				],
				[
					'position' => 'after_title',
					'value' => getBoardNotificationsCount($this->_memID) == 0 ? $txt['notifications_boards_none'] . '<br />' . $txt['notifications_boards_howto'] : $txt['notifications_boards_current'],
				],
			],
		];

		// Create the board notification list.
		createList($listOptions);
	}

	/**
	 * Generate the users existing topic notification list.
	 * Loads data into $context to be displayed wth template_topic_notification_list
	 */
	public function action_editNotificationTopics()
	{
		global $txt, $scripturl, $context, $modSettings;

		require_once(SUBSDIR . '/Topic.subs.php');

		$context['mention_types'] = getMemberNotificationsProfile($this->_memID);
		unset($context['sub_template']);

		// Now do the topic notifications.
		$listOptions = [
			'id' => 'topic_notification_list',
			'width' => '100%',
			'items_per_page' => $modSettings['defaultMaxMessages'],
			'no_items_label' => $txt['notifications_topics_none'] . '<br /><br />' . $txt['notifications_topics_howto'],
			'no_items_align' => 'left',
			'base_href' => $scripturl . '?action=profile;u=' . $this->_memID . ';area=notification',
			'default_sort_col' => 'last_post',
			'get_items' => [
				'function' => fn($start, $items_per_page, $sort, $memID) => $this->list_getTopicNotifications($start, $items_per_page, $sort, $memID),
				'params' => [
					$this->_memID,
				],
			],
			'get_count' => [
				'function' => fn($memID) => $this->list_getTopicNotificationCount($memID),
				'params' => [
					$this->_memID,
				],
			],
			'columns' => [
				'subject' => [
					'header' => [
						'value' => $txt['notifications_topics'],
						'class' => 'lefttext',
					],
					'data' => [
						'function' => static function ($topic) {
							global $txt;

							$link = $topic['link'];
							if ($topic['new'])
							{
								$link .= ' <a href="' . $topic['new_href'] . '" class="new_posts">' . $txt['new'] . '</a>';
							}

							return $link . ('<br /><span class="smalltext"><em>' . $txt['in'] . ' ' . $topic['board_link'] . '</em></span>');
						},
					],
					'sort' => [
						'default' => 'ms.subject',
						'reverse' => 'ms.subject DESC',
					],
				],
				'started_by' => [
					'header' => [
						'value' => $txt['started_by'],
						'class' => 'lefttext',
					],
					'data' => [
						'db' => 'poster_link',
					],
					'sort' => [
						'default' => 'real_name_col',
						'reverse' => 'real_name_col DESC',
					],
				],
				'last_post' => [
					'header' => [
						'value' => $txt['last_post'],
						'class' => 'lefttext',
					],
					'data' => [
						'sprintf' => [
							'format' => '<span class="smalltext">%1$s<br />' . $txt['by'] . ' %2$s</span>',
							'params' => [
								'updated' => false,
								'poster_updated_link' => false,
							],
						],
					],
					'sort' => [
						'default' => 'ml.id_msg DESC',
						'reverse' => 'ml.id_msg',
					],
				],
				'delete' => [
					'header' => [
						'value' => '<input type="checkbox" onclick="invertAll(this, this.form);" />',
						'class' => 'centertext',
						'style' => 'width:4%;',
					],
					'data' => [
						'sprintf' => [
							'format' => '<input type="checkbox" name="notify_topics[]" value="%1$d" />',
							'params' => [
								'id' => false,
							],
						],
						'class' => 'centertext',
					],
				],
			],
			'form' => [
				'href' => $scripturl . '?action=profile;area=notification',
				'include_sort' => true,
				'include_start' => true,
				'hidden_fields' => [
					'u' => $this->_memID,
					'sa' => $context['menu_item_selected'],
					$context['session_var'] => $context['session_id'],
				],
				'token' => $context['token_check'],
			],
			'additional_rows' => [
				[
					'class' => 'submitbutton',
					'position' => 'bottom_of_list',
					'value' => '
						<input type="submit" name="edit_notify_topics" value="' . $txt['notifications_update'] . '" />
						<input type="hidden" name="save" value="save" />',
				],
			],
		];

		// Create the topic notification list.
		createList($listOptions);
	}

	/**
	 * Callback for createList() in action_notification()
	 *
	 * @param int $start The item to start with (for pagination purposes)
	 * @param int $items_per_page The number of items to show per page
	 * @param string $sort A string indicating how to sort the results
	 * @param int $memID id_member
	 *
	 * @return array array of board notifications
	 * @uses template_ignoreboards()
	 */
	public function list_getBoardNotifications($start, $items_per_page, $sort, $memID)
	{
		// Return boards you see and their notification status for the list
		return boardNotifications($sort, $memID);
	}

	/**
	 * Callback for createList() in action_notification()
	 *
	 * @param int $start The item to start with (for pagination purposes)
	 * @param int $items_per_page The number of items to show per page
	 * @param string $sort A string indicating how to sort the results
	 * @param int $memID id_member
	 *
	 * @return array array of topic notifications
	 */
	public function list_getTopicNotifications($start, $items_per_page, $sort, $memID)
	{
		// Topic notifications, for the list
		return topicNotifications($start, $items_per_page, $sort, $memID);
	}

	/**
	 * Callback for createList() in action_notification()
	 *
	 * - Retrieve topic notifications count.
	 *
	 * @param int $memID id_member the id of the member who's notifications we are loading
	 * @return int
	 */
	public function list_getTopicNotificationCount($memID)
	{
		// Topic notifications count, for the list
		return topicNotificationCount($memID);
	}

	/**
	 * Allows the user to see the list of their ignored boards.
	 * (and un-ignore them)
	 */
	public function action_ignoreboards()
	{
		global $context, $modSettings, $cur_profile;

		// Have the admins enabled this option?
		if (empty($modSettings['allow_ignore_boards']))
		{
			throw new Exception('ignoreboards_disallowed', 'user');
		}

		theme()->getTemplates()->load('ProfileOptions');

		$context['sub_template'] = 'ignoreboards';
		require_once(SUBSDIR . '/Boards.subs.php');
		$context += getBoardList(['not_redirection' => true, 'ignore' => empty($cur_profile['ignore_boards']) ? [] : explode(',', $cur_profile['ignore_boards'])]);

		// Include a list of boards per category for easy toggling.
		foreach ($context['categories'] as $cat => &$category)
		{
			$context['boards_in_category'][$cat] = count($category['boards']);
			$category['child_ids'] = array_keys($category['boards']);
		}

		$this->loadThemeOptions();
	}

	/**
	 * Function to allow the user to choose group membership etc...
	 */
	public function action_groupMembership()
	{
		global $txt, $context;

		theme()->getTemplates()->load('ProfileOptions');
		$context['sub_template'] = 'groupMembership';

		$curMember = $this->_profile;
		$context['primary_group'] = (int) $curMember['id_group'];
		$msgName = $this->_req->getQuery('msg', 'trim');

		// Can they manage groups?
		$context['can_manage_membergroups'] = allowedTo('manage_membergroups');
		$context['can_manage_protected'] = allowedTo('admin_forum');
		$context['can_edit_primary'] = $context['can_manage_protected'];
		$context['update_message'] = isset($msgName, $txt['group_membership_msg_' . $msgName]) ? $txt['group_membership_msg_' . $msgName] : '';

		// Get all the groups this user is a member of.
		$groups = array_map('intval', explode(',', $curMember['additional_groups']));
		$groups[] = (int) $curMember['id_group'];

		// Ensure the query doesn't croak!
		if (empty($groups))
		{
			$groups = [0];
		}

		// Just to be sure...
		$groups = array_map('intval', $groups);

		// Get all the membergroups they can join.
		require_once(SUBSDIR . '/ProfileOptions.subs.php');
		$context['groups'] = loadMembergroupsJoin($groups, $this->_memID);

		// Add registered members on the end.
		$context['groups']['member'][0] = [
			'id' => 0,
			'name' => $txt['regular_members'],
			'desc' => $txt['regular_members_desc'],
			'type' => 0,
			'is_primary' => $context['primary_group'] === 0,
			'can_be_primary' => true,
			'can_leave' => 0,
		];

		// No changing primary one unless you have enough groups!
		if (count($context['groups']['member']) < 2)
		{
			$context['can_edit_primary'] = false;
		}

		// In the special case that someone is requesting membership of a group, setup some special context vars.
		$groupRequest = $this->_req->getQuery('request', 'intval');
		if (!isset($groupRequest, $context['groups']['available'][$groupRequest]))
		{
			return;
		}

		if ($context['groups']['available'][$groupRequest]['type'] !== 2)
		{
			return;
		}

		$context['group_request'] = $context['groups']['available'][$groupRequest];
	}

	/**
	 * This function actually makes all the group changes
	 *
	 * @return string
	 * @throws Exception no_access
	 */
	public function action_groupMembership2()
	{
		global $context, $modSettings, $scripturl, $language;

		// Let's be extra cautious...
		if (!$context['user']['is_owner'] || empty($modSettings['show_group_membership']))
		{
			isAllowedTo('manage_membergroups');
		}

		$group_id = $this->_req->getPost('gid', 'intval', $this->_req->getQuery('gid', 'intval', null));

		if (!isset($group_id) && !isset($this->_req->post->primary))
		{
			throw new Exception('no_access', false);
		}

		// GID may be from a link or a form
		checkSession(isset($this->_req->query->gid) ? 'get' : 'post');

		require_once(SUBSDIR . '/Membergroups.subs.php');

		$context['can_manage_membergroups'] = allowedTo('manage_membergroups');
		$context['can_manage_protected'] = allowedTo('admin_forum');

		// By default, the new primary is the old one.
		$newPrimary = (int) $this->_profile['id_group'];
		$addGroups = array_flip(explode(',', $this->_profile['additional_groups']));
		$canChangePrimary = (int) $this->_profile['id_group'] === 0;
		$changeType = isset($this->_req->post->primary) ? 'primary' : (isset($this->_req->post->req) ? 'request' : 'free');

		// One way or another, we have a target group in mind...
		$group_id = $group_id ?? (int) $this->_req->post->primary;
		$foundTarget = $changeType === 'primary' && $group_id === 0;

		// Sanity check!!
		if ($group_id === 1)
		{
			isAllowedTo('admin_forum');
		}

		// What ever we are doing, we need to determine if changing primary is possible!
		$groups_details = membergroupsById([$group_id, $this->_profile['id_group']], 0, true);

		// Protected groups require proper permissions!
		if ($group_id !== 1 && $groups_details[$group_id]['group_type'] === 1)
		{
			isAllowedTo('admin_forum');
		}

		foreach ($groups_details as $row)
		{
			// Is this the new group?
			if ($row['id_group'] === $group_id)
			{
				$foundTarget = true;
				$group_name = $row['group_name'];

				// Does the group type match what we're doing - are we trying to request a non-requestable group?
				if ($changeType === 'request' && $row['group_type'] !== 2)
				{
					throw new Exception('no_access', false);
				}

				// What about leaving a requestable group we are not a member of?
				if ($changeType === 'free' && $row['group_type'] === 2 && $this->_profile['id_group'] !== $row['id_group'] && !isset($addGroups[$row['id_group']]))
				{
					throw new Exception('no_access', false);
				}

				if ($changeType === 'free' && $row['group_type'] !== 3 && $row['group_type'] !== 2)
				{
					throw new Exception('no_access', false);
				}

				// We can't change the primary group if this is hidden!
				if ((int) $row['hidden'] === 2)
				{
					$canChangePrimary = false;
				}
			}

			// If this is their old primary, can we change it?
			if ($row['id_group'] === $this->_profile['id_group'] && ($row['group_type'] > 1 || $context['can_manage_membergroups']) && $canChangePrimary)
			{
				$canChangePrimary = true;
			}

			// If we are not doing a force primary move, don't do it automatically if current primary is not 0.
			if ($changeType !== 'primary' && $this->_profile['id_group'] !== 0)
			{
				$canChangePrimary = false;
			}

			// If this is the one we are acting on, can we even act?
			if ((!$context['can_manage_protected'] && $row['group_type'] === 1) || (!$context['can_manage_membergroups'] && $row['group_type'] === 0))
			{
				$canChangePrimary = false;
			}
		}

		// Didn't find the target?
		if (!$foundTarget)
		{
			throw new Exception('no_access', false);
		}

		// Final security check, don't allow users to promote themselves to admin.
		require_once(SUBSDIR . '/ProfileOptions.subs.php');
		if ($context['can_manage_membergroups'] && !allowedTo('admin_forum'))
		{
			$disallow = checkMembergroupChange($group_id);
			if ($disallow)
			{
				isAllowedTo('admin_forum');
			}
		}

		// If we're requesting, add the note then return.
		if ($changeType === 'request')
		{
			if (logMembergroupRequest($group_id, $this->_memID))
			{
				throw new Exception('profile_error_already_requested_group');
			}

			// Email all group moderators etc.
			require_once(SUBSDIR . '/Mail.subs.php');

			// Do we have any group moderators?
			require_once(SUBSDIR . '/Membergroups.subs.php');
			$moderators = array_keys(getGroupModerators($group_id));

			// Otherwise, this is the backup!
			if (empty($moderators))
			{
				require_once(SUBSDIR . '/Members.subs.php');
				$moderators = membersAllowedTo('manage_membergroups');
			}

			if (!empty($moderators))
			{
				require_once(SUBSDIR . '/Members.subs.php');
				$members = getBasicMemberData($moderators, ['preferences' => true, 'sort' => 'lngfile']);

				foreach ($members as $member)
				{
					if ((int) $member['notify_types'] !== 4)
					{
						continue;
					}

					// Check whether they are interested.
					if (!empty($member['mod_prefs']))
					{
						[, , $pref_binary] = explode('|', $member['mod_prefs']);
						if (!($pref_binary & 4))
						{
							continue;
						}
					}

					$replacements = [
						'RECPNAME' => $member['member_name'],
						'APPYNAME' => $this->_profile['member_name'],
						'GROUPNAME' => $group_name,
						'REASON' => $this->_req->post->reason,
						'MODLINK' => $scripturl . '?action=moderate;area=groups;sa=requests',
					];

					$emaildata = loadEmailTemplate('request_membership', $replacements, empty($member['lngfile']) || empty($modSettings['userLanguage']) ? $language : $member['lngfile']);
					sendmail($member['email_address'], $emaildata['subject'], $emaildata['body'], null, null, false, 2);
				}
			}

			return $changeType;
		}

		// Otherwise, we are leaving/joining a group.
		if ($changeType === 'free')
		{
			// Are we leaving?
			if ($this->_profile['id_group'] === $group_id || isset($addGroups[$group_id]))
			{
				if ($this->_profile['id_group'] === $group_id)
				{
					$newPrimary = 0;
				}
				else
				{
					unset($addGroups[$group_id]);
				}
			}
			// ... if not, must be joining.
			elseif ($canChangePrimary)
			{
				// Can we change the primary, and do we want to?
				if ($this->_profile['id_group'] !== 0)
				{
					$addGroups[$this->_profile['id_group']] = -1;
				}

				$newPrimary = $group_id;
			}
			// Otherwise it's an additional group...
			else
			{
				$addGroups[$group_id] = -1;
			}
		}
		// Finally, we must be setting the primary.
		elseif ($canChangePrimary)
		{
			if ($this->_profile['id_group'] !== 0)
			{
				$addGroups[$this->_profile['id_group']] = -1;
			}

			if (isset($addGroups[$group_id]))
			{
				unset($addGroups[$group_id]);
			}

			$newPrimary = $group_id;
		}

		// Finally, we can make the changes!
		foreach (array_keys($addGroups) as $id)
		{
			if (empty($id))
			{
				unset($addGroups[$id]);
			}
		}

		$addGroups = implode(',', array_flip($addGroups));

		// Ensure that we don't cache permissions if the group is changing.
		if ($context['user']['is_owner'])
		{
			$_SESSION['mc']['time'] = 0;
		}
		else
		{
			updateSettings(['settings_updated' => time()]);
		}

		require_once(SUBSDIR . '/Members.subs.php');
		updateMemberData($this->_memID, ['id_group' => $newPrimary, 'additional_groups' => $addGroups]);

		return $changeType;
	}
}
