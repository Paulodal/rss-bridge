<?php
/**
 * TwitterV2Bridge leverages Twitter API v2, and requires
 * a unique API Bearer Token, which requires creation of
 * a Twitter Dev account. Link to instructions in DESCRIPTION.
 */
class TwitterV2Bridge extends BridgeAbstract {
	const NAME = 'Twitter V2 Bridge';
	const URI = 'https://twitter.com/';
	const API_URI = 'https://api.twitter.com/2';
	const DESCRIPTION = 'Returns tweets (using Twitter API v2). See the 
	<a href="https://rss-bridge.github.io/rss-bridge/Bridge_Specific/TwitterV2.html">
	Configuration Instructions</a>.';
	const MAINTAINER = 'quickwick';
	const CONFIGURATION = array(
		'twitterv2apitoken' => array(
			'required' => true,
		)
	);
	const PARAMETERS = array(
		'global' => array(
			'filter' => array(
				'name' => 'Filter',
				'exampleValue' => 'rss-bridge',
				'required' => false,
				'title' => 'Specify a single term to search for'
			),
			'norep' => array(
				'name' => 'Without replies',
				'type' => 'checkbox',
				'title' => 'Activate to exclude reply tweets'
			),
			'noretweet' => array(
				'name' => 'Without retweets',
				'required' => false,
				'type' => 'checkbox',
				'title' => 'Activate to exclude retweets'
			),
			'nopinned' => array(
				'name' => 'Without pinned tweet',
				'required' => false,
				'type' => 'checkbox',
				'title' => 'Activate to exclude pinned tweets'
			),
			'maxresults' => array(
				'name' => 'Maximum results',
				'required' => false,
				'exampleValue' => '20',
				'title' => 'Maximum number of tweets to retrieve (limit is 100)'
			),
			'imgonly' => array(
				'name' => 'Only media tweets',
				'type' => 'checkbox',
				'title' => 'Activate to show only tweets with media (photo/video)'
			),
			'nopic' => array(
				'name' => 'Hide profile pictures',
				'type' => 'checkbox',
				'title' => 'Activate to hide profile pictures in content'
			),
			'noimg' => array(
				'name' => 'Hide images in tweets',
				'type' => 'checkbox',
				'title' => 'Activate to hide images in tweets'
			),
			'noimgscaling' => array(
				'name' => 'Disable image scaling',
				'type' => 'checkbox',
				'title' => 'Activate to display original sized images (no thumbnails)'
			),
			'idastitle' => array(
				'name' => 'Use tweet id as title',
				'type' => 'checkbox',
				'title' => 'Activate to use tweet id as title (instead of tweet text)'
			)
		),
		'By username' => array(
			'u' => array(
				'name' => 'username',
				'required' => true,
				'exampleValue' => 'sebsauvage',
				'title' => 'Insert a user name'
			)
		),
		'By keyword or hashtag' => array(
			'query' => array(
				'name' => 'Keyword or #hashtag',
				'required' => true,
				'exampleValue' => 'rss-bridge OR #rss-bridge',
				'title' => <<<EOD
* To search for multiple words (must contain all of these words), put a space between them.

Example: `rss-bridge release`.

* To search for multiple words (contains any of these words), put "OR" between them.

Example: `rss-bridge OR rssbridge`.

* To search for an exact phrase (including whitespace), put double-quotes around them.

Example: `"rss-bridge release"`

* If you want to search for anything **but** a specific word, put a hyphen before it.

Example: `rss-bridge -release` (ignores "release")

* Of course, this also works for hashtags.

Example: `#rss-bridge OR #rssbridge`

* And you can combine them in any shape or form you like.

Example: `#rss-bridge OR #rssbridge -release`
EOD
			)
		),
		'By list ID' => array(
			'listid' => array(
				'name' => 'List ID',
				'exampleValue' => '31748',
				'required' => true,
				'title' => 'Enter a list id'
			)
		)
	);

	private $apiToken     = null;
	private $authHeaders = array();

	public function getName() {
		switch($this->queriedContext) {
			case 'By keyword or hashtag':
				$specific = 'search ';
				$param = 'query';
				break;
			case 'By username':
				$specific = '@';
				$param = 'u';
				break;
			case 'By list ID':
				return 'Twitter List #' . $this->getInput('listid');
			default:
				return parent::getName();
		}
		return 'Twitter ' . $specific . $this->getInput($param);
	}

