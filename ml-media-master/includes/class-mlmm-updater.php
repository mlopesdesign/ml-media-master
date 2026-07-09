<?php
/**
 * ML Media Master — GitHub Releases Auto-Updater
 *
 * Single update engine for the native WordPress plugin update flow.
 * It uses the latest public GitHub Release and only accepts the versioned
 * release asset ZIP whose root is expected to be ml-media-master/.
 * GitHub source archives are never used.
 *
 * @package ML_Media_Master
 */

defined('ABSPATH') || exit;

final class MLMM_Updater {

	private const GITHUB_OWNER        = 'mlopesdesign';
	private const GITHUB_REPO         = 'ml-media-master';
	private const PLUGIN_SLUG         = 'ml-media-master';
	private const UPDATE_URI          = 'https://github.com/mlopesdesign/ml-media-master';
	private const API_URL             = 'https://api.github.com/repos/mlopesdesign/ml-media-master/releases/latest';
	private const TRANSIENT_KEY       = 'mlmm_github_release_cache';
	private const CACHE_TTL           = 1800; // Revalidate every 30 minutes.
	private const ERROR_RETRY_TTL     = 300;  // Retry API failures after 5 minutes.
	private const CACHE_STORAGE_TTL   = 604800; // Keep validators/stale data for 7 days.
	private const REQUIRES_WORDPRESS  = '6.0';
	private const REQUIRES_PHP        = '8.1';

	/** Absolute path to the main plugin file. */
	private string $plugin_file;

	/** plugin-dir/plugin-file.php relative to wp-content/plugins. */
	private string $plugin_basename;

	/** Current installed version. */
	private string $current_version;

	public function __construct(string $plugin_file, string $current_version) {
		$this->plugin_file     = $plugin_file;
		$this->plugin_basename = plugin_basename($plugin_file);
		$this->current_version = $current_version;
	}

	/** Register the complete native WordPress update integration. */
	public function init(): void {
		// Official Update URI integration available since WordPress 5.8.
		add_filter('update_plugins_github.com', [$this, 'filter_update_uri'], 10, 4);

		// Populate both newly generated and already-cached update transients.
		add_filter('pre_set_site_transient_update_plugins', [$this, 'inject_update'], 20, 1);
		add_filter('site_transient_update_plugins', [$this, 'inject_update'], 20, 1);

		add_filter('plugins_api', [$this, 'plugin_info'], 20, 3);

		// WordPress deletes update_plugins when "Check again" is requested.
		// Mark our own GitHub cache stale at the same moment so the manual check
		// really reaches GitHub instead of returning a release cached for hours.
		add_action('delete_site_transient_update_plugins', [$this, 'invalidate_release_cache'], 10, 1);
		add_action('admin_init', [$this, 'maybe_invalidate_on_manual_check'], 1);
		add_action('upgrader_process_complete', [$this, 'flush_cache_after_update'], 10, 2);
	}

	/**
	 * Native Update URI callback. The github.com hook is shared by every plugin
	 * using that host, therefore the plugin basename must always be checked.
	 *
	 * @param array|false $update
	 * @param array       $plugin_data
	 * @param string      $plugin_file
	 * @param string[]    $locales
	 * @return array|false
	 */
	public function filter_update_uri($update, $plugin_data, $plugin_file, $locales) {
		unset($plugin_data, $locales);

		if ((string) $plugin_file !== $this->plugin_basename) {
			return $update;
		}

		$release = $this->get_latest_release($this->is_force_refresh_request());

		if (!is_array($release)) {
			return $this->build_update_uri_payload(null);
		}

		return $this->build_update_uri_payload($release);
	}

	/**
	 * Inject the GitHub release into the standard update_plugins transient.
	 * This also repairs an already-cached WordPress transient immediately.
	 *
	 * @param mixed $transient
	 * @return mixed
	 */
	public function inject_update($transient) {
		if (!is_object($transient)) {
			return $transient;
		}

		if (!isset($transient->response) || !is_array($transient->response)) {
			$transient->response = [];
		}

		if (!isset($transient->no_update) || !is_array($transient->no_update)) {
			$transient->no_update = [];
		}

		if (!isset($transient->checked) || !is_array($transient->checked)) {
			$transient->checked = [];
		}

		$transient->checked[$this->plugin_basename] = $this->current_version;
		$release = $this->get_latest_release($this->is_force_refresh_request());

		if (
			is_array($release)
			&& !empty($release['version'])
			&& !empty($release['package_url'])
			&& version_compare((string) $release['version'], $this->current_version, '>')
		) {
			unset($transient->no_update[$this->plugin_basename]);
			$transient->response[$this->plugin_basename] = $this->build_transient_payload($release, true);
			return $transient;
		}

		unset($transient->response[$this->plugin_basename]);
		$transient->no_update[$this->plugin_basename] = $this->build_transient_payload($release, false);

		return $transient;
	}

