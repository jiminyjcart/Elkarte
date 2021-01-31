<?php

use ElkArte\AdminController\ManageBoards;
use ElkArte\EventManager;
use ElkArte\User;

/**
 * TestCase class for manage boards settings
 */
class TestManageBoardsSettings extends ElkArteCommonSetupTest
{
	protected $backupGlobalsBlacklist = ['user_info'];
	/**
	 * Initialize or add whatever necessary for these tests
	 */
	public function setUp()
	{
		parent::setUp();
		theme()->getTemplates()->loadLanguageFile('ManagePermissions', 'english', true, true);
	}

	/**
	 * Test the settings for admin search
	 */
	public function testSettings()
	{
		global $txt;

		$controller = new ManageBoards(new EventManager());
		$controller->setUser(User::$info);
		$controller->pre_dispatch();
		$settings = $controller->settings_search();

		// Lets see some hardcoded setting for boards management...
		$this->assertNotNull($settings);
		$this->assertTrue(in_array(array('title', 'settings'), $settings));
		$this->assertTrue(in_array(array('permissions', 'manage_boards', 'helptext' => $txt['permissionhelp_manage_boards']), $settings));
		$this->assertTrue(in_array(array('check', 'countChildPosts'), $settings));
	}
}
