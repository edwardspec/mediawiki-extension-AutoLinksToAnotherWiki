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

use FormatJson;
use MediaWiki\MediaWikiServices;
use Linker;
use ObjectCache;

/**
 * Methods to fetch the list of pages that exist in another wiki.
 */
class AnotherWikiPages {
	/** @var BagOStuff */
	protected $cache;

	public function __construct() {
		$this->cache = ObjectCache::getLocalClusterInstance();
	}

	/**
	 * @var array
	 * List of all pages found in another wiki: [ 'pageName' => 'url', ... ].
	 */
	protected $foundPages;

	/**
	 * Add links to the existing string $html and return the modified version.
	 * @param string $html
	 * @return string
	 */
	public function addLinks( $html ) {
		$foundPages = $this->fetchList();

		$regex = implode( '|', array_map( function ( $pageName ) {
			return preg_quote( $pageName, '/' );
		}, array_keys( $foundPages ) ) );

		// TODO: make the match partially case-insensitive,
		// for example, the text "Lions are big cats" should get an automatic link to "Big cats",
		// but make sure to not cause an incorrect link to "Big Cats" if the text says "Big Cats",
		// as page URLs are case-sensitive (except the first letter of the title).

		$newhtml = preg_replace_callback( "/$regex/", static function ( $matches ) use ( $foundPages ) {
			$pageName = $matches[0];
			$url = $foundPages[$pageName];

			return Linker::makeExternalLink( $url, $pageName );
		}, $html );

		return $newhtml;
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
			if ( !$result ) {
				// TODO: cache failure to fetch (to avoid sending HTTP queries over and over).
				// TODO: add a longer duration fallback cache.
				return [];
			}

			// 24 hours. TODO: add a way to explicitly clear this cache.
			$this->cache->set( $cacheKey, $result, 86400 );
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
		global $wgAutoLinksToAnotherWikiApiUrl;
		if ( !$wgAutoLinksToAnotherWikiApiUrl ) {
			// Not configured.
			return [];
		}

		$url = wfExpandUrl( $wgAutoLinksToAnotherWikiApiUrl, PROTO_HTTP );
		$url = wfAppendQuery( $url, [
			'format' => 'json',
			'formatversion' => 2,
			'action' => 'query',
			// It's possible to make do with a shorter query (list=allpages) without the generator,
			// but that would require 1 more HTTP query to discover the ArticlePath for the URLs.
			'generator' => 'allpages',
			'gaplimit' => 5000,
			'prop' => 'info',
			'inprop' => 'url'
		] );
		$req = MediaWikiServices::getInstance()->getHttpRequestFactory()
			->create( $url, [], __METHOD__ );

		$status = $req->execute();
		if ( !$status->isOK() ) {
			return [];
		}

		$result = FormatJson::decode( $req->getContent(), true );
		$rows = $result['query']['pages'] ?? [];
		if ( !$rows ) {
			return [];
		}

		$pages = [];
		foreach ( $rows as $row ) {
			$pages[$row['title']] = $row['fullurl'];
		}

		return $pages;
	}
}
