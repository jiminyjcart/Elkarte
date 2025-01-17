<?php

/**
 * This file contains functions that deal with getting and setting cache values.
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

namespace ElkArte\Cache;

use ElkArte\Cache\CacheMethod\AbstractCacheMethod;
use ElkArte\Debug;
use ElkArte\Helper\FileFunctions;
use ElkArte\Helper\Util;

/**
 * Class Cache - Methods that deal with getting and setting cache values.
 */
class Cache
{
	/** @var object Holds our static instance of the class */
	protected static $_instance;

	/** @var array Array of options for the methods (if needed) */
	protected $_options = [];

	/** @var bool If the cache is enabled or not. */
	protected $enabled = false;

	/** @var int The caching level */
	protected $level = 0;

	/** @var string The prefix to append to the cache key */
	protected $_key_prefix;

	/** @var string The accelerator in use */
	protected $_accelerator;

	/** @var string[] Cached keys */
	protected $_cached_keys = [];

	/** @var AbstractCacheMethod|null The caching engine object */
	protected $_cache_obj;

	/**
	 * Initialize the class, defines the options and the caching method to use
	 *
	 * @param int $level The level of caching
	 * @param string $accelerator The accelerator used
	 * @param array $options Any setting necessary to the caching engine
	 */
	public function __construct($level, $accelerator, $options)
	{
		$this->_options = $options;

		// Default to file based, so we can slow everything down :P
		if (empty($accelerator))
		{
			$accelerator = 'filebased';
		}
		$this->_accelerator = ucfirst($accelerator);

		$this->setLevel($level);
		if ($level > 0)
		{
			$this->enable(true);
			$this->level = $level;
		}
	}

	/**
	 * Enable or disable caching
	 *
	 * @param bool $enable
	 *
	 * @return $this
	 */
	public function enable($enable)
	{
		// Enable it if we can
		if (!$this->enabled && $this->_cache_obj === null)
		{
			$this->_init();
		}

		$this->enabled = (bool) $enable;

		return $this;
	}

	/**
	 * Initialize a cache class and call its initialization method
	 */
	protected function _init()
	{
		$cache_class = '\\ElkArte\\Cache\\CacheMethod\\' . $this->_accelerator;

		if (class_exists($cache_class))
		{
			$this->_cache_obj = new $cache_class($this->_options);
			$this->enabled = $this->_cache_obj->isAvailable();
		}
		else
		{
			$this->_cache_obj = null;
			$this->enabled = false;
		}

		$this->_build_prefix();
	}

	/**
	 * Set $_key_prefix to a "unique" value based on timestamp of a file
	 */
	protected function _build_prefix()
	{
		global $boardurl;

		if (!FileFunctions::instance()->fileExists(CACHEDIR . '/index.php'))
		{
			touch(CACHEDIR . '/index.php');
		}

		$this->_key_prefix = md5($boardurl . filemtime(CACHEDIR . '/index.php')) . '-ELK-';
	}

	/**
	 * Check if caching is enabled
	 *
	 * @return bool
	 */
	public function isEnabled()
	{
		return $this->enabled;
	}

	/**
	 * Return the current cache_obj
	 *
	 * @return AbstractCacheMethod|null
	 */
	public function getCacheEngine()
	{
		return $this->_cache_obj;
	}

	/**
	 * Return the cache accelerator in use
	 *
	 * @return string
	 */
	public function getAccelerator()
	{
		return $this->_accelerator;
	}

	/**
	 * Find and return the instance of the Cache class if it exists,
	 * otherwise start a new instance
	 */
	public static function instance()
	{
		if (self::$_instance === null)
		{
			global $cache_accelerator, $cache_enable, $cache_uid, $cache_password, $cache_servers;

			$options = [
				'servers' => empty($cache_servers) ? [] : explode(',', $cache_servers),
				'cache_uid' => empty($cache_uid) ? '' : $cache_uid,
				'cache_password' => empty($cache_password) ? '' : $cache_password,
			];

			self::$_instance = new Cache($cache_enable, $cache_accelerator, $options);
		}

		return self::$_instance;
	}

