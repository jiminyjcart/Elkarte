<?php

/**
 * Standard non-full index, non-custom index search
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

use ElkArte\Errors\Errors;
use ElkArte\Exceptions\Exception;

class WeightFactors
{
	/** @var bool */
	protected $_is_admin = false;

	/** @var array */
	protected $_weight = [];

	/** @var int */
	protected $_weight_total = 0;

	/** @var array */
	protected $_weight_factors = [];

	/**
	 * @param $_input_weights
	 * @param $is_admin
	 */
	public function __construct(protected $_input_weights, $is_admin = false)
	{
		$this->_is_admin = (bool) $is_admin;

		$this->_setup_weight_factors();
	}

	/**
	 * Sets up the weight factors for search functionality.
	 *
	 * This method initializes the weight factors used in the search feature,
	 * such as frequency, age, length, subject, etc. These weight factors are
	 * used to determine the relevance of search results based on different
	 * criteria.
	 *
	 * @return void
	 * @throws Exception
	 */
	private function _setup_weight_factors()
	{
		$default_factors = [
			'frequency' => [
				'search' => 'COUNT(*) / (CASE WHEN MAX(t.num_replies) < {int:short_topic_posts} THEN {int:short_topic_posts} ELSE MAX(t.num_replies) + 1 END)',
				'results' => '(t.num_replies + 1)',
			],
			'age' => [
				'search' => 'CASE WHEN MAX(m.id_msg) < {int:min_msg} THEN 0 ELSE (MAX(m.id_msg) - {int:min_msg}) / {int:recent_message} END',
				'results' => 'CASE WHEN t.id_first_msg < {int:min_msg} THEN 0 ELSE (t.id_first_msg - {int:min_msg}) / {int:recent_message} END',
			],
			'length' => [
				'search' => 'CASE WHEN MAX(t.num_replies) < {int:huge_topic_posts} THEN MAX(t.num_replies) / {int:huge_topic_posts} ELSE 1 END',
				'results' => 'CASE WHEN t.num_replies < {int:huge_topic_posts} THEN t.num_replies / {int:huge_topic_posts} ELSE 1 END',
			],
			'subject' => [
				'search' => 0,
				'results' => 0,
			],
			'first_message' => [
				'search' => 'CASE WHEN MIN(m.id_msg) = MAX(t.id_first_msg) THEN 1 ELSE 0 END',
			],
			'sticky' => [
				'search' => 'MAX(t.is_sticky)',
				'results' => 't.is_sticky',
			],
			'likes' => [
				'search' => 'CASE WHEN t.num_likes > 20 THEN 1 ELSE t.num_likes / 20 END',
				'results' => 't.num_likes',
			],
		];
		$this->_weight_factors = [
			'frequency' => [
				'search' => 'COUNT(*) / (CASE WHEN MAX(t.num_replies) < {int:short_topic_posts} THEN {int:short_topic_posts} ELSE MAX(t.num_replies) + 1 END)',
				'results' => '(t.num_replies + 1)',
			],
			'age' => [
				'search' => 'CASE WHEN MAX(m.id_msg) < {int:min_msg} THEN 0 ELSE (MAX(m.id_msg) - {int:min_msg}) / {int:recent_message} END',
				'results' => 'CASE WHEN t.id_first_msg < {int:min_msg} THEN 0 ELSE (t.id_first_msg - {int:min_msg}) / {int:recent_message} END',
			],
			'length' => [
				'search' => 'CASE WHEN MAX(t.num_replies) < {int:huge_topic_posts} THEN MAX(t.num_replies) / {int:huge_topic_posts} ELSE 1 END',
				'results' => 'CASE WHEN t.num_replies < {int:huge_topic_posts} THEN t.num_replies / {int:huge_topic_posts} ELSE 1 END',
			],
			'subject' => [
				'search' => 0,
				'results' => 0,
			],
			'first_message' => [
				'search' => 'CASE WHEN MIN(m.id_msg) = MAX(t.id_first_msg) THEN 1 ELSE 0 END',
			],
			'sticky' => [
				'search' => 'MAX(t.is_sticky)',
				'results' => 't.is_sticky',
			],
			'likes' => [
				'search' => 'CASE WHEN t.num_likes > 20 THEN 1 ELSE t.num_likes / 20 END',
				'results' => 't.num_likes',
			],
		];
		// These are fallback weights in case of errors somewhere.
		// Not intended to be passed to the hook
		$default_weights = [
			'search_weight_frequency' => 30,
			'search_weight_age' => 25,
			'search_weight_length' => 20,
			'search_weight_subject' => 15,
			'search_weight_first_message' => 10,
		];

		call_integration_hook('integrate_search_weights', [&$this->_weight_factors]);

		// Set the weight factors for each area (frequency, age, etc) as defined in the ACP
		$this->_calculate_weights($this->_weight_factors, $this->_input_weights);

		// Zero weight.  Weightless :P.
		if (empty($this->_weight_total))
		{
			// Admins can be bothered with a failure
			if ($this->_is_admin)
			{
				throw new Exception('search_invalid_weights');
			}

			// Even if users will get an answer, the admin should know something is broken
			Errors::instance()->log_lang_error('search_invalid_weights');

			// Instead is better to give normal users and guests some kind of result
			// using our defaults.
			// Using a different variable here because it may be the hook is screwing
			// things up
			$this->_calculate_weights($default_factors, $default_weights);
		}
	}

	/**
	 * Fill the $_weight variable and calculate the total weight
	 *
	 * @param array $factors
	 * @param int[] $weights
	 */
	private function _calculate_weights($factors, $weights)
	{
		foreach (array_keys($factors) as $weight_factor)
		{
			$this->_weight[$weight_factor] = (int) ($weights['search_weight_' . $weight_factor] ?? 0);
			$this->_weight_total += $this->_weight[$weight_factor];
		}
	}

	/**
	 * Retrieves the weight factors.
	 *
	 * @return array The weight factors.
	 */
	public function getFactors()
	{
		return $this->_weight_factors;
	}

	/**
	 * Retrieves the weight of the object.
	 *
	 * @return array The weight of the object.
	 */
	public function getWeight()
	{
		return $this->_weight;
	}

	/**
	 * Calculates the total weight.
	 *
	 * @return float The total weight.
	 */
	public function getTotal()
	{
		return $this->_weight_total;
	}
}
