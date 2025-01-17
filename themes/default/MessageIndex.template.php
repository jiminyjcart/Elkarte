<?php

/**
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

use ElkArte\MessageTopicIcons;

/**
 * Loads the template used to display boards
 */
function template_MessageIndex_init()
{
	theme()->getTemplates()->load('GenericBoards');
}

/**
 * Used to display sub-boards.
 */
function template_display_child_boards_above()
{
	global $context, $txt;

	echo '
	<header class="category_header">
		', $txt['parent_boards'], '
	</header>
	<section id="board_', $context['current_board'], '_childboards" class="forum_category">';

	template_list_boards($context['boards'], 'board_' . $context['current_board'] . '_children');

	echo '
	</section>';
}

/**
 * Header bar and extra details above topic listing
 *  - board description
 *  - who is viewing
 *  - sort container
 */
function template_topic_listing_above()
{
	global $context, $settings, $txt, $options;

	if ($context['no_topic_listing'])
	{
		return;
	}

	template_pagesection('normal_buttons');

	echo '
		<header id="description_board">
			<h2 class="category_header">', $context['name'], '</h2>
			<div class="generalinfo">';

	// Show the board description
	if (!empty($context['description']))
	{
		echo '
				<div id="boarddescription">
					', $context['description'], '
				</div>';
	}

	if (!empty($context['moderators']))
	{
		echo '
				<div class="moderators"><i class="icon icon-small i-user colorize-orange"></i>', count($context['moderators']) === 1 ? $txt['moderator'] : $txt['moderators'], ': ', implode(', ', $context['link_moderators']), '.</div>';
	}

	echo '
				<div id="topic_sorting" class="flow_flex" >
					<div id="whoisviewing">';

	// If we are showing who is viewing this topic, build it out
	if (!empty($settings['display_who_viewing']))
	{
		if ($settings['display_who_viewing'] == 1)
		{
			echo '<i class="icon icon-small i-users"></i>', count($context['view_members']), ' ', count($context['view_members']) === 1 ? $txt['who_member'] : $txt['members'];
		}
		else
		{
			echo '<i class="icon icon-small i-users"></i>', empty($context['view_members_list']) ? '0 ' . $txt['members'] : implode(', ', $context['view_members_list']) . (empty($context['view_num_hidden']) || $context['can_moderate_forum'] ? '' : ' (+ ' . $context['view_num_hidden'] . ' ' . $txt['hidden'] . ')');
		}

		echo $txt['who_and'], $context['view_num_guests'], ' ', $context['view_num_guests'] == 1 ? $txt['guest'] : $txt['guests'], $txt['who_viewing_board'];
	}

	// Sort topics mumbo-jumbo
	echo '
					</div>
					<ul id="sort_by" class="no_js">';

	$current_header = $context['topics_headers'][$context['sort_by']];
	echo '
						<li class="listlevel1 topic_sorting_row">', $txt['sort_by'], ': <a href="', $current_header['url'], '">', $txt[$context['sort_by']], '</a>
							<ul class="menulevel2" id="sortby">';

	foreach ($context['topics_headers'] as $key => $value)
	{
		echo '
								<li class="listlevel2 sort_by_item" id="sort_by_item_', $key, '">
									<a href="', $value['url'], '" class="linklevel2">', $txt[$key], ' ', $value['sort_dir_img'], '</a>
								</li>';
	}

	echo '
							</ul>
						</li>
						<li class="listlevel1 topic_sorting_row">
							<a class="sort topicicon i-sort', $context['sort_direction'], '" href="', $current_header['url'], '" title="', $context['sort_title'], '"></a>
						</li>';

	if (!empty($context['can_quick_mod']) && !empty($options['display_quick_mod']))
	{
		echo '
						<li class="listlevel1 quickmod_select_all">
							<label for="select_all" class="hide">', $txt['all'], '</label>
							<input type="checkbox" id="select_all" onclick="invertAll(this, document.getElementById(\'quickModForm\'), \'topics[]\');" />
						</li>';
	}

	echo '					
					</ul>
				</div>
			</div>
		</header>';
}

/**
 * The actual topic listing.
 */
