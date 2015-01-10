<?php

/**
 * ProcessAdvancedRedirects - a ProcessWire Module by Mike Anthony
 * Manage permanent and temporary redirects. Supports wildcards.
 *
 * Intended for: ProcessWire 2.6-dev
 * Developed in: ProcessWire 2.5.13
 *
 * Copyright (c) 2015, Mike Anthony. All Rights Reserved.
 * Licence: MIT License - http://mikeanthony.mit-license.org/
 *
 * http://pw.foundrybusiness.co.za/advanced-redirects
 *
 */

require_once __DIR__ . '/Blueprint.php';
require_once __DIR__ . '/ProcessAdvancedRedirectsConfig.php';

use ProcessAdvancedRedirectsConfig as Config;

class AdvancedRedirects extends Process {

	/**
	 * Determine if the tex/plain header is set
	 * @var boolean
	 */
	protected $headerSet = false;

	/**
	 * Object (Array) that holds SQL statements
	 * @var stClass
	 */
	protected $sql;

	/**
	 * Hold module information
	 * @var array
	 */
	protected $moduleInfo;

	/**
	 * The base table name
	 * @rfc Should we make this constant?
	 * @var string
	 */
	protected $tableName = 'process_advanced_redirects';

	/**
	 * Paths to forms
	 * @var string
	 */
	protected $entityFormPath = 'entity/';
	protected $mappingCollectionFormPath = 'mapping-collection/';

	/**
	 * Set the wildcard types.
	 * A wildcard type is the second fragment of a wildcard/
	 * Ex: {name:type}
	 * @var array
	 */
	protected $wildcards = array(
		'all' => '.*',
		'alpha' => '[a-z]+',
		'alphanum' => '\w+',
		'any' => '[\w.-_%\=\s]+',
		'ext' => 'aspx|asp|cfm|cgi|fcgi|dll|html|htm|shtml|shtm|jhtml|phtml|xhtm|xhtml|rbml|jspx|jsp|phps|php4|php',
		'num' => '\d+',
		'segment' => '[\w_-]+',
		'segments' => '[\w/_-]+',
	);

	/**
	 * Set smart wildcards.
	 * These are like shortcuts for declaring wildcards.
	 * See the docs for more info.
	 * @var array
	 */
	public $smartWildcards = array(
		'all' => 'all',
		'ext' => 'ext',
		'name|title|page|post|user|model|entry' => 'segment',
		'path|segments' => 'segments',
		'year|month|day|id|num' => 'num',
	);

	/**
	 * Class constructor
	 * Init moduleInfo, sql
	 */
	public function __construct() {
		$this->moduleInfo = wire('modules')->getModuleInfo($this, array('verbose' => true));
		$this->sql = (object) array(
			'entitySelectAll' => "SELECT * FROM {$this->tableName} ORDER BY source",
			'entitySelectAllJsonNav' => "SELECT * FROM {$this->tableName} ORDER BY updated_at DESC LIMIT 5",
			'entitySelectSingle' => "SELECT * FROM {$this->tableName} WHERE id=%s",
			'entityDropById' => "DELETE FROM {$this->tableName} WHERE id=%s",
			'entityInsert' => "INSERT INTO {$this->tableName} SET source = '%s', destination = '%s', date_start = %s, date_end = %s, user_created = %s, user_updated = %s ON DUPLICATE KEY UPDATE id = id",
			'entityUpdate' => "UPDATE {$this->tableName} SET source = '%s', destination = '%s', date_start = %s, date_end = %s, user_updated = %s WHERE id = %s",
			'mappingCollectionsSelectAll' => "SELECT * FROM {$this->tableName}_mc ORDER BY collection_name",
			'mappingCollectionsSelectSingle' => "SELECT * FROM {$this->tableName}_mc WHERE id=%s",
			'mappingCollectionsSelectAllFromName' => "SELECT * FROM {$this->tableName}_mc WHERE collection_name='%s'",
			'mappingCollectionsDropById' => "DELETE FROM {$this->tableName}_mc WHERE id=%s",
			'mappingCollectionsInsert' => "INSERT INTO {$this->tableName}_mc SET collection_name = '%s', collection_mappings = '%s', user_created = %s, user_updated = %s ON DUPLICATE KEY UPDATE id = id",
			'mappingCollectionsUpdate' => "UPDATE {$this->tableName}_mc SET collection_name = '%s', collection_mappings = '%s', user_updated = %s WHERE id = %s",
		);
	}