	/**
	 * Just before forgetting about the cache, let's save the existing keys.
	 */
	public function __destruct()
	{
		$cached = $this->get('_cached_keys');
		if (!is_array($cached))
		{
			$cached = [];
		}

		$_cached_keys = array_unique(array_merge($this->_cached_keys, $cached));
		$this->put('_cached_keys', $_cached_keys);
	}

	/**
	 * Gets the value from the cache specified by key, so long as it is not older than ttl seconds.
	 *
	 * - It may often "miss", so shouldn't be depended on.
	 * - It supports the same as \ElkArte\Cache\Cache::put().
	 *
	 * @param string $key
	 * @param int $ttl = 120
	 *
	 * @return null|mixed if it was a hit
	 */
	public function get($key, $ttl = 120)
	{
		global $db_show_debug;

		if (!$this->isEnabled())
		{
			return null;
		}

		if ($db_show_debug === true)
		{
			$cache_hit = [
				'k' => $key,
				'd' => 'get'
			];
			$st = microtime(true);
		}

		$key = $this->_key($key);
		$value = $this->_cache_obj->get($key, $ttl);

		if ($db_show_debug === true)
		{
			$cache_hit['t'] = microtime(true) - $st;
			$cache_hit['s'] = isset($value) ? strlen($value) : 0;
			Debug::instance()->cache($cache_hit);
		}

		call_integration_hook('cache_get_data', [$key, $ttl, $value]);

		return empty($value) ? null : Util::unserialize($value);
	}

	/**
	 * Get the key for the cache.
	 *
	 * @param string $key
	 *
	 * @return string
	 */
	protected function _key($key)
	{
		return $this->_key_prefix . $this->_cache_obj->fixkey($key);
	}

	/**
	 * Puts value in the cache under key for ttl seconds.
	 *
	 * - It may "miss" so shouldn't be depended on
	 * - Uses the cache engine chosen in the ACP and saved in settings.php
	 * - It supports:
	 *   - Memcache: https://www.php.net/memcache
	 *   - MemcacheD: https://www.php.net/memcached
	 *   - APCu: https://us3.php.net/manual/en/book.apcu.php
	 *   - Zend: https://help.zend.com/zend/current/content/data_cache_component.htm
	 *   - Redis: https://redis.io/learn/develop/php
	 *
	 * @param string $key
	 * @param string|int|array|null $value
	 * @param int $ttl = 120
	 */
	public function put($key, $value, $ttl = 120)
	{
		global $db_show_debug;

		if (!$this->isEnabled())
		{
			return;
		}

		// If we are showing debug information we have some data to collect
		if ($db_show_debug === true)
		{
			$cache_hit = [
				'k' => $key,
				'd' => 'put',
				's' => $value === null ? 0 : strlen(serialize($value))
			];
			$st = microtime(true);
		}

		$this->_cached_keys[] = $key;
		$key = $this->_key($key);
		$value = $value === null ? null : serialize($value);

		$this->_cache_obj->put($key, $value, $ttl);

		call_integration_hook('cache_put_data', [$key, $value, $ttl]);

		// Show the debug cache hit information
		if ($db_show_debug === true)
		{
			$cache_hit['t'] = microtime(true) - $st;
			Debug::instance()->cache($cache_hit);
		}
	}

	/**
	 * Try to retrieve a cache entry. On failure, call the appropriate function.
	 * This callback is sent as $file to include, and $function to call, with
	 * $params parameters.
	 *
	 * @param string $key cache entry key
	 * @param string $file file to include
	 * @param string $function function to call
	 * @param array $params parameters sent to the function
	 * @param int $level = 1
	 *
	 * @return array
	 */
	public function quick_get($key, $file, $function, $params, $level = 1)
	{
		call_integration_hook('pre_cache_quick_get', [&$key, &$file, &$function, &$params, &$level]);

		/* Refresh the cache if either:
			1. Caching is disabled.
			2. The cache level isn't high enough.
			3. The item has not been cached or the cached item expired.
			4. The cached item has a custom expiration condition evaluating to true.
			5. The expire time set in the cache item has passed (needed for Zend).
		*/
		$cache_block = $this->get($key, 3600);
		if ($this->level < $level
			|| !is_array($cache_block)
			|| !$this->isEnabled()
			|| (!empty($cache_block['refresh_eval']) && eval($cache_block['refresh_eval']))
			|| (!empty($cache_block['expires']) && $cache_block['expires'] < time()))
		{
			require_once(SOURCEDIR . '/' . $file);
			$cache_block = call_user_func_array($function, $params);

			if ($this->level >= $level)
			{
				$this->put($key, $cache_block, $cache_block['expires'] - time());
			}
		}

		// Some cached data may need a freshening up after retrieval.
		if (!empty($cache_block['post_retri_eval']))
		{
			eval($cache_block['post_retri_eval']);
		}

		call_integration_hook('post_cache_quick_get', [$cache_block]);

		return $cache_block['data'];
	}

