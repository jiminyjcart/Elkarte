<?php

/**
 * Utility class for search functionality.
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

namespace ElkArte\Search;

use ElkArte\AbstractModel;
use ElkArte\Helper\Util;
use ElkArte\Helper\ValuesContainer;

/**
 * Actually do the searches
 */
class SearchArray extends AbstractModel
{
	/** @var array Words not to be found in the search results (-word) */
	private $_excludedWords = [];

	/** @var bool If we are performing a boolean or simple search */
	private $_no_regexp = false;

	/** @var array Holds the words and phrases to be searched on */
	private $_searchArray = [];

	/** @var bool If search words were found on the blocklist */
	private $_foundBlockListedWords = false;

	/** @var array Holds words that will not be search on to inform the user they were skipped */
	private $_ignored = [];

	/**
	 * Usual constructor that does what any constructor does.
	 *
	 * @param string $_search_string
	 * @param string[] $_blocklist_words
	 * @param bool $_search_simple_fulltext
	 */
	public function __construct(protected $_search_string, private $_blocklist_words, private $_search_simple_fulltext = false)
	{
		parent::__construct();

		// Build the search query appropriate for the API in use
		$search_config = new ValuesContainer([
			'search_index' => $this->_modSettings['search_index'] ?: '',
		]);
		$searchAPI = new SearchApiWrapper($search_config);
		$searchAPI->supportsExtended() ? $this->searchArrayExtended() : $this->searchArray();
		unset($searchAPI);
	}

	/**
	 * Builds the search array
	 *
	 * @return array
	 */
	protected function searchArray()
	{
		// Change non-word characters into spaces.
		$stripped_query = $this->cleanString($this->_search_string);

		// This option will do fulltext searching in the most basic way.
		if ($this->_search_simple_fulltext)
		{
			$stripped_query = strtr($stripped_query, ['"' => '']);
		}

		$this->_no_regexp = preg_match('~&#(?:\d{1,7}|x[0-9a-fA-F]{1,6});~', $stripped_query) === 1;

		// Extract phrase parts first (e.g. some words "this is a phrase" some more words.)
		preg_match_all('/(?:^|\s)([-]?)"([^"]+)"(?:$|\s)/', $stripped_query, $matches, PREG_PATTERN_ORDER);
		$phraseArray = $matches[2];

		// Remove the phrase parts and extract the words.
		$wordArray = preg_replace('~(?:^|\s)(?:[-]?)"(?:[^"]+)"(?:$|\s)~u', ' ', $this->_search_string);
		$wordArray = explode(' ', Util::htmlspecialchars(un_htmlspecialchars($wordArray), ENT_QUOTES));

		// A minus sign in front of a word excludes the word.... so...
		// .. first, we check for things like -"some words", but not "-some words".
		$phraseArray = $this->_checkExcludePhrase($matches[1], $phraseArray);

		// Now we look for -test, etc.... normaller.
		$wordArray = $this->_checkExcludeWord($wordArray);

		// The remaining words and phrases are all included.
		$this->_searchArray = array_merge($phraseArray, $wordArray);

		// Trim everything and make sure there are no words that are the same.
		foreach ($this->_searchArray as $index => $value)
		{
			// Skip anything practically empty.
			if (($this->_searchArray[$index] = trim($value, "-_' ")) === '')
			{
				unset($this->_searchArray[$index]);
			}
			// Skip blocklisted words. Make sure to note we skipped them in case we end up with nothing.
			elseif (in_array($this->_searchArray[$index], $this->_blocklist_words))
			{
				$this->_foundBlockListedWords = true;
				unset($this->_searchArray[$index]);
			}
			// Don't allow very, very short words.
			elseif (Util::strlen($value) < 2)
			{
				$this->_ignored[] = $value;
				unset($this->_searchArray[$index]);
			}
		}

		$this->_searchArray = array_slice(array_unique($this->_searchArray), 0, 10);

		return $this->_searchArray;
	}

	/**
	 * Looks for phrases that should be excluded from results
	 *
	 * - Check for things like -"some words", but not "-some words"
	 * - Prevents redundancy with blocklist words
	 *
	 * @param string[] $matches
	 * @param string[] $phraseArray
	 *
	 * @return string[]
	 */
	private function _checkExcludePhrase($matches, $phraseArray)
	{
		foreach ($matches as $index => $word)
		{
			if ($word === '-')
			{
				if (($word = trim($phraseArray[$index], "-_' ")) !== '' && !in_array($word, $this->_blocklist_words))
				{
					$this->_excludedWords[] = $word;
				}

				unset($phraseArray[$index]);
			}
		}

		return $phraseArray;
	}

