<?php

/**
 * This file contains functions that deal with getting and setting cache values.
 *
 * @package   ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause (see accompanying LICENSE.txt file)
 *
 * @version 2.0 dev
 *
 */

namespace ElkArte\Cache\CacheMethod;

use UnexpectedValueException;

/**
 * Filebased caching is the fallback if nothing else is available, it simply
 * uses the filesystem to store queries results in order to try to reduce the
 * number of queries per time period.
 *
 * The performance gain may or may not exist depending on many factors.
 *
 * It requires the CACHEDIR constant to be defined and pointing to a writable directory.
 */
class Filebased extends AbstractCacheMethod
{
	/** {@inheritDoc} */
	protected $title = 'File-based caching';

	/** {@inheritDoc} */
	protected $prefix = 'data_';

	/** @var string File extension. */
	protected $ext = 'php';

	/**
	 * Obtain from the parent class the variables necessary
	 * to help the tests stay running smoothly.
	 *
	 * @param string $key
	 *
	 * @return string
	 */
	public function getFileName($key)
	{
		return $this->prefix . '_' . $key . '.' . $this->ext;
	}

	/**
	 * {@inheritDoc}
	 */
	public function exists($key)
	{
		return $this->fileFunc->fileExists(CACHEDIR . '/' . $this->getFileName($key));
	}

	/**
	 * {@inheritDoc}
	 */
	public function put($key, $value, $ttl = 120)
	{
		$fName = $this->getFileName($key);

		// Clearing this data
		if ($value === null)
		{
			$this->fileFunc->delete(CACHEDIR . '/' . $fName);
		}
		// Or stashing it away
		else
		{
			$cache_data = "<?php '" . json_encode(['expiration' => time() + $ttl, 'data' => $value]) . "';";

			// Write out the cache file, check that the cache write was successful; all the data must be written
			// If it fails due to low diskspace, or other, remove the cache file
			if (file_put_contents(CACHEDIR . '/' . $fName, $cache_data, LOCK_EX) !== strlen($cache_data))
			{
				$this->fileFunc->delete(CACHEDIR . '/' . $fName);
			}
		}

		$this->opcacheReset($fName);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get($key, $ttl = 120)
	{
		$return = null;
		$fName = $this->getFileName($key);

		if ($this->fileFunc->fileExists(CACHEDIR . '/' . $fName))
		{
			// Even though it exists, we may not be able to access the file
			$value = json_decode(substr(@file_get_contents(CACHEDIR . '/' . $fName), 7, -2), false);

			if ($value === null || $value->expiration < time())
			{
				$this->fileFunc->delete(CACHEDIR . '/' . $fName);
			}
			else
			{
				$return = $value->data;
			}

			unset($value);
			$this->is_miss = $return === null;

			return $return;
		}

		$this->is_miss = true;

		return $return;
	}

	/**
	 * Resets the opcache for a specific file.
	 *
	 * If opcache is switched on, and we can use it, immediately invalidates that opcode cache
	 * after a file is written so that future includes are not using a stale opcode cached file.
	 *
	 * @param string $fName The name of the cached file.
	 */
	private function opcacheReset($fName)
	{
		if (extension_loaded('Zend OPcache') && ini_get('opcache.enable'))
		{
			$opcache = ini_get('opcache.restrict_api');
			if ($opcache === false || $opcache === '')
			{
				opcache_invalidate(CACHEDIR . '/' . $fName, true);
			}
			elseif (stripos(BOARDDIR, $opcache) !== 0)
			{
				opcache_invalidate(CACHEDIR . '/' . $fName, true);
			}
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function clean($type = '')
	{
		try
		{
			$files = new \FilesystemIterator(CACHEDIR, \FilesystemIterator::SKIP_DOTS);

			foreach ($files as $file)
			{
				if ($file->getFilename() === 'index.php')
				{
					continue;
				}

				if ($file->getFilename() === '.htaccess')
				{
					continue;
				}

				if ($file->getExtension() !== $this->ext)
				{
					continue;
				}

				$this->fileFunc->delete($file->getPathname());
			}
		}
		catch (UnexpectedValueException)
		{
			// @todo
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function fixkey($key)
	{
		return strtr($key, ':/', '-_');
	}

	/**
	 * {@inheritDoc}
	 */
	public function isAvailable()
	{
		return $this->fileFunc->isDir(CACHEDIR) && $this->fileFunc->isWritable(CACHEDIR);
	}

	/**
	 * {@inheritDoc}
	 */
	public function details()
	{
		return ['title' => $this->title, 'version' => 'N/A'];
	}

	/**
	 * Adds the settings to the settings page.
	 *
	 * Used by integrate_modify_cache_settings added in the title method
	 *
	 * @param array $config_vars
	 */
	public function settings(&$config_vars)
	{
		global $txt;

		$config_vars[] = ['cachedir', $txt['cachedir'], 'file', 'text', 36, 'cache_cachedir', 'force_div_id' => 'filebased_cachedir'];
	}
}