function template_topic_listing()
{
	global $context, $options, $scripturl, $txt, $modSettings;

	if (!$context['no_topic_listing'])
	{
		// If this person can approve items, and we have some awaiting approval tell them.
		if (!empty($context['unapproved_posts_message']))
		{
			echo '
		<div class="warningbox">', $context['unapproved_posts_message'], '</div>';
		}

		// Quick Topic enabled ?
		template_quicktopic_above();

		// If Quick Moderation is enabled start the form.
		if (!empty($context['can_quick_mod']) && !empty($options['display_quick_mod']) && !empty($context['topics']))
		{
			echo '
	<form action="', $scripturl, '?action=quickmod;board=', $context['current_board'], '.', $context['start'], '" method="post" accept-charset="UTF-8" class="clear" name="quickModForm" id="quickModForm">';
		}

		echo '
		<main>
		<ul class="topic_listing" id="messageindex">';

		// No topics.... just say, "sorry bub".
		if (empty($context['topics']))
		{
			echo '
			<li class="basic_row">
				<div class="topic_info">
					<div class="topic_name">
						<h4>
							<strong>', $txt['topic_alert_none'], '</strong>
						</h4>
					</div>
				</div>
			</li>';
		}

		foreach ($context['topics'] as $topic)
		{
			// Is this topic pending approval, or does it have any posts pending approval?
			if ($context['can_approve_posts'] && $topic['unapproved_posts'])
			{
				$color_class = $topic['approved'] ? 'approve_row' : 'approvetopic_row';
			}
			// We start with locked and sticky topics.
			elseif ($topic['is_sticky'] && $topic['is_locked'])
			{
				$color_class = 'locked_row sticky_row';
			}
			// Sticky topics should get a different color, too.
			elseif ($topic['is_sticky'])
			{
				$color_class = 'sticky_row';
			}
			// Locked topics get special treatment as well.
			elseif ($topic['is_locked'])
			{
				$color_class = 'locked_row';
			}
			// Last, but not least: regular topics.
			else
			{
				$color_class = 'basic_row';
			}

			// First up the message icon
			echo '
			<li class="', $color_class, '">
				<div class="topic_icons', empty($modSettings['messageIcons_enable']) ? ' topicicon i-' . $topic['first_post']['icon'] : '', '">';

			if (!empty($modSettings['messageIcons_enable']))
			{
				echo '
					<img src="', $topic['first_post']['icon_url'], '" alt="" />';
			}

			echo '
					', $topic['is_posted_in'] ? '<span class="fred topicicon i-profile"></span>' : '', '
				</div>';

			// The subject/poster section
			echo '
				<div class="topic_info">

					<div class="topic_name" ', (empty($topic['quick_mod']['modify']) ? '' : 'id="topic_' . $topic['first_post']['id'] . '"  ondblclick="oQuickModifyTopic.modify_topic(\'' . $topic['id'] . "', '" . $topic['first_post']['id'] . '\');"'), '>
						<h4>';

			// Is this topic new? (assuming they are logged in!)
			if ($topic['new'] && $context['user']['is_logged'])
			{
				echo '
							<a class="new_posts" href="', $topic['new_href'], '" id="newicon' . $topic['first_post']['id'] . '">' . $txt['new'] . '</a>';
			}

			// Is this an unapproved topic, and they can approve it?
			if ($context['can_approve_posts'] && !$topic['approved'])
			{
				echo '
							<span class="require_approval">' . $txt['awaiting_approval'] . '</span>';
			}

			echo '
							', $topic['is_sticky'] ? '<strong>' : '', '<span class="preview" title="', $topic['default_preview'], '"><span id="msg_' . $topic['first_post']['id'] . '">', $topic['first_post']['link'], '</span></span>', $topic['is_sticky'] ? '</strong>' : '', '
						</h4>
					</div>
					<div class="topic_starter">
						', sprintf($txt['topic_started_by'], $topic['first_post']['member']['link']), empty($topic['pages']) ? '' : '
						<ul class="small_pagelinks" id="pages' . $topic['first_post']['id'] . '">' . $topic['pages'] . '</ul>', '
					</div>
				</div>';

			// The last poster info, avatar, when
			echo '
				<div class="topic_latest">';

			if (!empty($topic['last_post']['member']['avatar']))
			{
				echo '
					<span class="board_avatar">
						<a href="', $topic['last_post']['member']['href'], '">
							<img class="avatar" src="', $topic['last_post']['member']['avatar']['href'], '" alt="', $topic['last_post']['member']['name'], '" loading="lazy" />
						</a>
					</span>';
			}
			else
			{
				echo '
					<span class="board_avatar">
						<a href="#"></a>
					</span>';
			}

			echo '
					<a class="topicicon i-last_post" href="', $topic['last_post']['href'], '" title="', $txt['last_post'], '"></a>
					', $topic['last_post']['html_time'], '<br />
					', $txt['by'], ' ', $topic['last_post']['member']['link'], '
				</div>';

			// The stats section
			echo '
				<div class="topic_stats">
					', $topic['replies'], ' ', $txt['replies'], '<br />
					', $topic['views'], ' ', $txt['views'];

			// Show likes?
			if (!empty($modSettings['likes_enabled']))
			{
				echo '
					<br />
						', $topic['likes'], ' ', $txt['likes'];
			}

			echo '
				</div>';

			// Show the quick moderation options?
			if (!empty($context['can_quick_mod']) && !empty($options['display_quick_mod']))
			{
				echo '
				<div class="topic_moderation">
					<input type="checkbox" name="topics[]" aria-label="check ', $topic['id'], '" value="', $topic['id'], '" />
				</div>';
			}

			echo '
			</li>';
		}

		echo '
		</ul>
		</main>';

		// Show the moderation buttons
		if (!empty($context['can_quick_mod']) && !empty($options['display_quick_mod']) && !empty($context['topics']))
		{
			echo '
			<div id="moderationbuttons">';

			template_button_strip($context['mod_buttons'], '', ['id' => 'moderationbuttons_strip']);

			// Show a list of boards they can move the topic to.
			if ($context['can_move'])
			{
				echo '
				<span id="quick_mod_jump_to">&nbsp;</span>';
			}

			echo '
			</div>
			<input type="hidden" name="qaction" id="qaction" value="na" />
			<input type="hidden" name="' . $context['session_var'] . '" value="' . $context['session_id'] . '" />
		</form>';
		}
	}
}

