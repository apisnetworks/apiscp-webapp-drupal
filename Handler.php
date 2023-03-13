<?php
	/**
 * Copyright (C) Apis Networks, Inc - All Rights Reserved.
 *
 * Unauthorized copying of this file, via any medium, is
 * strictly prohibited without consent. Any dissemination of
 * material herein is prohibited.
 *
 * For licensing inquiries email <licensing@apisnetworks.com>
 *
 * Written by Matt Saladna <matt@apisnetworks.com>, August 2020
 */

	namespace Module\Support\Webapps\App\Type\Drupal;

	use Module\Support\Webapps\App\Type\Unknown\Handler as Unknown;

	class Handler extends Unknown
	{
		const NAME = 'Drupal';
		const ADMIN_PATH = '/user';
		const LINK = 'https://drupal.org';

		const FEAT_ALLOW_SSL = true;
		const FEAT_RECOVERY = false;

		public function recover(): bool
		{
			return $this->drupal_disable_all_plugins($this->hostname, $this->path);
		}

		public function getAppRoot(): ?string
		{
			if (!$this->docroot) {
				return $this->docroot;
			}

			// 9.0 may use a public dispatcher directory
			$stat = $this->file_stat($this->docroot . '/../web');
			if ($stat && $stat['file_type'] === 'dir') {
				// @todo convert to lookup with other webapps?
				$stat = $this->file_stat($this->docroot);
				return dirname($stat['referent'] ?: $this->docroot);
			}

			return $this->docroot;
		}


	}
