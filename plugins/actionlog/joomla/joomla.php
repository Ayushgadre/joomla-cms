<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  System.actionlogs
 *
 * @copyright   (C) 2018 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Installer\Installer;
use Joomla\CMS\Table\Table;
use Joomla\CMS\User\User;
use Joomla\Component\Actionlogs\Administrator\Helper\ActionlogsHelper;
use Joomla\Component\Actionlogs\Administrator\Plugin\ActionLogPlugin;
use Joomla\Utilities\ArrayHelper;

/**
 * Joomla! Users Actions Logging Plugin.
 *
 * @since  3.9.0
 */
class PlgActionlogJoomla extends ActionLogPlugin
{
	/**
	 * Array of loggable extensions.
	 *
	 * @var    array
	 * @since  3.9.0
	 */
	protected $loggableExtensions = [];

	/**
	 * Context aliases
	 *
	 * @var    array
	 * @since  3.9.0
	 */
	protected $contextAliases = ['com_content.form' => 'com_content.article'];

	/**
	 * Flag for loggable Api.
	 *
	 * @var    boolean
	 * @since  4.0.0
	 */
	protected $loggableApi = false;

	/**
	 * Array of loggable verbs.
	 *
	 * @var    array
	 * @since  4.0.0
	 */
	protected $loggableVerbs = [];

	/**
	 * Constructor.
	 *
	 * @param   object  &$subject  The object to observe.
	 * @param   array   $config    An optional associative array of configuration settings.
	 *
	 * @since   3.9.0
	 */
	public function __construct(&$subject, $config)
	{
		parent::__construct($subject, $config);

		$params = ComponentHelper::getComponent('com_actionlogs')->getParams();

		$this->loggableExtensions = $params->get('loggable_extensions', []);

		$this->loggableApi        = $params->get('loggable_api', 0);

		$this->loggableVerbs      = $params->get('loggable_verbs', []);
	}

