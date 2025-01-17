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
 * Interface for the bit up the print page.
 */
function template_print_above()
{
	global $context, $txt;

	echo '<!DOCTYPE html>
<html ', $context['right_to_left'] ? 'dir="rtl"' : '', '>
	<head>
		<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
		<meta name="robots" content="noindex" />
		<link rel="canonical" href="', $context['canonical_url'], '" />
		<title>', $txt['print_page'], ' - ', $context['topic_subject'], '</title>
		<style>
			body, a {
				color: #000;
				background: #fff;
			}
			body, td, .normaltext {
				font-family: Verdana, arial, helvetica, serif;
				font-size: small;
			}
			h1#title {
				font-size: large;
				font-weight: bold;
			}
			h2#breadcrumb {
				margin: 1em 0 2.5em 0;
				font-size: small;
				font-weight: bold;
			}
			dl#posts {
				width: 90%;
				margin: 0;
				padding: 0;
				list-style: none;
			}
			div.postheader, #poll_data {
				border: solid #000;
				border-width: 1px 0;
				padding: 4px 0;
			}
			div.postbody {
				margin: 1em 0 2em 2em;
			}
			table {
				empty-cells: show;
			}
			blockquote, code {
				border: 1px solid #000;
				margin: 3px;
				padding: 1px;
				display: block;
			}
			code {
				font: x-small monospace;
			}
			blockquote {
				font-size: x-small;
			}
			.smalltext, .quoteheader, .codeheader {
				font-size: x-small;
			}
			.largetext {
				font-size: large;
			}
			.centertext {
				text-align: center;
			}
			.emoji {
				max-width: 18px;
				padding: 0 .13em;
				vertical-align: text-bottom;
			}
			hr {
				height: 1px;
				border: 0;
				color: black;
				background: black;
			}
			.voted {
				font-weight: bold;
			}
			@media print {
				.print_options {
					display:none;
				}
			}
			@media screen {
				.print_options {
					margin:1em;
				}
			}
		</style>
	</head>
	<body>
		<div class="print_options">';

	// Which option is set, text or text&images
	if (!empty($context['viewing_attach']))
	{
		echo '
			<a href="', $context['view_attach_mode']['text'], '">', $txt['print_page_text'], '</a> | <strong><a href="', $context['view_attach_mode']['images'], '">', $txt['print_page_images'], '</a></strong>';
	}
	else
	{
		echo '
			<strong><a href="', $context['view_attach_mode']['text'], '">', $txt['print_page_text'], '</a></strong> | <a href="', $context['view_attach_mode']['images'], '">', $txt['print_page_images'], '</a>';
	}

	echo '
		</div>
		<h1 id="title">', $context['forum_name_html_safe'], '</h1>
		<h2 id="breadcrumb">', $context['category_name'], ' => ', (empty($context['parent_boards']) ? '' : implode(' => ', $context['parent_boards']) . ' => '), $context['board_name'], ' => ', $txt['topic_started'], ': ', $context['poster_name'], ' ', $txt['search_on'], ' ', $context['post_time'], '</h2>
		<div id="posts">';
}

/**
 * The topic may have a poll
 */
function template_print_poll_above()
{
	global $context, $txt;

	if (!empty($context['poll']))
	{
		echo '
			<div id="poll_data">', $txt['poll'], '
				<div class="question">', $txt['poll_question'], ': <strong>', $context['poll']['question'], '</strong>';

		$print_options = 1;
		foreach ($context['poll']['options'] as $option)
		{
			echo '
					<div class="', $option['voted_this'] ? 'voted' : '', '">', $txt['option'], ' ', $print_options++, ': <strong>', $option['option'], '</strong>
						', $context['allow_poll_view'] ? $txt['votes'] . ': ' . $option['votes'] : '', '
					</div>';
		}

		echo '
			</div>';
	}
}

/**
 * Interface for print page central view.
 */
function template_print_page()
{
	global $context, $txt, $scripturl, $topic;

	foreach ($context['posts'] as $post)
	{
		echo '
			<div class="postheader">
				', $txt['title'], ': <strong>', $post['subject'], '</strong><br />
				', $txt['post_by'], ': <strong>', $post['member'], '</strong> ', $txt['search_on'], ' <strong>', $post['time'], '</strong>
			</div>
			<div class="postbody">
				', $post['body'];

		// Show attachment images
		if (!empty($context['printattach'][$post['id_msg']]))
		{
			echo '
				<hr />';

			foreach ($context['printattach'][$post['id_msg']] as $attach)
			{
				if (!empty($context['ila_dont_show_attach_below'])
					&& in_array((int) $attach['id_attach'], $context['ila_dont_show_attach_below'], true))
				{
					continue;
				}

				echo '
					<img style="width:' . $attach['width'] . 'px; height:' . $attach['height'] . 'px;" src="', $scripturl . '?action=dlattach;topic=' . $topic . '.0;attach=' . $attach['id_attach'] . '" alt="" />';
			}
		}

		echo '
			</div>';
	}
}

/**
 * Interface for the bit down the print page.
 */
function template_print_below()
{
	global $txt, $context;

	echo '
		</div>
		<div class="print_options">';

	// Show the text / image links
	if (!empty($context['viewing_attach']))
	{
		echo '
			<a href="', $context['view_attach_mode']['text'], '">', $txt['print_page_text'], '</a> | <strong><a href="', $context['view_attach_mode']['images'], '">', $txt['print_page_images'], '</a></strong>';
	}
	else
	{
		echo '
			<strong><a href="', $context['view_attach_mode']['text'], '">', $txt['print_page_text'], '</a></strong> | <a href="', $context['view_attach_mode']['images'], '">', $txt['print_page_images'], '</a>';
	}

	echo '
		</div>
		<div id="footer" class="smalltext">
			', theme_copyright(), '
		</div>
	</body>
</html>';
}
