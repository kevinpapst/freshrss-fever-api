<?php
/**
 * Fever API for FreshRSS
 * Version 0.1
 * Author: Kevin Papst / https://github.com/kevinpapst
 *
 * This is highly inspired by:
 * - TinyTinyRSS Fever API plugin
 * - https://github.com/dasmurphy/tinytinyrss-fever-plugin
 */


// refresh is not allowed yet, probably we find a way to support it later
if (isset($_REQUEST["refresh"])) {
	exit;
}

// ================================================================================================
// BOOTSTRAP FreshRSS
require(__DIR__ . '/../../constants.php');
require(LIB_PATH . '/lib_rss.php');    //Includes class autoloader
Minz_Configuration::register('system', DATA_PATH . '/config.php', FRESHRSS_PATH . '/config.default.php');

// check is API is enabled globally
FreshRSS_Context::$system_conf = Minz_Configuration::get('system');
if (!FreshRSS_Context::$system_conf->api_enabled) {
	Minz_Log::warning('serviceUnavailable() ' . debugInfo(), API_LOG);
	header('HTTP/1.1 503 Service Unavailable');
	header('Content-Type: text/plain; charset=UTF-8');
	die('Service Unavailable!');
}

Minz_Session::init('FreshRSS');
// ================================================================================================

/**
 * Class FeverAPI_FeedDAO for more feed functions than FreshRSS offers.
 */
class FeverAPI_FeedDAO extends FreshRSS_FeedDAO
{
	/**
	 * @return FreshRSS_Feed
	 */
	public function getLastUpdatedFeed() {
		$sql = 'SELECT id, url, name, website, `lastUpdate`, `pathEntries`, `httpAuth`, keep_history, ttl '
			. 'FROM `' . $this->prefix . 'feed` ORDER BY `lastUpdate` LIMIT 1';
		$stm = $this->bd->prepare($sql);
		$stm->execute();

		return current(self::daoToFeed($stm->fetchAll(PDO::FETCH_ASSOC)));
	}
}

class FeverAPI_CategoryDAO extends FreshRSS_CategoryDAO
{
}

class FeverAPI_EntryDAO extends FreshRSS_EntryDAO
{
	/**
	 * @return int
	 */
	public function countAll() {
		$sql = 'SELECT COUNT(e.id) AS count FROM `' . $this->prefix . 'entry` e';
		$stm = $this->bd->prepare($sql);
		$stm->execute();
		$res = $stm->fetchAll(PDO::FETCH_COLUMN, 0);
		return $res[0];
	}

	/**
	 * @return FreshRSS_Entry[]
	 */
	public function getUnread()
	{
        $sql = 'SELECT id, guid, title, author, '
            . ($this->isCompressed() ? 'UNCOMPRESS(content_bin) AS content' : 'content')
            . ', link, date, is_read, is_favorite, id_feed, tags '
            . 'FROM `' . $this->prefix . 'entry` WHERE is_read=0';
		$stm = $this->bd->prepare($sql);
		$stm->execute();
		$result = $stm->fetchAll(PDO::FETCH_ASSOC);

		$entries = [];
		foreach ($result as $dao) {
			$entries[] = self::daoToEntry($dao);
		}

		return $entries;
	}

	/**
	 * @return FreshRSS_Entry[]
	 */
	public function getStarred()
	{
        $sql = 'SELECT id, guid, title, author, '
            . ($this->isCompressed() ? 'UNCOMPRESS(content_bin) AS content' : 'content')
            . ', link, date, is_read, is_favorite, id_feed, tags '
            . 'FROM `' . $this->prefix . 'entry` WHERE is_favorite=1';
		$stm = $this->bd->prepare($sql);
		$stm->execute();
		$result = $stm->fetchAll(PDO::FETCH_ASSOC);

		$entries = [];
		foreach ($result as $dao) {
			$entries[] = self::daoToEntry($dao);
		}

		return $entries;
	}

	/**
	 * TODO this is ugly
	 */
	protected function bindParamArray($prefix, $values, &$bindArray)
	{
		$str = "";
		foreach($values as $index => $value){
			$str .= ":".$prefix.$index.",";
			$bindArray[$prefix.$index] = $value;
		}
		return rtrim($str,",");
	}