	/**
	 * After save content logging method
	 * This method adds a record to #__action_logs contains (message, date, context, user)
	 * Method is called right after the content is saved
	 *
	 * @param   string   $context  The context of the content passed to the plugin
	 * @param   object   $article  A JTableContent object
	 * @param   boolean  $isNew    If the content is just about to be created
	 *
	 * @return  void
	 *
	 * @since   3.9.0
	 */
	public function onContentAfterSave($context, $article, $isNew): void
	{
		if (isset($this->contextAliases[$context]))
		{
			$context = $this->contextAliases[$context];
		}

		$params = ActionlogsHelper::getLogContentTypeParams($context);

		// Not found a valid content type, don't process further
		if ($params === null)
		{
			return;
		}

		list($option, $contentType) = explode('.', $params->type_alias);

		if (!$this->checkLoggable($option))
		{
			return;
		}

		if ($isNew)
		{
			$messageLanguageKey = $params->text_prefix . '_' . $params->type_title . '_ADDED';
			$defaultLanguageKey = 'PLG_SYSTEM_ACTIONLOGS_CONTENT_ADDED';
		}
		else
		{
			$messageLanguageKey = $params->text_prefix . '_' . $params->type_title . '_UPDATED';
			$defaultLanguageKey = 'PLG_SYSTEM_ACTIONLOGS_CONTENT_UPDATED';
		}

		// If the content type doesn't has it own language key, use default language key
		if (!$this->app->getLanguage()->hasKey($messageLanguageKey))
		{
			$messageLanguageKey = $defaultLanguageKey;
		}

		$id = empty($params->id_holder) ? 0 : $article->get($params->id_holder);

		$message = array(
			'action'   => $isNew ? 'add' : 'update',
			'type'     => $params->text_prefix . '_TYPE_' . $params->type_title,
			'id'       => $id,
			'title'    => $article->get($params->title_holder),
			'itemlink' => ActionlogsHelper::getContentTypeLink($option, $contentType, $id, $params->id_holder, $article),
		);

		$this->addLog(array($message), $messageLanguageKey, $context);
	}

	
	public function onContentAfterDelete($context, $article): void
	{
		$option = $this->app->input->get('option');

		if (!$this->checkLoggable($option))
		{
			return;
		}

		$params = ActionlogsHelper::getLogContentTypeParams($context);

		// Not found a valid content type, don't process further
		if ($params === null)
		{
			return;
		}

		// If the content type has it own language key, use it, otherwise, use default language key
		if ($this->app->getLanguage()->hasKey(strtoupper($params->text_prefix . '_' . $params->type_title . '_DELETED')))
		{
			$messageLanguageKey = $params->text_prefix . '_' . $params->type_title . '_DELETED';
		}
		else
		{
			$messageLanguageKey = 'PLG_SYSTEM_ACTIONLOGS_CONTENT_DELETED';
		}

		$id = empty($params->id_holder) ? 0 : $article->get($params->id_holder);

		$message = array(
			'action' => 'delete',
			'type'   => $params->text_prefix . '_TYPE_' . $params->type_title,
			'id'     => $id,
			'title'  => $article->get($params->title_holder),
		);

		$this->addLog(array($message), $messageLanguageKey, $context);
	}

	
	public function onContentChangeState($context, $pks, $value)
	{
		$option = $this->app->input->getCmd('option');

		if (!$this->checkLoggable($option))
		{
			return;
		}

		$params = ActionlogsHelper::getLogContentTypeParams($context);

		// Not found a valid content type, don't process further
		if ($params === null)
		{
			return;
		}

		list(, $contentType) = explode('.', $params->type_alias);

		switch ($value)
		{
			case 0:
				$messageLanguageKey = $params->text_prefix . '_' . $params->type_title . '_UNPUBLISHED';
				$defaultLanguageKey = 'PLG_SYSTEM_ACTIONLOGS_CONTENT_UNPUBLISHED';
				$action             = 'unpublish';
				break;
			case 1:
				$messageLanguageKey = $params->text_prefix . '_' . $params->type_title . '_PUBLISHED';
				$defaultLanguageKey = 'PLG_SYSTEM_ACTIONLOGS_CONTENT_PUBLISHED';
				$action             = 'publish';
				break;
			case 2:
				$messageLanguageKey = $params->text_prefix . '_' . $params->type_title . '_ARCHIVED';
				$defaultLanguageKey = 'PLG_SYSTEM_ACTIONLOGS_CONTENT_ARCHIVED';
				$action             = 'archive';
				break;
			case -2:
				$messageLanguageKey = $params->text_prefix . '_' . $params->type_title . '_TRASHED';
				$defaultLanguageKey = 'PLG_SYSTEM_ACTIONLOGS_CONTENT_TRASHED';
				$action             = 'trash';
				break;
			default:
				$messageLanguageKey = '';
				$defaultLanguageKey = '';
				$action             = '';
				break;
		}

		
		if (!$this->app->getLanguage()->hasKey($messageLanguageKey))
		{
			$messageLanguageKey = $defaultLanguageKey;
		}

		$db    = $this->db;
		$query = $db->getQuery(true)
			->select($db->quoteName([$params->title_holder, $params->id_holder]))
			->from($db->quoteName($params->table_name))
			->whereIn($db->quoteName($params->id_holder), ArrayHelper::toInteger($pks));
		$db->setQuery($query);

		try
		{
			$items = $db->loadObjectList($params->id_holder);
		}
		catch (RuntimeException $e)
		{
			$items = array();
		}

		$messages = array();

		foreach ($pks as $pk)
		{
			$message = array(
				'action'      => $action,
				'type'        => $params->text_prefix . '_TYPE_' . $params->type_title,
				'id'          => $pk,
				'title'       => $items[$pk]->{$params->title_holder},
				'itemlink'    => ActionlogsHelper::getContentTypeLink($option, $contentType, $pk, $params->id_holder, null),
			);

			$messages[] = $message;
		}

		$this->addLog($messages, $messageLanguageKey, $context);
	}

	
	public function onApplicationAfterSave($config): void
	{
		$option = $this->app->input->getCmd('option');

		if (!$this->checkLoggable($option))
		{
			return;
		}

		$messageLanguageKey = 'PLG_ACTIONLOG_JOOMLA_APPLICATION_CONFIG_UPDATED';
		$action             = 'update';

		$message = array(
			'action'         => $action,
			'type'           => 'PLG_ACTIONLOG_JOOMLA_TYPE_APPLICATION_CONFIG',
			'extension_name' => 'com_config.application',
			'itemlink'       => 'index.php?option=com_config',
		);

		$this->addLog(array($message), $messageLanguageKey, 'com_config.application');
	}

	
	public function onExtensionAfterInstall($installer, $eid)
	{
		$context = $this->app->input->get('option');

		if (!$this->checkLoggable($context))
		{
			return;
		}

		$manifest      = $installer->get('manifest');

		if ($manifest === null)
		{
			return;
		}

		$extensionType = $manifest->attributes()->type;

		
		if ($this->app->getLanguage()->hasKey(strtoupper('PLG_ACTIONLOG_JOOMLA_' . $extensionType . '_INSTALLED')))
		{
			$messageLanguageKey = 'PLG_ACTIONLOG_JOOMLA_' . $extensionType . '_INSTALLED';
		}
		else
		{
			$messageLanguageKey = 'PLG_ACTIONLOG_JOOMLA_EXTENSION_INSTALLED';
		}

		$message = array(
			'action'         => 'install',
			'type'           => 'PLG_ACTIONLOG_JOOMLA_TYPE_' . $extensionType,
			'id'             => $eid,
			'name'           => (string) $manifest->name,
			'extension_name' => (string) $manifest->name,
		);

		$this->addLog(array($message), $messageLanguageKey, $context);
	}

	
	public function onExtensionAfterUninstall($installer, $eid, $result)
	{
		$context = $this->app->input->get('option');

		if (!$this->checkLoggable($context))
		{
			return;
		}

		// If the process failed, we don't have manifest data, stop process to avoid fatal error
		if ($result === false)
		{
			return;
		}

		$manifest      = $installer->get('manifest');

		if ($manifest === null)
		{
			return;
		}

		$extensionType = $manifest->attributes()->type;

		// If the extension type has it own language key, use it, otherwise, use default language key
		if ($this->app->getLanguage()->hasKey(strtoupper('PLG_ACTIONLOG_JOOMLA_' . $extensionType . '_UNINSTALLED')))
		{
			$messageLanguageKey = 'PLG_ACTIONLOG_JOOMLA_' . $extensionType . '_UNINSTALLED';
		}
		else
		{
			$messageLanguageKey = 'PLG_ACTIONLOG_JOOMLA_EXTENSION_UNINSTALLED';
		}

		$message = array(
			'action'         => 'install',
			'type'           => 'PLG_ACTIONLOG_JOOMLA_TYPE_' . $extensionType,
			'id'             => $eid,
			'name'           => (string) $manifest->name,
			'extension_name' => (string) $manifest->name,
		);

		$this->addLog(array($message), $messageLanguageKey, $context);
	}

