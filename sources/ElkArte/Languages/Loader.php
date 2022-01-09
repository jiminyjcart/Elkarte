<?php

/**
 * This class takes care of loading language files
 *
 * @package   ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause (see accompanying LICENSE.txt file)
 *
 * @version 2.0 dev
 *
 */

namespace ElkArte\Languages;

use ElkArte\Debug;

/**
 * This class takes care of loading language files
 */
class Loader
{
	/** @var string */
	protected $path = '';

	/** @var string */
	protected $language = 'English';

	/** @var bool */
	protected $load_fallback;

	/** @var string[] Holds the name of the files already loaded to load them only once */
	protected $loaded = [];

	public function __construct($lang = null, $path = null, bool $load_fallback = true)
	{
		if ($lang !== null)
		{
			$this->language = ucfirst($lang);
		}
		if ($path === null)
		{
			$this->path = SOURCEDIR . '/ElkArte/Languages/';
		}
		else
		{
			$this->path = $path;
		}
		$this->load_fallback = $load_fallback;
	}

	public function load($file_name, $fatal = true, $fix_calendar_arrays = false)
	{
		global $db_show_debug;

		$file_names = explode('+', $file_name);

		// For each file open it up and write it out!
		foreach ($file_names as $file)
		{
			$file = ucfirst($file);
			if (isset($this->loaded[$file]))
			{
				continue;
			}

			$found = false;
			$found_fallback = false;
			if ($this->load_fallback)
			{
				$found_fallback = $this->loadFile($file, 'English');
			}
			$found = $this->loadFile($file, $this->language);

			$this->loaded[$file] = true;

			// Keep track of what we're up to, soldier.
			if ($found && $db_show_debug === true)
			{
				Debug::instance()->add(
					'language_files',
					$file . '.' . $this->language .
					' (' . str_replace(BOARDDIR, '', $this->path) . ')'
				);
			}

			// That couldn't be found!  Log the error, but *try* to continue normally.
			if (!$found && $fatal)
			{
				Errors::instance()->log_error(
					sprintf(
						$txt['theme_language_error'],
						$file . '.' . $this->language,
						'template'
					)
				);
				// If we do have a fallback it may not be necessary to break out.
				if ($found_fallback === false)
				{
					break;
				}
			}
		}

		if ($fix_calendar_arrays)
		{
			$this->fix_calendar_text();
		}
	}

	protected function loadFile($name, $lang)
	{
		/*
		 * I know this looks weird but this is used to include $txt files.
		 * If the parent doesn't declare them global, the scope will be
		 * local to this function. IOW, don't remove this line!
		 */
		global $txt;

		$filepath = $this->path . $name . '/' . $lang . '.php';
		if (file_exists($filepath))
		{
			require($filepath);
			return true;
		}
		return false;
	}

	/**
	 * Loads / Sets arrays for use in date display
	 * This is here and not in a language file for two reasons:
	 *  1. the structure is required by the code, so better be sure
	 *     to have it the way we are supposed to have it
	 *  2. Transifex (that we use for translating the strings) doesn't
	 *     support array of arrays, so if we move this to a language file
	 *     we'd need to move away from Tx.
	 */
	protected function fix_calendar_text()
	{
		global $txt;

		$txt['days'] = array(
			$txt['sunday'],
			$txt['monday'],
			$txt['tuesday'],
			$txt['wednesday'],
			$txt['thursday'],
			$txt['friday'],
			$txt['saturday'],
		);
		$txt['days_short'] = array(
			$txt['sunday_short'],
			$txt['monday_short'],
			$txt['tuesday_short'],
			$txt['wednesday_short'],
			$txt['thursday_short'],
			$txt['friday_short'],
			$txt['saturday_short'],
		);
		$txt['months'] = array(
			1 => $txt['january'],
			$txt['february'],
			$txt['march'],
			$txt['april'],
			$txt['may'],
			$txt['june'],
			$txt['july'],
			$txt['august'],
			$txt['september'],
			$txt['october'],
			$txt['november'],
			$txt['december'],
		);
		$txt['months_titles'] = array(
			1 => $txt['january_titles'],
			$txt['february_titles'],
			$txt['march_titles'],
			$txt['april_titles'],
			$txt['may_titles'],
			$txt['june_titles'],
			$txt['july_titles'],
			$txt['august_titles'],
			$txt['september_titles'],
			$txt['october_titles'],
			$txt['november_titles'],
			$txt['december_titles'],
		);
		$txt['months_short'] = array(
			1 => $txt['january_short'],
			$txt['february_short'],
			$txt['march_short'],
			$txt['april_short'],
			$txt['may_short'],
			$txt['june_short'],
			$txt['july_short'],
			$txt['august_short'],
			$txt['september_short'],
			$txt['october_short'],
			$txt['november_short'],
			$txt['december_short'],
		);
	}
}