	/**
	 * @param array $feed_ids
	 * @param array $entry_ids
	 * @param int|null $max_id
	 * @param int|null $since_id
	 * @return FreshRSS_Entry[]
	 */
	public function findEntries(array $feed_ids, array $entry_ids, $max_id, $since_id)
	{
		$values = [];
		$order = '';

		$sql = 'SELECT id, guid, title, author, '
			. ($this->isCompressed() ? 'UNCOMPRESS(content_bin) AS content' : 'content')
			. ', link, date, is_read, is_favorite, id_feed, tags '
			. 'FROM `' . $this->prefix . 'entry` WHERE';

		if (!empty($entry_ids)) {
			$bindEntryIds = $this->bindParamArray("id", $entry_ids, $values);
			$sql .= " id IN($bindEntryIds)";
		} else if (!empty($max_id)) {
			$sql .= ' id < :id';
			$values[':id'] = $max_id;
			$order = ' ORDER BY id DESC';
		} else {
			$sql .= ' id > :id';
			$values[':id'] = $since_id;
			$order = ' ORDER BY id ASC';
		}

		if (!empty($feed_ids)) {
			$bindFeedIds = $this->bindParamArray("feed", $feed_ids, $values);
			$sql .= " AND id_feed IN($bindFeedIds)";
		}

		$sql .= $order;
		$sql .= ' LIMIT 50';

		$stm = $this->bd->prepare($sql);
		$stm->execute($values);
		$result = $stm->fetchAll(PDO::FETCH_ASSOC);

		$entries = [];
		foreach ($result as $dao) {
			$entries[] = self::daoToEntry($dao);
		}

		return $entries;
	}

}

/**
 * Class FeverAPI - does all the heavy lifting
 *
 * API Password must be the result of the md5 sum of your FreshRSS "username:your-api-password"
 *
 * md5 -s "kevin:test-fever"
 * MD5 ("kevin:test-fever") = 7cd3293ae3c2f3d6648e76f9be65ca59
 */
class FeverAPI
{
    const API_LEVEL = 3;
    const STATUS_OK = 1;
    const STATUS_ERR = 0;

    /**
     * whether the API was requested with XML as return value (true) or as JSON (false)
     * @var bool
     */
    private $xml = false;

    /**
     * FeverAPI constructor does some basic initialization and logging.
     */
    public function __construct()
    {
        // set the user from the db
        $this->setUser();

        // are we xml or json?
        if (isset($_REQUEST["api"]) && strtolower($_REQUEST['api']) === 'xml') {
            $this->xml = true;
        }
    }

	/**
	 * find the user in the db with a particular api key
	 */
	private function setUser()
	{
		if (!isset($_POST["api_key"]) || empty($_POST["api_key"])) {
			FreshRSS_Context::$user_conf = null;
			return;
		}

		$apiKey = $_POST["api_key"];
		$usersDir = join_path(DATA_PATH, 'users');

		foreach (glob($usersDir . '/*', GLOB_ONLYDIR) as $username) {
			$username = str_replace($usersDir . '/', '', $username);
			if ($username == '_') {
				continue;
			}

			$config = get_user_configuration($username);
			if ($config->apiPasswordHash != '' && password_verify($apiKey, $config->apiPasswordHash)) {
				Minz_Session::_param('currentUser', $username);
				FreshRSS_Context::$user_conf = $config;
				return;
			}
		}
	}

    /**
     * @return bool
     */
    public function isXmlRequested()
    {
        return $this->xml;
    }

    /**
     * @return bool
     */
    public function isAuthenticatedApiUser()
    {
		if (FreshRSS_Context::$user_conf !== null) {
			return true;
		}

        return false;
    }

