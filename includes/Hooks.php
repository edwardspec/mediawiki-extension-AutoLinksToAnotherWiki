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

use Config;
use MediaWiki\Actions\ActionFactory;
use MediaWiki\Hook\OutputPageParserOutputHook;
use OutputPage;
use ParserOutput;
use Title;

/**
 * Hooks of Extension:AutoLinksToAnotherWiki.
 */
class Hooks implements OutputPageParserOutputHook {
	/** @var Config */
	protected $config;

	/** @var AnotherWikiPages */
	protected $awp;

	/** @var ActionFactory */
	protected $actionFactory;

	/**
	 * @param Config $config
	 * @param AnotherWikiPages $awp
	 * @param ActionFactory $actionFactory
	 */
	public function __construct(
		Config $config,
		AnotherWikiPages $awp,
		ActionFactory $actionFactory
	) {
		$this->config = $config;
		$this->awp = $awp;
		$this->actionFactory = $actionFactory;
	}

	/**
	 * Add external links to another wiki to the words that have an article in another wiki.
	 *
	 * @param OutputPage $out
	 * @param ParserOutput $parserOutput
	 * @return void
	 */
	public function onOutputPageParserOutput( $out, $parserOutput ): void {
		if ( !$parserOutput->hasText() ) {
			// Not needed.
			return;
		}

		$categoryName = $this->config->get( 'AutoLinksToAnotherWikiCategoryName' );
		if ( !$categoryName ) {
			// Not configured.
			return;
		}

		$actionName = $this->actionFactory->getActionName( $out->getContext() );
		if ( $actionName !== 'view' ) {
			// Replacements are only applied when viewing an article,
			// so that they wouldn't affect elements like <textarea> for editing/previewing, etc.
			return;
		}

		// Normalize the category name.
		$categoryTitle = Title::makeTitleSafe( NS_CATEGORY, $categoryName );
		$categoryName = $categoryTitle->getText();

		// FIXME: there is a possibility that getCategories() isn't returning the full list,
		// because these categories are only added in later invocations of this hook.
		if ( !in_array( $categoryName, $out->getCategories() ) ) {
			// This page is not in the configured category, so we don't need to add links to it.
			return;
		}

		$html = $parserOutput->getRawText();
		if ( $this->awp->addLinks( $html ) ) {
			$parserOutput->setText( $html );
		}
	}
}
