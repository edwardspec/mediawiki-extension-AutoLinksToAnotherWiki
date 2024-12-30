<?php

/**
 * Implements AutoLinksToAnotherWiki extension for MediaWiki.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

namespace MediaWiki\AutoLinksToAnotherWiki;

use BagOStuff;
use FormatJson;
use Language;
use Linker;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Http\HttpRequestFactory;

/**
 * Methods to fetch the list of pages that exist in another wiki.
 */
class AnotherWikiPages {
	/** @var ServiceOptions */
	protected $options;

	/** @var BagOStuff */
	protected $cache;

	/** @var Language */
	protected $contentLanguage;

	/** @var HttpRequestFactory */
	protected $httpRequestFactory;

	public const CONSTRUCTOR_OPTIONS = [
		'AutoLinksToAnotherWikiApiUrl',
		'AutoLinksToAnotherWikiExcludeLinksTo',
		'AutoLinksToAnotherWikiMaxTitles',
		'AutoLinksToAnotherWikiOnlyWithinClassName',
		'AutoLinksToAnotherWikiQueryLimit'
	];

	/**
	 * @var string[] List of page names found in another wiki.
	 * Set during update().
	 */
	protected $foundPages = [];

	/**
	 * @var string Value of ArticlePath in another wiki, e.g. "/wiki/$1".
	 * Set during update().
	 */
	protected $articlePath = '';

	/**
	 * @param ServiceOptions $options
	 * @param BagOStuff $cache
	 * @param Language $contentLanguage
	 * @param HttpRequestFactory $httpRequestFactory
	 */
	public function __construct(
		ServiceOptions $options,
		BagOStuff $cache,
		Language $contentLanguage,
		HttpRequestFactory $httpRequestFactory
	) {
		$this->options = $options;
		$this->cache = $cache;
		$this->contentLanguage = $contentLanguage;
		$this->httpRequestFactory = $httpRequestFactory;
	}

	/**
	 * Add links to the string $html.
	 * @param string &$html
	 * @return bool True if at least 1 replacement was made, false otherwise.
	 */
	public function addLinks( &$html ) {
		$this->updateList();
		if ( !$this->foundPages || !$this->articlePath ) {
			// No replacements needed.
			return false;
		}

		// Exclude unwanted links.
		$foundPages = array_diff(
			$this->foundPages,
			$this->options->get( 'AutoLinksToAnotherWikiExcludeLinksTo' )
		);

		foreach ( $foundPages as $pageName ) {
			// Canonical page names start with an uppercase letter,
			// but we must also match it if it starts with a lowercase letter.
			$foundPages[] = $this->contentLanguage->lcfirst( $pageName );
		}

		// Sort by the length of pagename (descending), so that the longest links would be added first
		// (e.g. "Times Square" should become "[[Times Square]]", not "Times [[Square]]").
		usort( $foundPages, static function ( $a, $b ) {
			return strlen( $b ) - strlen( $a );
		} );

		$callback = function ( $text, ReplaceTextInHtml $replacer ) use ( $foundPages ) {
			foreach ( array_chunk( $foundPages, 500 ) as $chunk ) {
				$regex = implode( '|', array_map( static function ( $pageName ) {
					return preg_quote( $pageName, '/' );
				}, $chunk ) );

				$text = preg_replace_callback( "/\b($regex)\b/", function ( $matches )
					use ( $replacer )
				{
					$pageName = $matches[0];
					$url = $this->getUrl( $pageName );

					return $replacer->getMarker( Linker::makeExternalLink( $url, $pageName ) );
				}, $text );
			}
			return $text;
		};

		$replacer = new ReplaceTextInHtml();
		$newHtml = $replacer->processHtml( $html, $callback,
			$this->options->get( 'AutoLinksToAnotherWikiOnlyWithinClassName' )
		);

		if ( $html === $newHtml ) {
			return false;
		}

		$html = $newHtml;
		return true;
	}

