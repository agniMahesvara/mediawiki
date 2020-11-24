<?php
/**
 * Copyright © 2007 Roan Kattouw "<Firstname>.<Lastname>@gmail.com"
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

use MediaWiki\MediaWikiServices;

/**
 * API module that facilitates deleting pages. The API equivalent of action=delete.
 * Requires API write mode to be enabled.
 *
 * @ingroup API
 */
class ApiDelete extends ApiBase {

	use ApiWatchlistTrait;

	public function __construct( ApiMain $mainModule, $moduleName, $modulePrefix = '' ) {
		parent::__construct( $mainModule, $moduleName, $modulePrefix );

		$this->watchlistExpiryEnabled = $this->getConfig()->get( 'WatchlistExpiry' );
		$this->watchlistMaxDuration = $this->getConfig()->get( 'WatchlistExpiryMaxDuration' );
	}

	/**
	 * Extracts the title and reason from the request parameters and invokes
	 * the local delete() function with these as arguments. It does not make use of
	 * the delete function specified by Article.php. If the deletion succeeds, the
	 * details of the article deleted and the reason for deletion are added to the
	 * result object.
	 */
	public function execute() {
		$this->useTransactionalTimeLimit();

		$params = $this->extractRequestParams();

		$pageObj = $this->getTitleOrPageId( $params, 'fromdbmaster' );
		$titleObj = $pageObj->getTitle();
		if ( !$pageObj->exists() &&
			// @phan-suppress-next-line PhanUndeclaredMethod
			!( $titleObj->getNamespace() === NS_FILE && self::canDeleteFile( $pageObj->getFile() ) )
		) {
			$this->dieWithError( 'apierror-missingtitle' );
		}

		$reason = $params['reason'];
		$user = $this->getUser();

		// Check that the user is allowed to carry out the deletion
		$this->checkTitleUserPermissions( $titleObj, 'delete' );

		// If change tagging was requested, check that the user is allowed to tag,
		// and the tags are valid
		if ( $params['tags'] ) {
			$tagStatus = ChangeTags::canAddTagsAccompanyingChange( $params['tags'], $user );
			if ( !$tagStatus->isOK() ) {
				$this->dieStatus( $tagStatus );
			}
		}

		if ( $titleObj->getNamespace() === NS_FILE ) {
			$status = self::deleteFile(
				$pageObj,
				$user,
				$params['oldimage'],
				$reason,
				false,
				$params['tags']
			);
		} else {
			$status = self::delete( $pageObj, $user, $reason, $params['tags'] );
		}

		if ( !$status->isOK() ) {
			$this->dieStatus( $status );
		}
		$this->addMessagesFromStatus( $status, [ 'warning' ], [ 'delete-scheduled' ] );

		// Deprecated parameters
		if ( $params['watch'] ) {
			$watch = 'watch';
		} elseif ( $params['unwatch'] ) {
			$watch = 'unwatch';
		} else {
			$watch = $params['watchlist'];
		}

		$watchlistExpiry = $this->getExpiryFromParams( $params );
		$this->setWatch( $watch, $titleObj, $user, 'watchdeletion', $watchlistExpiry );

		$r = [
			'title' => $titleObj->getPrefixedText(),
			'reason' => $reason,
		];

		if ( $status->hasMessage( 'delete-scheduled' ) ) {
			$r['scheduled'] = true;
		}
		if ( $status->value !== null ) {
			// Scheduled deletions don't currently have a log entry available at this point
			$r['logid'] = $status->value;
		}
		$this->getResult()->addValue( null, $this->getModuleName(), $r );
	}

