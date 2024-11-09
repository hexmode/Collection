<?php
/**
 * Collection Extension for MediaWiki
 *
 * Copyright (C) PediaPress GmbH
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
 */

namespace MediaWiki\Extension\Collection;

use HtmlArmor;
use MediaWiki\Context\RequestContext;
use MediaWiki\Hook\SidebarBeforeOutputHook;
use MediaWiki\Hook\SiteNoticeAfterHook;
use MediaWiki\Html\Html;
use MediaWiki\Html\TemplateParser;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\Output\Hook\OutputPageCheckLastModifiedHook;
use MediaWiki\Output\OutputPage;
use MediaWiki\Session\SessionManager;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use Skin;

class Hooks implements
	SidebarBeforeOutputHook,
	SiteNoticeAfterHook,
	OutputPageCheckLastModifiedHook
{
	/**
	 * Callback for SidebarBeforeOutput hook
	 *
	 * @param Skin $skin
	 * @param array &$sidebar
	 */
	public function onSidebarBeforeOutput( $skin, &$sidebar ): void {
		global $wgCollectionPortletForLoggedInUsersOnly;

		if ( !$wgCollectionPortletForLoggedInUsersOnly || $skin->getUser()->isRegistered() ) {

			$portlet = self::getPortlet( $skin );

			if ( $portlet ) {
				// Unset 'print' item. We have moved it to our own section.
				unset( $sidebar['TOOLBOX']['print'] );

				// Add our section
				$sidebar[ 'coll-print_export' ] = $portlet;
			}
		}
	}

	/**
	 * Determinte if the portlet should be shown.
	 */
	protected static function shouldShowPortlet( Skin $sk ): bool {
		$title = $sk->getTitle();
		if ( $title === null || !$title->exists() ) {
			return false;
		}

		$namespace = $title->getNamespace();
		$collNS = $sk->getConfig()->get( 'CollectionArticleNamespaces' );
		if ( !in_array( $namespace, $collNS )
			 && $namespace != NS_CATEGORY ) {
			return false;
		}

		$action = $sk->getRequest()->getVal( 'action', 'view' );
		if ( $action != 'view' && $action != 'purge' ) {
			return false;
		}
		return true;
	}

	/**
	 * Return HTML-code for the session switch (turning book creation on or off).
	 */
	protected static function getSessionSwitch( Skin $sk, Title $title, Title $booktitle ): array {
        $out = [];
		if ( !Session::isEnabled() ) {
			if ( !$sk->getConfig()->get( 'CollectionDisableSidebarLink' ) ) {
				$out[] = [
					'text' => $sk->msg( 'coll-create_a_book' )->text(),
					'id' => 'coll-create_a_book',
					'href' => $booktitle->getLocalURL(
						[ 'bookcmd' => 'book_creator', 'referer' => $title->getPrefixedText() ]
						),
				];
			}
		} else {
			$out[] = [
				'text' => $sk->msg( 'coll-book_creator_disable' )->escaped(),
				'id' => 'coll-book_creator_disable',
				'href' => $booktitle->getLocalURL(
					[
						'bookcmd' => 'stop_book_creator',
						'referer' => $title->getPrefixedText(),
					]
				),
			];
		}
		return $out;
	}

	/**
	 * Returns the initial parameters for link building.
	 */
	protected static function initializeParams( Skin $sk, Title $title ): array {
		$params = [
			'bookcmd' => 'render_article',
			'arttitle' => $title->getPrefixedText(),
			'returnto' => $title->getPrefixedText(),
		];

		$oldid = $sk->getRequest()->getVal( 'oldid' );
		if ( $oldid ) {
			$params['oldid'] = $oldid;
		} else {
			$params['oldid'] = $title->getLatestRevID();
		}

		return $params;
	}

	/**
	 * Return HTML-code to be inserted as portlet
	 *
	 * @param Skin $sk
	 *
	 * @return array[]|false
	 */
	public static function getPortlet( $sk ) {
		if ( !self::shouldShowPortlet( $sk ) ) {
			return false;
		}

		$title = $sk->getTitle();
		$booktitle = SpecialPage::getTitleFor( 'Book' );
		$out = self::getSessionSwitch($sk, $title, $booktitle);

		$params = self::initializeParams( $sk, $title );

		$collFormats = $sk->getConfig()->get( 'CollectionFormats' );
		$collPortletFormats = $sk->getConfig()->get( 'CollectionPortletFormats' );
		foreach ( $collPortletFormats as $writer ) {
			$params['writer'] = $writer;
			$out[] = [
				'text' => $sk->msg( 'coll-download_as', $collFormats[$writer] )->escaped(),
				'id' => 'coll-download-as-' . $writer,
				'href' => $booktitle->getLocalURL( $params ),
			];
		}

		// Move the 'printable' link into our section for consistency
		if ( !$sk->getOutput()->isPrintable() ) {
			$out[] = [ 'text' => $sk->msg( 'printableversion' )->text(),
					   'id' => 't-print',
					   'href' => $title->getLocalURL( [ 'printable' => 'yes' ] )
			];
		}

		return $out;
	}

	/**
	 * Callback for hook SiteNoticeAfter
	 * @param string &$siteNotice
	 * @param Skin $skin
	 */
	public function onSiteNoticeAfter( &$siteNotice, $skin ) {
		global $wgCollectionArticleNamespaces;

		$request = $skin->getRequest();
		$title = $skin->getTitle();

		$action = $request->getVal( 'action' );
		if ( $action != '' && $action != 'view' && $action != 'purge' ) {
			return;
		}

		$session = SessionManager::getGlobalSession();

		if (
			!isset( $session['wsCollection'] ) ||
			!isset( $session['wsCollection']['enabled'] ) ||
			!$session['wsCollection']['enabled']
		) {
			return;
		}

		if ( $title->isSpecial( 'Book' ) ) {
			$cmd = $request->getVal( 'bookcmd', '' );
			if ( $cmd == 'suggest' ) {
				$siteNotice .= self::renderBookCreatorBox( $title, 'suggest' );
			} elseif ( $cmd == '' ) {
				$siteNotice .= self::renderBookCreatorBox( $title, 'showbook' );
			}
			return;
		}

		if ( !$title->exists() ) {
			return;
		}

		$namespace = $title->getNamespace();
		if ( !in_array( $namespace, $wgCollectionArticleNamespaces )
			&& $namespace != NS_CATEGORY ) {
			return;
		}

		$siteNotice .= self::renderBookCreatorBox( $title );
	}

	/**
	 * @param Title $title
	 * @param string $mode
	 * @return string
	 */
	public static function renderBookCreatorBox( Title $title, $mode = '' ) {
		$templateParser = new TemplateParser( dirname( __DIR__ ) . '/templates' );
		$context = RequestContext::getMain();

		$imagePath = $context->getConfig()->get( MainConfigNames::ExtensionAssetsPath ) . '/Collection/images';
		$ptext = $title->getPrefixedText();
		$oldid = $context->getRequest()->getInt( 'oldid', 0 );
		if ( $oldid == $title->getLatestRevID() ) {
			$oldid = 0;
		}

		$out = $context->getOutput();
		$out->addModules( 'ext.collection.bookcreator' );
		$out->addModuleStyles( 'ext.collection.bookcreator.styles' );

		$addRemoveState = $mode;
		$helpPage = Title::newFromText( $context->msg( 'coll-helppage' )->text() );

		return $templateParser->processTemplate( 'create-book', [
			"actionsHtml" => self::getBookCreatorBoxContent( $title, $addRemoveState, $oldid ),
			"imagePath" => $imagePath,
			"title" => $context->msg( 'coll-book_creator' )->text(),
			"disable" => [
				"url" => SpecialPage::getTitleFor( 'Book' )->getLocalUrl(
					[ 'bookcmd' => 'stop_book_creator', 'referer' => $ptext ]
				),
				"title" => $context->msg( 'coll-book_creator_disable_tooltip' )->text(),
				"label" => $context->msg( 'coll-disable' )->escaped(),
			],
			"help" => [
				"url" => $helpPage ? $helpPage->getLocalUrl() : '',
				"label" => $context->msg( 'coll-help' )->escaped(),
				"title" => $context->msg( 'coll-help_tooltip' )->text(),
				"icon" => $imagePath . '/silk-help.png',
			]
		] );
	}

	/**
	 * @param Title $title
	 * @param string|null $hint Defaults to null
	 * @param null|int $oldid
	 * @return string
	 */
	public static function getBookCreatorBoxContent( Title $title, $hint = null, $oldid = null ) {
		global $wgExtensionAssetsPath;

		$imagePath = "$wgExtensionAssetsPath/Collection/images";

		return self::getBookCreatorBoxAddRemoveLink( $imagePath, $hint, $title, $oldid )
			. self::getBookCreatorBoxShowBookLink( $imagePath, $hint )
			. self::getBookCreatorBoxSuggestLink( $imagePath, $hint );
	}

	/**
	 * @param string $imagePath
	 * @param string $hint
	 * @param Title $title
	 * @param int $oldid
	 * @return string
	 */
	public static function getBookCreatorBoxAddRemoveLink(
		$imagePath,
		$hint,
		Title $title,
		$oldid
	) {
		$namespace = $title->getNamespace();
		$ptext = $title->getPrefixedText();

		if ( $hint == 'suggest' || $hint == 'showbook' ) {
			return Html::rawElement( 'span',
				[ 'style' => 'color: #777;' ],
				Html::element( 'img',
					[
						'src' => "$imagePath/disabled.png",
						'alt' => '',
						'width' => '16',
						'height' => '16',
						'style' => 'vertical-align: text-bottom',
					]
				)
				. '&#160;' . wfMessage( 'coll-not_addable' )->escaped()
			);
		}

		if ( $hint == 'addcategory' || $namespace == NS_CATEGORY ) {
			$id = 'coll-add_category';
			$icon = 'silk-add.png';
			$captionMsg = 'coll-add_category';
			$tooltipMsg = 'coll-add_category_tooltip';
			$query = [ 'bookcmd' => 'add_category', 'cattitle' => $title->getText() ];
			$onclick = "collectionCall('addcategory', mw.config.get('wgNamespaceNumber')," .
				"mw.config.get('wgTitle')]); return false;";
		} else {
			$collectionArgsJs = "mw.config.get('wgNamespaceNumber'), mw.config.get('wgTitle'), " .
				Html::encodeJsVar( $oldid );
			if ( $hint == 'addarticle'
				|| ( $hint == '' && Session::findArticle( $ptext, $oldid ) == -1 ) ) {
				$id = 'coll-add_article';
				$icon = 'silk-add.png';
				$captionMsg = 'coll-add_this_page';
				$tooltipMsg = 'coll-add_page_tooltip';
				$query = [ 'bookcmd' => 'add_article', 'arttitle' => $ptext, 'oldid' => $oldid ];
				$onclick = "collectionCall('addarticle', " . $collectionArgsJs . "); return false;";
			} else {
				$id = 'coll-remove_article';
				$icon = 'silk-remove.png';
				$captionMsg = 'coll-remove_this_page';
				$tooltipMsg = 'coll-remove_page_tooltip';
				$query = [ 'bookcmd' => 'remove_article', 'arttitle' => $ptext, 'oldid' => $oldid ];
				$onclick = "collectionCall('removearticle', " . $collectionArgsJs . "); return false;";
			}
		}

		$linkRenderer = MediaWikiServices::getInstance()->getLinkRenderer();

		return $linkRenderer->makeKnownLink(
			SpecialPage::getTitleFor( 'Book' ),
			new HtmlArmor( Html::element( 'img',
				[
					'src' => "$imagePath/$icon",
					'alt' => '',
					'width' => '16',
					'height' => '16',
				]
			)
			. '&#160;' . wfMessage( $captionMsg )->escaped() ),
			[
				'id' => $id,
				'rel' => 'nofollow',
				'title' => wfMessage( $tooltipMsg )->text(),
				'onclick' => $onclick,
			],
			$query
		);
	}

	/**
	 * @param string $imagePath
	 * @param string $hint
	 * @return string
	 */
	public static function getBookCreatorBoxShowBookLink( $imagePath, $hint ) {
		$numArticles = Session::countArticles();

		if ( $hint == 'showbook' ) {
			return Html::rawElement( 'strong',
				[
					'class' => 'collection-creatorbox-iconlink',
				],
				Html::element( 'img',
					[
						'src' => "$imagePath/silk-book_open.png",
						'alt' => '',
						'width' => '16',
						'height' => '16',
					]
				)
				. '&#160;' . wfMessage( 'coll-show_collection' )->escaped()
				. ' (' . wfMessage( 'coll-n_pages' )->numParams( $numArticles )->escaped() . ')'
			); // @todo FIXME: Hard coded parentheses.
		} else {
			$linkRenderer = MediaWikiServices::getInstance()->getLinkRenderer();

			return $linkRenderer->makeKnownLink(
				SpecialPage::getTitleFor( 'Book' ),
				new HtmlArmor( Html::element( 'img',
					[
						'src' => "$imagePath/silk-book_open.png",
						'alt' => '',
						'width' => '16',
						'height' => '16',
					]
				)
				. '&#160;' . wfMessage( 'coll-show_collection' )->escaped()
				. ' (' . wfMessage( 'coll-n_pages' )->numParams( $numArticles )->escaped() . ')' ), // @todo FIXME: Hard coded parentheses.
				[
					'rel' => 'nofollow',
					'title' => wfMessage( 'coll-show_collection_tooltip' )->text(),
					'class' => 'collection-creatorbox-iconlink',
				]
			);
		}
	}

	/**
	 * @param string $imagePath
	 * @param string $hint
	 * @return string
	 */
	public static function getBookCreatorBoxSuggestLink( $imagePath, $hint ) {
		if ( wfMessage( 'coll-suggest_enabled' )->escaped() != '1' ) {
			return '';
		}

		if ( $hint == 'suggest' ) {
			return Html::rawElement( 'strong',
				[
					'class' => 'collection-creatorbox-iconlink',
				],
				Html::element( 'img',
					[
						'src' => "$imagePath/silk-wand.png",
						'alt' => '',
						'width' => '16',
						'height' => '16',
						'style' => 'vertical-align: text-bottom',
					]
				)
				. '&#160;' . wfMessage( 'coll-make_suggestions' )->escaped()
			);
		} else {
			$linkRenderer = MediaWikiServices::getInstance()->getLinkRenderer();

			return $linkRenderer->makeKnownLink(
				SpecialPage::getTitleFor( 'Book' ),
				new HtmlArmor( Html::element( 'img',
					[
						'src' => "$imagePath/silk-wand.png",
						'alt' => '',
						'width' => '16',
						'height' => '16',
						'style' => 'vertical-align: text-bottom',
					]
				)
				. '&#160;' . wfMessage( 'coll-make_suggestions' )->escaped() ),
				[
					'rel' => 'nofollow',
					'title' => wfMessage( 'coll-make_suggestions_tooltip' )->text(),
					'class' => 'collection-creatorbox-iconlink',
				],
				[ 'bookcmd' => 'suggest' ]
			);
		}
	}

	/**
	 * OutputPageCheckLastModified hook
	 * @param array &$modifiedTimes
	 * @param OutputPage $out
	 */
	public function onOutputPageCheckLastModified( &$modifiedTimes, $out ) {
		$session = SessionManager::getGlobalSession();
		if ( isset( $session['wsCollection']['timestamp'] ) ) {
			$modifiedTimes['collection'] = $session['wsCollection']['timestamp'];
		}
	}
}