	/**
	 * Looks for words that should be excluded in the results (-word)
	 *
	 * - Look for -test, etc.
	 * - Prevents excluding blocklist words since it is redundant
	 *
	 * @param string[] $wordArray
	 *
	 * @return string[]
	 */
	private function _checkExcludeWord($wordArray)
	{
		foreach ($wordArray as $index => $word)
		{
			if (strpos(trim($word), '-') === 0)
			{
				if (($word = trim($word, "-_' ")) !== '' && !in_array($word, $this->_blocklist_words))
				{
					$this->_excludedWords[] = $word;
				}

				unset($wordArray[$index]);
			}
		}

		return $wordArray;
	}

	/**
	 * Constructs a binary mode query to pass back to a search API
	 *
	 * Understands the use of OR | AND & as search modifiers
	 * Currently used by the sphinx API
	 *
	 * @return string
	 */
	public function searchArrayExtended()
	{
		$keywords = ['include' => [], 'exclude' => []];

		// Split our search string and return an empty string if no matches
		if (!preg_match_all('~(-?)("[^"]+"|[^" ]+)~', $this->_search_string, $tokens, PREG_SET_ORDER))
		{
			return $this->_searchArray[] = '';
		}

		// First we split our string into included and excluded words and phrases
		$or_part = false;
		foreach ($tokens as $token)
		{
			$phrase = false;

			// Strip the quotes off of a phrase
			if ($token[2][0] === '"')
			{
				$token[2] = substr($token[2], 1, -1);
				$phrase = true;
			}

			// Prepare this token
			$cleanWords = $this->cleanString($token[2]);

			// Explode the cleanWords again in case the cleaning puts more spaces into it
			$addWords = $phrase ? ['"' . $cleanWords . '"'] : preg_split('~\s+~u', $cleanWords, -1, PREG_SPLIT_NO_EMPTY);

			// Excluding this word?
			if ($token[1] === '-')
			{
				$keywords['exclude'] = array_merge($keywords['exclude'], $addWords);
			}
			// OR'd keywords (we only do this if we have something to OR with)
			elseif (($token[2] === 'OR' || $token[2] === '|') && count($keywords['include']))
			{
				$last = array_pop($keywords['include']);
				$keywords['include'][] = is_array($last) ? $last : [$last];
				$or_part = true;
				continue;
			}
			// AND is implied in a Sphinx Search
			elseif ($token[2] === 'AND' || $token[2] === '&' || trim($cleanWords) === '')
			{
				continue;
			}
			elseif ($or_part)
			{
				// If this was part of an OR branch, add it to the proper section
				$keywords['include'][count($keywords['include']) - 1] = array_merge($keywords['include'][count($keywords['include']) - 1], $addWords);
			}
			else
			{
				$keywords['include'] = array_merge($keywords['include'], $addWords);
			}

			// Start fresh on this...
			$or_part = false;
		}

		// Let's make sure they're not canceling each other out
		$results = array_diff(array_map('serialize', $keywords['include']), array_map('serialize', $keywords['exclude']));
		if (array_map('unserialize', $results) === [])
		{
			return $this->_searchArray[] = '';
		}

		// Now we compile our arrays into a valid search string
		$query_parts = [];
		foreach ($keywords['include'] as $keyword)
		{
			$query_parts[] = is_array($keyword) ? '(' . implode(' | ', $keyword) . ')' : $keyword;
		}

		foreach ($keywords['exclude'] as $keyword)
		{
			$query_parts[] = '-' . $keyword;
		}

		return $this->_searchArray[] = implode(' ', $query_parts);
	}

	/**
	 * Cleans a string of everything but alphanumeric characters and certain
	 * special characters ",-,_  so -movie or "animal farm" are preserved
	 *
	 * @param string $string A string to clean
	 * @return string A cleaned up string
	 */
	public function cleanString($string)
	{
		// Decode the entities first
		$string = html_entity_decode($string, ENT_QUOTES, 'UTF-8');

		// Lowercase string
		$string = Util::strtolower($string);

		// Fix numbers so they search easier (decimals, SSN, dates) 123-45-6789 => 123_45_6789
		$string = preg_replace('~([\d]+)[-./]+(?=[\d])~u', '$1_', $string);

		// Last but not least, strip everything out that's not alphanumeric
		return preg_replace('~[^\pL\pN_"-]+~u', ' ', $string);
	}

	/**
	 * Retrieves the search array
	 *
	 * @return array The search array
	 */
	public function getSearchArray()
	{
		return $this->_searchArray;
	}

	/**
	 * Retrieves the array of excluded words
	 *
	 * @return array The array of excluded words
	 */
	public function getExcludedWords()
	{
		return $this->_excludedWords;
	}

	/**
	 * Get the value of the _no_regexp property
	 *
	 * @return bool
	 */
	public function getNoRegexp()
	{
		return $this->_no_regexp;
	}

	/**
	 * Returns if block listed words are found
	 *
	 * @return bool
	 */
	public function foundBlockListedWords()
	{
		return $this->_foundBlockListedWords;
	}

	/**
	 * Returns the array of ignored items
	 *
	 * @return array The array of ignored items
	 */
	public function getIgnored()
	{
		return $this->_ignored;
	}
}