	/**
	 * Get URL in another wiki by page name.
	 * @param string $pageName
	 * @return string
	 */
	protected function getUrl( $pageName ) {
		$pageName = $this->contentLanguage->ucfirst( $pageName );
		return str_replace( '$1', strtr( $pageName, ' ', '_' ), $this->articlePath );
	}

	/**
	 * Make an API query to another wiki, return parsed API result.
	 * @param array $query
	 * @return mixed|null
	 *
	 * @phan-param array<string,mixed> $query
	 */
	protected function sendApiQuery( array $query ) {
		$apiUrl = $this->options->get( 'AutoLinksToAnotherWikiApiUrl' );
		if ( !$apiUrl ) {
			// Not configured.
			return null;
		}

		$url = wfAppendQuery( wfExpandUrl( $apiUrl, PROTO_HTTPS ), $query );
		$req = $this->httpRequestFactory->create( $url, [], __METHOD__ );

		$status = $req->execute();
		if ( !$status->isOK() ) {
			return null;
		}

		return FormatJson::decode( $req->getContent(), true );
	}

	/**
	 * Update list of articles and ArticlePath of another wiki.
	 */
	protected function updateList() {
		$cacheKey = $this->getCacheKey();
		$result = $this->cache->get( $cacheKey );
		if ( $result === false ) { /* Not found in the cache */
			$articlePath = $this->fetchArticlePath();
			$foundPages = $this->fetchList();

			$result = [ $articlePath, $foundPages ];

			$this->cache->set( $cacheKey, $result,
				// Failure to fetch is cached for 5 minutes (to avoid sending HTTP queries over and over).
				// Successful response is cached for 24 hours.
				$result === [] ? 300 : 86400
			);
		}

		[ $this->articlePath, $this->foundPages ] = $result;
	}

	/**
	 * Returns memcached key used by updateList().
	 * @return string
	 */
	protected function getCacheKey() {
		return $this->cache->makeKey( 'anotherwikipages-data' );
	}

	/**
	 * Make an API query to determine ArticlePath of another wiki. Returns ArticlePath.
	 * @return string
	 */
	protected function fetchArticlePath() {
		$ret = $this->sendApiQuery( [
			'format' => 'json',
			'formatversion' => 2,
			'action' => 'query',
			'meta' => 'siteinfo',
			'siprop' => 'general'
		] );
		if ( !$ret ) {
			return '';
		}

		$server = $ret['query']['general']['server'] ?? null;
		$path = $ret['query']['general']['articlepath'] ?? null;
		if ( !$server || !$path ) {
			// Sanity check.
			return '';
		}

		return wfExpandUrl( $server . $path, PROTO_HTTPS );
	}

	/**
	 * Make an API query "what pages do you have" to another wiki.
	 * @return string[] The list of found page names.
	 */
	protected function fetchList() {
		$limit = max( 1, min( 5000, intval( $this->options->get( 'AutoLinksToAnotherWikiQueryLimit' ) ) ) );
		$maxTitles = max( $limit, $this->options->get( 'AutoLinksToAnotherWikiMaxTitles' ) );

		$query = [
			'format' => 'json',
			'formatversion' => 2,
			'action' => 'query',
			'list' => 'allpages',
			'aplimit' => $limit
		];

		$rows = [];
		while ( count( $rows ) < $maxTitles ) {
			$result = $this->sendApiQuery( $query );
			if ( !$result ) {
				break;
			}

			$newRows = $result['query']['allpages'] ?? [];
			if ( !$newRows ) {
				break;
			}
			$rows = array_merge( $rows, array_slice( $newRows, 0, $maxTitles - count( $rows ) ) );

			$continueToken = $result['continue']['apcontinue'] ?? null;
			if ( !$continueToken ) {
				break;
			}
			$query['apcontinue'] = $continueToken;
		}

		$pages = [];
		foreach ( $rows as $row ) {
			$pages[] = $row['title'];
		}

		return $pages;
	}
}