	/**
	 * Provide the native "View details" modal.
	 *
	 * @param mixed  $result
	 * @param string $action
	 * @param object $args
	 * @return mixed
	 */
	public function plugin_info($result, $action, $args) {
		if (
			$action !== 'plugin_information'
			|| !is_object($args)
			|| (string) ($args->slug ?? '') !== self::PLUGIN_SLUG
		) {
			return $result;
		}

		$release = $this->get_latest_release($this->is_force_refresh_request());
		if (!is_array($release) || empty($release['version'])) {
			return $result;
		}

		$info                = new stdClass();
		$info->name          = 'ML Media Master';
		$info->slug          = self::PLUGIN_SLUG;
		$info->version       = (string) $release['version'];
		$info->author        = '<a href="https://mlopesdesign.com.br">Marcio Lopes</a>';
		$info->author_profile = 'https://mlopesdesign.com.br';
		$info->homepage      = self::UPDATE_URI;
		$info->download_link = (string) ($release['package_url'] ?? '');
		$info->last_updated  = (string) ($release['published_at'] ?? '');
		$info->requires      = self::REQUIRES_WORDPRESS;
		$info->requires_php  = self::REQUIRES_PHP;
		$info->external      = true;
		$info->sections      = [
			'description' => 'ML Media Master: conversão automática de JPG, JPEG e PNG para WebP no upload do WordPress e do ML Gallery Pro. GIF nunca é convertido.',
			'changelog'   => nl2br(esc_html((string) ($release['body'] ?? ''))),
		];

		return $info;
	}

	/**
	 * Fetch and validate the latest GitHub Release.
	 *
	 * Positive responses are revalidated every 30 minutes. A manual WordPress
	 * "Check again" invalidates the freshness timestamp and forces this method
	 * to contact GitHub immediately. ETag/Last-Modified validators are retained
	 * whenever possible, reducing API usage and supporting HTTP 304 responses.
	 *
	 * @return array<string,mixed>|null
	 */
	private function get_latest_release(bool $force_refresh = false): ?array {
		$now    = time();
		$cached = get_site_transient(self::TRANSIENT_KEY);
		$cached = is_array($cached) ? $cached : [];

		$cached_release = isset($cached['release']) && is_array($cached['release'])
			? $cached['release']
			: null;

		$fetched_at = isset($cached['fetched_at']) ? (int) $cached['fetched_at'] : 0;
		$retry_after = isset($cached['retry_after']) ? (int) $cached['retry_after'] : 0;

		if (!$force_refresh && $fetched_at > 0 && ($now - $fetched_at) < self::CACHE_TTL) {
			return $cached_release;
		}

		if (!$force_refresh && $retry_after > $now) {
			return $cached_release;
		}

		$headers = [
			'Accept'               => 'application/vnd.github+json',
			'X-GitHub-Api-Version' => '2022-11-28',
		];

		if (!empty($cached['etag'])) {
			$headers['If-None-Match'] = (string) $cached['etag'];
		}

		if (!empty($cached['last_modified'])) {
			$headers['If-Modified-Since'] = (string) $cached['last_modified'];
		}

		$response = wp_remote_get(self::API_URL, [
			'timeout'     => 15,
			'redirection' => 3,
			'user-agent'  => 'ML-Media-Master-Updater/' . $this->current_version . '; ' . home_url('/'),
			'headers'     => $headers,
		]);

		if (is_wp_error($response)) {
			$this->store_api_failure($cached, $now, $response->get_error_message());
			return $cached_release;
		}

		$status = (int) wp_remote_retrieve_response_code($response);

		if ($status === 304 && is_array($cached_release)) {
			$cached['fetched_at'] = $now;
			$cached['retry_after'] = 0;
			$cached['last_error'] = '';
			$this->store_cache($cached);
			return $cached_release;
		}

		if ($status !== 200) {
			$this->store_api_failure($cached, $now, 'GitHub API HTTP ' . $status);
			return $cached_release;
		}

		$body = json_decode((string) wp_remote_retrieve_body($response), true);
		$release = $this->parse_release(is_array($body) ? $body : []);

		if (!is_array($release)) {
			$this->store_api_failure($cached, $now, 'Release inválida ou sem o asset ZIP versionado do plugin.');
			return $cached_release;
		}

		$new_cache = [
			'release'       => $release,
			'fetched_at'    => $now,
			'retry_after'   => 0,
			'etag'          => (string) wp_remote_retrieve_header($response, 'etag'),
			'last_modified' => (string) wp_remote_retrieve_header($response, 'last-modified'),
			'last_error'    => '',
		];

		$this->store_cache($new_cache);

		return $release;
	}

