<?php

/**
 * ProcessJumplinks - a ProcessWire Module by Mike Rockett
 * Manage permanent and temporary redirects. Uses named wildcards and mapping collections.
 *
 * Compatible with ProcessWire 2.6.1+
 *
 * Copyright (c) 2015, Mike Rockett. All Rights Reserved.
 * Licence: MIT License - http://mit-license.org/
 *
 * @see https://github.com/mikerockett/ProcessJumplinks/wiki [Documentation]
 * @see https://mods.pw/92 [Modules Directory Page]
 * @see https://processwire.com/talk/topic/8697-jumplinks/ [Support/Discussion Thread]
 * @see https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=L8F6FFYK6ENBQ [PayPal Donation]
 */

class ProcessJumplinks extends Process
{

    /** Schema version for current release */
    const schemaVersion = 4;

    /** NULL Date **/
    const NULL_DATE = '0000-00-00 00:00:00';

    /**
     * Determine if the text/plain header is set
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
    protected $tableName = 'process_jumplinks';

    /**
     * The base table name for ProcessRedirects
     * @var string
     */
    protected $redirectsTableName = 'ProcessRedirects'; // This is the default, and will be checked for case

    /**
     * Paths to forms
     * @var string
     */
    protected $entityFormPath = 'entity/';
    protected $mappingCollectionFormPath = 'mappingcollection/';
    protected $importPath = 'import/';
    protected $clearNotFoundLogPath = 'clearnotfoundlog/';

    /**
     * Lowest date - for use when working with timestamps
     * @var string
     */
    protected $lowestDate = '1974-10-10';

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
    protected $smartWildcards = array(
        'all' => 'all',
        'ext' => 'ext',
        'name|title|page|post|user|model|entry' => 'segment',
        'path|segments' => 'segments',
        'year|month|day|id|num' => 'num',
    );

    /**
     * Inject assets (used as assets are automatically inserted when
     * using the same name as the module, but the get thrown in before
     * JS dependencies. WireTabs also gets thrown in.)
     * @return [type] [description]
     */
    protected function injectAssets()
    {
        // Inject script and style
        $moduleAssetPath = "{$this->config->urls->ProcessJumplinks}Assets";
        $this->config->scripts->add("{$moduleAssetPath}/ProcessJumplinks.min.js");
        $this->config->styles->add("{$moduleAssetPath}/ProcessJumplinks.css");

        // Include WireTabs
        $this->modules->get('JqueryWireTabs');
    }

    /**
     * Class constructor
     * Init moduleInfo, sql
     */
    public function __construct()
    {
        $this->lowestDate = strtotime($this->lowestDate);

        $this->moduleInfo = wire('modules')->getModuleInfo($this, array('verbose' => true));

        // Get the correct table name for ProcessRedirects
        $redirectsTableNameQuery = $this->database->prepare('SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = :db_name AND TABLE_NAME LIKE :table_name');
        $redirectsTableNameQuery->execute(array(
            'db_name' => $this->config->dbName,
            'table_name' => $this->redirectsTableName,
        ));
        if ($redirectsTableNameQuery->rowCount() > 0) {
            $redirectsTableNameResult = $redirectsTableNameQuery->fetch(PDO::FETCH_OBJ)->TABLE_NAME;
            if ($this->redirectsTableName == $redirectsTableNameResult ||
                strtolower($this->redirectsTableName) == $redirectsTableNameResult) {
                $this->redirectsTableName = $redirectsTableNameResult;
            }
        }

        $this->sql = (object) array(
            'entity' => (object) array(
                'selectAll' => "SELECT * FROM {$this->tableName} ORDER BY source",
                'selectOne' => "SELECT * FROM {$this->tableName} WHERE id = :id",
                'dropOne' => "DELETE FROM {$this->tableName} WHERE id = :id",
                'insert' => "INSERT INTO {$this->tableName} SET source = :source, destination = :destination, hits = :hits, date_start = :date_start, date_end = :date_end, user_created = :user_created, user_updated = :user_updated, created_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP ON DUPLICATE KEY UPDATE id = id",
                'update' => "UPDATE {$this->tableName} SET source = :source, destination = :destination, date_start = :date_start, date_end = :date_end, user_updated = :user_updated, updated_at = CURRENT_TIMESTAMP WHERE id = :id",
                'updateHits' => "UPDATE {$this->tableName} SET hits = :hits WHERE id = :id",
                'updateLastHitDate' => "UPDATE {$this->tableName} SET last_hit = :last_hit WHERE id = :id",
            ),
            'collection' => (object) array(
                'selectAll' => "SELECT * FROM {$this->tableName}_mc ORDER BY collection_name",
                'selectOne' => "SELECT * FROM {$this->tableName}_mc WHERE id = :id",
                'selectOneByName' => "SELECT * FROM {$this->tableName}_mc WHERE collection_name = :collection",
                'dropOne' => "DELETE FROM {$this->tableName}_mc WHERE id = :id",
                'insert' => "INSERT INTO {$this->tableName}_mc SET collection_name = :collection, collection_mappings = :mappings, user_created = :user_created, user_updated = :user_updated, created_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP ON DUPLICATE KEY UPDATE id = id",
                'update' => "UPDATE {$this->tableName}_mc SET collection_name = :collection, collection_mappings = :mappings, user_updated = :user_updated WHERE id = :id",
            ),
            'notFoundMonitor' => (object) array(
                'selectAll' => "SELECT * FROM {$this->tableName}_nf ORDER BY created_at DESC LIMIT 100",
                'insert' => "INSERT INTO {$this->tableName}_nf SET request_uri = :request_uri, referrer = :referrer, user_agent = :user_agent, created_at = CURRENT_TIMESTAMP ON DUPLICATE KEY UPDATE id = id",
                'deleteAll' => "TRUNCATE TABLE {$this->tableName}_nf",
            ),
        );
    }

