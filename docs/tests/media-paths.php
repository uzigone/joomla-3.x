<?php
/**
 * Run: php docs/tests/media-paths.php
 * Exercises the real filesystem and media list model without booting a site or connecting to a database.
 */
PHP_SAPI === 'cli' or die;
define('_JEXEC', 1);
define('JPATH_BASE', dirname(__DIR__, 2));
require JPATH_BASE . '/includes/defines.php';
require JPATH_LIBRARIES . '/import.legacy.php';
require JPATH_LIBRARIES . '/cms.php';
restore_exception_handler();
require JPATH_ADMINISTRATOR . '/components/com_media/helpers/media.php';
require JPATH_ADMINISTRATOR . '/components/com_media/models/list.php';

class MediaPathTestModel extends MediaModelList
{
	public function __construct() {}
	public function getState($property = null, $default = null)
	{
		return $property === 'folder' ? JFilterInput::getInstance()->clean('../images-private', 'path') : $default;
	}
}

function check($condition, $message)
{
	if (!$condition)
	{
		throw new RuntimeException($message);
	}
}

$fixture = sys_get_temp_dir() . '/joomla-media-paths-' . bin2hex(random_bytes(8));
mkdir($fixture, 0700);
mkdir($fixture . '/images', 0700);
mkdir($fixture . '/images/child', 0700);
mkdir($fixture . '/images-private', 0700);
file_put_contents($fixture . '/images/inside.txt', 'Inside fixture');
file_put_contents($fixture . '/images-private/outside.txt', 'Outside fixture');
define('COM_MEDIA_BASE', $fixture . '/images');

try
{
	$list = (new MediaPathTestModel)->getList();
	check(array_column($list['docs'], 'name') === array('inside.txt'), 'Listing a sibling must fall back to the media root.');

	$cases = array(
		array(COM_MEDIA_BASE, COM_MEDIA_BASE, true),
		array(COM_MEDIA_BASE . '/child', COM_MEDIA_BASE . '/', true),
		array(COM_MEDIA_BASE . '/inside.txt', COM_MEDIA_BASE, true),
		array(COM_MEDIA_BASE . '/child/../inside.txt', COM_MEDIA_BASE, true),
		array(COM_MEDIA_BASE . '/../images-private', COM_MEDIA_BASE, false),
		array(COM_MEDIA_BASE . '/../images-private/outside.txt', COM_MEDIA_BASE, false),
		array(COM_MEDIA_BASE . '/missing', COM_MEDIA_BASE, false),
		array(COM_MEDIA_BASE, $fixture . '/missing', false),
	);

	foreach ($cases as $case)
	{
		check(JHelperMedia::isPathWithinBase($case[0], $case[1]) === $case[2], 'Unexpected containment result for ' . $case[0]);
	}

	if (DIRECTORY_SEPARATOR === '/')
	{
		check(JHelperMedia::isPathWithinBase(COM_MEDIA_BASE, '/'), 'A filesystem root must remain a valid base.');
	}

	if (function_exists('symlink') && @symlink($fixture . '/images-private', COM_MEDIA_BASE . '/outside-link'))
	{
		check(!JHelperMedia::isPathWithinBase(COM_MEDIA_BASE . '/outside-link/outside.txt', COM_MEDIA_BASE), 'An escaping symlink must be rejected.');
		check(symlink(COM_MEDIA_BASE . '/child', COM_MEDIA_BASE . '/inside-link'), 'Could not create inside symlink.');
		check(JHelperMedia::isPathWithinBase(COM_MEDIA_BASE . '/inside-link', COM_MEDIA_BASE), 'An internal symlink must remain usable.');
	}
	else
	{
		echo "SKIP: symlinks are unavailable on this system.\n";
	}

	echo "PASS: media listing, root/child paths, sibling boundaries, missing paths and symlinks.\n";
}
finally
{
	foreach (array('/images/outside-link', '/images/inside-link') as $link)
	{
		if (is_link($fixture . $link))
		{
			unlink($fixture . $link);
		}
	}

	unlink($fixture . '/images/inside.txt');
	unlink($fixture . '/images-private/outside.txt');
	rmdir($fixture . '/images/child');
	rmdir($fixture . '/images');
	rmdir($fixture . '/images-private');
	rmdir($fixture);
}