	/**
	 * Validate release metadata and locate the exact versioned plugin asset.
	 *
	 * @param array<string,mixed> $body
	 * @return array<string,mixed>|null
	 */
	private function parse_release(array $body): ?array {
		if (!empty($body['draft']) || !empty($body['prerelease']) || empty($body['tag_name'])) {
			return null;
		}

		$version = ltrim(trim((string) $body['tag_name']), 'vV');
		if (!preg_match('/^\d+(?:\.\d+){2,3}$/', $version)) {
			return null;
		}

		$assets = isset($body['assets']) && is_array($body['assets']) ? $body['assets'] : [];
		$package_url = $this->pick_asset_zip_url($assets, $version);
		if ($package_url === '') {
			return null;
		}

		return [
			'tag_name'     => (string) $body['tag_name'],
			'version'      => $version,
			'html_url'     => !empty($body['html_url']) ? esc_url_raw((string) $body['html_url']) : self::UPDATE_URI,
			'body'         => (string) ($body['body'] ?? ''),
			'published_at' => (string) ($body['published_at'] ?? ''),
			'package_url'  => $package_url,
		];
	}

	/**
	 * Select only the plugin's exact versioned Release asset.
	 * Accepted examples:
	 * - ml-media-master-v2.9.59.0.zip
	 * - ml-media-master-v2_9_59_0.zip
	 *
	 * GitHub-generated source archives are not part of the Release assets array,
	 * and there is deliberately no fallback to an arbitrary ZIP.
	 *
	 * @param array<int,mixed> $assets
	 */
	private function pick_asset_zip_url(array $assets, string $version): string {
		foreach ($assets as $asset) {
			if (!is_array($asset)) {
				continue;
			}

			$name  = trim((string) ($asset['name'] ?? ''));
			$url   = trim((string) ($asset['browser_download_url'] ?? ''));
			$state = strtolower(trim((string) ($asset['state'] ?? 'uploaded')));

			if ($name === '' || $url === '' || $state !== 'uploaded') {
				continue;
			}

			if (!preg_match('/^' . preg_quote(self::PLUGIN_SLUG, '/') . '-v(.+)\.zip$/i', $name, $matches)) {
				continue;
			}

			$asset_version = preg_replace('/[._-]+/', '.', (string) $matches[1]);
			$asset_version = trim((string) $asset_version, '.');

			if ($asset_version !== $version) {
				continue;
			}

			if (!$this->is_trusted_asset_url($url)) {
				continue;
			}

			return esc_url_raw($url);
		}

		return '';
	}

	private function is_trusted_asset_url(string $url): bool {
		$parts = wp_parse_url($url);
		if (!is_array($parts)) {
			return false;
		}

		$scheme = strtolower((string) ($parts['scheme'] ?? ''));
		$host   = strtolower((string) ($parts['host'] ?? ''));
		$path   = (string) ($parts['path'] ?? '');
		$prefix = '/' . self::GITHUB_OWNER . '/' . self::GITHUB_REPO . '/releases/download/';

		return $scheme === 'https' && $host === 'github.com' && strpos($path, $prefix) === 0;
	}

