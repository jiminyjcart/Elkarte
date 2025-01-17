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

/**
 * Template to display the who's online table header
 */
function template_whos_selection_above()
{
	global $context, $txt;

	// Display the table header and breadcrumbs.
	echo '
	<div id="whos_online">
		<form action="', getUrl('action', ['action' => 'who']), '" method="post" id="whoFilter" accept-charset="UTF-8">
			<h2 class="category_header">', $txt['who_title'], '</h2>';

	$extra = '
			<div class="selectbox flow_flex_right">
				<label for="show_top">' . $txt['who_show1'] . '</label>
				<select name="show_top" id="show_top" onchange="document.forms.whoFilter.show.value = this.value; document.forms.whoFilter.submit();">';

	foreach ($context['show_methods'] as $value => $label)
	{
		$extra .= '
					<option value="' . $value . '" ' . ($value == $context['show_by'] ? ' selected="selected"' : '') . '>' . $label . '</option>';
	}

	$extra .= '
				</select>
				<noscript>
					<input type="submit" name="submit_top" value="' . $txt['go'] . '" />
				</noscript>
			</div>';

	template_pagesection(false, '', array('extra' => $extra));
}

/**
 * Who's online page.
 */
function template_whos_online()
{
	global $context, $scripturl, $txt;

	echo '
			<div id="mlist">
				<dl class="whos_online', empty($context['members']) ? ' no_members' : '', '">
					<dt class="table_head">
						<div class="online_member">
							<a href="', $scripturl, '?action=who;start=', $context['start'], ';show=', $context['show_by'], ';sort=user', $context['sort_direction'] != 'down' && $context['sort_by'] == 'user' ? '' : ';asc', '" rel="nofollow">', $txt['who_user'], $context['sort_by'] == 'user' ? '<i class="icon i-sort-alpha-' . $context['sort_direction'] . ' icon-small"></i>' : '', '</a>
						</div>
						<div class="online_time">
							<a href="', $scripturl, '?action=who;start=', $context['start'], ';show=', $context['show_by'], ';sort=time', $context['sort_direction'] == 'down' && $context['sort_by'] == 'time' ? ';asc' : '', '" rel="nofollow">', $txt['who_time'], $context['sort_by'] == 'time' ? '<i class="icon i-sort-numeric-' . $context['sort_direction'] . ' icon-small"></i>' : '', '</a>
						</div>
						<div class="online_action">', $txt['who_action'], '</div>
					</dt>';

	// For every member display their name, time and action (and more for admin).
	foreach ($context['members'] as $member)
	{
		echo '
					<dd class="online_row">
						<div class="online_member">
							<span class="member', $member['is_hidden'] ? ' hidden' : '', '">
								', $member['is_guest'] ? $member['name'] : '<a href="' . $member['href'] . '" title="' . $txt['profile_of'] . ' ' . $member['name'] . '"' . (empty($member['color']) ? '' : ' style="color: ' . $member['color'] . '"') . '>' . $member['name'] . '</a>', '
							</span>';

		if (!empty($member['ip']))
		{
			echo '
							<a class="track_ip" href="' . $member['track_href'] . '">(' . $member['ip'] . ')</a>';
		}

		echo '
						</div>
						<div class="online_time nowrap">', $member['time'], '</div>
						<div class="online_action">', $member['action'], '</div>
					</dd>';
	}

	echo '
				</dl>';

	// No members?
	if (empty($context['members']))
	{
		echo '
				<div class="well centertext">
					', $txt['who_no_online_' . ($context['show_by'] == 'guests' || $context['show_by'] == 'spiders' ? $context['show_by'] : 'members')], '
				</div>';
	}

	echo '
			</div>';
}

/**
 * Close up the who's online page
 */
function template_whos_selection_below()
{
	global $context, $txt;

	$extra = '
			<div class="selectbox flow_flex_right">
				<label for="show">' . $txt['who_show1'] . '</label>
				<select name="show" id="show" onchange="document.forms.whoFilter.submit();">';

	foreach ($context['show_methods'] as $value => $label)
	{
		$extra .= '
					<option value="' . $value . '" ' . ($value == $context['show_by'] ? ' selected="selected"' : '') . '>' . $label . '</option>';
	}

	$extra .= '
				</select>
				<noscript>
					<input type="submit" name="submit_top" value="' . $txt['go'] . '" />
				</noscript>
			</div>';

	template_pagesection(false, '', array('extra' => $extra));

	echo '
		</form>
	</div>';
}