    /**
     * this does all the processing, since the fever api does not have a specific variable that specifies the operation
     */
    public function process()
    {
        $response_arr = array();

        if (!$this->isAuthenticatedApiUser()) {
            throw new \Exception('No user given or user is not allowed to access API');
        }

        if (isset($_REQUEST["groups"])) {
            $response_arr["groups"] = $this->getGroups();
            $response_arr["feeds_groups"] = $this->getFeedsGroup();
        }

        if (isset($_REQUEST["feeds"])) {
            $response_arr["feeds"] = $this->getFeeds();
            $response_arr["feeds_groups"] = $this->getFeedsGroup();
        }

        if (isset($_REQUEST["favicons"])) {
            $response_arr["favicons"] = $this->getFavicons();
        }

        if (isset($_REQUEST["items"])) {
            $response_arr["total_items"] = $this->getTotalItems();
            $response_arr["items"] = $this->getItems();
        }

        if (isset($_REQUEST["links"])) {
            $response_arr["links"] = $this->getLinks();
        }

        if (isset($_REQUEST["unread_item_ids"])) {
            $response_arr["unread_item_ids"] = $this->getUnreadItemIds();
        }

        if (isset($_REQUEST["saved_item_ids"])) {
            $response_arr["saved_item_ids"] = $this->getSavedItemIds();
        }

		// TODO
        if (isset($_REQUEST["mark"], $_REQUEST["as"], $_REQUEST["id"])) {
            if (is_numeric($_REQUEST["id"])) {
                $before = (isset($_REQUEST["before"])) ? $_REQUEST["before"] : null;
                if ($before > pow(10, 10)) {
                    $before = round($before / 1000);
                }
                $method_name = "set" . ucfirst($_REQUEST["mark"]) . "As" . ucfirst($_REQUEST["as"]);

                if (method_exists($this, $method_name)) {
                    $id = intval($_REQUEST["id"]);
                    $this->{$method_name}($id, $before);
                    switch ($_REQUEST["as"]) {
                        case "read":
                        case "unread":
                            $response_arr["unread_item_ids"] = $this->getUnreadItemIds();
                            break;

                        case 'saved':
                        case 'unsaved':
                            $response_arr["saved_item_ids"] = $this->getSavedItemIds();
                            break;
                    }
                }
            }
        }

        return $response_arr;
    }

    /**
     * Returns either an JSON or XML string.
     * Always include api_version, status as 'auth'
     *
     * @param int $status
     * @param array $reply
     * @return string
     */
    public function wrap($status, array $reply = [])
    {
        $arr = ['api_version' => self::API_LEVEL, 'auth' => $status];

        if ($status === self::STATUS_OK) {
            $arr['last_refreshed_on_time'] = (string) $this->lastRefreshedOnTime();
			$arr = array_merge($arr, $reply);
        }

        if ($this->xml) {
            return $this->array_to_xml($arr);
        }

        return json_encode($arr);
    }

    /**
	 * FIXME
     * fever supports xml wrapped in <response> tags
     * @param $array
     * @param string $container
     * @param bool $is_root
     * @return mixed
     */
    protected function array_to_xml($array, $container = 'response', $is_root = true)
    {
        if (!is_array($array)) {
            $array = array($array);
        }

        $xml = '';

        if ($is_root) {
            $xml .= '<?xml version="1.0" encoding="utf-8"?>';
            $xml .= "<{$container}>";
        }

        foreach ($array as $key => $value) {
            // make sure key is a string
            $elem = $key;

            if (!is_string($key) && !empty($container)) {
                $elem = $container;
            }

            $xml .= "<{$elem}>";

            if (is_array($value)) {
                if (array_keys($value) !== array_keys(array_keys($value))) {
                    $xml .= $this->array_to_xml($value, '', false);
                } else {
                    $xml .= $this->array_to_xml($value, str_replace('/s$/', '', $elem), false);
                }
            } else {
                $xml .= (htmlspecialchars($value, ENT_COMPAT, 'ISO-8859-1') != $value) ? "<![CDATA[{$value}]]>" : $value;
            }

            $xml .= "</{$elem}>";
        }

        if ($is_root) {
            $xml .= "</{$container}>";
        }

        return preg_replace('/[\x00-\x1F\x7F]/', '', $xml);
    }

    /**
     * every authenticated method includes last_refreshed_on_time
     *
     * @return int
     */
    protected function lastRefreshedOnTime()
    {
		$lastUpdate = 0;

 		$dao = new FeverAPI_FeedDAO();
		$feed = $dao->getLastUpdatedFeed();

		if (!empty($feed)) {
			$lastUpdate = $feed->lastUpdate();
		}

        return $lastUpdate;
    }