	/** @param array<string,mixed>|null $release */
	private function build_update_uri_payload(?array $release): array {
		$version = is_array($release) && !empty($release['version'])
			? (string) $release['version']
			: $this->current_version;

		$is_update = version_compare($version, $this->current_version, '>');

		return [
			'id'           => self::UPDATE_URI,
			'slug'         => self::PLUGIN_SLUG,
			'version'      => $version,
			'url'          => is_array($release) && !empty($release['html_url']) ? (string) $release['html_url'] : self::UPDATE_URI,
			'package'      => $is_update && is_array($release) ? (string) ($release['package_url'] ?? '') : '',
			'requires'     => self::REQUIRES_WORDPRESS,
			'requires_php' => self::REQUIRES_PHP,
			'icons'        => [],
			'banners'      => [],
			'banners_rtl'  => [],
		];
	}

	/**
	 * @param array<string,mixed>|null $release
	 */
	private function build_transient_payload(?array $release, bool $is_update): object {
		$version = $is_update && is_array($release) && !empty($release['version'])
			? (string) $release['version']
			: $this->current_version;

		return (object) [
			'id'            => self::UPDATE_URI,
			'slug'          => self::PLUGIN_SLUG,
			'plugin'        => $this->plugin_basename,
			'new_version'   => $version,
			'url'           => is_array($release) && !empty($release['html_url']) ? (string) $release['html_url'] : self::UPDATE_URI,
			'package'       => $is_update && is_array($release) ? (string) ($release['package_url'] ?? '') : '',
			'requires'      => self::REQUIRES_WORDPRESS,
			'requires_php'  => self::REQUIRES_PHP,
			'icons'         => [],
			'banners'       => [],
			'banners_rtl'   => [],
			'compatibility' => new stdClass(),
		];
	}

	/** @param array<string,mixed> $cache */
	private function store_cache(array $cache): void {
		set_site_transient(self::TRANSIENT_KEY, $cache, self::CACHE_STORAGE_TTL);
	}

	/** @param array<string,mixed> $cache */
	private function store_api_failure(array $cache, int $now, string $message): void {
		$cache['fetched_at']  = 0;
		$cache['retry_after'] = $now + self::ERROR_RETRY_TTL;
		$cache['last_error']  = $message;
		$this->store_cache($cache);
	}

	/**
	 * Keep ETag/Last-Modified but force the next read to revalidate GitHub.
	 *
	 * @param mixed $transient_name
	 */
	public function invalidate_release_cache($transient_name = null): void {
		unset($transient_name);
		$cached = get_site_transient(self::TRANSIENT_KEY);

		if (!is_array($cached)) {
			delete_site_transient(self::TRANSIENT_KEY);
			return;
		}

		$cached['fetched_at']  = 0;
		$cached['retry_after'] = 0;
		$this->store_cache($cached);
	}

	/** Ensure the update-core.php "Check again" request bypasses our cache. */
	public function maybe_invalidate_on_manual_check(): void {
		if (!$this->is_force_refresh_request()) {
			return;
		}

		if (function_exists('current_user_can') && !current_user_can('update_plugins')) {
			return;
		}

		$this->invalidate_release_cache();
	}

	private function is_force_refresh_request(): bool {
		$force_check = '';
		if (isset($_GET['force-check'])) {
			$force_check = sanitize_text_field(wp_unslash((string) $_GET['force-check']));
		}

		$action = '';
		if (isset($_GET['action'])) {
			$action = sanitize_text_field(wp_unslash((string) $_GET['action']));
		}

		return in_array(strtolower($force_check), ['1', 'true', 'yes'], true)
			|| $action === 'force-check';
	}

	/**
	 * Invalidate release metadata after either single or bulk plugin updates.
	 * WordPress runs this hook using the old plugin code, so no version-specific
	 * migration is performed here.
	 *
	 * @param object              $upgrader
	 * @param array<string,mixed> $hook_extra
	 */
	public function flush_cache_after_update($upgrader, $hook_extra): void {
		unset($upgrader);

		if (!is_array($hook_extra) || ($hook_extra['type'] ?? '') !== 'plugin') {
			return;
		}

		$single_match = isset($hook_extra['plugin'])
			&& (string) $hook_extra['plugin'] === $this->plugin_basename;

		$bulk_match = in_array(
			$this->plugin_basename,
			(array) ($hook_extra['plugins'] ?? []),
			true
		);

		if ($single_match || $bulk_match) {
			$this->invalidate_release_cache();
		}
	}
}
