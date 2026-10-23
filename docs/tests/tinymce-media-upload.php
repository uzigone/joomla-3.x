<?php
/**
 * Run once for each case: own, category, denied, administrator.
 * Example: php docs/tests/tinymce-media-upload.php own
 * Executes the real editor options and com_media access gate with in-memory users/configuration.
 * Layout rendering, session tokens and controller dispatch are doubled; no upload, plugin hook or database runs.
 */
PHP_SAPI === 'cli' or die;
define('_JEXEC', 1);
define('JPATH_ROOT', dirname(__DIR__, 2));
define('JPATH_PLATFORM', JPATH_ROOT . '/libraries');
define('JPATH_ADMINISTRATOR', JPATH_ROOT . '/administrator');
define('JPATH_COMPONENT_ADMINISTRATOR', JPATH_ROOT . '/administrator/components/com_media');
require JPATH_PLATFORM . '/vendor/autoload.php';
require JPATH_PLATFORM . '/src/Document/Document.php';

use Joomla\Registry\Registry;

function check($condition, $message)
{
	if (!$condition)
	{
		throw new RuntimeException($message);
	}
}

class JPlugin { public $params; }
class JLayoutHelper { public static function render($layout, $data) { return ''; } }
class JText
{
	public static function script($key) {}
	public static function _($key) { return $key; }
}
class JSession { public static function getFormToken() { return 'fixture-token'; } }
class JLoader { public static function register($name, $path) {} }
class JAccessExceptionNotallowed extends RuntimeException {}
class UploadDispatchReached extends RuntimeException {}
class JControllerLegacy
{
	public static function getInstance($name, $options)
	{
		throw new UploadDispatchReached;
	}
}
class JComponentHelper
{
	public static function getParams($name)
	{
		return new Registry(array('image_path' => 'images', 'file_path' => 'media'));
	}
}
class JUri
{
	public static function base($pathOnly = false)
	{
		return '/subdir' . (JFactory::$app->administrator ? '/administrator' : '');
	}
	public static function root($pathOnly = false) { return '/subdir'; }
	public static function getInstance() { return new self; }
	public function getHost() { return 'example.test'; }
}
class UploadTestInput
{
	public $values = array('option' => 'com_content');
	public function get($key, $default = null, $filter = null)
	{
		return isset($this->values[$key]) ? $this->values[$key] : $default;
	}
}
class UploadTestUser
{
	public $id = 73;
	public $canCreate = true;
	public $canEdit = true;
	public $manager = false;
	public function authorise($action, $asset)
	{
		return ($asset === 'com_media' && (($action === 'core.create' && $this->canCreate) || ($action === 'core.manage' && $this->manager)))
			|| ($asset === 'com_content.article.42' && $action === 'core.edit.own' && $this->canEdit);
	}
	public function getAuthorisedCategories($asset, $action)
	{
		return $this->canEdit && $asset === 'com_content' && $action === 'core.create' ? array(7) : array();
	}
}
class UploadTestApplication
{
	public $administrator = false;
	public $input;
	public function isClient($client) { return $client === ($this->administrator ? 'administrator' : 'site'); }
}
class UploadTestLanguage
{
	public function getTag() { return 'en-GB'; }
	public function isRtl() { return false; }
}
class JFactory
{
	public static $app;
	public static $user;
	public static $document;
	public static function getApplication() { return self::$app; }
	public static function getUser() { return self::$user; }
	public static function getDocument() { return self::$document; }
	public static function getLanguage() { return new UploadTestLanguage; }
}

require JPATH_ROOT . '/plugins/editors/tinymce_latest/tinymce_latest.php';
class UploadTestEditor extends PlgEditorTinymce_latest
{
	public function __construct()
	{
		$this->app = JFactory::$app;
		$this->params = new Registry;
	}
	protected function getSetParams($user)
	{
		return new Registry(array('toolbar1' => array('image'), 'content_css' => 0, 'path' => 'articles'));
	}
}

