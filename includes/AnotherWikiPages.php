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

/**
 * Methods to fetch the list of pages that exist in another wiki.
 */
class AnotherWikiPages {

	/**
	 * Make an API query "what pages do you have" to another wiki.
	 */
	public function fetchListUncached() {
		global $wgAutoLinksToAnotherWikiApiUrl;
		if ( !$wgAutoLinksToAnotherWikiApiUrl ) {
			// Not configured.
			return;
		}

		$url = wfExpandUrl( $wgAutoLinksToAnotherWikiApiUrl, PROTO_HTTP );
		$url = wfAppendQuery( $url, [
			'format' => 'json',
			'formatversion' => 2,
			'action' => 'query',
			// It's possible to make do with a shorter query (list=allpages) without the generator,
			// but that would require 1 more HTTP query to discover the ArticlePath for the URLs.
			'generator' => 'allpages',
			'prop' => 'info',
			'inprop' => 'url'
		] );
		$req = MediaWikiServices::getInstance()->getHttpRequestFactory()
			->create( $url, [], __METHOD__ );

		$status = $req->execute();
		if ( !$status->isOK() ) {
			return;
		}

		$result = FormatJson::decode( $req->getContent(), true );
		$rows = $result['query']['pages'] ?? [];
		if ( !$rows ) {
			return;
		}

		$pages = [];
		foreach ( $rows as $row ) {
			$pages[$row['title']] = $row['fullurl'];
		}

		// TODO: cache $pages
	}

	// TODO: cache for fetchListUncached()
	// TODO: longer duration fallback cache, throttle attempts to send HTTP query if they are failing.
	// TODO: getRegex(), etc.
}