    /**
     * Module initialisation
     * @hook ProcessPageView::pageNotFound to scanAndRedirect
     */
    public function init()
    {

        parent::init();

        // Set the admin page URL for JS
        $this->config->js('pjAdminPageUrl', $this->pages->get('name=jumplinks,template=admin')->url);

        // Make sure schemas are up to date
        if ($this->schemaVersion < self::schemaVersion) {
            $this->updateDatabaseSchema();
        }

        // Set the request (URI), and trim off the leading slash,
        // as we won't be needing it for comparison.
        $this->request = ltrim(@$_SERVER['REQUEST_URI'], '/');

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
                "{$this->config->urls->root}index.php.pwpj\\1",
                $this->request
            ));
        }

        // Hook prior to the pageNotFound event ...
        $this->addHookBefore('ProcessPageView::pageNotFound', $this, 'scanAndRedirect', array('priority' => 10));
    }

    /**
     * Update database schema
     * This method applies incremental updates until latest schema version is
     * reached, while also keeping schemaVersion config setting up to date.
     */
    private function updateDatabaseSchema()
    {
        while ($this->_schemaVersion < self::schemaVersion) {
            ++$this->_schemaVersion;
            $memoryVersion = $this->_schemaVersion;
            switch (true) {
                case ($memoryVersion <= 4):
                    $statement = $this->blueprint("schema-update-v{$memoryVersion}");
                    break;
                default:
                    throw new WireException("[Jumplinks] Unrecognized database schema version: {$memoryVersion}");
            }
            if ($statement && $this->database->exec($statement) !== false) {
                $configData = $this->modules->getModuleConfigData($this);
                $configData['_schemaVersion'] = $memoryVersion;
                $this->modules->saveModuleConfigData($this, $configData);
                $this->message($this->_('[Jumplinks] Schema updates applied.'));
            } else {
                throw new WireException("[Jumplinks] Couldn't update database schema to version {$memoryVersion}");
            }
        }
    }

    /**
     * Generate help link (contextual)
     * @param  string $uri
     * @return string
     */
    protected function helpLinks($uri = '', $justTheLink = false)
    {
        if (!empty($uri)) {
            $uri = "/{$uri}";
        }

        if ($justTheLink) {
            return $this->moduleInfo['href'] . $uri;
        } else {
            $supportDevelopment = $this->_('Support Development');
            $needHelp = $this->_('Need Help?');
            $documentation = $this->_('Documentation');
            return "<div class=\"pjHelpLink\"><a class=\"paypal\" target=\"_blank\" href=\"https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=L8F6FFYK6ENBQ\">{$supportDevelopment}</a><a target=\"_blank\" href=\"https://processwire.com/talk/topic/8697-jumplinks/\">{$needHelp}</a><a target=\"_blank\" href=\"{$this->moduleInfo['href']}{$uri}\">{$documentation}</a></div>";
        }
    }

    /**
     * Create a blueprint from file and give it some variables.
     * @caller multiple
     * @param  string $name
     * @param  array  $data
     * @return string
     */
    protected function blueprint($name, $data = array())
    {
        // Require the Blueprint parser
        require_once __DIR__ . '/Classes/Blueprint.php';

        $blueprint = new Blueprint($name);

        $data = array_filter($data);

        if (empty($data)) {
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
    protected function compileDestinationUrl($destination, $renderForOutput = false)
    {
        $pageIdentifier = 'page:';
        $usingPageIdentifier = substr($destination, 0, 5) === $pageIdentifier;

        // Check if we're not using a page identifier or selector
        if (!$usingPageIdentifier) {
            // Check to see if we're working with an absolute URL
            // and if we're currently using HTTPS
            $hasScheme = (bool) parse_url($destination, PHP_URL_SCHEME);
            $https = ($this->config->https) ? 's' : '';

            // If URL is absolute, then skip the prefix, otherwise build it
            $prefix = ($hasScheme) ? '' : "http{$https}://{$this->config->httpHost}/";

            // If we're rendering for backend output, truncate and return the destination.
            // Otherwise, return the full destination.
            return ($renderForOutput)
                ? $this->truncate($destination)
                : $prefix . $destination;
        } else {
            // If we're using a page identifier, fetch it
            $pageId = str_replace($pageIdentifier, '', $destination);
            $page = $this->pages->get((int) $pageId);

            // If it's a valid page, then get its URL
            if ($page->id) {
                $destination = ltrim($page->path, '/');
                if (empty($destination)) {
                    $destination = '/';
                }
                if ($renderForOutput) {
                    $destination = "<abbr title=\"{$page->title} ({$page->httpUrl})\">{$destination}</abbr>";
                }
            }

            return $destination;
        }

    }

    /**
     * Fetch the URI to the module's config page
     * @caller multiple
     * @return string
     */
    protected function getModuleConfigUri()
    {
        return "{$this->config->urls->admin}module/edit?name={$this->moduleInfo['name']}";
        // ^ Better way to get this URI?
    }

    /**
     * Clean a passed wildcard value
     * @caller scanAndRedirect
     * @param  string $input
     * @param  bool   $noLower
     * @return string
     */
    public function cleanWildcard($input, $noLower = false)
    {
        if ($this->enhancedWildcardCleaning) {
            // Courtesy @sln on StackOverflow
            $input = preg_replace_callback("~([A-Z])([A-Z]+)(?=[A-Z]|\b)~", function ($captures) {
                return $captures[1] . strtolower($captures[2]);
            }, $input);
            $input = preg_replace("~(?<=\\w)(?=[A-Z])~", "-\\1\\2", $input);
        }

        $input = preg_replace("~%u([a-f\d]{3,4})~i", "&#x\\1;", urldecode($input));
        $input = preg_replace("~[^\\pL\d\/]+~u", '-', $input);
        $input = iconv('utf-8', 'us-ascii//TRANSLIT', $input);

        if ($this->enhancedWildcardCleaning) {
            $input = preg_replace("~(\d)([a-z])~i", "\\1-\\2", preg_replace("~([a-z])(\d)~i", "\\1-\\2", $input));
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
    protected function truncate($string, $length = 55)
    {
        if (strlen($string) > $length) {
            return substr($string, 0, $length) . " <span class=\"ellipses\" title=\"{$string}\">...</span>";
        } else {
            return $string;
        }
    }

    /**
     * Given a fieldtype, create, populate, and return an Inputfield
     * @param  string $fieldNameId
     * @param  array  $meta
     * @return Inputfield
     */
    protected function buildInputField($fieldNameId, $meta)
    {
        $field = $this->modules->get($fieldNameId);

        foreach ($meta as $metaNames => $metaInfo) {
            $metaNames = explode('+', $metaNames);
            foreach ($metaNames as $metaName) {
                $field->$metaName = $metaInfo;
            }

        }

        return $field;
    }

    /**
     * Given a an Inputfield, add props and return
     * @param  string $field
     * @param  array  $meta
     * @return Inputfield
     */
    protected function populateInputField($field, $meta)
    {
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
    protected function getResponseCode($request)
    {
        stream_context_set_default(array(
            'http' => array(
                'method' => 'HEAD',
            ),
        ));

        $response = get_headers($request);

        return substr($response[0], 9, 3);
    }

    /**
     * Determine if the current user has debug rights.
     * Must have relevant permission, and debug mode must be turned on.
     * @return bool
     */
    protected function userHasDebugRights()
    {
        return ($this->moduleDebug && $this->user->hasPermission('jumplinks-admin'));
    }

    /**
     * Log something. Will set plain text header if not already set.
     * @caller scanAndRedirect
     * @param  string $message
     * @param  bool   $indent
     * @param  bool   $break
     * @param  bool   $die
     */
    protected function log($message, $indent = false, $break = false, $die = false)
    {
        if ($this->userHasDebugRights()) {
            if (!$this->headerSet) {
                header('Content-Type: text/plain');
                $this->headerSet = true;
            }

            $indent = ($indent) ? '- ' : '';
            $break = ($break) ? "\n" : '';

            print str_replace('.pwpj', '', "{$indent}{$message}\n{$break}");

            if ($die) {
                die();
            }
        }
    }

    /**
     * Log 404 to monitor
     * @caller scanAndRedirect
     * @param  string $request
     */
    protected function log404($request)
    {

        $this->database->prepare($this->sql->notFoundMonitor->insert)->execute(array(
            'request_uri' => $request,
            'referrer' => @$_SERVER['HTTP_REFERER'],
            'user_agent' => $_SERVER['HTTP_USER_AGENT'],
        ));
    }

    /**
     * The fun part.
     * @caller Hook: ProcessPageView::pageNotFound
     */
    protected function scanAndRedirect()
    {

        $jumplinks = $this->db->query($this->sql->entity->selectAll);

        $request = $this->request;

        if ($jumplinks->num_rows === 0) {
            $this->log404($request);
            return false;
        }

        $this->log('Page not found; scanning for jumplinks...');

        $requestedUrlFirstPart = 'http' . ((@$_SERVER['HTTPS'] == 'on') ? 's' : '') . "://{$_SERVER['HTTP_HOST']}";

        // Do some logging
        $this->log('Checked at: ' . date('r'), true);
        $this->log("Requested URL: {$requestedUrlFirstPart}/{$request}", true);
        $this->log("PW Version: {$this->config->version}\n\n== START ==", true, true);

        $rootUrl = $this->config->urls->root;

        if ($rootUrl !== '/') {
            $request = substr($request, strlen($rootUrl) - 1);
        }

        // Get the available wildcards, prepare for pattern match
        $availableWildcards = '';
        foreach ($this->wildcards as $wildcard => $expression) {
            $availableWildcards .= "{$wildcard}|";
        }

        $availableWildcards = rtrim($availableWildcards, '|');

        // Assign the wildcard pattern check
        $pattern = '~\{!?([a-z]+):(' . $availableWildcards . ')\}~';

        // Begin the loop
        while ($jumplink = $jumplinks->fetch_object()) {

            $starts = (strtotime($jumplink->date_start) > $this->lowestDate) ? strtotime($jumplink->date_start) : false;
            $ends = (strtotime($jumplink->date_end) > $this->lowestDate) ? strtotime($jumplink->date_end) : false;

            $this->log("[Checking jumplink #{$jumplink->id}]");

            // Timed Activation:
            // If it ends, but doesn't start, then make it start now
            if ($ends && !$starts) {
                $starts = time();
            }

            // If it starts (which it will always do), but doesn't end,
            // then set a dummy timestamp that is always in the future.
            $dummyEnd = false;
            $message = '';
            if ($starts && !$ends) {
                $ends = time() + (60 * 60);
                $dummyEnd = true;
                $message = '(has no ending, using dummy timestamp)';
            }

            // Log the activation periods for debugging
            if ($starts || $ends) {
                $this->log(sprintf("Timed Activation:             %s to %s {$message}", date('r', $starts), date('r', $ends)), true);
            }

            $this->log("Original Source Path:         {$jumplink->source}", true);

            // Prepare the Source Path for matching:
            // First, escape ? (and reverse /\?) & :, and rename index.php so we can make use of such requests.
            // Then, convert '[character]' to 'character?' for matching.
            $source = preg_replace('~\[([a-z0-9\/])\]~i', "\\1?", str_replace(
                array('?', '/\?', '&', ':', 'index.php'),
                array('\?', '/?', '\&', '\:', 'index.php.pwpj'),
                $jumplink->source));

            // Reverse : escaping for wildcards
            $source = preg_replace("~\{([a-z]+)\\\:([a-z]+)\}~i", "{\\1:\\2}", $source);

            if ($source !== $jumplink->source) {
                $this->log("Escaped Source Path:          {$source}", true);
            }

            // Compile the destination URL
            $destination = $this->compileDestinationUrl($jumplink->destination);

            // Setup capture prevention
            $nonCaptureMatcher = "~<(.*?)>~";
            if (preg_match($nonCaptureMatcher, $source)) {
                $source = preg_replace($nonCaptureMatcher, "(?:\\1)", $source);
            }

            // Prepare Smart Wildcards - replace them with their equivalent standard ones.
            $hasSmartWildcards = false;
            foreach ($this->smartWildcards as $wildcard => $wildcardType) {
                $smartWildcardMatcher = "~\{($wildcard)\}~i";
                if (preg_match($smartWildcardMatcher, $source)) {
                    $source = preg_replace($smartWildcardMatcher, "{\\1:{$wildcardType}}", $source);
                    $hasSmartWildcards = true;
                }
            }

            $computedReplacements = array();

            // Convert wildcards into expressions for replacement
            $computedWildcards = preg_replace_callback($pattern, function ($captures) use (&$computedReplacements) {
                $computedReplacements[] = $captures[1];
                return "({$this->wildcards[$captures[2]]})";
            }, $source);

            // Some more logging
            if ($hasSmartWildcards) {
                $this->log("After Smart Wildcards:        {$source}", true);
            }

            $this->log("Compiled Source Path:         {$computedWildcards}", true);

            // If the request matches the source currently being checked:
            if (preg_match("~^$computedWildcards$~i", $request)) {

                // For the purposes of mapping, fetch all the collections and compile them
                $collections = $this->db->query($this->sql->collection->selectAll);
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
                            $wildcardCleaning = $this->wildcardCleaning;
                            if ($wildcardCleaning === 'fullClean' || $wildcardCleaning === 'semiClean') {
                                $captures[$c] = $this->cleanWildcard($captures[$c], ($wildcardCleaning === 'fullClean') ? false : true);
                            }

                        }
                        $openingTag = (preg_match($paramSkipCleanCheck, $result)) ? '{!' : '{';
                        $result = str_replace($openingTag . $value . '}', $captures[$c], $result);

                        // In preparation for wildcard mapping,
                        // Swap out any mapping wildcards with their uncleaned values
                        $value = preg_quote($value);
                        $result = preg_replace("~\{{$value}\|([a-z]+)\}~i", "($uncleanedCapture|\\1)", $result);
                        $this->log("> Wildcard Check:             {$c}> {$value} = {$uncleanedCapture} -> {$captures[$c]}", true);
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
                    // If we have a match, bring it in
                    // Otherwise, fill the mapping wildcard with the original data
                    if (isset($compiledCollections->{$mapCaptures[2]}[$mapCaptures[1]])) {
                        return $compiledCollections->{$mapCaptures[2]}[$mapCaptures[1]];
                    } else {
                        return $mapCaptures[1];
                    }
                }, $convertedWildcards);

                // Check for any selectors and get the respective page
                $selectorUsed = false;
                $selectorMatched = false;

                $convertedWildcards = preg_replace_callback("~\[\[([\w-_\/\s=\",.']+)\]\]~i", function ($selectorCaptures) use (&$selectorUsed, &$selectorMatched) {
                    $selectorUsed = true;
                    $page = $this->pages->get($selectorCaptures[1]);
                    if ($page->id > 0) {
                        $selectorMatched = true;
                        return ltrim($page->url, '/');
                    }
                }, $convertedWildcards);

                $this->log("Original Destination Path:    {$jumplink->destination}", true);

                // If a match was found, but the selector didn't return a page, then continue the loop
                if ($selectorUsed && !$selectorMatched) {
                    $this->log("\nWhilst a match was found, the selector you specified didn't return a page. So, this jumplink will be skipped.", false, true);
                    continue;
                }

                $this->log("Compiled Destination Path:    {$convertedWildcards}", true, true);

                // Check for Timed Activation and determine if we're in the period specified
                $time = time();
                $activated = ($starts || $ends)
                    ? ($time >= $starts && $time <= $ends)
                    : true;

                // If we're not debugging, and we're Time-activated, then do the redirect
                if (!$this->userHasDebugRights() && $activated) {
                    $hitsPlusOne = $jumplink->hits + 1;
                    $this->database->prepare($this->sql->entity->updateHits)->execute(array(
                        'hits' => $hitsPlusOne,
                        'id' => $jumplink->id,
                    ));
                    $this->database->prepare($this->sql->entity->updateLastHitDate)->execute(array(
                        'last_hit' => date('Y-m-d H:i:s'),
                        'id' => $jumplink->id,
                    ));
                    $this->session->redirect($convertedWildcards, !($starts || $ends));
                }

                // Otherwise, continue logging
                $type = ($starts) ? '302, temporary' : '301, permanent';
                $this->log("Match found! We'll do the following redirect ({$type}) when Debug Mode has been turned off:", false, true);
                $this->log("From URL:   {$requestedUrlFirstPart}/{$request}", true);
                $this->log("To URL:     {$convertedWildcards}", true);

                if ($starts || $ends) {
                    // If it ends before it starts, then show the time it starts.
                    // Otherwise, show the period.
                    if ($dummyEnd) {
                        $this->log(sprintf('Timed:      From %s onwards', date('r', $starts)), true);
                    } else {
                        $this->log(sprintf('Timed:      From %s to %s', date('r', $starts), date('r', $ends)), true);
                    }
                }

                // We can exit at this point.
                if ($this->userHasDebugRights()) {
                    die();
                }
            }

            // If there were no available redirect definitions,
            // then inform the debugger.
            $this->log("\nNo match there...", false, true);
        }

        // Considering we don't have one available, let's check to see if the Source Path
        // exists on the Legacy Domain, if defined.
        $legacyDomain = trim($this->legacyDomain);
        if (!empty($legacyDomain)) {
            // Fetch the accepted codes
            $okCodes = trim(!empty($this->statusCodes))
                ? array_map('trim', explode(' ', $this->statusCodes))
                : explode(',', $this->statusCodes);

            // Prepare and do the request
            $domainRequest = $this->legacyDomain . $request;
            $status = $this->getResponseCode($domainRequest);

            // If the response has an accepted code, then 302 redirect (or log)
            if (in_array($status, $okCodes)) {
                if (!$this->userHasDebugRights()) {
                    $this->session->redirect($domainRequest, false);
                }

                $this->log("Found Source Path on Legacy Domain (with status code {$status}); redirect allowed to:");
                $this->log($domainRequest, true, false, true);
            }
        }

        // If set in config, log 404 hits to the database
        if ($this->enable404Monitor == true) {
            $this->log404($request);
        }

        // If all fails, say so.
        $this->log("No matches, sorry. We'll let your 404 error page take over when Debug Mode is turned off.");
        if ($this->userHasDebugRights()) {
            die();
        }
    }

    /**
     * Admin Page: Module Root
     * @return string
     */
    public function ___execute()
    {
        // Assets
        $this->injectAssets();

        // Get Jumplinks
        $jumplinks = $this->db->query($this->sql->entity->selectAll);

        // Set Page title
        $this->setFuel('processHeadline', $this->_('Manage Jumplinks'));

        // Assign the main container (wrapper)
        $tabContainer = new InputfieldWrapper();

        // Add the Jumplinks tab
        $jumplinksTab = new InputfieldWrapper();
        $jumplinksTab->attr('title', 'Jumplinks');

        // Setup the datatable
        $jumplinksTable = $this->modules->get('MarkupAdminDataTable');
        $jumplinksTable->setEncodeEntities(false);
        $jumplinksTable->setClass('jumplinks redirects');
        $jumplinksTable->headerRow(array($this->_('Source'), $this->_('Destination'), $this->_('Start'), $this->_('End'), $this->_('Hits')));

        // Setup and add the tab description markup
        $pronoun = $this->_n('it', 'one', $jumplinks->num_rows);
        if ($jumplinks->num_rows == 0) {
            $description = $this->_("You don't have any jumplinks yet.");
        } else {
            $description = $this->_n('You have one jumplink registered.', 'Your jumplinks are listed below.', $jumplinks->num_rows) . ' ' . sprintf($this->_('To edit/delete %s, simply click on its Source.'), $pronoun);
        }
        $jumplinksDescriptionMarkup = $this->modules->get('InputfieldMarkup');
        $jumplinksDescriptionMarkup->value = $description;
        $jumplinksTab->append($jumplinksDescriptionMarkup);

        // Work through each jumplink, formatting data as we go along.
        $hits = 0;
        while ($jumplink = $jumplinks->fetch_object()) {
            // Source and Destination
            $jumplink->source = htmlentities($jumplink->source);
            $jumplink->destination = $this->compileDestinationUrl($jumplink->destination, true);

            // Timed Activation columns
            if (strtotime($jumplink->date_start) < $this->lowestDate) {
                $jumplink->date_start = null;
            }
            if (strtotime($jumplink->date_end) < $this->lowestDate) {
                $jumplink->date_end = null;
            }
            $relativeStartTime = str_replace('Never', '', wireRelativeTimeStr($jumplink->date_start, true));
            $relativeEndTime = str_replace('Never', '', wireRelativeTimeStr($jumplink->date_end, true));
            $relativeStartTime = ($relativeStartTime === '-')
                ? $relativeStartTime
                : "<abbr title=\"{$jumplink->date_start}\">{$relativeStartTime}</abbr>";
            $relativeEndTime = ($relativeEndTime === '-')
                ? $relativeEndTime
                : "<abbr title=\"{$jumplink->date_end}\">{$relativeEndTime}</abbr>";
            $relativeLastHit = wireRelativeTimeStr($jumplink->last_hit, true);

            // Format the Hits column to show the last hit date in a tooltip.
            $jumplinkHits = (strtotime($jumplink->last_hit) < $this->lowestDate)
                ? $jumplink->hits
                : "<abbr title=\"Last hit: {$relativeLastHit} ($jumplink->last_hit)\">{$jumplink->hits}</abbr>";

            // If the last hit was more than 30 days ago,
            // let the user know so that it may be deleted.
            if (strtotime($jumplink->last_hit) > $this->lowestDate &&
                strtotime($jumplink->last_hit) < strtotime('-30 days')) {
                $jumplinkHits .= '<span id="staleJumplink"></span>';
            }
            $hits = $hits + $jumplink->hits;

            // Add the row, now that the data has been formatted.
            $jumplinksTable->row(array(
                $this->truncate($jumplink->source, 80) => "{$this->entityFormPath}?id={$jumplink->id}",
                $jumplink->destination,
                $relativeStartTime,
                $relativeEndTime,
                $jumplinkHits,
            ));
        }

        // Register button setup
        switch ($jumplinks->num_rows) {
            case 0:
                $registerJumplinkButtonLabel = $this->_('Register First Jumplink');
                break;
            case 1:
                $registerJumplinkButtonLabel = $this->_('Register Another Jumplink');
                break;
            default:
                $registerJumplinkButtonLabel = $this->_('Register New Jumplink');
                break;
        }

        // Close the query
        $jumplinks->close();

        // Build Register button
        $registerJumplinkButton = $this->populateInputField($this->modules->get('InputfieldButton'), array(
            'id' => 'registerJumplink',
            'href' => $this->entityFormPath,
            'value' => $registerJumplinkButtonLabel,
            'icon' => 'plus-circle',
        ))->addClass('head_button_clone');

        // Build config button
        $moduleConfigLinkButton = $this->populateInputField($this->modules->get('InputfieldButton'), array(
            'id' => 'moduleConfigLink',
            'href' => $this->getModuleConfigUri(),
            'value' => $this->_('Configuration'),
            'icon' => 'cog',
        ))->addClass('ui-priority-secondary ui-button-float-right');

        // Add buttons
        $buttons = $registerJumplinkButton->render() . $moduleConfigLinkButton->render();

        // Render and append the table container
        $jumplinksTableContainer = $this->modules->get('InputfieldMarkup');
        $jumplinksTableContainer->value = $jumplinksTable->render() . $buttons;
        $jumplinksTab->append($jumplinksTableContainer);

        // Add the Mapping Collections tab
        $mappingCollectionsTab = new InputfieldWrapper();
        $mappingCollectionsTab->attr('title', $this->_('Mapping Collections'));
        $mappingCollectionsTab->id = 'mappingCollections';

        // Get Mapping Collections
        $mappingCollections = $this->db->query($this->sql->collection->selectAll);

        // Setup the data table
        $mappingCollectionsTable = $this->modules->get('MarkupAdminDataTable');
        $mappingCollectionsTable->setEncodeEntities(false);
        $mappingCollectionsTable->setClass('jumplinks mapping-collections');
        $mappingCollectionsTable->setSortable(false);
        $mappingCollectionsTable->headerRow(array($this->_('Collection Name'), $this->_('Mappings'), $this->_('Created'), $this->_('Last Modified')));

        // Setup the description markup
        if ($mappingCollections->num_rows === 0) {
            $pronoun = 'one';
            $head = $this->_("You don't have any collections installed.");
        } else {
            $head = $this->_n('You have one collection installed.', 'Your collections are listed below.', $mappingCollections->num_rows);
            $pronoun = $this->_n('it', 'one', $mappingCollections->num_rows);
        }
        $description = ($mappingCollections->num_rows === 0) ? '' : sprintf($this->_('To edit/uninstall %s, simply click on its Name.'), $pronoun);

        $mappingCollectionsDescriptionMarkup = $this->modules->get('InputfieldMarkup');
        $mappingCollectionsDescriptionMarkup->value = "{$head} {$description}";

        // Add the description markup.
        $mappingCollectionsTab->append($mappingCollectionsDescriptionMarkup);

        // Work through each collection.
        while ($mappingCollection = $mappingCollections->fetch_object()) {
            // Timestamps
            $userCreated = $this->users->get($mappingCollection->user_created)->name;
            $userUpdated = $this->users->get($mappingCollection->user_updated)->name;
            $created = wireRelativeTimeStr($mappingCollection->created_at) . sprintf($this->_('by %s'), $userCreated);
            $updated = wireRelativeTimeStr($mappingCollection->updated_at) . sprintf($this->_('by %s'), $userUpdated);
            if ($mappingCollection->created_at === $mappingCollection->updated_at) {
                $updated = '';
            }

            // Add the collection
            $mappingCollectionsTable->row(array(
                $mappingCollection->collection_name => "{$this->mappingCollectionFormPath}?id={$mappingCollection->id}",
                count(explode("\n", trim($mappingCollection->collection_mappings))),
                $created,
                $updated,
            ));
        }

        // Install button label setup
        $installMappingCollectionButtonLabel = ($mappingCollections->num_rows === 1) ? $this->_('Install Another Mapping Collection') : $this->_('Install New Mapping Collection');

        // Close the query
        $mappingCollections->close();

        // Install button setup
        $installMappingCollectionButton = $this->populateInputField($this->modules->get('InputfieldButton'), array(
            'id' => 'installMappingCollection',
            'href' => $this->mappingCollectionFormPath,
            'value' => $installMappingCollectionButtonLabel,
            'icon' => 'plus-circle',
        ));

        // Add the button
        $buttons = $installMappingCollectionButton->render();

        // Add the description and table.
        $mappingCollectionsTableContainer = $this->modules->get('InputfieldMarkup');
        $mappingCollectionsTableContainer->value = $mappingCollectionsTable->render() . $buttons;
        $mappingCollectionsTab->append($mappingCollectionsTableContainer);

        // Add Import tab
        $importTab = new InputfieldWrapper();
        $importTab->attr('title', $this->_('Import'));
        $importTab->id = 'import';

        // Setup description markup.
        $infoContainer = $this->modules->get('InputfieldMarkup');
        if ($this->modules->isInstalled('ProcessRedirects')) {
            $infoContainer->value = $this->_('To import your jumplinks, select an option below:');
        } else {
            $infoContainer->value = $this->_('To import your jumplinks, click the button below:');
        }

        // Add description markup.
        $importTab->append($infoContainer);

        // Setup main container.
        $importContainer = $this->modules->get('InputfieldMarkup');

        // Setup CSV button
        $importFromCSVButton = $this->populateInputField($this->modules->get('InputfieldButton'), array(
            'id' => 'importFromCSV',
            'href' => "{$this->importPath}",
            'value' => $this->_('Import from CSV'),
        ));

        // Add button.
        $importContainer->value = $importFromCSVButton->render();

        // If ProcessRedirects is installed, add the applicable Import button.
        if ($this->modules->isInstalled('ProcessRedirects')) {
            $importFromRedirectsButton = $this->populateInputField($this->modules->get('InputfieldButton'), array(
                'id' => 'importFromRedirects',
                'href' => "{$this->importPath}?type=redirects",
                'value' => $this->_('Import from Redirects Module'),
            ))->addClass('ui-priority-secondary');
            $importContainer->value .= $importFromRedirectsButton->render();
        }

        // Append the container.
        $importTab->append($importContainer);

        // If the 404 monitor is enabled, add the tab and container.
        if ($this->enable404Monitor) {

            // Add 404 Monitor tab.
            $notFoundMonitorTab = new InputfieldWrapper();
            $notFoundMonitorTab->attr('title', $this->_('404 Monitor'));
            $notFoundMonitorTab->id = 'notFoundMonitor';

            // Get 404 hits.
            $notFoundEntities = $this->db->query($this->sql->notFoundMonitor->selectAll);

            // Setup the container.
            $infoContainer = $this->modules->get('InputfieldMarkup');

            // Setup the description.
            if ($notFoundEntities->num_rows === 0) {
                $infoContainer->value = $this->_("There have been no '404 Not Found' hits on your site.");
            } else if ($notFoundEntities->num_rows === 1) {
                $infoContainer->value = $this->_("Below is the last '404 Not Found' hit. To create a jumplink for it, simply click on its Request URI.");
            } else {
                $infoContainer->value = $this->_("Below are the last {$notFoundEntities->num_rows} '404 Not Found' hits. To create a jumplink for one, simply click on its Request URI.");
            }

            // Add description to tab container.
            $notFoundMonitorTab->append($infoContainer);

            // Setup the datatable.
            $notFoundMonitorTable = $this->modules->get('MarkupAdminDataTable');
            $notFoundMonitorTable->setEncodeEntities(false);
            $notFoundMonitorTable->setClass('jumplinks notFounds');
            $notFoundMonitorTable->setSortable(false);
            $notFoundMonitorTable->headerRow(array($this->_('Request URI'), $this->_('Referrer'), $this->_('User Agent'), $this->_('Date/Time')));

            // Get the UA parser
            require_once __DIR__ . '/Classes/ParseUserAgent.php';

            // Loop through each 404, formatting as we go along.
            while ($notFoundEntity = $notFoundEntities->fetch_object()) {
                $userAgentParsed = ParseUserAgent::get($notFoundEntity->user_agent);
                $source = urlencode($notFoundEntity->request_uri);

                // Add the 404 row.
                $notFoundMonitorTable->row(array(
                    $notFoundEntity->request_uri => "{$this->entityFormPath}?id=0&source={$source}",
                    (!is_null($notFoundEntity->referrer)) ? $notFoundEntity->referrer : '',
                    "<abbr title=\"{$notFoundEntity->user_agent}\">{$userAgentParsed['browser']} {$userAgentParsed['version']}</abbr>",
                    $notFoundEntity->created_at,
                ));
            }

            // Setup Clear Button
            $button = '';
            if ($notFoundEntities->num_rows > 0) {
                $clearNotFoundLogButton = $this->populateInputField($this->modules->get('InputfieldButton'), array(
                    'id' => 'clearNotFoundLog',
                    'href' => $this->clearNotFoundLogPath,
                    'value' => $this->_('Clear All'),
                    'icon' => 'times-circle',
                ));
                $button = $clearNotFoundLogButton->render();
            }

            // Close the query.
            $notFoundEntities->close();

            // Add the data table and button.
            $notFoundMonitorTableContainer = $this->modules->get('InputfieldMarkup');
            $notFoundMonitorTableContainer->value = $notFoundMonitorTable->render() . $button;

            // Add the 404 container.
            $notFoundMonitorTab->append($notFoundMonitorTableContainer);
        }

        // Add all tabs
        $tabContainer
            ->append($jumplinksTab)
            ->append($mappingCollectionsTab)
            ->append($importTab);
        if ($this->enable404Monitor) {
            $tabContainer->append($notFoundMonitorTab);
        }

        // Let backend know that we're adminstering jumplinks.
        $this->config->js('pjAdmin', true);

        // We have to wrap it in a form to prevent spacing underneath
        // the tabs. This goes hand in hand with a rule in the stylesheet.
        return "<form id=\"pjTabs\">{$tabContainer->render()}{$this->helpLinks()}</form>";
    }

    /**
     * Admin Page: Add/Edit Entity (Redirect)
     * @return string
     */
    public function ___executeEntity()
    {
        $this->injectAssets();

        // Get the ID if we're editing
        $editingId = (isset($this->input->get->id)) ? $this->input->get->id : 0;

        $this->setFuel('processHeadline', ($editingId > 0) ? $this->_('Editing Jumplink') : $this->_('Register New Jumplink'));

        if ($editingId > 0) {
            // Fetch the details and list vars
            $query = $this->database->prepare($this->sql->entity->selectOne);
            $query->execute(array(
                'id' => $editingId,
            ));
            list($id, $sourcePath, $destinationUriUrl, $hits,
                $userCreated, $userUpdated, $dateStart, $dateEnd,
                $createdAt, $updatedAt, $lastHit) = $query->fetch();

            // Format dates (times)
            $dateStart = (strtotime($dateStart) > $this->lowestDate) ? date('Y-m-d h:m A', strtotime($dateStart)) : null;
            $dateEnd = (strtotime($dateEnd) > $this->lowestDate) ? date('Y-m-d h:m A', strtotime($dateEnd)) : null;
        }

        // Prep the form
        $form = $this->modules->get('InputfieldForm');
        $form->id = 'pjInputForm';
        $form->method = 'POST';
        $form->action = '../commit/';

        // ID field
        $field = $this->modules->get('InputfieldHidden');
        $form->add($this->populateInputField($field, array(
            'name' => 'id',
            'value' => $editingId,
        )));

        if ($editingId > 0 &&
            strtotime($lastHit) > $this->lowestDate &&
            strtotime($lastHit) < strtotime('-30 days')) {

            $field = $this->modules->get('InputfieldMarkup');
            $form->add($this->populateInputField($field, array(
                'id' => 'staleJumplink',
                'value' => sprintf($this->_("This jumplink hasn't been hit in over 30 days (last hit on %s), and so it is safe to delete."), $lastHit),
            )));
        }

        if ($editingId == 0 && isset($this->input->get->source)) {
            $sourcePath = urldecode($this->input->get->source);
        }

        // Source Path field
        $field = $this->modules->get('InputfieldText');
        $form->add($this->populateInputField($field, array(
            'name+id' => 'sourcePath',
            'label' => $this->_('Source'),
            'description' => sprintf($this->_('Enter a URI relative to the root of your site. **[(see examples)](%1\$s/Examples)**'), $this->moduleInfo['href']),
            'required' => 1,
            'collapsed' => Inputfield::collapsedNever,
            'value' => isset($sourcePath) ? $sourcePath : '',
        )));

        // Destination fields
        $destinationFieldset = $this->buildInputField('InputfieldFieldset', array(
            'label' => __('Destination'),
        ));
        $destinationSelectorsFieldset = $this->buildInputField('InputfieldFieldset', array(
            'label' => __('or select one using...'),
            'notes' => $this->_('If you choose to not use either of the Page selectors below, be sure to enter a valid path above.'),
            'collapsed' => Inputfield::collapsedYes,
        ));
        $destinationPageField = $this->modules->get('InputfieldPageListSelect');
        $destinationPageAutoField = $this->modules->get('InputfieldPageAutocomplete');
        $destinationPathField = $this->modules->get('InputfieldText');

        // Check if the current destination is a page
        if (isset($destinationUriUrl) && $page = $this->pages->get((int) str_replace('page:', '', $destinationUriUrl))) {
            $isPage = (bool) $page->id;
            if ($isPage) {
                $destinationPageField->value = $page->id;
                $destinationPageAutoField->value = $page->id;
                $destinationPathField->collapsed = Inputfield::collapsedYes;
                $destinationSelectorsFieldset->collapsed = Inputfield::collapsedNo;
            } else {
                $destinationSelectorsFieldset->collapsed = Inputfield::collapsedYes;
                $destinationPathField->collapsed = Inputfield::collapsedBlank;
            }
        } else {
            $destinationPageField->collapsed = Inputfield::collapsedYes;
            $destinationPageAutoField->collapsed = Inputfield::collapsedYes;
        }

        // Destination Path field
        $destinationFieldset->add($this->populateInputField($destinationPathField, array(
            'name+id' => 'destinationUriUrl',
            'label' => $this->_('Specify a destination'),
            'description' => sprintf($this->_("Enter either a URI relative to the root of your site, an absolute URL, or a Page ID. **[(see examples)](%1\$s/Examples)**"), $this->moduleInfo['href']),
            'notes' => sprintf($this->_('If you select a page from either of the Page selectors below, its identifier will be placed here.'), $this->moduleInfo['href']),
            'required' => 1,
            'value' => isset($destinationUriUrl) ? $destinationUriUrl : '',
        )));

        // Select from tree
        $destinationSelectorsFieldset->add($this->populateInputField($destinationPageField, array(
            'name+id' => 'destinationPage',
            'label' => $this->_('Page Tree'),
            'parent_id' => 0,
            'startLabel' => $this->_('Choose a Page'),
        )));

        // Select via auto-complete
        $destinationSelectorsFieldset->add($this->populateInputField($destinationPageAutoField, array(
            'name+id' => 'destinationPageAuto',
            'label' => $this->_('Auto Complete'),
            'parent_id' => 0,
            'maxSelectedItems' => 1,
        )));

        $destinationFieldset->add($destinationSelectorsFieldset);
        $form->add($destinationFieldset);

        // Timed Activation fieldset
        $fieldSet = $this->modules->get('InputfieldFieldset');
        $fieldSet->label = $this->_('Timed Activation');
        $fieldSet->collapsed = Inputfield::collapsedYes;
        $fieldSet->description = $this->_("If you'd like this jumplink to only function during a specific time-range, then select the start and end dates and times below.");
        $fieldSet->notes = $this->_("You don't have to specify both. If you only specify a start time, you're simply delaying activation. If you only specify an end time, then you're simply telling it when to stop.\nIf an End Date/Time is specified, a temporary redirect will be made (302 status code, as opposed to 301).");

        $datetimeFieldDefaults = array(
            'datepicker' => 1,
            'timeInputFormat' => 'h:m A',
            'yearRange' => '-0:+100',
            'collapsed' => Inputfield::collapsedNever,
            'columnWidth' => 50,
        );

        // Start field
        $field = $this->modules->get('InputfieldDatetime');
        $fieldSet->add($this->populateInputField($field, array_merge(array(
            'name' => 'dateStart',
            'label' => $this->_('Start Date/Time'),
            'value' => (isset($dateStart)) ? $dateStart : '',
        ), $datetimeFieldDefaults)));

        // End field
        $field = $this->modules->get('InputfieldDatetime');
        $fieldSet->add($this->populateInputField($field, array_merge(array(
            'name' => 'dateEnd',
            'label' => $this->_('End Date/Time'),
            'value' => (isset($dateEnd)) ? $dateEnd : '',
        ), $datetimeFieldDefaults)));

        $form->add($fieldSet);

        // If we're editing:
        if ($editingId > 0) {
            // Get and ddd info markup
            $field = $this->modules->get('InputfieldMarkup');
            $userCreated = $this->users->get($userCreated);
            $userUpdated = $this->users->get($userUpdated);
            $userUrl = wire('config')->urls->admin . 'access/users/edit/?id=';
            $relativeTimes = array(
                'created' => wireRelativeTimeStr($createdAt),
                'updated' => wireRelativeTimeStr($updatedAt),
            );
            $lastHitFormatted = $this->_("This jumplink hasn't been hit yet.");
            if (strtotime($lastHit) > $this->lowestDate) {
                $lastHitFormatted = sprintf($this->_('Last hit on: %s (%s)'), $lastHit, wireRelativeTimeStr($lastHit));
            }

            $form->add($this->populateInputField($field, array(
                'id' => 'info',
                'label' => $this->_('Info'),
                'value' => $this->blueprint('entity-info', array(
                    'user-created-name' => $userCreated->name,
                    'user-updated-name' => $userUpdated->name,
                    'user-created-url' => $userUrl . $userCreated->id,
                    'user-updated-url' => $userUrl . $userUpdated->id,
                    'created-at' => $createdAt,
                    'created-at-relative' => $relativeTimes['created'],
                    'updated-at' => $updatedAt,
                    'updated-at-relative' => $relativeTimes['updated'],
                    'last-hit' => $lastHitFormatted,
                )),
            )));

            // Add Delete button
            $field = $this->modules->get('InputfieldCheckbox');
            $form->add($this->populateInputField($field, array(
                'name' => 'delete',
                'label' => $this->_('Delete'),
                'icon' => 'times-circle',
                'description' => $this->_("If you'd like to delete this jumplink, check the box below."),
                'label2' => $this->_('Delete this jumplink'),
                'collapsed' => Inputfield::collapsedYes,
            )));
        }

        // Save/Update button
        $field = $this->modules->get('InputfieldButton');
        $form->add($this->populateInputField($field, array(
            'name+id' => 'saveJumplink',
            'value' => ($editingId) ? $this->_('Update Jumplink') : $this->_('Save Jumplink'),
            'icon' => 'save',
            'type' => 'submit',
        ))->addClass('head_button_clone'));

        $this->config->js('pjEntity', true);

        // Return the rendered page
        return $form->render() . $this->helpLinks('Working-with-Jumplinks');
    }

    /**
     * Commit a new jumplink
     * @param  String $input
     * @param  int    $hits     = 0
     * @param  bool   $updating = false
     * @param  int    $id       = 0
     */
    protected function commitJumplink($input, $hits = 0, $updating = false, $id = 0)
    {

        $noWildcards = (
            false === strpos($input->destinationUriUrl, '{') &&
            false === strpos($input->destinationUriUrl, '}')
        );
        $isRelative = !(bool) parse_url($input->destinationUriUrl, PHP_URL_SCHEME);

        // If the Destination Path's URI matches that of a page, use a page ID instead
        if ($noWildcards && $isRelative) {
            if (($page = $this->pages->get('/' . trim($input->destinationUriUrl, '/'))) && $page->id) {
                $input->destinationUriUrl = "page:{$page->id}";
            }
        }

        // Escape Source and Destination (Sanitised) Paths
        $source = ltrim($this->db->escape_string($input->sourcePath), '/');
        $destination = ltrim($this->db->escape_string($this->sanitizer->url($input->destinationUriUrl)), '/');

        // Prepare dates (times) for database entry
        $start = (!isset($input->dateStart) || empty($input->dateStart)) ? self::NULL_DATE : date('Y-m-d H:i:s', strtotime(str_replace('-', '/', $input->dateStart)));
        $end = (!isset($input->dateEnd) || empty($input->dateEnd)) ? self::NULL_DATE : date('Y-m-d H:i:s', strtotime(str_replace('-', '/', $input->dateEnd)));

        // Set the user creating/updating
        if (!$updating) {
            $userCreated = $this->user->id;
        }

        $userUpdated = $this->user->id;

        // Insert/Update

        $dataBind = array(
            'source' => $source,
            'destination' => $destination,
            'date_start' => $start,
            'date_end' => $end,
            'user_updated' => $userUpdated,
        );

        if ($updating) {
            $query = $this->database->prepare($this->sql->entity->update);
            $dataBind['id'] = $id;
        } else {
            $query = $this->database->prepare($this->sql->entity->insert);
            $dataBind['hits'] = $hits;
            $dataBind['user_created'] = $userCreated;
        }
        $query->execute($dataBind);
    }

    /**
     * API method to add a new jumplink
     * @param String $source
     * @param String $destination
     * @param String $start
     * @param String $end
     */
    public function add($source, $destination, $start = '', $end = '')
    {
        $this->commitJumplink((object) array(
            'sourcePath' => $source,
            'destinationUriUrl' => $destination,
            'dateStart' => $start,
            'dateEnd' => $end,
        ));
    }

    /**
     * Admin Route: Commit new jumplink or update existing
     */
    public function ___executeCommit()
    {
        // Just to be on the safe side...
        if ($this->input->post->id == null) {
            $this->session->redirect('../');
        }

        $input = $this->input->post;

        // Set the ID and check if we're updating
        $id = (int) $input->id;
        $isUpdating = ($id !== 0);

        // If we're updating, check if we should delete
        if ($isUpdating && $input->delete) {
            $query = $this->database->prepare($this->sql->entity->dropOne);
            $query->execute(array(
                'id' => $id,
            ));
            $this->message($this->_('Jumplink deleted.'));
            $this->session->redirect('../');
        }

        // Otherwise, continue to commit jumplink to DB
        $this->commitJumplink($input, 0, $isUpdating, $id);

        $this->message($this->_('Jumplink saved.'));

        $this->session->redirect('../');
    }

    /**
     * Admin Page: Install/Uninstall Mapping Collections
     * @return string
     */
    public function ___executeMappingCollection()
    {
        $this->injectAssets();

        $this->setFuel('processHeadline', $this->_('Install New Mapping Collection'));

        // Get the ID if we're editing
        $editingId = (isset($this->input->get->id)) ? $this->input->get->id : 0;

        if ($editingId) {
            // Fetch the details and list vars
            $query = $this->database->prepare($this->sql->collection->selectOne);
            $query->execute(array(
                'id' => $editingId,
            ));
            list($id, $collectionName, $collectionData, $userCreated, $userUpdated, $updatedAt, $createdAt) = $query->fetch();

            $this->setFuel('processHeadline', $this->_("Editing Mapping Collection: {$collectionName}"));
        }

        // Prep the form
        $form = $this->modules->get('InputfieldForm');
        $form->id = 'pjInputForm';
        $form->method = 'POST';
        $form->action = '../commitmappingcollection/';

        // ID field
        $field = $this->modules->get('InputfieldHidden');
        $form->add($this->populateInputField($field, array(
            'name' => 'id',
            'value' => $editingId,
        )));

        // Mapping Name field
        $field = $this->modules->get('InputfieldText');
        $form->add($this->populateInputField($field, array(
            'name+id' => 'collectionName',
            'label' => $this->_('Name'),
            'notes' => $this->_('Only use alpha characters (a-z). Name will be sanitised upon submission. This name is the identifier to be used in mapping wildcards.'),
            'required' => 1,
            'collapsed' => Inputfield::collapsedNever,
            'value' => isset($collectionName) ? $collectionName : '',
        )));

        // Mapping Data field
        $field = $this->modules->get('InputfieldTextarea');
        $form->add($this->populateInputField($field, array(
            'name+id' => 'collectionData',
            'label' => $this->_('Mapping Data'),
            'description' => sprintf($this->_('Enter each mapping for this collection, one per line, in the following format: key=value. You will more than likely make use of this feature if you are mapping IDs to URL-friendly names, but you can use named identifiers too. To learn more about how this feature works, please [read through the documentation](%s).'), $this->helpLinks('Mapping-Collections', true)),
            'notes' => sprintf($this->_("To make things easier, you'll probably want to export your data from your old platform/framework in this format.\n**Note:** All **values** will be cleaned according to the 'Wildcard Cleaning' setting in the [module's configuration](%s)."), $this->getModuleConfigUri()),
            'required' => 1,
            'rows' => 10,
            'collapsed' => Inputfield::collapsedNever,
            'value' => isset($collectionData) ? $collectionData : '',
        )));

        // If we're editing:
        if ($editingId > 0) {
            // Get and ddd info markup
            $field = $this->modules->get('InputfieldMarkup');
            $userCreated = $this->users->get($userCreated);
            $userUpdated = $this->users->get($userUpdated);
            $userUrl = wire('config')->urls->admin . 'access/users/edit/?id=';
            $relativeTimes = array(
                'created' => wireRelativeTimeStr($createdAt),
                'updated' => wireRelativeTimeStr($updatedAt),
            );
            $form->add($this->populateInputField($field, array(
                'id' => 'info',
                'label' => $this->_('Info'),
                'value' => $this->blueprint('collection-info', array(
                    'user-created-name' => $userCreated->name,
                    'user-updated-name' => $userUpdated->name,
                    'user-created-url' => $userUrl . $userCreated->id,
                    'user-updated-url' => $userUrl . $userUpdated->id,
                    'created-at' => $createdAt,
                    'created-at-relative' => $relativeTimes['created'],
                    'updated-at' => $updatedAt,
                    'updated-at-relative' => $relativeTimes['updated'],
                )),
            )));

            // Add Uninstall button
            $field = $this->modules->get('InputfieldCheckbox');
            $form->add($this->populateInputField($field, array(
                'name+id' => 'uninstallCollection',
                'label' => $this->_('Uninstall'),
                'icon' => 'times-circle',
                'description' => $this->_("If you'd like to uninstall this collection, check the box below."),
                'label2' => $this->_('Uninstall this collection'),
                'collapsed' => Inputfield::collapsedYes,
            )));
        }

        // Install/Update & Return button
        $field = $this->modules->get('InputfieldButton');
        $form->add($this->populateInputField($field, array(
            'name+id' => 'installMappingCollection',
            'value' => ($editingId) ? $this->_('Update & Return') : $this->_('Install & Return'),
            'icon' => 'save',
            'type' => 'submit',
        )));

        $this->config->js('pjCollection', true);

        // Return the rendered page
        return $form->render() . $this->helpLinks('Mapping-Collections');
    }

    /**
     * Commit a new mapping collection
     * @param  String $collectionName
     * @param  Array  $data
     * @param  int    $id
     */
    protected function commitMappingCollection($collectionName, $collectionData, $id = 0)
    {
        // Clean up name (alphas only)
        $collectionName = preg_replace('~[^a-z]~', '', strtolower($collectionName));

        // Fetch, trim, and explode the data for cleaning
        $mappings = explode("\n", trim($collectionData));

        $compiledMappings = array();

        // Split up the key/value pairs and clean
        foreach ($mappings as $mapping) {
            $mapping = explode('=', $mapping);

            $wildcardCleaning = $this->wildcardCleaning;
            if ($wildcardCleaning === 'fullClean' || $wildcardCleaning === 'semiClean') {
                $mapping[1] = $this->cleanWildcard($mapping[1], ($wildcardCleaning === 'fullClean') ? false : true);
            }

            $compiledMappings[trim($mapping[0])] = $mapping[1];
        }

        $dbInput = '';

        foreach ($compiledMappings as $key => $value) {
            $dbInput .= "$key=$value\n";
        }

        $dbInput = trim($dbInput);

        $updating = ($id > 0);

        // Set the user creating/updating
        if (!$updating) {
            $userCreated = $this->user->id;
        }

        $userUpdated = $this->user->id;

        $dataBind = array(
            'collection' => $collectionName,
            'mappings' => $dbInput,
            'user_updated' => $userUpdated,
        );

        if ($updating) {
            $query = $this->database->prepare($this->sql->collection->update);
            $dataBind['id'] = $id;
        } else {
            $query = $this->database->prepare($this->sql->collection->insert);
            $dataBind['user_created'] = $userCreated;
        }
        $query->execute($dataBind);
    }

    /**
     * API call to create a new collection, or add to an existing one
     * @param String $name
     * @param Array $data
     */
    public function collection($name, $data)
    {
        $collectionData = "";
        $id = 0;

        // Check if the collection already exists
        // and grab its data if it does
        $collections = $this->database->prepare($this->sql->collection->selectOneByName);
        $collections->execute(array(
            'collection' => $name,
        ));
        if (count($collections) !== 0) {
            while ($collection = $collections->fetch(PDO::FETCH_OBJ)) {
                $id = (int) $collection->id;
                $collectionData = $collection->collection_mappings . "\n";
            }
        }

        // Gather the data from the array
        foreach ($data as $key => $value) {
            $collectionData .= "{$key}={$value}\n";
        }

        // And send it off!
        $this->commitMappingCollection($name, trim($collectionData, "\n"), $id);
    }

    /**
     * Admin Route: Commit new mapping collection or update existing
     */
    public function ___executeCommitMappingCollection()
    {
        // Just to be on the safe side...
        if ($this->input->post->id == null) {
            $this->session->redirect('../');
        }

        $input = $this->input->post;

        // Set the ID and check if we're updating
        $id = (int) $input->id;
        $isUpdating = ($id > 0);

        // If we're updating, check if we should uninstall
        if ($isUpdating && $input->uninstallCollection) {
            $this->database->prepare($this->sql->collection->dropOne)->execute(array(
                'id' => $id,
            ));
            $this->message($this->_('Collection uninstalled.'));
            $this->session->redirect('../');
        }

        $this->commitMappingCollection($input->collectionName, $input->collectionData, $id);
        $this->message(sprintf($this->_("Mapping Collection '%s' saved."), $collectionName));
        $this->session->redirect('../');
    }

    /**
     * Admin Page: Backup form
     * @return string
     */
    public function ___executeImport()
    {
        $this->injectAssets();
        $this->setFuel('processHeadline', $this->_('Import Jumplinks'));

        // Prep the form
        $form = $this->modules->get('InputfieldForm');
        $form->id = 'pjInputForm';
        $form->method = 'POST';
        $form->action = '../doimport/';

        $importType = $this->input->get->type;
        if (is_null($importType)) {
            $importType = 'csv';
        }

        $redoing = $this->input->get('redo') == true;

        switch ($importType) {
            case 'redirects':
                $this->config->js('pjImportRedirectsModule', true);
                if (!$this->modules->isInstalled('ProcessRedirects')) {
                    $this->session->redirect('../');
                }
                if (!$this->redirectsImported || $redoing) {
                    // Information
                    $field = $this->modules->get('InputfieldMarkup');
                    if ($redoing) {
                        $infoLabel = $this->_('If your last import failed, you can always try the import again. First, make sure that the jumplinks you imported have been deleted. Alternatively, just uncheck the ones that sucessfully imported the first time.');
                    } else {
                        $infoLabel = $this->_('You have the Redirects module installed. As such, you can migrate your existing redirects from the module (below) to Jumplinks. If there are any redirects you wish to exclude, simply uncheck the box in the first column');
                    }
                    $form->add($this->populateInputField($field, array(
                        'label' => $this->_('Import from the Redirects module'),
                        'value' => $infoLabel,
                    )));
                }

                // Redirects
                $importInfoMarkup = $this->modules->get('InputfieldMarkup');

                if ($this->redirectsImported && !$redoing) {
                    $importInfoMarkup->label = $this->_('Redirects already imported');
                    $importInfoMarkup->value = $this->_('All your redirects from ProcessRedirects have already been imported. You can safely uninstall ProcessRedirects. However, if something went wrong during the import, you can always try again using the button below. Of course, this import facility will not appear once ProcessRedirects is uninstalled.');
                    $form->add($importInfoMarkup);
                    $oopsButton = $this->modules->get('InputfieldButton');
                    $form->add($this->populateInputField($oopsButton, array(
                        'name+id' => 'oopsRedo',
                        'value' => $this->_('Something went wrong, let me try again'),
                        'icon' => 'repeat',
                        'href' => '?type=redirects&redo=true',
                    )));
                } else {
                    $redirectsTable = $this->modules->get('MarkupAdminDataTable');
                    $redirectsTable->setClass('old-redirects');
                    $redirectsTable->setEncodeEntities(false);
                    $redirectsTable->setSortable(false);
                    $redirectsTable->headerRow(array($this->_('Import'), $this->_('Redirect From'), $this->_('Redirect To'), $this->_('Hits')));

                    $jumplinks = $this->db->query("SELECT * FROM {$this->redirectsTableName} ORDER BY redirect_from");

                    while ($jumplink = $jumplinks->fetch_object()) {
                        $redirectsTable->row(array(
                            "<input type=\"checkbox\" name=\"importArray[]\" checked value=\"{$jumplink->id}\">",
                            $jumplink->redirect_from,
                            $jumplink->redirect_to,
                            $jumplink->counter,
                        ));
                    }

                    $importInfoMarkup->label = $this->_('Available Redirects');
                    $importInfoMarkup->value = $redirectsTable->render();
                    $form->add($importInfoMarkup);
                }

                break;
            default: // type = csv
                $this->config->js('pjImportCSVData', true);
                // Information
                $field = $this->modules->get('InputfieldTextarea');
                $form->add($this->populateInputField($field, array(
                    'name+id' => 'csvData',
                    'label' => $this->_('Import from CSV'),
                    'description' => sprintf($this->_("Paste in your old redirects below, where each one is on its own line. You may use any standard delimeter you like (comma, colon, semi-colon, period, pipe, or tab), so long as it is consistent throughout. Any URI/URL that contains the delimter must be wrapped in double quotes. **Note:** Please ensure that there are no empty lines!\n\n**Column Order:** *Source*, *Destination*, *Time Start*, *Time End*. The last two columns are optional - when used, they should contain any valid date/time string. However, if they are used in one line, then all other lines should have blank entries for these columns.\n\nFor examples, see the **[documentation](%s)**."), $this->helpLinks("Importing#importing-from-{$importType}", true)),
                    'notes' => $this->_("**Conversion Notes:**\n1. Any encoded ampersands (**&amp;amp;**) will be converted to **&amp;**.\n2. If the source or destination of a redirect contains leading slashes, they will be stripped."),
                    'rows' => 15,
                )));

                // Headings
                $field = $this->modules->get('InputfieldCheckbox');
                $form->add($this->populateInputField($field, array(
                    'name+id' => 'csvHeadings',
                    'label' => $this->_('My CSV data contains headings'),
                    'notes' => $this->_('No need to worry about what your headings are called. The importer simply ignores them when you check this box.'),
                )));

                break;
        }

        // Type of import
        $field = $this->modules->get('InputfieldHidden');
        $form->add($this->populateInputField($field, array(
            'name' => 'importType',
            'value' => $importType,
        )));

        // Import data/redirects button
        $field = $this->modules->get('InputfieldButton');
        if ($importType === 'redirects' && !$this->redirectsImported || $redoing) {
            $form->add($this->populateInputField($field, array(
                'name+id' => 'doImport',
                'value' => $this->_('Import these Redirects'),
                'icon' => 'arrow-right',
                'type' => 'submit',
            ))->addClass('head_button_clone'));
        } else if ($importType !== 'redirects') {
            $form->add($this->populateInputField($field, array(
                'name+id' => 'doImport',
                'value' => $this->_('Import Data'),
                'icon' => 'cloud-upload',
                'type' => 'submit',
            ))->addClass('head_button_clone'));
        }

        // Rename import type for docs
        if ($importType === 'redirects') {
            $importType = 'processredirects';
        }

        $this->config->js('pjImport', true);

        return $form->render() . $this->helpLinks("Importing#importing-from-{$importType}");
    }

    /**
     * Admin Route: Do an import based on data sent.
     */
    public function ___executeDoImport()
    {
        // Just to be on the safe side...
        if ($this->input->post->importType == null) {
            $this->session->redirect('../');
        }

        // Get the type of import ...
        $importType = $this->input->post->importType;

        // ... and go!
        switch ($importType) {
            case 'csv':
                // Require the CSV parser
                require_once __DIR__ . '/Classes/ParseCSV.php';

                // Prepare the parser
                $csv = new ParseCSV();
                $csv->heading = $this->input->post->csvHeadings;
                $csv->auto($this->input->post->csvData);

                $cols = array('source', 'destination', 'date_start', 'date_end');

                // Loop through each row and column to cleanse
                // data and insert into table
                foreach ($csv->data as $key => $row) {
                    $jumplink = array();
                    $col = 0;
                    foreach ($row as $value) {
                        ++$col;
                        if ($col >= 4) {
                            continue;
                        }

                        $value = ltrim($value, '/');

                        // check which col we're working with
                        // and cleanse accordingly
                        switch ($col) {
                            case 1: // source
                                $value = str_replace('&amp;', '&', $value);
                                break;
                            case 2: // destination
                                $value = str_replace('&amp;', '&', $this->sanitizer->url($value));
                                break;
                            case 3: // time start
                            case 4: // time end
                                $value = empty($value) ? self::NULL_DATE : "'" . date('Y-m-d H:i:s', strtotime(str_replace('-', '/', $value))) . "'";
                                break;
                        }

                        $jumplink[$cols[$col - 1]] = $this->db->escape_string($value);

                    }

                    if ($col === 2) {
                        $jumplink[$cols[2]] = self::NULL_DATE;
                        $jumplink[$cols[3]] = self::NULL_DATE;
                    }

                    $jumplinkData = (object) array(
                        'sourcePath' => $jumplink['source'],
                        'destinationUriUrl' => $jumplink['destination'],
                        'dateStart' => $jumplink['date_start'],
                        'dateEnd' => $jumplink['date_end'],
                    );
                    $this->commitJumplink($jumplinkData, 0);
                }

                $this->message('Redirects imported from CSV.');
                $this->session->redirect('../');

                break;
            case 'redirects':
                // Fetch the importArray - make sure all values are integers.
                $redirectsArray = implode(',', array_map('intval', $this->input->post->importArray));

                // Now fetch the redirects
                $query = $this->database->prepare("SELECT * FROM {$this->redirectsTableName} WHERE id IN ($redirectsArray)");
                $query->execute();

                // Gather and count the redirects
                $redirects = $query->fetchAll(PDO::FETCH_OBJ);
                $countRedirects = count($redirects);

                // And import them
                if ($countRedirects > 0) {
                    foreach ($redirects as $redirect) {
                        $jumplink = (object) array(
                            'sourcePath' => $redirect->redirect_from,
                            'destinationUriUrl' => preg_replace("~\^(\d+)$~i", "page:\\1", $redirect->redirect_to),
                        );
                        $this->commitJumplink($jumplink, $redirect->counter);
                    }
                }

                // Don't allow another import (we're importing for a reason - to migrate over to one module)
                $configData = $this->modules->getModuleConfigData($this);
                $configData['redirectsImported'] = true;
                $this->modules->saveModuleConfigData($this, $configData);

                $this->message($this->_('Redirects imported. You can now safely uninstall ProcessRedirects.'));
                $this->session->redirect('../');

                break;
            default:
                $this->session->redirect('../');
        }

    }

    /**
     * Admin Route: Clear the 404 log.
     */
    public function ___executeClearNotFoundLog()
    {

        $this->db->query($this->sql->notFoundMonitor->deleteAll);
        $this->message($this->_('[Jumplinks] 404 Monitor cleared.'));
        $this->session->redirect('../');
    }

    /**
     * Install the module
     */
    public function ___install()
    {
        // Install tables (their schemas may not remain the same as updateDatabaseSchema() may change them)
        foreach (array('main', 'mc') as $schema) {
            $this->db->query($this->blueprint("schema-create-{$schema}"));
        }

        parent::___install();
    }

    /**
     * Uninstall the module
     */
    public function ___uninstall()
    {
        // Uninstall tables
        $this->db->query($this->blueprint('schema-drop'));
        parent::___uninstall();
    }

    /**
     * Dump and die
     * @param  Mixed $mixed Anything
     */
    protected function dd($mixed, $die = true)
    {
        header('Content-Type: text/plain');
        var_dump($mixed);
        $die && die;
    }

}