	/**
	 * On updating extensions logging method
	 * This method adds a record to #__action_logs contains (message, date, context, user)
	 * Method is called when an extension is updated
	 *
	 * @param   Installer  $installer  Installer instance
	 * @param   integer    $eid        Extension id
	 *
	 * @return  void
	 *
	 * @since   3.9.0
	 */
	public function onExtensionAfterUpdate($installer, $eid)
	{
		$context = $this->app->input->get('option');

		if (!$this->checkLoggable($context))
		{
			return;
		}

		$manifest      = $installer->get('manifest');

		if ($manifest === null)
		{
			return;
		}

		$extensionType = $manifest->attributes()->type;

		// If the extension type has it own language key, use it, otherwise, use default language key
		if ($this->app->getLanguage()->hasKey('PLG_ACTIONLOG_JOOMLA_' . $extensionType . '_UPDATED'))
		{
			$messageLanguageKey = 'PLG_ACTIONLOG_JOOMLA_' . $extensionType . '_UPDATED';
		}
		else
		{
			$messageLanguageKey = 'PLG_ACTIONLOG_JOOMLA_EXTENSION_UPDATED';
		}

		$message = array(
			'action'         => 'update',
			'type'           => 'PLG_ACTIONLOG_JOOMLA_TYPE_' . $extensionType,
			'id'             => $eid,
			'name'           => (string) $manifest->name,
			'extension_name' => (string) $manifest->name,
		);

		$this->addLog(array($message), $messageLanguageKey, $context);
	}

	
	public function onExtensionAfterSave($context, $table, $isNew): void
	{
		$option = $this->app->input->getCmd('option');

		if ($table->get('module') != null)
		{
			$option = 'com_modules';
		}

		if (!$this->checkLoggable($option))
		{
			return;
		}

		$params = ActionlogsHelper::getLogContentTypeParams($context);

		// Not found a valid content type, don't process further
		if ($params === null)
		{
			return;
		}

		list(, $contentType) = explode('.', $params->type_alias);

		if ($isNew)
		{
			$messageLanguageKey = $params->text_prefix . '_' . $params->type_title . '_ADDED';
			$defaultLanguageKey = 'PLG_SYSTEM_ACTIONLOGS_CONTENT_ADDED';
		}
		else
		{
			$messageLanguageKey = $params->text_prefix . '_' . $params->type_title . '_UPDATED';
			$defaultLanguageKey = 'PLG_SYSTEM_ACTIONLOGS_CONTENT_UPDATED';
		}

		// If the extension type doesn't have it own language key, use default language key
		if (!$this->app->getLanguage()->hasKey($messageLanguageKey))
		{
			$messageLanguageKey = $defaultLanguageKey;
		}

		$message = array(
			'action'         => $isNew ? 'add' : 'update',
			'type'           => 'PLG_ACTIONLOG_JOOMLA_TYPE_' . $params->type_title,
			'id'             => $table->get($params->id_holder),
			'title'          => $table->get($params->title_holder),
			'extension_name' => $table->get($params->title_holder),
			'itemlink'       => ActionlogsHelper::getContentTypeLink($option, $contentType, $table->get($params->id_holder), $params->id_holder),
		);

		$this->addLog(array($message), $messageLanguageKey, $context);
	}

	
	public function onExtensionAfterDelete($context, $table): void
	{
		if (!$this->checkLoggable($this->app->input->get('option')))
		{
			return;
		}

		$params = ActionlogsHelper::getLogContentTypeParams($context);

		// Not found a valid content type, don't process further
		if ($params === null)
		{
			return;
		}

		$messageLanguageKey = 'PLG_SYSTEM_ACTIONLOGS_CONTENT_DELETED';

		$message = array(
			'action' => 'delete',
			'type'   => 'PLG_ACTIONLOG_JOOMLA_TYPE_' . $params->type_title,
			'title'  => $table->get($params->title_holder),
		);

		$this->addLog(array($message), $messageLanguageKey, $context);
	}