    /**
     * @return array
     */
    protected function getFeeds()
    {
        $feeds = [];

		$dao = new FeverAPI_FeedDAO();
		$myFeeds = $dao->listFeeds();

		/** @var FreshRSS_Feed $feed */
		foreach ($myFeeds as $feed) {
            $feeds[] = [
            	"id" => $feed->id(),
                "favicon_id" => $feed->id(),
                "title" => $feed->name(),
                "url" => $feed->url(),
                "site_url" => $feed->website(),
                "is_spark" => 0, // unsupported
                "last_updated_on_time" => $feed->lastUpdate()
			];
        }

        return $feeds;
    }

	/**
	 * @return array
	 */
	protected function getGroups()
	{
		$groups = array();

		$dao = new FeverAPI_CategoryDAO();
		$categories = $dao->listCategories(false, false);

		/** @var FreshRSS_Category $category */
		foreach ($categories as $category) {
			$groups[] = [
				'id' => $category->id(),
				'title' => $category->name()
			];
		}

		return $groups;
	}

    /**
     * @return array
     */
    protected function getFavicons()
    {
        $favicons = array();

		$dao = new FeverAPI_FeedDAO();
		$myFeeds = $dao->listFeeds();

		$salt = FreshRSS_Context::$system_conf->salt;

		/** @var FreshRSS_Feed $feed */
		foreach ($myFeeds as $feed) {

			$id = hash('crc32b', $salt . $feed->url());
			$filename = DATA_PATH . '/favicons/' . $id . '.ico';
			if (!file_exists($filename)) {
				continue;
			}

			$favicons[] = [
				"id" => $feed->id(),
				"data" => image_type_to_mime_type(exif_imagetype($filename)) . ";base64," . base64_encode(file_get_contents($filename))
			];
		}

        return $favicons;
    }

	/**
	 * @return int
	 */
	protected function getTotalItems()
	{
		$total_items = 0;

		$dao = new FeverAPI_EntryDAO();
		$result = $dao->countAll();

		if (!empty($result)) {
			$total_items = $result;
		}

		return $total_items;
	}

	/**
	 * @return array
	 */
	protected function getFeedsGroup()
	{
		$groups = array();
		$ids = [];

		$dao = new FeverAPI_FeedDAO();
		$myFeeds = $dao->listFeeds();

		/** @var FreshRSS_Feed $feed */
		foreach ($myFeeds as $feed) {
			$ids[$feed->category()][] = $feed->id();
		}

		foreach($ids as $category => $feedIds) {
			$groups[] = [
				'group_id' => $category,
				'feed_ids' => implode(',', $feedIds)
			];
		}

		return $groups;
	}

    /**
     * AFAIK there is no 'hot links' alternative in FreshRSS
     * @return array
     */
    protected function getLinks()
    {
        return [];
    }

	/**
	 * @param FreshRSS_Entry[] $entries
	 * @return string
	 */
    protected function entriesToIdList($entries = array())
	{
		$ids = [];
		foreach ($entries as $entry) {
			$ids[] = $entry->id();
		}

		return implode(',', array_values($ids));
	}

    /**
     * @return string
     */
    protected function getUnreadItemIds()
    {
		$dao = new FeverAPI_EntryDAO();
		$entries = $dao->getUnread();
		return $this->entriesToIdList($entries);
    }

    /**
     * @return string
     */
    protected function getSavedItemIds()
    {
		$dao = new FeverAPI_EntryDAO();
		$entries = $dao->getStarred();
		return $this->entriesToIdList($entries);
    }

	protected function setItemAsRead($id)
	{
		$dao = new FeverAPI_EntryDAO();
		$dao->markRead($id, true);
	}

	protected function setItemAsUnread($id)
	{
		$dao = new FeverAPI_EntryDAO();
		$dao->markRead($id, false);
	}

	protected function setItemAsSaved($id)
	{
		$dao = new FeverAPI_EntryDAO();
		$dao->markFavorite($id, true);
	}

	protected function setItemAsUnsaved($id)
	{
		$dao = new FeverAPI_EntryDAO();
		$dao->markFavorite($id, false);
	}

