<?php
/** Run: php docs/tests/media-webp.php (no application or database). */
PHP_SAPI === 'cli' or die;
define('_JEXEC', 1);
define('JPATH_BASE', dirname(__DIR__, 2));
require JPATH_BASE . '/includes/defines.php';
require JPATH_LIBRARIES . '/import.legacy.php';
require JPATH_LIBRARIES . '/cms.php';
restore_exception_handler();
require JPATH_ADMINISTRATOR . '/components/com_media/helpers/media.php';
require JPATH_ADMINISTRATOR . '/components/com_media/models/list.php';

class MediaWebpTestModel extends MediaModelList
{
	public function __construct() {}
	public function getState($property = null, $default = null)
	{
		return $property === 'folder' ? '' : $default;
	}
}

$fixture = sys_get_temp_dir() . '/joomla-media-webp-' . bin2hex(random_bytes(8));
mkdir($fixture, 0700);
define('COM_MEDIA_BASE', $fixture);
$image = base64_decode('UklGRiQAAABXRUJQVlA4IBgAAAAwAQCdASoBAAEAAUAmJaQAA3AA/v02aAA=');
file_put_contents($fixture . '/image.webp', $image);
file_put_contents($fixture . '/uppercase.WEBP', $image);
file_put_contents($fixture . '/document.txt', 'A document');

try
{
	$list = (new MediaWebpTestModel)->getList();

	if (array_column($list['images'], 'name') !== array('image.webp', 'uppercase.WEBP')
		|| array_column($list['docs'], 'name') !== array('document.txt'))
	{
		throw new RuntimeException('WebP files must appear as selectable images, not documents.');
	}

	foreach ($list['images'] as $file)
	{
		if ($file->width !== 1 || $file->height !== 1 || $file->mime !== 'image/webp' || !(new JHelperMedia)->isImage($file->name))
		{
			throw new RuntimeException('WebP dimensions, MIME type or helper classification are incorrect.');
		}
	}

	echo "PASS: lowercase/uppercase WebP selection, dimensions and MIME type; documents remain separate.\n";
}
finally
{
	unlink($fixture . '/image.webp');
	unlink($fixture . '/uppercase.WEBP');
	unlink($fixture . '/document.txt');
	rmdir($fixture);
}
