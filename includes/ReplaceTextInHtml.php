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

use Wikimedia\RemexHtml\DOM\DOMBuilder;
use Wikimedia\RemexHtml\DOM\DOMSerializer;
use Wikimedia\RemexHtml\Serializer\HtmlFormatter;
use Wikimedia\RemexHtml\Tokenizer\Tokenizer;
use Wikimedia\RemexHtml\TreeBuilder\Dispatcher;
use Wikimedia\RemexHtml\TreeBuilder\TreeBuilder;

/**
 * Methods to search/replace text inside HTML without corrupting tags, etc.
 */
class ReplaceTextInHtml {
	/**
	 * Same as preg_replace_callback(), but doesn't corrupt tags within subject string ($html).
	 * @param string $pattern
	 * @param callable $callback
	 * @param string $html
	 * @return string
	 */
	public static function replaceWithCallback( $pattern, $callback, $html ) {
		$formatter = new class ( [
			'pattern' => $pattern,
			'callback' => $callback
		] ) extends HtmlFormatter {
			/** @var string */
			protected $pattern;

			/** @var callable */
			protected $callback;

			/** @inheritDoc */
			public function __construct( $options = [] ) {
				parent::__construct( $options );

				$this->pattern = $options['pattern'];
				$this->callback = $options['callback'];
			}

			/**
			 * @param \DOMNode $node
			 * @return string
			 */
			public function formatDOMNode( $node ) {
				if ( $node->nodeType == XML_TEXT_NODE ) {
					// @phan-suppress-next-line PhanUndeclaredProperty
					$node->data = preg_replace_callback( $this->pattern, $this->callback, $node->data );
				}

				return parent::formatDOMNode( $node );
			}
		};

		$domBuilder = new DOMBuilder();
		$serializer = new DOMSerializer( $domBuilder, $formatter );
		$treeBuilder = new TreeBuilder( $serializer );
		$dispatcher = new Dispatcher( $treeBuilder );
		$tokenizer = new Tokenizer( $dispatcher, $html );
		$tokenizer->execute();

		return $serializer->getResult();
	}

}