	/**
	 * FIXME
	 * @param $html
	 * @return string
	 */
	protected function rewrite_urls($html)
	{
		libxml_use_internal_errors(true);

		$charset_hack = '<head>
			<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
		</head>';

		$doc = new DOMDocument();
		$doc->loadHTML($charset_hack . $html);
		$xpath = new DOMXPath($doc);

		$entries = $xpath->query('//*/text()');

		foreach ($entries as $entry) {
			if (strstr($entry->wholeText, "://") !== false) {
				$text = preg_replace("/((?<!=.)((http|https|ftp)+):\/\/[^ ,!]+)/i",
					"<a target=\"_blank\" href=\"\\1\">\\1</a>", $entry->wholeText);

				if ($text != $entry->wholeText) {
					$cdoc = new DOMDocument();
					$cdoc->loadHTML($charset_hack . $text);


					foreach ($cdoc->childNodes as $cnode) {
						$cnode = $doc->importNode($cnode, true);

						if ($cnode) {
							$entry->parentNode->insertBefore($cnode);
						}
					}

					$entry->parentNode->removeChild($entry);

				}
			}
		}

		$node = $doc->getElementsByTagName('body')->item(0);

		// http://tt-rss.org/forum/viewtopic.php?f=1&t=970
		if ($node)
			return $doc->saveXML($node);
		else
			return $html;
	}

	/**
	 * FIXME
	 * @param $str
	 * @param bool $site_url
	 * @return string
	 */
	protected function my_sanitize($str, $site_url = false)
	{
		$res = trim($str);
		if (!$res) return '';

		if (strpos($res, "href=") === false)
			$res = $this->rewrite_urls($res);

		$charset_hack = '<head>
			<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
		</head>';

		$res = trim($res);
		if (!$res) return '';

		libxml_use_internal_errors(true);

		$doc = new DOMDocument();
		$doc->loadHTML($charset_hack . $res);
		$xpath = new DOMXPath($doc);

		$entries = $xpath->query('(//a[@href]|//img[@src])');

		foreach ($entries as $entry) {

			if ($site_url) {

				if ($entry->hasAttribute('href'))
					$entry->setAttribute('href',
						rewrite_relative_url($site_url, $entry->getAttribute('href')));

				if ($entry->hasAttribute('src')) {
					$src = rewrite_relative_url($site_url, $entry->getAttribute('src'));
					$entry->setAttribute('src', $src);
				}
			}

			if (strtolower($entry->nodeName) == "a") {
				$entry->setAttribute("target", "_blank");
			}
		}

		$entries = $xpath->query('//iframe');
		foreach ($entries as $entry) {
			$entry->setAttribute('sandbox', 'allow-scripts allow-same-origin');
		}

		$disallowed_attributes = array('id', 'style', 'class');

		$entries = $xpath->query('//*');
		foreach ($entries as $entry) {
			if ($entry->hasAttributes()) {
				$attrs_to_remove = array();
				foreach ($entry->attributes as $attr) {
					if (strpos($attr->nodeName, 'on') === 0) { //remove onclick and other on* attributes
						array_push($attrs_to_remove, $attr);
					}

					if (in_array($attr->nodeName, $disallowed_attributes)) {
						array_push($attrs_to_remove, $attr);
					}
				}
				foreach ($attrs_to_remove as $attr) {
					$entry->removeAttributeNode($attr);
				}
			}
		}

		$doc->removeChild($doc->firstChild); //remove doctype
		$res = $doc->saveHTML();
		return $res;
	}