	/**
	 * We have our own delete() function, since Article.php's implementation is split in two phases
	 *
	 * @param WikiPage $page WikiPage object to work on
	 * @param User $user User doing the action
	 * @param string|null &$reason Reason for the deletion. Autogenerated if null
	 * @param string[] $tags Tags to tag the deletion with
	 * @return Status
	 */
	private static function delete( WikiPage $page, User $user, &$reason = null, $tags = [] ) {
		$title = $page->getTitle();

		// Auto-generate a summary, if necessary
		if ( $reason === null ) {
			// Need to pass a throwaway variable because generateReason expects
			// a reference
			$hasHistory = false;
			$reason = $page->getAutoDeleteReason( $hasHistory );
			if ( $reason === false ) {
				// Should be reachable only if the page has no revisions
				return Status::newFatal( 'cannotdelete', $title->getPrefixedText() ); // @codeCoverageIgnore
			}
		}

		$error = '';

		// Luckily, WikiPage provides a reusable delete function that does the hard work for us
		return $page->doDeleteArticleReal(
			$reason,
			$user,
			false, // don't suppress
			null, // unused
			$error,
			null, // unused
			$tags
		);
	}

	/**
	 * @param File $file
	 * @return bool
	 */
	protected static function canDeleteFile( File $file ) {
		return $file->exists() && $file->isLocal() && !$file->getRedirected();
	}

	/**
	 * @param WikiPage $page Object to work on
	 * @param User $user User doing the action
	 * @param string $oldimage Archive name
	 * @param string|null &$reason Reason for the deletion. Autogenerated if null.
	 * @param bool $suppress Whether to mark all deleted versions as restricted
	 * @param string[] $tags Tags to tag the deletion with
	 * @return Status
	 */
	private static function deleteFile( WikiPage $page, User $user, $oldimage,
		&$reason = null, $suppress = false, $tags = []
	) {
		$title = $page->getTitle();

		// @phan-suppress-next-line PhanUndeclaredMethod There's no right typehint for it
		$file = $page->getFile();
		if ( !self::canDeleteFile( $file ) ) {
			return self::delete( $page, $user, $reason, $tags );
		}

		if ( $oldimage ) {
			if ( !FileDeleteForm::isValidOldSpec( $oldimage ) ) {
				return Status::newFatal( 'invalidoldimage' );
			}
			$oldfile = MediaWikiServices::getInstance()->getRepoGroup()
				->getLocalRepo()->newFromArchiveName( $title, $oldimage );
			if ( !$oldfile->exists() || !$oldfile->isLocal() || $oldfile->getRedirected() ) {
				return Status::newFatal( 'nodeleteablefile' );
			}
		}

		if ( $reason === null ) { // Log and RC don't like null reasons
			$reason = '';
		}

		return FileDeleteForm::doDelete( $title, $file, $oldimage, $reason, $suppress, $user, $tags );
	}

	public function mustBePosted() {
		return true;
	}

	public function isWriteMode() {
		return true;
	}

	public function getAllowedParams() {
		$params = [
			'title' => null,
			'pageid' => [
				ApiBase::PARAM_TYPE => 'integer'
			],
			'reason' => null,
			'tags' => [
				ApiBase::PARAM_TYPE => 'tags',
				ApiBase::PARAM_ISMULTI => true,
			],
			'watch' => [
				ApiBase::PARAM_DFLT => false,
				ApiBase::PARAM_DEPRECATED => true,
			],
		];

		// Params appear in the docs in the order they are defined,
		// which is why this is here and not at the bottom.
		$params += $this->getWatchlistParams();

		return $params + [
			'unwatch' => [
				ApiBase::PARAM_DFLT => false,
				ApiBase::PARAM_DEPRECATED => true,
			],
			'oldimage' => null,
		];
	}

	public function needsToken() {
		return 'csrf';
	}

	protected function getExamplesMessages() {
		return [
			'action=delete&title=Main%20Page&token=123ABC'
				=> 'apihelp-delete-example-simple',
			'action=delete&title=Main%20Page&token=123ABC&reason=Preparing%20for%20move'
				=> 'apihelp-delete-example-reason',
		];
	}

	public function getHelpUrls() {
		return 'https://www.mediawiki.org/wiki/Special:MyLanguage/API:Delete';
	}
}