	/**
	 * On saving user data logging method
	 *
	 * Method is called after user data is stored in the database.
	 * This method logs who created/edited any user's data
	 *
	 * @param   array    $user     Holds the new user data.
	 * @param   boolean  $isnew    True if a new user is stored.
	 * @param   boolean  $success  True if user was successfully stored in the database.
	 * @param   string   $msg      Message.
	 *
	 * @return  void
	 *
	 * @since   3.9.0
	 */
	public function onUserAfterSave($user, $isnew, $success, $msg): void
	{
		$context = $this->app->input->get('option');
		$task    = $this->app->input->post->get('task');

		if (!$this->checkLoggable($context))
		{
			return;
		}

		$jUser = Factory::getUser();

		if (!$jUser->id)
		{
			$messageLanguageKey = 'PLG_ACTIONLOG_JOOMLA_USER_REGISTERED';
			$action             = 'register';

			// Reset request
			if ($task === 'reset.request')
			{
				$messageLanguageKey = 'PLG_ACTIONLOG_JOOMLA_USER_RESET_REQUEST';
				$action             = 'resetrequest';
			}

			// Reset complete
			if ($task === 'reset.complete')
			{
				$messageLanguageKey = 'PLG_ACTIONLOG_JOOMLA_USER_RESET_COMPLETE';
				$action             = 'resetcomplete';
			}

			// Registration Activation
			if ($task === 'registration.activate')
			{
				$messageLanguageKey = 'PLG_ACTIONLOG_JOOMLA_USER_REGISTRATION_ACTIVATE';
				$action             = 'activaterequest';
			}
		}
		elseif ($isnew)
		{
			$messageLanguageKey = 'PLG_SYSTEM_ACTIONLOGS_CONTENT_ADDED';
			$action             = 'add';
		}
		else
		{
			$messageLanguageKey = 'PLG_SYSTEM_ACTIONLOGS_CONTENT_UPDATED';
			$action             = 'update';
		}

		$userId   = $jUser->id ?: $user['id'];
		$username = $jUser->username ?: $user['username'];

		$message = array(
			'action'      => $action,
			'type'        => 'PLG_ACTIONLOG_JOOMLA_TYPE_USER',
			'id'          => $user['id'],
			'title'       => $user['name'],
			'itemlink'    => 'index.php?option=com_users&task=user.edit&id=' . $user['id'],
			'userid'      => $userId,
			'username'    => $username,
			'accountlink' => 'index.php?option=com_users&task=user.edit&id=' . $userId,
		);

		$this->addLog(array($message), $messageLanguageKey, $context, $userId);
	}