	/**
	 * @return array
	 */
	protected function getItems()
	{
		$feed_ids = [];
		$entry_ids = [];
		$max_id = null;
		$since_id = null;

		if (isset($_REQUEST["feed_ids"]) || isset($_REQUEST["group_ids"]))
		{
			if (isset($_REQUEST["feed_ids"])) {
				$feed_ids = explode(",", $_REQUEST["feed_ids"]);
			}

			$dao = new FeverAPI_CategoryDAO();
			if (isset($_REQUEST["group_ids"])) {
				$group_ids = explode(",", $_REQUEST["group_ids"]);
				foreach ($group_ids as $id) {
					/** @var FreshRSS_Category $category */
					$category = $dao->searchById($id);
					/** @var FreshRSS_Feed $feed */
					foreach ($category->feeds() as $feed) {
						$feeds[] = $feed->id();
					}
				}

				$feed_ids = array_unique($feeds);
			}
		}

		if (isset($_REQUEST["max_id"]))
		{
			// use the max_id argument to request the previous $item_limit items
			if (is_numeric($_REQUEST["max_id"])) {
				$max = ($_REQUEST["max_id"] > 0) ? intval($_REQUEST["max_id"]) : 0;
				if ($max) {
					$max_id = $max;
				}
			}
		}
		else if (isset($_REQUEST["with_ids"]))
		{
			$entry_ids = explode(",", $_REQUEST["with_ids"]);
		}
		else
		{
			// use the since_id argument to request the next $item_limit items
			$since_id = isset($_REQUEST["since_id"]) && is_numeric($_REQUEST["since_id"]) ? intval($_GET["since_id"]) : 0;
		}

		$items = [];

		$dao = new FeverAPI_EntryDAO();
		$entries = $dao->findEntries($feed_ids, $entry_ids, $max_id, $since_id);

		foreach($entries as $entry) {
			$items[] = [
				"id" => $entry->id(),
				"feed_id" => $entry->feed(false),
				"title" => $entry->title(),
				"author" => $entry->author(),
				"html" => $this->my_sanitize($entry->content()),
				"url" => $entry->link(),
				"is_saved" => $entry->isFavorite() ? 1 : 0,
				"is_read" => $entry->isRead() ? 1 : 0,
				"created_on_time" => $entry->date(true)
			];
		}

		return $items;
	}

    /**
     * FIXME
     */
    protected function setFeed($id, $cat, $before = 0)
    {
        /*
        // if before is zero, set it to now so feeds all items are read from before this point in time
        if ($before == 0)
            $before = time();

        if (is_numeric($id)) {
            // this is a category
            if ($cat) {
                // if not special feed
                if ($id > 0) {
                    db_query("UPDATE ttrss_user_entries
						SET unread = false, last_read = NOW() WHERE ref_id IN
							(SELECT id FROM
								(SELECT id FROM ttrss_entries, ttrss_user_entries WHERE ref_id = id
									AND owner_uid = '" . db_escape_string($_SESSION["uid"]) . "' AND unread = true AND feed_id IN
										(SELECT id FROM ttrss_feeds WHERE cat_id IN (" . intval($id) . ")) AND date_entered < '" . date("Y-m-d H:i:s", $before) . "' ) as tmp)");

                } // this is "all" to fever, but internally "all" is -4
                else if ($id == 0) {
                    $id = -4;
                    db_query("UPDATE ttrss_user_entries
							SET unread = false, last_read = NOW() WHERE ref_id IN
								(SELECT id FROM
									(SELECT id FROM ttrss_entries, ttrss_user_entries WHERE ref_id = id
										AND owner_uid = '" . db_escape_string($_SESSION["uid"]) . "' AND unread = true AND date_entered < '" . date("Y-m-d H:i:s", $before) . "' ) as tmp)");
                }
            } // not a category
            else if ($id > 0) {
                db_query("UPDATE ttrss_user_entries
					SET unread = false, last_read = NOW() WHERE ref_id IN
						(SELECT id FROM
							(SELECT id FROM ttrss_entries, ttrss_user_entries WHERE ref_id = id
								AND owner_uid = '" . db_escape_string($_SESSION["uid"]) . "' AND unread = true AND feed_id = " . intval($id) . " AND date_entered < '" . date("Y-m-d H:i:s", $before) . "' ) as tmp)");

            }
            ccache_update($id, $_SESSION["uid"], $cat);
        }
        */
    }

    protected function setFeedAsRead($id, $before)
    {
        $this->setFeed($id, false, $before);
    }

    protected function setGroupAsRead($id, $before)
    {
        $this->setFeed($id, true, $before);
    }
}

// ================================================================================================
// Start the Fever API handling
$handler = new FeverAPI();

if ($handler->isXmlRequested()) {
	header("Content-Type: text/xml");
} else {
	header("Content-Type: application/json");
}

if (!$handler->isAuthenticatedApiUser()) {
	echo $handler->wrap(FeverAPI::STATUS_ERR, []);
} else {
    echo $handler->wrap(FeverAPI::STATUS_OK, $handler->process());
}