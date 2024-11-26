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

use MediaWiki\Actions\ActionFactory;
use MediaWiki\Hook\BeforePageDisplayHook;
use OutputPage;
use Skin;
use Title;

/**
 * Hooks of Extension:AutoLinksToAnotherWiki.
 */
class Hooks implements BeforePageDisplayHook {
	/** @var ActionFactory */
	protected $actionFactory;

	/**
	 * @param ActionFactory $actionFactory
	 */
	public function __construct( ActionFactory $actionFactory ) {
		$this->actionFactory = $actionFactory;
	}

	/**
	 * Add "Show images from subcategories" link to category pages.
	 *
	 * @param OutputPage $out
	 * @param Skin $skin
	 * @return void
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		// We are not using OutputPageBeforeHTML hook, because we need getCategories(),
		// and some of categories may be added after OutputPageBeforeHTML has already been called.
		global $wgAutoLinksToAnotherWikiCategoryName;
		if ( !$wgAutoLinksToAnotherWikiCategoryName ) {
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
		$categoryTitle = Title::makeTitleSafe( NS_CATEGORY, $wgAutoLinksToAnotherWikiCategoryName );
		$categoryName = $categoryTitle->getText();

		if ( !in_array( $categoryName, $out->getCategories() ) ) {
			// This page is not in the configured category, so we don't need to add links to it.
			return;
		}

		$awp = new AnotherWikiPages();

		$html = $out->getHTML();
		if ( $awp->addLinks( $html ) ) {
			$out->clearHTML();
			$out->addHTML( $html );
		}
	}
}