	/**
	 * On deleting user data logging method
	 *
	 * Method is called after user data is deleted from the database
	 *
	 * @param   array    $user     Holds the user data
	 * @param   boolean  $success  True if user was successfully stored in the database
	 * @param   string   $msg      Message
	 *
	 * @return  void
	 *
	 * @since   3.9.0
	 */
	public function onUserAfterDelete($user, $success, $msg): void
	{
		$context = $this->app->input->get('option');

		if (!$this->checkLoggable($context))
		{
			return;
		}

		$messageLanguageKey = 'PLG_SYSTEM_ACTIONLOGS_CONTENT_DELETED';

		$message = array(
			'action'      => 'delete',
			'type'        => 'PLG_ACTIONLOG_JOOMLA_TYPE_USER',
			'id'          => $user['id'],
			'title'       => $user['name'],
		);

		$this->addLog(array($message), $messageLanguageKey, $context);
	}

	/**
	 * On after save user group data logging method
	 *
	 * Method is called after user group is stored into the database
	 *
	 * @param   string   $context  The context
	 * @param   Table    $table    DataBase Table object
	 * @param   boolean  $isNew    Is new or not
	 *
	 * @return  void
	 *
	 * @since   3.9.0
	 */
	public function onUserAfterSaveGroup($context, $table, $isNew): void
	{
		// Override context (com_users.group) with the component context (com_users) to pass the checkLoggable
		$context = $this->app->input->get('option');

		if (!$this->checkLoggable($context))
		{
			return;
		}

		if ($isNew)
		{
			$messageLanguageKey = 'PLG_SYSTEM_ACTIONLOGS_CONTENT_ADDED';
			$action             = 'add';
		}
		else
		{
			$messageLanguageKey = 'PLG_SYSTEM_ACTIONLOGS_CONTENT_UPDATED';
			$action             = 'update';
		}

		$message = array(
			'action'      => $action,
			'type'        => 'PLG_ACTIONLOG_JOOMLA_TYPE_USER_GROUP',
			'id'          => $table->id,
			'title'       => $table->title,
			'itemlink'    => 'index.php?option=com_users&task=group.edit&id=' . $table->id,
		);

		$this->addLog(array($message), $messageLanguageKey, $context);
	}

