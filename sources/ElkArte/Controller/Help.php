<?php

/**
 * This file has the job of taking care of help messages and the help center.
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

namespace ElkArte\Controller;

use ElkArte\AbstractController;
use ElkArte\Exceptions\Exception;
use ElkArte\Helper\Util;
use ElkArte\Hooks;
use ElkArte\Languages\Txt;

/**
 * Handles the help page and boxes
 */
class Help extends AbstractController
{
	/**
	 * Pre Dispatch, called before other methods.  Loads integration hooks.
	 */
	public function pre_dispatch()
	{
		Hooks::instance()->loadIntegrationsSettings();
	}

	/**
	 * Default action handler: just help.
	 *
	 * @see AbstractController::action_index
	 */
	public function action_index()
	{
		// I need help!
		$this->action_help();
	}

	/**
	 * Simply redirects to the ElkArte wiki
	 */
	public function action_help()
	{
		redirectexit('https://github.com/elkarte/Elkarte/wiki');
	}

	/**
	 * Show boxes with more detailed help on items, when the user clicks on their help icon.
	 *
	 * What it does
	 * - It handles both administrative or user help.
	 * - Data: $_GET['help'] parameter, it holds what string to display
	 * and where to get the string from. ($helptxt or $txt)
	 * - It is accessed via ?action=quickhelp;help=?.
	 *
	 * @uses ManagePermissions language file, if the help starts with permissionhelp.
	 * @uses Help template, 'popup' sub-template.
	 */
	public function action_quickhelp()
	{
		global $txt, $helptxt, $context;

		if (!isset($this->_req->query->help) || !is_string($this->_req->query->help))
		{
			throw new Exception('no_access', false);
		}

		if (!isset($helptxt))
		{
			$helptxt = array();
		}

		$help_str = Util::htmlspecialchars($this->_req->query->help);

		// Load the admin help language file and template.
		Txt::load('Help');

		// Load permission specific help
		if (substr($help_str, 0, 14) === 'permissionhelp')
		{
			Txt::load('ManagePermissions');
		}

		// Load our template
		theme()->getTemplates()->load('Help');

		// Allow addons to load their own language file here.
		call_integration_hook('integrate_quickhelp');

		// Set the page title to something relevant.
		$context['page_title'] = $context['forum_name'] . ' - ' . $txt['help'];

		// Only show the 'popup' sub-template, no layers.
		theme()->getLayers()->removeAll();
		$context['sub_template'] = 'popup';

		$helps = explode('+', $help_str);
		$context['help_text'] = '';

		// Find what to display: the string will be in $helptxt['help'] or in $txt['help']
		foreach ($helps as $help)
		{
			if (isset($helptxt[$help]))
			{
				$context['help_text'] .= $helptxt[$help];
			}
			elseif (isset($txt[$help]))
			{
				$context['help_text'] .= $txt[$help];
			}
			// nothing :(
			else
			{
				// Using the passed string, but convert <br /> back for formatting
				$help = str_replace('&lt;br /&gt;', '<br />', $help);
				$context['help_text'] .= $help;
			}
		}

		// Link to the forum URL, and include session id.
		if (preg_match('~%(\d+\$)?s\?~', $context['help_text'], $match))
		{
			$context['help_text'] = sprintf($context['help_text'], getUrl('boardindex', []), $context['session_id'], $context['session_var']);
		}
	}
}
