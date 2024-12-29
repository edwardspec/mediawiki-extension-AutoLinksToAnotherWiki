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

/**
 * Methods to search/replace text inside HTML without corrupting tags, etc.
 */
class ReplaceTextInHtml {
	/** @var string[] */
	protected $markers = [];

	/** @var string[] */
	protected $markerValues = [];

	/**
	 * Run $callback on parts of subject string ($html) that can't be corrupted with replacements.
	 * @param string $html
	 * @param \Closure(string,ReplaceTextInHtml):string $callback
	 * @return string Resulting HTML after replacements.
	 */
	public function processHtml( $html, $callback ) {
		// The purpose of markers is to prevent unwanted replacements inside new strings
		// that we have just added. First, we replace words with markers,
		// then we replace markers with new strings.
		$this->markers = [];
		$this->markerValues = [];

		// Temporarily hide HTML tags, links and URLs to prevent replacements in them.
		foreach ( [
			'/<(a|h[1-6])[ >].*?<\/\1>/',
			'/<[^<>]*>|http?:[^\s]+/'
		] as $pattern ) {
			$html = preg_replace_callback( $pattern, function ( $matches ) {
				return $this->getMarker( $matches[0] );
			}, $html );
		}

		$html = $callback( $html, $this );

		// Restore markers.
		return str_replace( $this->markers, $this->markerValues, $html );
	}

	/**
	 * Encode a string, protecting it from further replacements.
	 * Returns encoded string ("marker") that can be inserted into the new text at any point.
	 * All markers are automatically decoded at the end of processHtml().
	 *
	 * @param string $newValue String that we want to appear in the new text.
	 * @return string
	 */
	public function getMarker( $newValue ) {
		$marker = '{{LINKMARKER' . count( $this->markers ) . '}}';

		$this->markers[] = $marker;
		$this->markerValues[] = $newValue;

		return $marker;
	}

}