	/**
	 * On deleting user group data logging method
	 *
	 * Method is called after user group is deleted from the database
	 *
	 * @param   array    $group    Holds the group data
	 * @param   boolean  $success  True if user was successfully stored in the database
	 * @param   string   $msg      Message
	 *
	 * @return  void
	 *
	 * @since   3.9.0
	 */
	public function onUserAfterDeleteGroup($group, $success, $msg): void
	{
		$context = $this->app->input->get('option');

		if (!$this->checkLoggable($context))
		{
			return;
		}

		$messageLanguageKey = 'PLG_SYSTEM_ACTIONLOGS_CONTENT_DELETED';

		$message = array(
			'action'      => 'delete',
			'type'        => 'PLG_ACTIONLOG_JOOMLA_TYPE_USER_GROUP',
			'id'          => $group['id'],
			'title'       => $group['title'],
		);

		$this->addLog(array($message), $messageLanguageKey, $context);
	}

	/**
	 * Method to log user login success action
	 *
	 * @param   array  $options  Array holding options (user, responseType)
	 *
	 * @return  void
	 *
	 * @since   3.9.0
	 */
	public function onUserAfterLogin($options)
	{
		if ($options['action'] === 'core.login.api')
		{
			return;
		}

		$context = 'com_users';

		if (!$this->checkLoggable($context))
		{
			return;
		}

		$loggedInUser       = $options['user'];
		$messageLanguageKey = 'PLG_ACTIONLOG_JOOMLA_USER_LOGGED_IN';

		$message = array(
			'action'      => 'login',
			'userid'      => $loggedInUser->id,
			'username'    => $loggedInUser->username,
			'accountlink' => 'index.php?option=com_users&task=user.edit&id=' . $loggedInUser->id,
			'app'         => 'PLG_ACTIONLOG_JOOMLA_APPLICATION_' . $this->app->getName(),
		);

		$this->addLog(array($message), $messageLanguageKey, $context, $loggedInUser->id);
	}

	/**
	 * Method to log user login failed action
	 *
	 * @param   array  $response  Array of response data.
	 *
	 * @return  void
	 *
	 * @since   3.9.0
	 */
	public function onUserLoginFailure($response)
	{
		$context = 'com_users';

		if (!$this->checkLoggable($context))
		{
			return;
		}

		// Get the user id for the given username
		$query = $this->db->getQuery(true)
			->select($this->db->quoteName(array('id', 'username')))
			->from($this->db->quoteName('#__users'))
			->where($this->db->quoteName('username') . ' = ' . $this->db->quote($response['username']));
		$this->db->setQuery($query);

		try
		{
			$loggedInUser = $this->db->loadObject();
		}
		catch (\Joomla\Database\Exception\ExecutionFailureException $e)
		{
			return;
		}

		// Not a valid user, return
		if (!isset($loggedInUser->id))
		{
			return;
		}

		$messageLanguageKey = 'PLG_ACTIONLOG_JOOMLA_USER_LOGIN_FAILED';

		$message = array(
			'action'      => 'login',
			'id'          => $loggedInUser->id,
			'userid'      => $loggedInUser->id,
			'username'    => $loggedInUser->username,
			'accountlink' => 'index.php?option=com_users&task=user.edit&id=' . $loggedInUser->id,
			'app'         => 'PLG_ACTIONLOG_JOOMLA_APPLICATION_' . $this->app->getName(),
		);

		$this->addLog(array($message), $messageLanguageKey, $context, $loggedInUser->id);
	}

	/**
	 * Method to log user's logout action
	 *
	 * @param   array  $user     Holds the user data
	 * @param   array  $options  Array holding options (remember, autoregister, group)
	 *
	 * @return  void
	 *
	 * @since   3.9.0
	 */
	public function onUserLogout($user, $options = array())
	{
		$context = 'com_users';

		if (!$this->checkLoggable($context))
		{
			return;
		}

		$loggedOutUser = User::getInstance($user['id']);

		if ($loggedOutUser->block)
		{
			return;
		}

		$messageLanguageKey = 'PLG_ACTIONLOG_JOOMLA_USER_LOGGED_OUT';

		$message = array(
			'action'      => 'logout',
			'id'          => $loggedOutUser->id,
			'userid'      => $loggedOutUser->id,
			'username'    => $loggedOutUser->username,
			'accountlink' => 'index.php?option=com_users&task=user.edit&id=' . $loggedOutUser->id,
			'app'         => 'PLG_ACTIONLOG_JOOMLA_APPLICATION_' . $this->app->getName(),
		);

		$this->addLog(array($message), $messageLanguageKey, $context);
	}

