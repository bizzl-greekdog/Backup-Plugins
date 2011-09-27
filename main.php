<?php
/*
 Plugin Name:	Backup Plugins
 Plugin URI:
 Description:	Backs your blog's active plugins and themes up
 Version:		1.0.0
 Author:		Benjamin Kleiner
 Author URI:	https://github.com/bizzl-greekdog
 License:		LGPL3
 */

if (!function_exists('join_path')) {

	function join_path() {
		$fuck = func_get_args();
		for ($i = 0; $i < count($fuck); $i++)
			if (is_array($fuck[$i]))
				array_splice($fuck, $i, 1, $fuck[$i]);
		$f = implode(DIRECTORY_SEPARATOR, $fuck);
		return preg_replace('/(?<!:)\\' . DIRECTORY_SEPARATOR . '+/', DIRECTORY_SEPARATOR, $f);
	}

}


if (!function_exists('button')) {

	function button($text, $class = false, $href = false) {
		if ($class)
			$class = "button-$class";
		else
			$class = 'button';
		if ($href)
			return sprintf('<a class="%s" href="%s">%s</a>', $class, $href, $text);
		else
			return sprintf('<button class="%s">%s</button>', $class, $text);
	}

}


if (!function_exists('error')) {

	function error($error) {
		echo '<div class="error">' . $error . '</div>';
		return false;
	}

}


if (!function_exists('rglob')) {

	/**
	 * @see http://snipplr.com/view/16233/
	 */
	function rglob($pattern, $flags = 0, $path = '') {
	    if (!$path && ($dir = dirname($pattern)) != '.') {
	        if ($dir == '\\' || $dir == '/') $dir = '';
	        return rglob(basename($pattern), $flags, $dir . '/');
	    }
	    $paths = glob($path . '*', GLOB_ONLYDIR | GLOB_NOSORT);
	    $files = glob($path . $pattern, $flags);
	    foreach ($paths as $p)
	    	$files = array_merge($files, rglob($pattern, $flags, $p . '/'));
	    return $files;
	}

}

class WP_Backup_Plugins {

	protected static $types = array(
		'mu' => array('root' => MUPLUGINDIR, 'name' => false),
		'local' => array('root' => PLUGINDIR, 'name' => false),
		'dropins' => array('root' => WP_CONTENT_DIR, 'name' => false),
		'theme' => array('root' => false, 'name' => false),
		'network' => array('root' => PLUGINDIR, 'name' => false)
	);

	public static function init() {
		add_action('admin_menu', array(
				__CLASS__,
				'menu_init'
		));
		
		self::$types['mu']['name'] = __('Must-Use');
		self::$types['local']['name'] = __('Local');
		self::$types['dropins']['name'] = __('Drop-Ins');
		self::$types['theme']['name'] = __('Theme');
		self::$types['theme']['root'] = get_theme_root();
		self::$types['network']['name'] = __('Network');
		
		foreach (self::$types as $type => $data)
			self::$types[$type]['root'] = str_replace(ABSPATH, '', $data['root']);
	}

	public static function menu_init() {
		add_submenu_page('tools.php', __('Backup plugins'), __('Backup plugins'), 'edit_plugins', 'backup-plugins', array(
				__CLASS__,
				'backup_plugins'
		));
	}

	private static function cleanse($plugin_file, $plugin_dir) {
		$plugin_file = str_replace(ABSPATH . $plugin_dir . DIRECTORY_SEPARATOR, '', $plugin_file);
		if (strpos($plugin_file, DIRECTORY_SEPARATOR) !== false)
			$plugin_file = dirname($plugin_file);
		return $plugin_file;
	}
	
	private static function glob_plugin($plugin_file, &$target, $plugin_dir) {
		$plugin_file = self::cleanse($plugin_file, $plugin_dir);
		$abspath = ABSPATH . $plugin_dir . DIRECTORY_SEPARATOR;
		$target[] = $plugin_dir . DIRECTORY_SEPARATOR . $plugin_file;
		if (!is_dir($abspath . $plugin_file))
			return;
		$glob = rglob('*', 0, $abspath . $plugin_file . DIRECTORY_SEPARATOR);
		foreach ($glob as $file)
			$target[] = $plugin_dir . DIRECTORY_SEPARATOR . str_replace($abspath, '', $file);
	}

	public static function archive($zip_name, $plugins) {
		if (file_exists($zip_name) && !unlink($zip_name))
			return error(sprintf(__('Couldn\'t delete <em>%s</em>!'), $zip_name));
		$files_for_zip = array();
		foreach ($plugins as $type => $files) {
			$files_for_zip[] = $r = self::$types[$type]['root'];
			foreach ($files as $plugin_file)
				self::glob_plugin($plugin_file, $files_for_zip, $r);
		}
		$files_for_zip = array_unique($files_for_zip);
		sort($files_for_zip);

		$zip = new ZipArchive();

		if ($zip->open($zip_name, ZIPARCHIVE::CREATE) !== true)
			return error(sprintf(__('Couldn\'t create <em>%s</em>!'), $zip_name));

		foreach ($files_for_zip as $file) {
			$r = false;
			if (is_dir(ABSPATH . $file))
				$r = $zip->addEmptyDir($file);
			else
				$r = $zip->addFile(ABSPATH . $file, $file);
			if (!$r)
				error(sprintf(__('Couldn\'t add file <em>%s</em> to archive <em>%s</em>!'), $file, basename($zip_name)));
		}
		$zip->close();
	}

	public static function backup_plugins() {
		echo '<div class="wrap">';
		echo '<h2>' . __('Plugin Backup') . '</h2>';
		
		$plugins = array(
			'mu' => wp_get_mu_plugins(),
			'local' => wp_get_active_and_valid_plugins(),
			'dropins' => array(),
			'theme' => array_unique(array(
				get_theme_root() . DIRECTORY_SEPARATOR . get_stylesheet(),
				get_theme_root() . DIRECTORY_SEPARATOR . get_template()
			))
		);
		if (is_multisite())
			$plugins['network'] = wp_get_active_network_plugins();
		
		foreach (_get_dropins() as $name => $dropin) {
			$path = join_path(WP_CONTENT_DIR, $name);
			if (file_exists($path))
				$plugins['dropins'][] = $path;
		}
		
		$upload = wp_upload_dir();
		
		$zip_name = join_path($upload['basedir'], 'Plugins for ' . get_bloginfo('name') . '.zip');
		$create_text = __('Create archive');
		$create_class = 'primary';
		if (isset($_REQUEST['create_archive']))
			self::archive($zip_name, $plugins);
		if (file_exists($zip_name)) {
			echo '<p>';
			printf('Archive was last created on %s', date_i18n(get_option('links_updated_date_format'), fileatime($zip_name)));
			echo '</p>';
			echo button(
				__('Download archive'),
				'primary',
				str_replace(ABSPATH, get_bloginfo('siteurl') . '/', $zip_name)
			);
			$create_text = __('Update archive');
			$create_class = 'secondary';
		}
		echo button(
			$create_text,
			$create_class,
			admin_url('tools.php?page=backup-plugins&amp;create_archive=1')
		);
		echo '</div>';
	}

}

WP_Backup_Plugins::init();