$case = isset($argv[1]) ? $argv[1] : 'own';
check(in_array($case, array('own', 'category', 'denied', 'administrator'), true), 'Unknown case.');
JFactory::$app = new UploadTestApplication;
JFactory::$app->input = new UploadTestInput;
JFactory::$app->administrator = $case === 'administrator';
JFactory::$user = new UploadTestUser;
JFactory::$user->canEdit = $case !== 'denied';
JFactory::$user->manager = $case === 'administrator';
JFactory::$document = new Joomla\CMS\Document\Document;
$editor = new UploadTestEditor;

if ($case === 'category')
{
	JFactory::$app->input->values = array('option' => 'com_categories', 'extension' => 'com_content.article');
}

$editor->onDisplay('jform[articletext]', '', 600, 400, 20, 10, false, null,
	$case === 'category' ? null : 'com_content.article.42', 73);
$options = JFactory::$document->getScriptOptions('plg_editor_tinymce_latest');
$defaults = $options['tinyMCE']['default'];
$upload = isset($options['tinyMCE']['articletext']['joomla']['upload'])
	? $options['tinyMCE']['articletext']['joomla']['upload'] : $defaults['joomla']['upload'];
parse_str(parse_url($upload['url'], PHP_URL_QUERY), $query);
check(isset($query['asset']) && $query['asset'] === ($case === 'category' ? 'com_content' : 'com_content.article.42'), 'Upload is missing the edited asset context.');
check(isset($query['author']) && $query['author'] === '73', 'Upload is missing the author context.');
check(isset($query['pop_up']) && $query['pop_up'] === '1', 'Upload must use the configured images folder.');
check($upload['token'] === 'fixture-token' && $upload['folder'] === 'articles', 'Token or upload folder was lost.');
check(strpos($upload['url'], JUri::base(true) . '/index.php?') === 0, 'Wrong frontend/administrator URL.');

// Another editor must not inherit the first editor's asset or author, or change its options.
$editor->onDisplay('jform[second]', '', 600, 400, 20, 10, false, null, 'com_content.article.99', 81);
$options = JFactory::$document->getScriptOptions('plg_editor_tinymce_latest');
parse_str(parse_url($options['tinyMCE']['second']['joomla']['upload']['url'], PHP_URL_QUERY), $second);
check($second['asset'] === 'com_content.article.99' && $second['author'] === '81', 'Editors share the wrong upload context.');
check($options['tinyMCE']['articletext']['joomla']['upload'] === $upload, 'Second editor changed the first editor.');

// No create permission must still disable uploads.
JFactory::$user->canCreate = false;
JFactory::$document = new Joomla\CMS\Document\Document;
$editor->onDisplay('jform[restricted]', '', 600, 400, 20, 10, false, null, 'com_content.article.42', 73);
$restricted = JFactory::$document->getScriptOptions('plg_editor_tinymce_latest');
check(empty($restricted['tinyMCE']['default']['joomla']['upload'])
	&& !$restricted['tinyMCE']['default']['automatic_uploads'], 'Uploads enabled without media create permission.');
JFactory::$user->canCreate = true;

// Let the real component entry point evaluate the generated URL and select the media base.
JFactory::$app->input->values = $query;
try
{
	require JPATH_COMPONENT_ADMINISTRATOR . '/media.php';
	throw new RuntimeException('Expected the access gate or controller dispatch.');
}
catch (JAccessExceptionNotallowed $e)
{
	check($case === 'denied' && $e->getCode() === 403, 'An authorized editor was denied access.');
}
catch (UploadDispatchReached $e)
{
	check($case !== 'denied', 'Unauthorized user passed the access gate.');
	check(COM_MEDIA_BASE === JPATH_ROOT . '/images', 'Upload selected the files directory instead of images.');
}

echo 'PASS: ' . $case . " upload context/access gate, per-editor isolation, image base and disabled uploads.\n";