	/**
	 * Function to check if a component is loggable or not
	 *
	 * @param   string  $extension  The extension that triggered the event
	 *
	 * @return  boolean
	 *
	 * @since   3.9.0
	 */
	protected function checkLoggable($extension)
	{
		return in_array($extension, $this->loggableExtensions);
	}

	/**
	 * On after Remind username request
	 *
	 * Method is called after user request to remind their username.
	 *
	 * @param   array  $user  Holds the user data.
	 *
	 * @return  void
	 *
	 * @since   3.9.0
	 */
	public function onUserAfterRemind($user)
	{
		$context = $this->app->input->get('option');

		if (!$this->checkLoggable($context))
		{
			return;
		}

		$message = array(
			'action'      => 'remind',
			'type'        => 'PLG_ACTIONLOG_JOOMLA_TYPE_USER',
			'id'          => $user->id,
			'title'       => $user->name,
			'itemlink'    => 'index.php?option=com_users&task=user.edit&id=' . $user->id,
			'userid'      => $user->id,
			'username'    => $user->name,
			'accountlink' => 'index.php?option=com_users&task=user.edit&id=' . $user->id,
		);

		$this->addLog(array($message), 'PLG_ACTIONLOG_JOOMLA_USER_REMIND', $context, $user->id);
	}

	/**
	 * On after Check-in request
	 *
	 * Method is called after user request to check-in items.
	 *
	 * @param   array  $table  Holds the table name.
	 *
	 * @return  void
	 *
	 * @since   3.9.3
	 */
	public function onAfterCheckin($table)
	{
		$context = 'com_checkin';
		$user    = Factory::getUser();

		if (!$this->checkLoggable($context))
		{
			return;
		}

		$message = array(
			'action'      => 'checkin',
			'type'        => 'PLG_ACTIONLOG_JOOMLA_TYPE_USER',
			'id'          => $user->id,
			'title'       => $user->username,
			'itemlink'    => 'index.php?option=com_users&task=user.edit&id=' . $user->id,
			'userid'      => $user->id,
			'username'    => $user->username,
			'accountlink' => 'index.php?option=com_users&task=user.edit&id=' . $user->id,
			'table'       => $table,
		);

		$this->addLog(array($message), 'PLG_ACTIONLOG_JOOMLA_USER_CHECKIN', $context, $user->id);
	}

	/**
	 * On after log action purge
	 *
	 * Method is called after user request to clean action log items.
	 *
	 * @param   array  $group  Holds the group name.
	 *
	 * @return  void
	 *
	 * @since   3.9.4
	 */
	public function onAfterLogPurge($group = '')
	{
		$context = $this->app->input->get('option');
		$user    = Factory::getUser();
		$message = array(
			'action'      => 'actionlogs',
			'type'        => 'PLG_ACTIONLOG_JOOMLA_TYPE_USER',
			'id'          => $user->id,
			'title'       => $user->username,
			'itemlink'    => 'index.php?option=com_users&task=user.edit&id=' . $user->id,
			'userid'      => $user->id,
			'username'    => $user->username,
			'accountlink' => 'index.php?option=com_users&task=user.edit&id=' . $user->id,
		);
		$this->addLog(array($message), 'PLG_ACTIONLOG_JOOMLA_USER_LOG', $context, $user->id);
	}