/**
 * The lower icons and jump to.
 */
function template_topic_listing_below()
{
	global $context, $txt, $options;

	if ($context['no_topic_listing'])
	{
		return;
	}

	template_pagesection('normal_buttons');

	// Show breadcrumbs at the bottom too.
	theme_breadcrumbs();

	echo '
	<footer id="topic_icons" class="description">
		<div class="qaction_row" id="message_index_jump_to">&nbsp;</div>';

	if (!$context['no_topic_listing'])
	{
		template_basicicons_legend();
	}

	if (!empty($context['can_quick_mod']) && !empty($options['display_quick_mod']) && !empty($context['topics']))
	{
		theme()->addInlineJavascript('
			let oInTopicListModeration = new InTopicListModeration({
				aQmActions: ["restore", "markread", "merge", "sticky", "approve", "lock", "remove", "move"],
				sButtonStrip: "moderationbuttons",
				sButtonStripDisplay: "moderationbuttons_strip",
				bUseImageButton: false,
				bHideStrip: true,
				sFormId: "quickModForm",
				
				bCanRemove: ' . (empty($context['allow_qm']['can_remove']) ? 'false' : 'true') . ',
				aActionRemove: [' . implode(',', $context['allow_qm']['can_remove']) . '],
				sRemoveButtonLabel: "' . $txt['remove_topic'] . '",
				sRemoveButtonImage: "i-delete",
				sRemoveButtonConfirm: "' . $txt['quickmod_confirm'] . '",
				
				bCanMove: ' . (empty($context['allow_qm']['can_move']) ? 'false' : 'true') . ',
				aActionMove: [' . implode(',', $context['allow_qm']['can_move']) . '],
				sMoveButtonLabel: "' . $txt['move_topic'] . '",
				sMoveButtonImage: "i-move",
				sMoveButtonConfirm: "' . $txt['quickmod_confirm'] . '",

				bCanLock: ' . ($context['allow_qm']['can_lock'] ? 'true' : 'false') . ',
				aActionLock: [' . implode(',', $context['allow_qm']['can_lock']) . '],
				sLockButtonLabel: "' . $txt['set_lock'] . '",
				sLockButtonImage: "i-lock",
				
				bCanApprove: ' . (empty($context['allow_qm']['can_approve']) ? 'false' : 'true') . ',
				aActionApprove: [' . implode(',', $context['allow_qm']['can_approve']) . '],
				sApproveButtonLabel: "' . $txt['approve'] . '",
				sApproveButtonImage: "i-check",
				sApproveButtonConfirm: "' . $txt['quickmod_confirm'] . '",				
				
				bCanSticky: ' . ($context['can_sticky'] ? 'true' : 'false') . ',
				sStickyButtonLabel: "' . $txt['set_sticky'] . '",
				sStickyButtonImage: "i-pin",
				
				bCanMerge: ' . ($context['can_merge'] ? 'true' : 'false') . ',
				sMergeButtonLabel: "' . $txt['merge'] . '",
				sMergeButtonImage: "i-merge",
				
				bCanMarkread: ' . ($context['can_markread'] ? 'true' : 'false') . ',
				sMarkreadButtonLabel: "' . $txt['mark_read_short'] . '",
				sMarkreadButtonImage: "i-view",
				sMarkreadButtonConfirm: "' . $txt['mark_these_as_read_confirm'] . '",				

				bCanRestore: ' . ($context['can_restore'] ? 'true' : 'false') . ',
				sRestoreButtonLabel: "' . $txt['restore_topic'] . '",
				sRestoreButtonImage: "i-recycle",
				sRestoreButtonConfirm: "' . $txt['quickmod_confirm'] . '",
			});', true);
	}

	echo '
			<script>';

	if (!empty($context['using_relative_time']))
	{
		echo '
			document.querySelectorAll(".topic_latest").forEach(element => element.classList.add("relative"));';
	}

	if (!empty($context['can_quick_mod']) && !empty($options['display_quick_mod']) && !empty($context['topics']) && $context['can_move'])
	{
		echo '
			aJumpTo[aJumpTo.length] = new JumpTo({
				sContainerId: "quick_mod_jump_to",
				sJumpToTemplate: "%dropdown_list%",
				iCurBoardId: ', $context['current_board'], ',
				iCurBoardChildLevel: ', $context['jump_to']['child_level'], ',
				sCurBoardName: "', $context['jump_to']['board_name'], '",
				sBoardChildLevelIndicator: "&#8195;",
				sBoardPrefix: "&#10148;",
				sCatPrefix: "",
				sCatClass: "jump_to_header",
				sClassName: "qaction",
				bNoRedirect: true,
				sCustomName: "move_to",
				bOnLoad: true
			});';
	}

	echo '
			aJumpTo[aJumpTo.length] = new JumpTo({
				sContainerId: "message_index_jump_to",
				sJumpToTemplate: "<label class=\"smalltext\" for=\"%select_id%\">', $context['jump_to']['label'], ':<" + "/label> %dropdown_list%",
				iCurBoardId: ', $context['current_board'], ',
				iCurBoardChildLevel: ', $context['jump_to']['child_level'], ',
				sCurBoardName: "', $context['jump_to']['board_name'], '",
				sBoardChildLevelIndicator: "&#8195;",
				sBoardPrefix: "&#10148;",
				sCatPrefix: "",
				sCatClass: "jump_to_header",
				bOnLoad: true,
				sGoButtonLabel: "', $txt['quick_mod_go'], '"
			});
		</script>
	</footer>';

	// Javascript for inline editing, double-clicking to edit subject
	theme()->addInlineJavascript('
		let oQuickModifyTopic = new QuickModifyTopic({
			aHidePrefixes: Array("pages", "newicon"),
			bMouseOnDiv: false
		});', true
	);

	// Message preview when enabled
	if (!empty($context['message_index_preview']))
	{
		theme()->addInlineJavascript('
		if ((!is_mobile && !is_touch) || use_click_menu) {
			isFunctionLoaded("SiteTooltip").then((available) => {
				if (available) {
					let tooltip = new SiteTooltip();
					tooltip.create(".preview");
				}
			});
		};', true
		);
	}
}

/**
 * This is quick topic area above the topic listing, shown when the subject input gains focus
 */
function template_quicktopic_above()
{
	global $context, $options, $txt, $modSettings, $settings;

	// Using  quick topic, and you can start a new topic?
	if ($context['can_post_new'] && !empty($options['display_quick_reply']) && !$context['user']['is_guest'])
	{
		echo '
		<form  id="postmodify" action="', getUrl('action', ['action' => 'post2', 'board' => $context['current_board']]), '" method="post" accept-charset="UTF-8" name="postmodify" onsubmit="submitonce(this);', (empty($modSettings['mentions_enabled']) ? '' : "revalidateMentions('postmodify', '" . $context['post_box_name'] . "');"), '">
		<ul id="quicktopic" class="topic_listing" >
			<li class="basic_row">
				<div class="topic_icons', empty($modSettings['messageIcons_enable']) ? ' topicicon i-xx' : '', '">';

		if (!empty($modSettings['messageIcons_enable']))
		{
			$icon_sources = new MessageTopicIcons(!empty($modSettings['messageIconChecks_enable']), $settings['theme_dir']);
			echo '
					<img src="', $icon_sources->getIconURL(-1), '" alt="" />';
		}

		echo '
				</div>
				<div id="quicktopic_title">', $txt['start_new_topic'], '</div>
				<input id="quicktopic_subject" type="text" name="subject" tabindex="', $context['tabindex']++, '" size="70" maxlength="80" class="input_text"', ' placeholder="', $txt['subject'], '" required="required" />
				<div id="quicktopicbox" class="hide">
					<div class="post_wrapper', empty($options['hide_poster_area']) ? '' : '2', '">';

		if (empty($options['hide_poster_area']))
		{
			echo '
						<ul class="poster no_js">', template_build_poster_div($context['thisMember'], false), '</ul>';
		}

		echo '
						<div class="postarea', empty($options['hide_poster_area']) ? '' : '2', '">';

		// Is visual verification enabled?
		if (!empty($context['require_verification']))
		{
			template_verification_controls($context['visual_verification_id'], '<strong>' . $txt['verification'] . ':</strong>', '<br />');
		}

		template_control_richedit($context['post_box_name']);

		echo '
							', $context['becomes_approved'] ? '' : '<p class="infobox">' . $txt['wait_for_approval'] . '</p>';

		echo '
							<input type="hidden" name="topic" value="0" />
							<input type="hidden" name="icon" value="xx" />
							<input type="hidden" name="from_qr" value="1" />
							<input type="hidden" name="board" value="', $context['current_board'], '" />
							<input type="hidden" name="goback" value="', empty($options['return_to_post']) ? '0' : '1', '" />
							<input type="hidden" name="', $context['session_var'], '" value="', $context['session_id'], '" />
							<input type="hidden" name="seqnum" value="', $context['form_sequence_number'], '" />
							<div id="post_confirm_buttons" class="submitbutton">',
								template_control_richedit_buttons($context['post_box_name']), '
							</div>';

		// Show the draft last saved on area
		if (!empty($context['drafts_save']))
		{
			echo '
						<div class="draftautosave">
							<span id="throbber" class="hide"><i class="icon i-oval"></i>&nbsp;</span>
							<span id="draft_lastautosave"></span>
						</div>';
		}

		echo '
					</div>
				</div>
			</li>
		</ul>
		</form>';

		quickTopicToggle();
	}
}

/**
 * Adds needed JS to show the quick topic area
 */
function quickTopicToggle()
{
	theme()->addInlineJavascript('
		document.getElementById("quicktopic_subject").onfocus = function() {
			let quicktopicbox = document.getElementById("quicktopicbox");
			let isVisible = quicktopicbox && (quicktopicbox.style.display !== "none" && quicktopicbox.offsetHeight !== 0);
			
			if (!isVisible)
			{
				quicktopicbox.slideDown();
			}
		};', true);
}