	/**
	 * Module initialisation
	 * @hook ProcessPageView::pageNotFound to scanAndRedirect
	 */
	public function init() {
		parent::init();

		// Set the admin page URL for JS
		$this->config->js("parAdminPageUrl", $this->pages->get('name=advanced-redirects')->url);

		// Set the helpLink
		$this->helpLink = "<a class=\"helpLink\" target=\"_blank\" href=\"{$this->moduleInfo['href']}\">Need help?</a>";

		// Include WireTabs
		$this->modules->get('JqueryWireTabs');

		// Make sure schemas are up to date
		if ($this->schemaVersion < ProcessAdvancedRedirectsConfig::SCHEMA_VERSION) {
			$this->updateDatabaseSchemas();
		}

		// Set the request (URI), and trim off the leading slash,
		// as we won't be needing it for comparison.
		$this->request = ltrim($_SERVER['REQUEST_URI'], '/');

		// If a request is made to the index.php file, with a slash,
		// then redirect to root (mod_rewrite is a PW requirement)
		if ($this->request === 'index.php/' || $this->request === 'index.php') {
			$this->session->redirect($this->config->urls->root);
		}

		// Magic ahead: Replace index.php with a dummy do we can scan such requests.
		// But first, redirect requests to index.php/ so we don't have any legacy domain false positives,
		// such as remote 301s used to trim trailing slashes.
		$indexExpression = "~^index.php(\?|\/)~";
		if (preg_match($indexExpression, $this->request)) {
			$this->session->redirect(preg_replace(
				$indexExpression,
				"{$this->config->urls->root}index.php.pw-par\\1",
				$this->request
			));
		}

		// Hook to the 404 event ...
		$this->addHook('ProcessPageView::pageNotFound', $this, 'scanAndRedirect', array('priority' => 10));
	}

	/**
	 * Update database schema
	 * This method applies incremental updates until latest schema version is
	 * reached, while also keeping schemaVersion config setting up to date.
	 * @state    Will be populated on first schema change
	 */
	private function updateDatabaseSchemas() {}

	/**
	 * Create a blueprint from file and give it some variables.
	 * @caller multiple
	 * @param  string $name
	 * @param  array  $data
	 * @return string
	 */
	protected function blueprint($name, $data = array()) {
		$blueprint = new Blueprint($name);

		if (empty(array_filter($data))) {
			// Should we rather just always include tableName?
			// It's used quite often...
			$data = array('table-name' => $this->tableName);
		}

		$blueprint->hydrate($data);

		return (string) $blueprint->build();
	}

	/**
	 * Compile destination URL, keeping page refs, HTTPS, and subdirectories considered.
	 * @caller multiple
	 * @param  string $destination
	 * @param  bool   $renderForOutput
	 * @param  bool   $http
	 * @return string
	 */
	protected function compileDestinationUrl($destination, $renderForOutput = false, $http = true) {
		$pageIdentifier = 'page:';

		// Check if we're using a page identifier
		if (substr($destination, 0, 5) !== $pageIdentifier) {
			// Check to see if we're working with an absolute URL
			// and if we're currently using HTTPS
			$hasScheme = (bool) parse_url($destination, PHP_URL_SCHEME);
			$https = ($this->config->https) ? 's' : '';

			// If URL is absolute, then skip the prefix, otherwise build it
			$prefix = ($hasScheme) ? '' : "http{$https}://{$this->config->httpHost}/";

			// If we're rendering for backend output, truncate and return the destination.
			// Otherwise, return the full destination.
			return ($renderForOutput)
			? $this->truncate($destination, 35)
			: $prefix . $destination;
		}

		// If we're using a page identifier, fetch it
		$pageId = str_replace($pageIdentifier, '', $destination);
		$page = $this->pages->get((int) $pageId);

		// If it's a valid page, then get its URL
		if ($page->id) {
			$pagePath = $page->get($http ? "httpUrl" : "path");

			// If we're rendering for output, make it pretty.
			$destination = ($renderForOutput)
			? "<abbr title=\"{$pagePath}\">{$page->get('headline|title|name')}<abbr>"
			: $pagePath;
		}

		return $destination;
	}

