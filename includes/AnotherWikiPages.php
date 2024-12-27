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
		'AutoLinksToAnotherWikiMaxTitles',
		'AutoLinksToAnotherWikiQueryLimit'
	];

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
		$foundPages = $this->fetchList();
		if ( !$foundPages ) {
			// No replacements needed.
			return false;
		}

		foreach ( array_keys( $foundPages ) as $pageName ) {
			// Canonical page names start with an uppercase letter,
			// but we must also match it if it starts with a lowercase letter.
			$lcfirstPageName = $this->contentLanguage->lcfirst( $pageName );
			$foundPages[$lcfirstPageName] = $foundPages[$pageName];
		}

		// Sort by the length of pagename (descending), so that the longest links would be added first
		// (e.g. "Times Square" should become "[[Times Square]]", not "Times [[Square]]").
		uksort( $foundPages, static function ( $a, $b ) {
			return strlen( $b ) - strlen( $a );
		} );

		// The purpose of markers is to prevent unwanted replacements inside URLs
		// that we have just added. First, we replace words with markers,
		// then we replace markers with URLs.
		$markers = [];
		$markerValues = [];

		// Helper method to create a new marker that will later be replaced with $newValue.
		$getMarker = static function ( $newValue ) use ( &$markers, &$markerValues ) {
			$marker = '{{LINKMARKER' . count( $markers ) . '}}';

			$markers[] = $marker;
			$markerValues[] = $newValue;

			return $marker;
		};

		// Temporarily hide HTML tags, links and URLs to prevent replacements in them.
		$newHtml = $html;
		foreach ( [
			'/<(a|h[1-6])[ >].*?<\/\1>/',
			'/<[^<>]*>|http?:[^\s]+/'
		] as $pattern ) {
			$newHtml = preg_replace_callback( $pattern, static function ( $matches ) use ( $getMarker ) {
				return $getMarker( $matches[0] );
			}, $newHtml );
		}

		$countTotal = 0;
		foreach ( array_chunk( array_keys( $foundPages ), 500 ) as $chunk ) {
			$regex = implode( '|', array_map( static function ( $pageName ) {
				return preg_quote( $pageName, '/' );
			}, $chunk ) );

			$newHtml = preg_replace_callback( "/\b($regex)\b/", static function ( $matches )
				use ( $foundPages, $getMarker )
			{
				$pageName = $matches[0];
				$url = $foundPages[$pageName];

				return $getMarker( Linker::makeExternalLink( $url, $pageName ) );
			}, $newHtml, -1, $count );
			$countTotal += $count;
		}

		if ( $countTotal < 1 ) {
			return false;
		}

		$html = str_replace( $markers, $markerValues, $newHtml );
		return true;
	}

	/**
	 * Make an API query "what pages do you have" to another wiki.
	 * @return array The list of found pages: [ "Page name1" => "URL1", ... ]
	 */
	protected function fetchList() {
		$cacheKey = $this->getCacheKey();

		$result = $this->cache->get( $cacheKey );
		if ( $result === false ) { /* Not found in the cache */
			$result = $this->fetchListUncached();
			$this->cache->set( $cacheKey, $result,
				// Failure to fetch is cached for 5 minutes (to avoid sending HTTP queries over and over).
				// Successful response is cached for 24 hours.
				$result === [] ? 300 : 86400
			);
		}

		return $result;
	}

	/**
	 * Returns memcached key used by fetchList().
	 * @return string
	 */
	protected function getCacheKey() {
		return $this->cache->makeKey( 'anotherwikipages-list' );
	}

	/**
	 * Uncached version of fetchList(). Shouldn't be used outside of fetchList().
	 * @return array
	 */
	public function fetchListUncached() {
		$apiUrl = $this->options->get( 'AutoLinksToAnotherWikiApiUrl' );
		if ( !$apiUrl ) {
			// Not configured.
			return [];
		}

		$limit = max( 1, min( 5000, intval( $this->options->get( 'AutoLinksToAnotherWikiQueryLimit' ) ) ) );
		$maxTitles = max( $limit, $this->options->get( 'AutoLinksToAnotherWikiMaxTitles' ) );

		$query = [
			'format' => 'json',
			'formatversion' => 2,
			'action' => 'query',
			// It's possible to make do with a shorter query (list=allpages) without the generator,
			// but that would require 1 more HTTP query to discover the ArticlePath for the URLs.
			'generator' => 'allpages',
			'gaplimit' => $limit,
			'prop' => 'info',
			'inprop' => 'url'
		];

		$rows = [];
		while ( count( $rows ) < $maxTitles ) {
			$url = wfAppendQuery( wfExpandUrl( $apiUrl, PROTO_HTTP ), $query );
			$req = $this->httpRequestFactory->create( $url, [], __METHOD__ );

			$status = $req->execute();
			if ( !$status->isOK() ) {
				break;
			}

			$result = FormatJson::decode( $req->getContent(), true );
			$newRows = $result['query']['pages'] ?? [];
			if ( !$newRows ) {
				break;
			}
			$rows = array_merge( $rows, array_slice( $newRows, 0, $maxTitles - count( $rows ) ) );

			$continueToken = $result['continue']['gapcontinue'] ?? null;
			if ( !$continueToken ) {
				break;
			}
			$query['gapcontinue'] = $continueToken;
		}

		$pages = [];
		foreach ( $rows as $row ) {
			$pages[$row['title']] = $row['fullurl'];
		}

		return $pages;
	}
}