	/**
	 * On after log export
	 *
	 * Method is called after user request to export action log items.
	 *
	 * @param   array  $group  Holds the group name.
	 *
	 * @return  void
	 *
	 * @since   3.9.4
	 */
	public function onAfterLogExport($group = '')
	{
		$context = $this->app->input->get('option');
		$user    = Factory::getUser();
		$message = array(
			'action'      => 'actionlogs',
			'type'        => 'PLG_ACTIONLOG_JOOMLA_TYPE_USER',
			'id'          => $user->id,
			'title'       => $user->username,
			'itemlink'    => 'index.php?option=com_users&task=user.edit&id=' . $user->id,
			'userid'      => $user->id,
			'username'    => $user->username,
			'accountlink' => 'index.php?option=com_users&task=user.edit&id=' . $user->id,
		);
		$this->addLog(array($message), 'PLG_ACTIONLOG_JOOMLA_USER_LOGEXPORT', $context, $user->id);
	}

	/**
	 * On after Cache purge
	 *
	 * Method is called after user request to clean cached items.
	 *
	 * @param   string  $group  Holds the group name.
	 *
	 * @return  void
	 *
	 * @since   3.9.4
	 */
	public function onAfterPurge($group = 'all')
	{
		$context = $this->app->input->get('option');
		$user    = Factory::getUser();

		if (!$this->checkLoggable($context))
		{
			return;
		}

		$message = array(
			'action'      => 'cache',
			'type'        => 'PLG_ACTIONLOG_JOOMLA_TYPE_USER',
			'id'          => $user->id,
			'title'       => $user->username,
			'itemlink'    => 'index.php?option=com_users&task=user.edit&id=' . $user->id,
			'userid'      => $user->id,
			'username'    => $user->username,
			'accountlink' => 'index.php?option=com_users&task=user.edit&id=' . $user->id,
			'group'       => $group,
		);
		$this->addLog(array($message), 'PLG_ACTIONLOG_JOOMLA_USER_CACHE', $context, $user->id);
	}

	/**
	 * On after Api dispatched
	 *
	 * Method is called after user perform an API request better on onAfterDispatch.
	 *
	 * @return  void
	 *
	 * @since   4.0.0
	 */
	public function onAfterDispatch()
	{
		if (!$this->app->isClient('api'))
		{
			return;
		}

		if ($this->loggableApi === 0)
		{
			return;
		}

		$verb = $this->app->input->getMethod();

		if (!in_array($verb, $this->loggableVerbs))
		{
			return;
		}

		$context = $this->app->input->get('option');

		if (!$this->checkLoggable($context))
		{
			return;
		}

		$user = $this->app->getIdentity();
		$message = array(
			'action'      => 'API',
			'verb'        => $verb,
			'username'    => $user->username,
			'accountlink' => 'index.php?option=com_users&task=user.edit&id=' . $user->id,
			'url'         => htmlspecialchars(urldecode($this->app->get('uri.route')), ENT_QUOTES, 'UTF-8'),
		);
		$this->addLog(array($message), 'PLG_ACTIONLOG_JOOMLA_API', $context, $user->id);
	}

	/**
	 * On after CMS Update
	 *
	 * Method is called after user update the CMS.
	 *
	 * @param   string  $oldVersion  The Joomla version before the update
	 *
	 * @return  void
	 *
	 * @since   3.9.21
	 */
	public function onJoomlaAfterUpdate($oldVersion = null)
	{
		$context = $this->app->input->get('option');
		$user    = Factory::getUser();

		if (empty($oldVersion))
		{
			$oldVersion = \Joomla\CMS\Language\Text::_('JLIB_UNKNOWN');
		}

		$message = array(
			'action'      => 'joomlaupdate',
			'type'        => 'PLG_ACTIONLOG_JOOMLA_TYPE_USER',
			'id'          => $user->id,
			'title'       => $user->username,
			'itemlink'    => 'index.php?option=com_users&task=user.edit&id=' . $user->id,
			'userid'      => $user->id,
			'username'    => $user->username,
			'accountlink' => 'index.php?option=com_users&task=user.edit&id=' . $user->id,
			'version'     => JVERSION,
			'oldversion'  => $oldVersion,
		);
		$this->addLog(array($message), 'PLG_ACTIONLOG_JOOMLA_USER_UPDATE', $context, $user->id);
	}
}