	/**
	 * Fetch the URI to the module's config page
	 * @caller multiple
	 * @return string
	 */
	protected function getModuleConfigUri() {
		return "{$this->config->urls->admin}module/edit?name={$this->moduleInfo['name']}";
		// ^ Better way to get this URI?
	}

	/**
	 * Fetch the URI to the module's config page
	 * @caller scanAndRedirect
	 * @param  string $input
	 * @param  bool   $noLower
	 * @return string
	 */
	public function cleanPath($input, $noLower = false) {
		if ($this->{Config::ENHANCED_WILDCARD_CLEANING}) {
			// Courtesy @sln on StackOverflow
			$input = preg_replace_callback("~([A-Z])([A-Z]+)(?=[A-Z]|\b)~", function ($captures) {
				return $captures[1] . strtolower($captures[2]);
			}, $input);
			$input = preg_replace("~(?<=\\w)(?=[A-Z])~", "-\\1\\2", $input);
		}

		$input = preg_replace("~%u([a-f\d]{3,4})~i", "&#x\\1;", urldecode($input));
		$input = preg_replace("~[^\\pL\d\/]+~u", '-', $input);
		$input = iconv('utf-8', 'us-ascii//TRANSLIT', $input);

		if ($this->{Config::ENHANCED_WILDCARD_CLEANING}) {
			// Merge these two?
			$input = preg_replace("~([a-z])(\d)~i", "\\1-\\2", $input);
			$input = preg_replace("~(\d)([a-z])~i", "\\1-\\2", $input);
		}

		$input = trim($input, '-');
		$input = preg_replace('~[^-\w\/]+~', '', $input);
		if (!$noLower) {
			$input = strtolower($input);
		}

		return (empty($input)) ? '' : $input;
	}

	/**
	 * Truncate string, and append ellipses with tooltip
	 * @caller multiple
	 * @param  string $string
	 * @param  int    $length
	 * @return string
	 */
	protected function truncate($string, $length = 55) {
		return (strlen($string) > $length)
		? substr($string, 0, $length) . " <span class=\"ellipses\" title=\"{$string}\">...</span>"
		: $string;
	}

	/**
	 * Given a an Inputfield, add props and return
	 * @param  string $field
	 * @param  array  $meta
	 * @return Inputfield
	 */
	protected function populateInputField($field, $meta) {
		foreach ($meta as $metaNames => $metaInfo) {
			$metaNames = explode('+', $metaNames);
			foreach ($metaNames as $metaName) {
				$field->$metaName = $metaInfo;
			}
		}

		return $field;
	}

	/**
	 * Get response code of remote request
	 * @caller scanAndRedirect
	 * @param  $request
	 * @return string
	 */
	protected function getResponseCode($request) {
		stream_context_set_default(array(
			'http' => array(
				'method' => 'HEAD',
			),
		));

		$response = get_headers($request);

		return substr($response[0], 9, 3);
	}

	/**
	 * Log something. Will set plain text header if not already set.
	 * @caller scanAndRedirect
	 * @param  string $message
	 * @param  bool   $indent
	 * @param  bool   $break
	 * @param  bool   $die
	 */
	protected function log($message, $indent = false, $break = false, $die = false) {
		if ($this->{Config::MODULE_DEBUG} && $this->user->hasPermission('advanced-redirects-admin')) {
			if (!$this->headerSet) {
				header("Content-Type: text/plain");
				$this->headerSet = true;
			}

			$indent = ($indent) ? "- " : '';
			$break = ($break) ? "\n" : '';

			print str_replace('.pw-par', '', "{$indent}{$message}\n{$break}");

			if ($die) {
				die();
			}
		}
	}

