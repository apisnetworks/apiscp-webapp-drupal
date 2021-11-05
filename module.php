<?php
	declare(strict_types=1);
	/**
	 *  +------------------------------------------------------------+
	 *  | apnscp                                                     |
	 *  +------------------------------------------------------------+
	 *  | Copyright (c) Apis Networks                                |
	 *  +------------------------------------------------------------+
	 *  | Licensed under Artistic License 2.0                        |
	 *  +------------------------------------------------------------+
	 *  | Author: Matt Saladna (msaladna@apisnetworks.com)           |
	 *  +------------------------------------------------------------+
	 */

	use Module\Support\Webapps\VersionFetcher\Github;
	use Opcenter\Versioning;

	/**
	 * Drupal drush interface
	 *
	 * @package core
	 */
	class Drupal_Module extends \Module\Support\Webapps
	{
		const APP_NAME = 'Drupal';

		// primary domain document root
		const DRUPAL_CLI = '/usr/share/pear/drupal.phar';
		const DEFAULT_BRANCH = '9.x';
		const DRUPAL_MAJORS = ['8.x', '9.x', '10.x'];
		// latest release
		const DRUPAL_CLI_URL = 'https://github.com/drush-ops/drush/releases/download/8.4.8/drush.phar';
		const VERSION_CHECK_URL = 'https://updates.drupal.org/release-history';
		const DEFAULT_VERSION_LOCK = 'major';

		const DRUPAL_COMPATIBILITY = [
			'8'  => '8.x',
			'9'  => '8.4',
			'10' => ''
		];
		protected $aclList = array(
			'max' => array('sites/*/files')
		);

		/**
		 * void __construct(void)
		 *
		 * @ignore
		 */
		public function __construct()
		{
			parent::__construct();
		}

		/**
		 * Install WordPress into a pre-existing location
		 *
		 * @param string $hostname domain or subdomain to install WordPress
		 * @param string $path     optional path under hostname
		 * @param array  $opts     additional install options
		 * @return bool
		 */
		public function install(string $hostname, string $path = '', array $opts = array()): bool
		{
			if (!$this->mysql_enabled()) {
				return error('%(what)s must be enabled to install %(app)s',
					['what' => 'MySQL', 'app' => static::APP_NAME]);
			}
			$docroot = $this->getAppRoot($hostname, $path);
			if (!$docroot) {
				return error('failed to install Drupal');
			}

			if (!$this->parseInstallOptions($opts, $hostname, $path)) {
				return false;
			}

			// can't fetch translation file from ftp??
			// don't worry about it for now
			if (!isset($opts['locale'])) {
				$opts['locale'] = 'us';
			}

			if (!isset($opts['dist'])) {
				$opts['profile'] = 'standard';
				$opts['dist'] = 'drupal';
				if (isset($opts['version'])) {
					if (strcspn($opts['version'], '.0123456789x')) {
						return error('invalid version number, %s', $opts['version']);
					}
					$opts['dist'] .= '-' . $opts['version'];
				}

			} else if (!isset($opts['profile'])) {
				$opts['profile'] = $opts['dist'];
			}

			$cmd = 'dl %(dist)s';

			$tmpdir = '/tmp/drupal' . crc32((string)\Util_PHP::random_int());
			$args = array(
				'tempdir' => $tmpdir,
				'path'    => $docroot,
				'dist'    => $opts['dist']
			);
			/**
			 * drupal expects destination dir to exist
			 * move /tmp/<RANDOM NAME>/drupal to <DOCROOT> instead
			 * of downloading to <DOCROOT>/drupal and moving everything down 1
			 */
			$this->file_create_directory($tmpdir);
			$ret = $this->_exec('/tmp', $cmd . ' --drupal-project-rename --destination=%(tempdir)s -q', $args);

			if (!$ret['success']) {
				return error('failed to download Drupal - out of space? Error: `%s\'',
					coalesce($ret['stderr'], $ret['stdout'])
				);
			}
			if ($this->file_exists($docroot)) {
				$this->file_delete($docroot, true);
			}

			$this->file_purge();
			$ret = $this->file_rename($tmpdir . '/drupal', $docroot);
			$this->file_delete($tmpdir, true);
			if (!$ret) {
				return error("failed to move Drupal install to `%s'", $docroot);
			}

			if (isset($opts['site-email']) && !preg_match(Regex::EMAIL, $opts['site-email'])) {
				return error("invalid site email `%s' provided", $opts['site-email']);
			}

			if (!isset($opts['site-email'])) {
				// default to active domain, hope it's valid!
				if (false === strpos($hostname, '.')) {
					$hostname .= '.' . $this->domain;
				}
				$split = $this->web_split_host($hostname);
				if (!$this->email_address_exists('postmaster', $split['domain'])) {
					if (!$this->email_transport_exists($split['domain'])) {
						warn("email is not configured for domain `%s', messages sent from installation may " .
							'be unrespondable', $split['domain']);
					} else if ($this->email_add_alias('postmaster', $split['domain'], $opts['email'])) {
						info("created `postmaster@%s' address for Drupal mailings that " .
							"will forward to `%s'", $split['domain'], $opts['email']);
					} else {
						warn("failed to create Drupal postmaster address `postmaster@%s', messages " .
							'sent from installation may be unrespondable', $split['domain']);
					}
				}
				$opts['site-email'] = 'postmaster@' . $split['domain'];
			}

			$db = \Module\Support\Webapps\DatabaseGenerator::mysql($this->getAuthContext(), $hostname);
			if (!$db->create()) {
				return false;
			}

			$proto = 'mysql';
			if (!empty($opts['version']) && version_compare($opts['version'], '7.0', '<')) {
				$proto = 'mysqli';
			}
			$dburi = $proto . '://' . $db->username . ':' .
				$db->password . '@' . $db->hostname . '/' . $db->database;

			if (!isset($opts['title'])) {
				$opts['title'] = 'A Random Drupal Install';
			}

			$autogenpw = false;
			if (!isset($opts['password'])) {
				$autogenpw = true;
				$opts['password'] = \Opcenter\Auth\Password::generate();
				info("autogenerated password `%s'", $opts['password']);
			}

			info("setting admin user to `%s'", $opts['user']);

			$xtra = array(
				"install_configure_form.update_status_module='array(FALSE,FALSE)'"
			);
			// drush reqs name if dist not drupal otherwise
			// getPath() on null error

			if ($opts['dist'] === 'drupal') {
				$dist = '';
			} else {
				$dist = $opts['dist'];
			}
			$args = array(
				'dist'         => $dist,
				'profile'      => $opts['profile'],
				'dburi'        => $dburi,
				'account-name' => $opts['user'],
				'account-pass' => $opts['password'],
				'account-mail' => $opts['email'],
				'locale'       => $opts['locale'],
				'site-mail'    => $opts['site-email'],
				'title'        => $opts['title'],
				'xtraopts'     => implode(' ', $xtra)
			);

			$ret = $this->_exec($docroot,
				'site-install %(profile)s -q --db-url=%(dburi)s --account-name=%(account-name)s ' .
				'--account-pass=%(account-pass)s --account-mail=%(account-mail)s ' .
				'--site-mail=%(site-mail)s --site-name=%(title)s %(xtraopts)s', $args);

			if (!$ret['success']) {
				info('removing temporary files');
				$this->file_delete($docroot, true);
				$db->rollback();

				return error('failed to install Drupal: %s', $ret['stderr']);
			}
			// by default, let's only open up ACLs to the bare minimum
			$this->file_touch($docroot . '/.htaccess');
			$this->removeInvalidDirectives($docroot, 'sites/default/files/');

			$this->initializeMeta($docroot, $opts);

			$this->fortify($hostname, $path, 'max');
			$fqdn = $this->web_normalize_hostname($hostname);
			/**
			 * Make sure RewriteBase is present, move to Webapps?
			 */
			$this->fixRewriteBase($docroot, $path);

			$this->_postInstallTrustedHost($dist, $hostname, $docroot);

			if (!empty($opts['ssl'])) {
				// @todo force redirect to HTTPS
			}

			$this->notifyInstalled($hostname, $path, $opts);

			return info('%(app)s installed - confirmation email with login info sent to %(email)s',
				['app' => static::APP_NAME, 'email' => $opts['email']]);
		}

		private function _exec(?string $path, $cmd, array $args = array())
		{
			// client may override tz, propagate to bin
			$tz = date_default_timezone_get();
			$debug = is_debug() ? ' -v' : '';
			$cli = 'php -d pdo_mysql.default_socket=' . escapeshellarg(ini_get('mysqli.default_socket')) .
				' -d date.timezone=' . $tz . ' -d memory_limit=192m ' . self::DRUPAL_CLI . $debug . ' -y';
			if (!is_array($args)) {
				$args = func_get_args();
				array_shift($args);
			}
			$user = $this->username;
			if ($path) {
				$user = parent::getDocrootUser($path);
				$cli = 'cd %(path)s && ' . $cli;
				$args['path'] = $path;
			}
			$cmd = $cli . ' ' . $cmd;
			$ret = $this->pman_run($cmd, $args, null, ['user' => $user]);
			if (0 === strncmp((string)coalesce($ret['stderr'], $ret['stdout']), 'Error:', 6)) {
				// move stdout to stderr on error for consistency
				$ret['success'] = false;
				if (!$ret['stderr']) {
					$ret['stderr'] = $ret['stdout'];
				}

			}

			return $ret;
		}

		/**
		 * Get installed version
		 *
		 * @param string $hostname
		 * @param string $path
		 * @return null|string version number
		 */
		public function get_version(string $hostname, string $path = ''): ?string
		{

			if (!$this->valid($hostname, $path)) {
				return null;
			}
			$docroot = $this->getAppRoot($hostname, $path);

			return $this->_getVersion($docroot);
		}

		/**
		 * Location is a valid WP install
		 *
		 * @param string $hostname or $docroot
		 * @param string $path
		 * @return bool
		 */
		public function valid(string $hostname, string $path = ''): bool
		{
			if ($hostname[0] === '/') {
				$docroot = $hostname;
			} else {
				$docroot = $this->getAppRoot($hostname, $path);
				if (!$docroot) {
					return false;
				}
			}

			return $this->file_exists($docroot . '/sites/default')
				|| $this->file_exists($docroot . '/sites/all');
		}

		/**
		 * Get version using exact docroot
		 *
		 * @param $docroot
		 * @return string
		 */
		protected function _getVersion($docroot): ?string
		{
			$ret = $this->_exec($docroot, 'status --format=json');
			if (!$ret['success']) {
				return null;
			}

			$output = json_decode($ret['stdout'], true);

			return $output['drupal-version'] ?? null;
		}

		/**
		 * Add trusted_host_patterns if necessary
		 *
		 * @param $version
		 * @param $hostname
		 * @param $docroot
		 * @return bool
		 */
		private function _postInstallTrustedHost($version, $hostname, $docroot): bool
		{
			if (version_compare((string)$version, '8.0', '<')) {
				return true;
			}
			$file = $docroot . '/sites/default/settings.php';
			$content = $this->file_get_file_contents($file);
			if (!$content) {
				return error('unable to add trusted_host_patterns configuration - cannot get ' .
					"Drupal configuration for `%s'", $hostname);
			}
			$content .= "\n\n" .
				'/** in the event the domain name changes, trust site configuration */' . "\n" .
				'$settings["trusted_host_patterns"] = array(' . "\n" .
				"\t" . "'^(www\.)?' . " . 'str_replace(".", "\\\\.", $_SERVER["DOMAIN"]) . ' . "'$'" . "\n" .
				');' . "\n";

			return $this->file_put_file_contents($file, $content, true, true);
		}

		/**
		 * Install and activate plugin
		 *
		 * @param string $hostname domain or subdomain of wp install
		 * @param string $path     optional path component of wp install
		 * @param string $plugin   plugin name
		 * @param string $version  optional plugin version
		 * @return bool
		 */
		public function install_plugin(string $hostname, string $path, string $plugin, string $version = ''): bool
		{
			$docroot = $this->getAppRoot($hostname, $path);
			if (!$docroot) {
				return error('invalid Drupal location');
			}
			$dlplugin = $plugin;
			if ($version) {
				if (false === strpos($version, '-')) {
					// Drupal seems to like <major>-x naming conventions
					$version .= '-x';
				}
				$dlplugin .= '-' . $version;
			}
			$args = array($plugin);
			$ret = $this->_exec($docroot, 'pm-download -y %s', $args);
			if (!$ret['success']) {
				return error("failed to install plugin `%s': %s", $plugin, $ret['stderr']);
			}

			if (!$this->enable_plugin($hostname, $path, $plugin)) {
				return warn("downloaded plugin `%s' but failed to activate: %s", $plugin, $ret['stderr']);
			}
			info("installed plugin `%s'", $plugin);

			return true;
		}

		public function enable_plugin(string $hostname, ?string $path, $plugin)
		{
			$docroot = $this->getAppRoot($hostname, $path);
			if (!$docroot) {
				return error('invalid Drupal location');
			}
			$ret = $this->_exec($docroot, 'pm-enable -y %s', array($plugin));
			if (!$ret) {
				return error("failed to enable plugin `%s': %s", $plugin, $ret['stderr']);
			}

			return true;
		}

		/**
		 * Uninstall a plugin
		 *
		 * @param string      $hostname
		 * @param string      $path
		 * @param string      $plugin plugin name
		 * @param bool|string $force  delete even if plugin activated
		 * @return bool
		 */
		public function uninstall_plugin(string $hostname, string $path, string $plugin, bool $force = false): bool
		{
			$docroot = $this->getAppRoot($hostname, $path);
			if (!$docroot) {
				return error('invalid Drupal location');
			}

			$args = array($plugin);

			if ($this->plugin_active($hostname, $path, $plugin)) {
				if (!$force) {
					return error("plugin `%s' is active, disable first");
				}
				$this->disable_plugin($hostname, $path, $plugin);
			}

			$cmd = 'pm-uninstall %s';

			$ret = $this->_exec($docroot, $cmd, $args);

			if (!$ret['stdout'] || !strncmp($ret['stdout'], 'Warning:', strlen('Warning:'))) {
				return error("failed to uninstall plugin `%s': %s", $plugin, $ret['stderr']);
			}
			info("uninstalled plugin `%s'", $plugin);

			return true;
		}

		public function plugin_active(string $hostname, ?string $path, $plugin)
		{
			$docroot = $this->getAppRoot($hostname, (string)$path);
			if (!$docroot) {
				return error('invalid Drupal location');
			}
			$plugin = $this->plugin_status($hostname, (string)$path, $plugin);

			return $plugin['status'] === 'enabled';
		}

		public function plugin_status(string $hostname, string $path = '', string $plugin = null): ?array
		{
			$docroot = $this->getAppRoot($hostname, $path);
			if (!$docroot) {
				return error('invalid Drupal location');
			}
			$cmd = 'pm-info --format=json %(plugin)s';
			$ret = $this->_exec($docroot, $cmd, ['plugin' => $plugin]);
			if (!$ret['success']) {
				return null;
			}
			$plugins = [];
			foreach (json_decode($ret['stdout'], true) as $name => $meta) {
				$plugins[$name] = [
					'version' => $meta['version'],
					'next'    => null,
					'current' => true,
					'max'     => $meta['version']
				];
			}

			return $plugin ? array_pop($plugins) : $plugins;
		}

		public function disable_plugin($hostname, ?string $path, $plugin)
		{
			$docroot = $this->getAppRoot($hostname, (string)$path);
			if (!$docroot) {
				return error('invalid Drupal location');
			}
			$ret = $this->_exec($docroot, 'pm-disable -y %s', array($plugin));
			if (!$ret) {
				return error("failed to disable plugin `%s': %s", $plugin, $ret['stderr']);
			}
			info("disabled plugin `%s'", $plugin);

			return true;
		}

		/**
		 * Recovery mode to disable all plugins
		 *
		 * @param string $hostname subdomain or domain of WP
		 * @param string $path     optional path
		 * @return bool
		 */
		public function disable_all_plugins(string $hostname, string $path = ''): bool
		{
			$docroot = $this->getAppRoot($hostname, $path);
			if (!$docroot) {
				return error('failed to determine path');
			}
			$plugins = array();
			$installed = $this->list_all_plugins($hostname, $path);
			if (!$installed) {
				return true;
			}
			foreach ($installed as $plugin => $info) {
				if (strtolower($info['status']) !== 'enabled') {
					continue;
				}
				$this->disable_plugin($hostname, $path, $plugin);
				$plugins[] = $info['name'];

			}
			if ($plugins) {
				info("disabled plugins: `%s'", implode(',', $plugins));
			}

			return true;
		}

		public function list_all_plugins($hostname, $path = '', $status = '')
		{
			$docroot = $this->getAppRoot($hostname, $path);
			if (!$docroot) {
				return error('invalid Drupal location');
			}
			if ($status) {
				$status = strtolower($status);
				$status = '--status=' . $status;
			}
			$ret = $this->_exec($docroot, 'pm-list --format=json --no-core %s', array($status));
			if (!$ret['success']) {
				return error('failed to enumerate plugins: %s', $ret['stderr']);
			}

			return json_decode($ret['stdout'], true);
		}

		/**
		 * Uninstall Drupal from a location
		 *
		 * @param string $hostname
		 * @param string $path
		 * @param string $delete
		 * @return bool
		 * @internal param string $deletefiles remove all files under docroot
		 */
		public function uninstall(string $hostname, string $path = '', string $delete = 'all'): bool
		{
			return parent::uninstall($hostname, $path, $delete);
		}

		/**
		 * Get database configuration for a blog
		 *
		 * @param string $hostname domain or subdomain of Drupal
		 * @param string $path     optional path
		 * @return array|bool
		 */
		public function db_config(string $hostname, string $path = '')
		{
			$docroot = $this->getAppRoot($hostname, $path);
			if (!$docroot) {
				return error('failed to determine Drupal');
			}
			$code = 'include("./sites/default/settings.php"); $conf = $databases["default"]["default"]; print serialize(array("user" => $conf["username"], "password" => $conf["password"], "db" => $conf["database"], "prefix" => $conf["prefix"], "host" => $conf["host"]));';
			$cmd = 'cd %(path)s && php -r %(code)s';
			$ret = $this->pman_run($cmd, array('path' => $docroot, 'code' => $code));

			if (!$ret['success']) {
				return error("failed to obtain Drupal configuration for `%s'", $docroot);
			}

			return \Util_PHP::unserialize(trim($ret['stdout']));
		}

		private function _extractBranch($version)
		{
			if (substr($version, -2) === '.x') {
				return $version;
			}
			$pos = strpos($version, '.');
			if (false === $pos) {
				// sent major alone
				return $version . '.x';
			}
			$newver = substr($version, 0, $pos);

			return $newver . '.x';
		}

		/**
		 * Get all current major versions
		 *
		 * @return array
		 */
		private function _getVersions(): array
		{
			$key = 'drupal.versions';
			$cache = Cache_Super_Global::spawn();
			if (0 && false !== ($ver = $cache->get($key))) {
				return (array)$ver;
			}
			// 8.7.11+
			$versions = (new Github)->setMode('tags')->fetch('drupal/drupal');
			$cache->set($key, $versions, 43200);

			return $versions;
		}

		/**
		 * Change WP admin credentials
		 *
		 * $fields is a hash whose indices match password
		 *
		 * @param string $hostname
		 * @param string $path
		 * @param array  $fields password only field supported for now
		 * @return bool
		 */
		public function change_admin(string $hostname, string $path, array $fields): bool
		{
			$docroot = $this->getAppRoot($hostname, $path);
			if (!$docroot) {
				return warn('failed to change administrator information');
			}
			$admin = $this->get_admin($hostname, $path);

			if (!$admin) {
				return error('cannot determine admin of Drupal install');
			}

			$args = array(
				'user' => $admin
			);

			if (isset($fields['password'])) {
				$args['password'] = $fields['password'];
				$ret = $this->_exec($docroot, 'user-password --password=%(password)s %(user)s', $args);
				if (!$ret['success']) {
					return error("failed to update password for user `%s': %s", $admin, $ret['stderr']);
				}
			}

			return true;
		}

		/**
		 * Get the primary admin for a Drupal instance
		 *
		 * @param string $hostname
		 * @param string $path
		 * @return null|string admin or false on failure
		 */
		public function get_admin(string $hostname, string $path = ''): ?string
		{
			$docroot = $this->getAppRoot($hostname, $path);
			$ret = $this->_exec($docroot, 'user-information 1 --format=json');
			if (!$ret['success']) {
				warn('failed to enumerate Drupal administrative users');

				return null;
			}
			$tmp = json_decode($ret['stdout'], true);
			if (!$tmp) {
				return null;
			}
			$tmp = array_pop($tmp);

			return $tmp['name'];
		}

		/**
		 * Update core, plugins, and themes atomically
		 *
		 * @param string $hostname subdomain or domain
		 * @param string $path     optional path under hostname
		 * @param string $version
		 * @return bool
		 */
		public function update_all(string $hostname, string $path = '', string $version = null): bool
		{
			$ret = ($this->update($hostname, $path, $version) && $this->update_plugins($hostname, $path))
				|| error('failed to update all components');

			parent::setInfo($this->getDocumentRoot($hostname, $path), [
				'version' => $this->get_version($hostname, $path),
				'failed'  => !$ret
			]);

			return $ret;
		}

		/**
		 * Update Drupal to latest version
		 *
		 * @param string $hostname domain or subdomain under which WP is installed
		 * @param string $path     optional subdirectory
		 * @param string $version
		 * @return bool
		 */
		public function update(string $hostname, string $path = '', string $version = null): bool
		{
			$docroot = $this->getAppRoot($hostname, $path);
			if (!$docroot) {
				return error('update failed');
			}
			if ($this->isLocked($docroot)) {
				return error('Drupal is locked - remove lock file from `%s\' and try again', $docroot);
			}
			if ($version) {
				if (!is_scalar($version) || strcspn($version, '.0123456789x-')) {
					return error('invalid version number, %s', $version);
				}
				$current = $this->_extractBranch($version);
			} else {
				$current = $this->_extractBranch($this->get_version($hostname, $path));
				$version = Versioning::nextVersion(
					$this->get_versions(),
					$this->get_version($hostname, $path)
				);
			}

			// save .htaccess
			$htaccess = $docroot . DIRECTORY_SEPARATOR . '.htaccess';
			if ($this->file_exists($htaccess) && !$this->file_move($htaccess, $htaccess . '.bak', true)) {
				return error('upgrade failure: failed to save copy of original .htaccess');
			}
			$this->file_purge();
			$cmd = 'pm-update drupal-%(version)s';
			$args = array('version' => $version);

			$this->_setMaintenance($docroot, true, $current);
			$ret = $this->_exec($docroot, $cmd, $args);
			$this->file_purge();
			$this->_setMaintenance($docroot, false, $current);

			if ($this->file_exists($htaccess . '.bak') && !$this->file_move($htaccess . '.bak', $htaccess, true)
				&& ($this->file_purge() || true)
			) {
				warn("failed to rename backup `%s/.htaccess.bak' to .htaccess", $docroot);
			}

			parent::setInfo($docroot, [
				'version' => $this->get_version($hostname, $path) ?? $version,
				'failed'  => !$ret['success']
			]);
			$this->fortify($hostname, $path, array_get($this->getOptions($docroot), 'fortify', 'max'));

			if (!$ret['success']) {
				return error('failed to update Drupal: %s', coalesce($ret['stderr'], $ret['stdout']));
			}


			return $ret['success'];
		}

		public function isLocked(string $docroot): bool
		{
			return file_exists($this->domain_fs_path() . $docroot . DIRECTORY_SEPARATOR .
				'.drush-lock-update');
		}

		/**
		 * Set Drupal maintenance mode before/after update
		 *
		 * @param      $docroot
		 * @param      $mode
		 * @param null $version
		 * @return bool
		 */
		private function _setMaintenance($docroot, $mode, $version = null)
		{
			if (null === $version) {
				$version = $this->_getVersion($docroot);
			}
			if ($version[0] >= 8) {
				$maintenancecmd = 'sset system.maintenance_mode %(mode)d';
				$cachecmd = 'cr';
			} else {
				$maintenancecmd = 'vset --exact maintenance_mode %(mode)d';
				$cachecmd = 'cache-clear all';
			}

			$ret = $this->_exec($docroot, $maintenancecmd, array('mode' => (int)$mode));
			if (!$ret['success']) {
				warn('failed to set maintenance mode');
			}
			$ret = $this->_exec($docroot, $cachecmd);
			if (!$ret['success']) {
				warn('failed to rebuild cache');
			}

			return true;
		}

		/**
		 * Update Drupal plugins and themes
		 *
		 * @param string $hostname domain or subdomain
		 * @param string $path     optional path within host
		 * @param array  $plugins
		 * @return bool
		 */
		public function update_plugins(string $hostname, string $path = '', array $plugins = array()): bool
		{
			$docroot = $this->getAppRoot($hostname, $path);
			if (!$docroot) {
				return error('update failed');
			}
			$cmd = 'pm-update --check-disabled --no-core';

			$args = array();
			if ($plugins) {
				for ($i = 0, $n = count($plugins); $i < $n; $i++) {
					$plugin = $plugins[$i];
					$version = null;
					if (isset($plugin['version'])) {
						$version = $plugin['version'];
					}
					if (isset($plugin['name'])) {
						$plugin = $plugin['name'];
					}

					$name = 'p' . $i;
					$cmd .= ' %(' . $name . ')s';
					$args[$name] = $plugin . ($version ? '-' . $version : '');
				}
			}

			$ret = $this->_exec($docroot, $cmd, $args);
			if (!$ret['success']) {
				/**
				 * NB: "Command pm-update needs a higher bootstrap level"...
				 * Use an older version of Drush to bring the version up
				 * to use the latest drush
				 */
				return error("plugin update failed: `%s'", coalesce($ret['stderr'], $ret['stdout']));
			}

			return $ret['success'];
		}

		public function _housekeeping()
		{
			if (!file_exists(self::DRUPAL_CLI) || filemtime(self::DRUPAL_CLI) < filemtime(__FILE__)) {
				$url = self::DRUPAL_CLI_URL;
				$res = Util_HTTP::download($url, self::DRUPAL_CLI);
				if (!$res) {
					return error('failed to install Drupal CLI');
				}
				info('downloaded Drupal CLI');
				chmod(self::DRUPAL_CLI, 0755);
			}

			$local = $this->service_template_path('siteinfo') . '/' . self::DRUPAL_CLI;
			if (!file_exists($local)) {
				copy(self::DRUPAL_CLI, $local);
				chmod($local, 755);
			}

			return true;
		}

		/**
		 * Get all available Drupal  versions
		 *
		 * @return array
		 */
		public function get_versions(): array
		{
			$versions = $this->_getVersions();

			return array_column($versions, 'version');
		}

		/**
		 * Update WordPress themes
		 *
		 * @param string $hostname subdomain or domain
		 * @param string $path     optional path under hostname
		 * @param array  $themes
		 * @return bool
		 */
		public function update_themes(string $hostname, string $path = '', array $themes = array()): bool
		{
			return false;
		}

		private function _getCommand()
		{
			return 'php ' . self::DRUPAL_CLI;
		}
	}