	/**
	 * Same as $this->get but sets $var to the result and return if it was a hit
	 *
	 * @param mixed $var The variable to be assigned the result
	 * @param string $key
	 * @param int $ttl
	 *
	 * @return null|bool if it was a hit
	 */
	public function getVar(&$var, $key, $ttl = 120)
	{
		$var = $this->get($key, $ttl);

		return !$this->isMiss();
	}

	/**
	 * @return bool If the result of the last get was a miss
	 */
	public function isMiss()
	{
		return $this->isEnabled() ? $this->_cache_obj->isMiss() : true;
	}

	/**
	 * Empty out the cache in use as best it can
	 *
	 * It may only remove the files of a certain type (if the $type parameter is given)
	 * Type can be user, data or left blank
	 *  - user clears out user data
	 *  - data clears out system / opcode data
	 *  - If no type is specified will perform a complete cache clearing
	 * For cache engines that do not distinguish on types, a full cache flush will be done
	 *
	 * @param string $type = ''
	 */
	public function clean($type = '')
	{
		if (!$this->isEnabled())
		{
			return;
		}

		$this->_cache_obj->clean($type);

		// Invalidate cache, to be sure!
		// ... as long as CACHEDIR/index.php can be modified, anyway.
		@touch(CACHEDIR . '/index.php');

		// Give addons a way to trigger cache cleaning.
		call_integration_hook('integrate_clean_cache');

		clearstatcache();
	}

	/**
	 * Retrieves the current level.
	 *
	 * @return int The current level.
	 */
	public function getLevel()
	{
		return $this->level;
	}

	/**
	 * Set the caching level. Setting it to <= 0 disables caching
	 *
	 * @param int $level
	 *
	 * @return $this
	 */
	public function setLevel($level)
	{
		$this->level = (int) $level;

		if ($this->level <= 0)
		{
			$this->enable(false);
		}

		return $this;
	}

	/**
	 * Checks if the system level is set to a value strictly higher than the
	 * required level of the cache request.
	 *
	 * @param int $level
	 *
	 * @return bool
	 */
	public function levelHigherThan($level)
	{
		return $this->isEnabled() && $this->level > $level;
	}

	/**
	 * Checks if the system level is set to a value strictly lower than the
	 * required level of the cache request.
	 * Returns true also if the cache is disabled (it's lower than any level).
	 *
	 * @param int $level
	 *
	 * @return bool
	 */
	public function levelLowerThan($level)
	{
		if (!$this->isEnabled())
		{
			return true;
		}

		return $this->level < $level;
	}

	/**
	 * @param $key
	 */
	public function remove($key)
	{
		if (!$this->isEnabled())
		{
			return;
		}

		$key = $this->_key($key);
		$this->_cache_obj->remove($key);
	}

	/**
	 * Removes one or multiple keys from the cache.
	 *
	 * Supports the preg_match syntax.
	 *
	 * @param string|string[] $keys_match The regulat expression/s to match
	 *                        the key to remove from the cache.
	 * @param string $delimiter The delimiter used by preg_match.
	 * @param string $modifiers Any modifier required by the regexp.
	 */
	public function removeKeys($keys_match, $delimiter = '~', $modifiers = '')
	{
		if (!$this->isEnabled())
		{
			return;
		}

		$to_remove = (array) $keys_match;
		$pattern = $delimiter . implode('|', $to_remove) . $delimiter . $modifiers;

		foreach ($this->_cached_keys as $cached_key)
		{
			if (preg_match($pattern, $cached_key) === 1)
			{
				$this->_cache_obj->remove($cached_key);
			}
		}
	}
}