	/**
	 * The fun part.
	 * @caller Hook: ProcessPageView::pageNotFound
	 */
	protected function scanAndRedirect() {
		$redirects = $this->db->query($this->sql->entitySelectAll);

		if ($redirects->num_rows === 0) {
			return false;
		}

		$this->log("Page not found; scanning for redirects...");

		$request = $this->request;
		$requestedUrlFirstPart = "http" . ((@$_SERVER['HTTPS'] == 'on') ? "s" : "") . "://{$_SERVER['HTTP_HOST']}";

		// Do some logging
		$this->log('Checked at: ' . date('r'), true);
		$this->log("Requested URL: {$requestedUrlFirstPart}/{$request}", true);
		$this->log("PW Version: {$this->config->version}", true, true);

		$rootUrl = $this->config->urls->root;

		if ($rootUrl !== '/') {
			$request = substr($request, strlen($rootUrl) - 1);
		}

		// Get the available wildcards, prepare for pattern match
		$availableWildcards = '';
		foreach ($this->wildcards as $wildcard => $expression) {
			$availableWildcards .= "$wildcard|";
		}
		$availableWildcards = rtrim($availableWildcards, '|');

		// Assign the wildcard pattern check
		$pattern = '~\{!?([a-z]+):(' . $availableWildcards . ')\}~';

		// Begin the loop
		while ($redirect = $redirects->fetch_object()) {
			$starts = strtotime($redirect->date_start);
			$ends = strtotime($redirect->date_end);

			// Timed Activation:
			// If it ends, but doesn't start, then make it start now
			if ($ends && !$starts) {
				$starts = time();
			}

			// If it starts (which it will always do), but doesn't end,
			// then set a dummy timestamp that is always in the future.
			if ($starts && !$ends) {
				$ends = time() + (60 * 60);
				$message = '(has no ending, using dummy timestamp)';
			} else {
				$message = '';
			}

			// Log the activation periods for debugging
			if ($starts || $ends) {
				$this->log(sprintf("Timed Activation:             %s to %s {$message}", date('r', $starts), date('r', $ends)), true);
			}

			$this->log("Source Path (Unescaped):      {$redirect->source}", true);

			// Prepare the Source Path for matching:
			// First, escape ? & :, and rename index.php so we can make use of such requests.
			// Then, convert '[character]' to 'character?' for matching.
			$source = preg_replace('~\[([a-z0-9\/])\]~i', "\\1?", str_replace(
				array('?', '&', ':', '+', 'index.php'),
				array('\?', '\&', '\:', '+', 'index.php.pw-par'),
				$redirect->source));

			// Reverse ? escaping for wildcards
			$source = preg_replace("~\{([a-z]+)\\\:([a-z]+)\}~i", "{\\1:\\2}", $source);

			// Compile the destination URL
			$destination = $this->compileDestinationUrl($redirect->destination);

			if ($source !== $redirect->source) {
				$this->log("Source Path (Escaped):        {$source}", true);
			}

			// Setup capture prevention
			// Perhaps we should use different delimters, such as "(!something)"?
			$nonCaptureMatcher = "~<(.*?)>~";
			if (preg_match($nonCaptureMatcher, $source)) {
				$source = preg_replace($nonCaptureMatcher, "(?:\\1)", $source);
			}

			// Prepare Smart Wildcards - replace them with their equivalent standard ones.
			foreach ($this->smartWildcards as $wildcard => $wildcardType) {
				$smartWildcardMatcher = "~\{($wildcard)\}~i";
				if (preg_match($smartWildcardMatcher, $source)) {
					$source = preg_replace($smartWildcardMatcher, "{\\1:{$wildcardType}}", $source);
				}
			}

			$computedReplacements = array();

			// Convert wildcards into expressions for replacement
			$computedWildcards = preg_replace_callback($pattern, function ($captures) use (&$computedReplacements) {
				$computedReplacements[] = $captures[1];
				return "({$this->wildcards[$captures[2]]})";
			}, $source);

			// Some more logging
			$this->log("Source Path (Stage 1):        {$source}", true);
			$this->log("Source Path (Stage 2):        {$computedWildcards}", true);

			// If the request matches the source currently being checked:
			if (preg_match("~^$computedWildcards$~i", $request)) {
				// For the purposes of mapping, fetch all the collections and compile them
				$collections = $this->db->query($this->sql->mappingCollectionsSelectAll);
				$compiledCollections = new StdClass();
				while ($collection = $collections->fetch_object()) {
					$collectionData = explode("\n", $collection->collection_mappings);
					$compiledCollectionData = array();
					foreach ($collectionData as $mapping) {
						$mapping = explode('=', $mapping);
						$compiledCollectionData[$mapping[0]] = $mapping[1];
					}

					$compiledCollections->{$collection->collection_name} = $compiledCollectionData;
				}

				// Iterate through each source wildcard:
				$convertedWildcards = preg_replace_callback("~$computedWildcards~i", function ($captures) use ($destination, $computedReplacements) {
					$result = $destination;

					for ($c = 1, $n = count($captures); $c < $n; ++$c) {
						$value = array_shift($computedReplacements);

						// Check for destination wildcards that don't need to be cleaned
						$paramSkipCleanCheck = "~\{!$value\}~i";
						$uncleanedCapture = $captures[$c];
						if (!preg_match($paramSkipCleanCheck, $result)) {
							$cleanPath = $this->{Config::WILDCARD_CLEANING};
							if ($cleanPath === 'fullClean' || $cleanPath === 'semiClean') {
								$captures[$c] = $this->cleanPath($captures[$c], ($cleanPath === 'fullClean') ? false : true);
							}
						}
						$openingTag = (preg_match($paramSkipCleanCheck, $result)) ? '{!' : '{';
						$result = str_replace($openingTag . $value . '}', $captures[$c], $result);

						// In preparation for wildcard mapping,
						// Swap out any mapping wildcards with their uncleaned values
						$value = preg_quote($value);
						$result = preg_replace("~\{{$value}\|([a-z]+)\}~i", "($uncleanedCapture|\\1)", $result);
					}

					// Trim the result of trailing slashes, and
					// add one again if the Destination Path asked for it.
					$result = rtrim($result, '/');
					if (substr($destination, -1) === '/') {
						$result .= '/';
					}

					return $result;
				}, $request);

				// Perform any mappings
				$convertedWildcards = preg_replace_callback("~\(([\w-_\/]+)\|([a-z]+)\)~i", function ($mapCaptures) use ($compiledCollections) {
					if (isset($compiledCollections->{$mapCaptures[2]}[$mapCaptures[1]])) {
						// If we have a match, bring it in
						return $compiledCollections->{$mapCaptures[2]}[$mapCaptures[1]];
					} else {
						// Otherwise, fill the mapping wildcard with the original data
						return $mapCaptures[1];
					}
				}, $convertedWildcards);

				$this->log("Destination Path (Original):  $redirect->destination", true);
				$this->log("Destination Path (Compiled):  $destination", true);

				// Check for Timed Activation and determine if we're in the period specified
				$time = time();
				$activated = ($starts || $ends)
				? ($time >= $starts && $time <= $ends)
				: true;

				// If we're not debugging, and we're Time-activated, then do the redirect
				if (!$this->{Config::MODULE_DEBUG} && $activated) {
					$this->session->redirect($convertedWildcards, !$activated);
				}

				// Otherwise, continue logging
				$this->log("Destination Path (Converted): {$convertedWildcards}", true, true);
				$type = ($starts) ? '302, temporary' : '301, permanent';
				$this->log("Match found! We'll do the following redirect ({$type}) when Debug Mode has been turned off:", false, true);
				$this->log("From URL:   {$requestedUrlFirstPart}/{$request}", true);
				$this->log("To URL:     {$convertedWildcards}", true);

				// If we're in the period specified:
				if (!$activated) {
					// If it ends before it starts, then show the time it starts.
					// Otherwise, show the period.
					if ($ends < $starts) {
						$this->log(sprintf("Timed:      From %s onwards", date('r', $starts)), true);
					} else {
						$this->log(sprintf("Timed:      From %s to %s", date('r', $starts), date('r', $ends)), true);
					}
				}

				// We can exit at this point.
				if ($this->{Config::MODULE_DEBUG}) {
					die();
				}
			}

			// If there were no available redirect definitions,
			// then inform the debugger.
			$this->log("\nNo match there...", false, true);
		}

		// Considering we don't have one available, let's check to see if the Source Path
		// exists on the Legacy Domain, if defined.
		if (!empty(trim($this->{Config::LEGACY_DOMAIN}))) {
			// Fetch the accepted codes
			$okCodes = trim(!empty($this->{Config::STATUS_CODES}))
			? array_map('trim', explode(' ', $this->{Config::STATUS_CODES}))
			: explode(',', $this->statusCodes);

			// Prepare and do the request
			$domainRequest = $this->{Config::LEGACY_DOMAIN} . $request;
			$status = $this->getResponseCode($domainRequest);

			// If the response has an accepted code, then redirect (or log)
			if (in_array($status, $okCodes)) {
				$this->log("Found Source Path on Legacy Domain (with status code {$status}); redirect allowed to:");
				$this->log($domainRequest, true, false, true);
				$this->session->redirect($domainRequest, false);
			}
		}

		// If all fails, say so.
		$this->log("No matches, sorry...");
		if ($this->{Config::MODULE_DEBUG}) {
			die();
		}

	}
}