	public function collectData() {
		// $data will contain an array of all found tweets
		$data = null;
		// Contains user data (when in by username context)
		$user = null;
		// Array of all found tweets
		$tweets = array();

		$hideProfilePic = $this->getInput('nopic');
		$hideImages = $this->getInput('noimg');
		$hideReplies = $this->getInput('norep');
		$hideRetweets = $this->getInput('noretweet');
		$hidePinned = $this->getInput('nopinned');
		$tweetFilter = $this->getInput('filter');
		$maxResults = $this->getInput('maxresults');
		if ($maxResults > 100) {
			$maxResults = 100;
		}
		$idAsTitle = $this->getInput('idastitle');
		$onlyMediaTweets = $this->getInput('imgonly');

		// Read API token from config.ini.php, put into Header
		$this->apiToken     = $this->getOption('twitterv2apitoken');
		$this->authHeaders = array(
			'authorization: Bearer ' . $this->apiToken,
		);

		// Try to get all tweets
		switch($this->queriedContext) {
		case 'By username':
			//Get id from username
			$params = array(
				'user.fields'	=> 'pinned_tweet_id,profile_image_url'
			);
			$user = $this->makeApiCall('/users/by/username/'
			. $this->getInput('u'), $params);

			if(isset($user->errors)) {
				Debug::log('User JSON: ' . json_encode($user));
				returnServerError('Requested username can\'t be found.');
			}

			// Set default params
			$params = array(
				'max_results'	=> (empty($maxResults) ? '10' : $maxResults ),
				'tweet.fields'
				=> 'created_at,referenced_tweets,entities,attachments',
				'user.fields'	=> 'pinned_tweet_id',
				'expansions'
				=> 'referenced_tweets.id.author_id,entities.mentions.username,attachments.media_keys',
				'media.fields'	=> 'type,url,preview_image_url'
			);

			// Set params to filter out replies and/or retweets
			if($hideReplies && $hideRetweets) {
				$params['exclude'] = 'replies,retweets';
			} elseif($hideReplies) {
				$params['exclude'] = 'replies';
			} elseif($hideRetweets) {
				$params['exclude'] = 'retweets';
			}

			// Get the tweets
			$data = $this->makeApiCall('/users/' . $user->data->id
			. '/tweets', $params);
			break;

		case 'By keyword or hashtag':
			$params = array(
				'query'			=> $this->getInput('query'),
				'max_results'	=> (empty($maxResults) ? '10' : $maxResults ),
				'tweet.fields'
				=> 'created_at,referenced_tweets,entities,attachments',
				'expansions'
				=> 'referenced_tweets.id.author_id,entities.mentions.username,attachments.media_keys',
				'media.fields'	=> 'type,url,preview_image_url'
			);

			// Set params to filter out replies and/or retweets
			if($hideReplies) {
				$params['query'] = $params['query'] . ' -is:reply';
			}
			if($hideRetweets) {
				$params['query'] = $params['query'] . ' -is:retweet';
			}

			$data = $this->makeApiCall('/tweets/search/recent', $params);
			break;

		case 'By list ID':
			// Set default params
			$params = array(
				'max_results' => (empty($maxResults) ? '10' : $maxResults ),
				'tweet.fields'
				=> 'created_at,referenced_tweets,entities,attachments',
				'expansions'
				=> 'referenced_tweets.id.author_id,entities.mentions.username,attachments.media_keys',
				'media.fields'	=> 'type,url,preview_image_url'
			);

			$data = $this->makeApiCall('/lists/' . $this->getInput('listid') .
			'/tweets', $params);
			break;

		default:
			returnServerError('Invalid query context !');
		}

		if((isset($data->errors) && !isset($data->data)) ||
		(isset($data->meta) && $data->meta->result_count === 0)) {
			Debug::log('Data JSON: ' . json_encode($data));
			switch($this->queriedContext) {
			case 'By keyword or hashtag':
				returnServerError('No results for this query.');
			case 'By username':
				returnServerError('Requested username cannnot be found.');
			case 'By list ID':
				returnServerError('Requested list cannnot be found');
			}
		}

		// figure out the Pinned Tweet Id
		if($hidePinned) {
			$pinnedTweetId = null;
			if(isset($user) && isset($user->data->pinned_tweet_id)) {
				$pinnedTweetId = $user->data->pinned_tweet_id;
			}
		}

		// Extract Media data into array
		isset($data->includes->media) ? $includesMedia = $data->includes->media : $includesMedia = null;

		// Extract additional Users data into array
		isset($data->includes->users) ? $includesUsers = $data->includes->users : $includesUsers = null;

		// Extract additional Tweets data into array
		isset($data->includes->tweets) ? $includesTweets = $data->includes->tweets : $includesTweets = null;

		// Extract main Tweets data into array
		$tweets = $data->data;

		// Make another API call to get user and media info for retweets
		// Is there some way to get this info included in original API call?
		$retweetedData = null;
		$retweetedMedia = null;
		$retweetedUsers = null;
		if(!$hideImages && !$hideRetweets && isset($includesTweets)) {
			// There has to be a better PHP way to extract the tweet Ids?
			$includesTweetsIds = array();
			foreach($includesTweets as $includesTweet) {
				$includesTweetsIds[] = $includesTweet->id;
			}
			//Debug::log('includesTweetsIds: ' . join(',', $includesTweetsIds));

			// Set default params for API query
			$params = array(
				'ids'			=> join(',', $includesTweetsIds),
				'tweet.fields'  => 'entities,attachments',
				'expansions'	=> 'author_id,attachments.media_keys',
				'media.fields'	=> 'type,url,preview_image_url',
				'user.fields'	=> 'id,profile_image_url'
			);

			// Get the retweeted tweets
			$retweetedData = $this->makeApiCall('/tweets', $params);

			// Extract retweets Media data into array
			isset($retweetedData->includes->media) ? $retweetedMedia
			= $retweetedData->includes->media : $retweetedMedia = null;

			// Extract retweets additional Users data into array
			isset($retweetedData->includes->users) ? $retweetedUsers
			= $retweetedData->includes->users : $retweetedUsers = null;
		}

		// Create output array with all required elements for each tweet
		foreach($tweets as $tweet) {
			//Debug::log('Tweet JSON: ' . json_encode($tweet));

			// Skip pinned tweet (if selected)
			if($hidePinned && $tweet->id === $pinnedTweetId) {
				continue;
			}

			// Check if Retweet or Reply
			$retweetTypes = array('retweeted', 'quoted');
			$isRetweet = false;
			$isReply = false;
			if(isset($tweet->referenced_tweets)) {
				if(in_array($tweet->referenced_tweets[0]->type, $retweetTypes)) {
					$isRetweet = true;
				} elseif ($tweet->referenced_tweets[0]->type === 'replied_to') {
					$isReply = true;
				}
			}

			// Skip replies and/or retweets (if selected). This check is primarily for lists
			// These should already be pre-filtered for username and keyword queries
			if (($hideRetweets && $isRetweet) || ($hideReplies && $isReply)) {
				continue;
			}

			$cleanedTweet = nl2br($tweet->text);
			//Debug::log('cleanedTweet: ' . $cleanedTweet);

			// Perform filtering (skip tweets that don't contain desired word, if provided)
			if (! empty($tweetFilter)) {
				if(stripos($cleanedTweet, $this->getInput('filter')) === false) {
					continue;
				}
			}

			// Initialize empty array to hold eventual HTML output
			$item = array();

			// Start setting values needed for HTML output
			if($isRetweet || is_null($user)) {
				Debug::log('Tweet is retweet, or $user is null');
				// Replace tweet object with original retweeted object
				if($isRetweet) {
					foreach($includesTweets as $includesTweet) {
						if($includesTweet->id === $tweet->referenced_tweets[0]->id) {
							$tweet = $includesTweet;
							break;
						}
					}
				}

				// Skip self-Retweets (can cause duplicate entries in output)
				if(isset($user) && $tweet->author_id === $user->data->id) {
					continue;
				}

				// Get user object for retweeted tweet
				$originalUser = new stdClass(); // make the linters stop complaining
				if(isset($retweetedUsers)) {
					Debug::log('Searching for tweet author_id in $retweetedUsers');
					foreach($retweetedUsers as $retweetedUser) {
						if($retweetedUser->id === $tweet->author_id) {
							$originalUser = $retweetedUser;
							Debug::log('Found author_id match in $retweetedUsers');
							break;
						}
					}
				}
				if(!isset($originalUser->username) && isset($includesUsers)) {
					Debug::log('Searching for tweet author_id in $includesUsers');
					foreach($includesUsers as $includesUser) {
						if($includesUser->id === $tweet->author_id) {
							$originalUser = $includesUser;
							Debug::log('Found author_id match in $includesUsers');
							break;
						}
					}
				}

				$item['username']  = $originalUser->username;
				$item['fullname']  = $originalUser->name;
				if(isset($originalUser->profile_image_url)) {
					$item['avatar']    = $originalUser->profile_image_url;
				} else{
					$item['avatar'] = null;
				}
			} else{
				$item['username']  = $user->data->username;
				$item['fullname']  = $user->data->name;
				$item['avatar']    = $user->data->profile_image_url;
			}
			$item['id']        = $tweet->id;
			$item['timestamp'] = $tweet->created_at;
			$item['uri']
			= self::URI . $item['username'] . '/status/' . $item['id'];
			$item['author']    = ($isRetweet ? 'RT: ' : '' )
						 . $item['fullname']
						 . ' (@'
						 . $item['username'] . ')';

			// Skip non-media tweet (if selected)
			// This check must wait until after retweets are identified
			if ($onlyMediaTweets && !isset($tweet->attachments->media_keys)) {
				continue;
			}

			// Search for and replace URLs in Tweet text
			$foundUrls = false;
			if(isset($tweet->entities->urls)) {
				foreach($tweet->entities->urls as $url) {
					$cleanedTweet = str_replace($url->url,
						'<a href="' . $url->expanded_url
						. '">' . $url->display_url . '</a>',
						$cleanedTweet);
					$foundUrls = true;
				}
			}
			if($foundUrls === false) {
				// fallback to regex'es
				$reg_ex = '/(http|https|ftp|ftps)\:\/\/[a-zA-Z0-9\-\.]+\.[a-zA-Z]{2,3}(\/\S*)?/';
				if(preg_match($reg_ex, $cleanedTweet, $url)) {
					$cleanedTweet = preg_replace($reg_ex,
						"<a href='{$url[0]}' target='_blank'>{$url[0]}</a> ",
						$cleanedTweet);
				}
			}

			// generate the title
			if ($idAsTitle) {
				$titleText = $tweet->id;
			} else{
				$titleText = strip_tags($cleanedTweet);
			}

			if($isRetweet && substr($titleText, 0, 4) === 'RT @') {
				$titleText = substr_replace($titleText, ':', 2, 0 );
			} elseif ($isReply  && !$idAsTitle) {
				$titleText = 'R: ' . $titleText;
			}

			$item['title'] = $titleText;

			// Add avatar
			$picture_html = '';
			if(!$hideProfilePic && isset($item['avatar'])) {
				$picture_html = <<<EOD
<a href="https://twitter.com/{$item['username']}">
<img
	style="margin-right: 10px; margin-bottom: 10px;"
	alt="{$item['username']}"
	src="{$item['avatar']}"
	title="{$item['fullname']}" />
</a>
EOD;
			}

			// Get images
			$media_html = '';
			if(!$hideImages && isset($tweet->attachments->media_keys)) {

				// Match media_keys in tweet to media list from, put matches
				// into new array
				$tweetMedia = array();
				// Start by checking the original list of tweet Media includes
				if(isset($includesMedia)) {
					foreach($includesMedia as $includesMedium) {
						if(in_array ($includesMedium->media_key,
						$tweet->attachments->media_keys)) {
							$tweetMedia[] = $includesMedium;
						}
					}
				}
				// If no matches found, check the retweet Media includes
				if(empty($tweetMedia) && isset($retweetedMedia)) {
					foreach($retweetedMedia as $retweetedMedium) {
						if(in_array ($retweetedMedium->media_key,
						$tweet->attachments->media_keys)) {
							$tweetMedia[] = $retweetedMedium;
						}
					}
				}

				foreach($tweetMedia as $media) {
					switch($media->type) {
					case 'photo':
						if ($this->getInput('noimgscaling')) {
							$image = $media->url;
							$display_image = $media->url;
						} else{
							$image = $media->url . '?name=orig';
							$display_image = $media->url;
						}
						// add enclosures
						$item['enclosures'][] = $image;

						$media_html .= <<<EOD
<a href="{$image}">
<img
	referrerpolicy="no-referrer"
	src="{$display_image}" />
</a>
EOD;
						break;
					case 'video':
						// To Do: Is there a way to easily match this
						// to a URL for a link?
						$display_image = $media->preview_image_url;

						$media_html .= <<<EOD
<img
	referrerpolicy="no-referrer"
	src="{$display_image}" />
EOD;
						break;
					case 'animated_gif':
						// To Do: Is there a way to easily match this to a
						// URL for a link?
						$display_image = $media->preview_image_url;

						$media_html .= <<<EOD
<img
	referrerpolicy="no-referrer"
	src="{$display_image}" />
EOD;
						break;
					default:
						Debug::log('Missing support for media type: '
						. $media->type);
					}
				}
			}

			$item['content'] = <<<EOD
<div style="float: left;">
	{$picture_html}
</div>
<div style="display: table;">
	{$cleanedTweet}
</div>
<div style="display: block; margin-top: 16px;">
	{$media_html}
</div>
EOD;

			$item['content'] = htmlspecialchars_decode($item['content'], ENT_QUOTES);

			// put out item
			$this->items[] = $item;
		}

		// Sort all tweets in array by date
		usort($this->items, array('TwitterV2Bridge', 'compareTweetDate'));
	}

	private static function compareTweetDate($tweet1, $tweet2) {
		return (strtotime($tweet1['timestamp']) < strtotime($tweet2['timestamp']) ? 1 : -1);
	}

	/**
	 * Tries to make an API call to Twitter.
	 * @param $api string API entry point
	 * @param $params array additional URI parmaeters
	 * @return object json data
	 */
	private function makeApiCall($api, $params) {
		$uri = self::API_URI . $api . '?' . http_build_query($params);
		$result = getContents($uri, $this->authHeaders, array(), false);
		$data = json_decode($result);
		return $data;
	}
}
