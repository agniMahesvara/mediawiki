<?php

/**
 * API for MediaWiki 1.12+
 *
 * Copyright © 2008 Vasiliev Victor vasilvv@gmail.com,
 * based on ApiQueryAllPages.php
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

use MediaWiki\MainConfigNames;
use MediaWiki\ParamValidator\TypeDef\UserDef;
use MediaWiki\Permissions\GroupPermissionsLookup;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\IntegerDef;
use Wikimedia\Rdbms\IDatabase;

/**
 * Query module to enumerate all images.
 *
 * @ingroup API
 */
class ApiQueryAllImages extends ApiQueryGeneratorBase {

	/**
	 * @var LocalRepo
	 */
	protected $mRepo;

	/** @var GroupPermissionsLookup */
	private $groupPermissionsLookup;

	/**
	 * @param ApiQuery $query
	 * @param string $moduleName
	 * @param RepoGroup $repoGroup
	 * @param GroupPermissionsLookup $groupPermissionsLookup
	 */
	public function __construct(
		ApiQuery $query,
		$moduleName,
		RepoGroup $repoGroup,
		GroupPermissionsLookup $groupPermissionsLookup
	) {
		parent::__construct( $query, $moduleName, 'ai' );
		$this->mRepo = $repoGroup->getLocalRepo();
		$this->groupPermissionsLookup = $groupPermissionsLookup;
	}

	/**
	 * Override parent method to make sure the repo's DB is used
	 * which may not necessarily be the same as the local DB.
	 *
	 * TODO: allow querying non-local repos.
	 * @return IDatabase
	 */
	protected function getDB() {
		return $this->mRepo->getReplicaDB();
	}

	public function execute() {
		$this->run();
	}

	public function getCacheMode( $params ) {
		return 'public';
	}

	/**
	 * @param ApiPageSet $resultPageSet
	 * @return void
	 */
	public function executeGenerator( $resultPageSet ) {
		if ( $resultPageSet->isResolvingRedirects() ) {
			$this->dieWithError( 'apierror-allimages-redirect', 'invalidparammix' );
		}

		$this->run( $resultPageSet );
	}

	/**
	 * @param ApiPageSet|null $resultPageSet
	 * @return void
	 */
	private function run( $resultPageSet = null ) {
		$repo = $this->mRepo;
		if ( !$repo instanceof LocalRepo ) {
			$this->dieWithError( 'apierror-unsupportedrepo' );
		}

		$prefix = $this->getModulePrefix();

		$db = $this->getDB();

		$params = $this->extractRequestParams();

		// Table and return fields
		$prop = array_fill_keys( $params['prop'], true );

		$fileQuery = LocalFile::getQueryInfo();
		$this->addTables( $fileQuery['tables'] );
		$this->addFields( $fileQuery['fields'] );
		$this->addJoinConds( $fileQuery['joins'] );

		$ascendingOrder = true;
		if ( $params['dir'] == 'descending' || $params['dir'] == 'older' ) {
			$ascendingOrder = false;
		}

		if ( $params['sort'] == 'name' ) {
			// Check mutually exclusive params
			$disallowed = [ 'start', 'end', 'user' ];
			foreach ( $disallowed as $pname ) {
				if ( isset( $params[$pname] ) ) {
					$this->dieWithError(
						[
							'apierror-invalidparammix-mustusewith',
							"{$prefix}{$pname}",
							"{$prefix}sort=timestamp"
						],
						'invalidparammix'
					);
				}
			}
			if ( $params['filterbots'] != 'all' ) {
				$this->dieWithError(
					[
						'apierror-invalidparammix-mustusewith',
						"{$prefix}filterbots",
						"{$prefix}sort=timestamp"
					],
					'invalidparammix'
				);
			}

			// Pagination
			if ( $params['continue'] !== null ) {
				$cont = $this->parseContinueParamOrDie( $params['continue'], [ 'string' ] );
				$op = $ascendingOrder ? '>=' : '<=';
				$this->addWhere( $db->buildComparison( $op, [ 'img_name' => $cont[0] ] ) );
			}

			// Image filters
			$from = $params['from'] === null ? null : $this->titlePartToKey( $params['from'], NS_FILE );
			$to = $params['to'] === null ? null : $this->titlePartToKey( $params['to'], NS_FILE );
			$this->addWhereRange( 'img_name', $ascendingOrder ? 'newer' : 'older', $from, $to );

			if ( isset( $params['prefix'] ) ) {
				$this->addWhere( 'img_name' . $db->buildLike(
					$this->titlePartToKey( $params['prefix'], NS_FILE ),
					$db->anyString() ) );
			}
		} else {
			// Check mutually exclusive params
			$disallowed = [ 'from', 'to', 'prefix' ];
			foreach ( $disallowed as $pname ) {
				if ( isset( $params[$pname] ) ) {
					$this->dieWithError(
						[
							'apierror-invalidparammix-mustusewith',
							"{$prefix}{$pname}",
							"{$prefix}sort=name"
						],
						'invalidparammix'
					);
				}
			}
			if ( $params['user'] !== null && $params['filterbots'] != 'all' ) {
				// Since filterbots checks if each user has the bot right, it
				// doesn't make sense to use it with user
				$this->dieWithError(
					[ 'apierror-invalidparammix-cannotusewith', "{$prefix}user", "{$prefix}filterbots" ]
				);
			}

			// Pagination
			$this->addTimestampWhereRange(
				'img_timestamp',
				$ascendingOrder ? 'newer' : 'older',
				$params['start'],
				$params['end']
			);
			// Include in ORDER BY for uniqueness
			$this->addWhereRange( 'img_name', $ascendingOrder ? 'newer' : 'older', null, null );

			if ( $params['continue'] !== null ) {
				$cont = $this->parseContinueParamOrDie( $params['continue'], [ 'int', 'string' ] );
				$op = ( $ascendingOrder ? '>=' : '<=' );
				$this->addWhere( $db->buildComparison( $op, [
					'img_timestamp' => $db->timestamp( $cont[0] ),
					'img_name' => $cont[1],
				] ) );
			}

			// Image filters
			if ( $params['user'] !== null ) {
				$this->addWhereFld( $fileQuery['fields']['img_user_text'], $params['user'] );
			}
			if ( $params['filterbots'] != 'all' ) {
				$this->addTables( 'user_groups' );
				$this->addJoinConds( [ 'user_groups' => [
					'LEFT JOIN',
					[
						'ug_group' => $this->groupPermissionsLookup->getGroupsWithPermission( 'bot' ),
						'ug_user = actor_user',
						'ug_expiry IS NULL OR ug_expiry >= ' . $db->addQuotes( $db->timestamp() )
					]
				] ] );
				$groupCond = $params['filterbots'] == 'nobots' ? 'NULL' : 'NOT NULL';
				$this->addWhere( "ug_group IS $groupCond" );
			}
		}

		// Filters not depending on sort
		if ( isset( $params['minsize'] ) ) {
			$this->addWhere( 'img_size>=' . (int)$params['minsize'] );
		}

		if ( isset( $params['maxsize'] ) ) {
			$this->addWhere( 'img_size<=' . (int)$params['maxsize'] );
		}

		$sha1 = false;
		if ( isset( $params['sha1'] ) ) {
			$sha1 = strtolower( $params['sha1'] );
			if ( !$this->validateSha1Hash( $sha1 ) ) {
				$this->dieWithError( 'apierror-invalidsha1hash' );
			}
			$sha1 = Wikimedia\base_convert( $sha1, 16, 36, 31 );
		} elseif ( isset( $params['sha1base36'] ) ) {
			$sha1 = strtolower( $params['sha1base36'] );
			if ( !$this->validateSha1Base36Hash( $sha1 ) ) {
				$this->dieWithError( 'apierror-invalidsha1base36hash' );
			}
		}
		if ( $sha1 ) {
			$this->addWhereFld( 'img_sha1', $sha1 );
		}

		if ( $params['mime'] !== null ) {
			if ( $this->getConfig()->get( MainConfigNames::MiserMode ) ) {
				$this->dieWithError( 'apierror-mimesearchdisabled' );
			}

			$mimeConds = [];
			foreach ( $params['mime'] as $mime ) {
				list( $major, $minor ) = File::splitMime( $mime );
				$mimeConds[] = $db->makeList(
					[
						'img_major_mime' => $major,
						'img_minor_mime' => $minor,
					],
					LIST_AND
				);
			}
			// safeguard against internal_api_error_DBQueryError
			if ( count( $mimeConds ) > 0 ) {
				$this->addWhere( $db->makeList( $mimeConds, LIST_OR ) );
			} else {
				// no MIME types, no files
				$this->getResult()->addValue( 'query', $this->getModuleName(), [] );
				return;
			}
		}

		$limit = $params['limit'];
		$this->addOption( 'LIMIT', $limit + 1 );
		$sortFlag = '';
		if ( !$ascendingOrder ) {
			$sortFlag = ' DESC';
		}
		if ( $params['sort'] == 'timestamp' ) {
			$this->addOption( 'ORDER BY', 'img_timestamp' . $sortFlag );
		} else {
			$this->addOption( 'ORDER BY', 'img_name' . $sortFlag );
		}

		$res = $this->select( __METHOD__ );

		$titles = [];
		$count = 0;
		$result = $this->getResult();
		foreach ( $res as $row ) {
			if ( ++$count > $limit ) {
				// We've reached the one extra which shows that there are
				// additional pages to be had. Stop here...
				if ( $params['sort'] == 'name' ) {
					$this->setContinueEnumParameter( 'continue', $row->img_name );
				} else {
					$this->setContinueEnumParameter( 'continue', "$row->img_timestamp|$row->img_name" );
				}
				break;
			}

			if ( $resultPageSet === null ) {
				$file = $repo->newFileFromRow( $row );
				$info = array_merge( [ 'name' => $row->img_name ],
					ApiQueryImageInfo::getInfo( $file, $prop, $result ) );
				self::addTitleInfo( $info, $file->getTitle() );

				$fit = $result->addValue( [ 'query', $this->getModuleName() ], null, $info );
				if ( !$fit ) {
					if ( $params['sort'] == 'name' ) {
						$this->setContinueEnumParameter( 'continue', $row->img_name );
					} else {
						$this->setContinueEnumParameter( 'continue', "$row->img_timestamp|$row->img_name" );
					}
					break;
				}
			} else {
				$titles[] = Title::makeTitle( NS_FILE, $row->img_name );
			}
		}

		if ( $resultPageSet === null ) {
			$result->addIndexedTagName( [ 'query', $this->getModuleName() ], 'img' );
		} else {
			$resultPageSet->populateFromTitles( $titles );
		}
	}

	public function getAllowedParams() {
		$ret = [
			'sort' => [
				ParamValidator::PARAM_DEFAULT => 'name',
				ParamValidator::PARAM_TYPE => [
					'name',
					'timestamp'
				]
			],
			'dir' => [
				ParamValidator::PARAM_DEFAULT => 'ascending',
				ParamValidator::PARAM_TYPE => [
					// sort=name
					'ascending',
					'descending',
					// sort=timestamp
					'newer',
					'older'
				]
			],
			'from' => null,
			'to' => null,
			'continue' => [
				ApiBase::PARAM_HELP_MSG => 'api-help-param-continue',
			],
			'start' => [
				ParamValidator::PARAM_TYPE => 'timestamp'
			],
			'end' => [
				ParamValidator::PARAM_TYPE => 'timestamp'
			],
			'prop' => [
				ParamValidator::PARAM_TYPE => ApiQueryImageInfo::getPropertyNames( $this->propertyFilter ),
				ParamValidator::PARAM_DEFAULT => 'timestamp|url',
				ParamValidator::PARAM_ISMULTI => true,
				ApiBase::PARAM_HELP_MSG => 'apihelp-query+imageinfo-param-prop',
				ApiBase::PARAM_HELP_MSG_PER_VALUE =>
					ApiQueryImageInfo::getPropertyMessages( $this->propertyFilter ),
			],
			'prefix' => null,
			'minsize' => [
				ParamValidator::PARAM_TYPE => 'integer',
			],
			'maxsize' => [
				ParamValidator::PARAM_TYPE => 'integer',
			],
			'sha1' => null,
			'sha1base36' => null,
			'user' => [
				ParamValidator::PARAM_TYPE => 'user',
				UserDef::PARAM_ALLOWED_USER_TYPES => [ 'name', 'ip', 'id', 'interwiki' ],
			],
			'filterbots' => [
				ParamValidator::PARAM_DEFAULT => 'all',
				ParamValidator::PARAM_TYPE => [
					'all',
					'bots',
					'nobots'
				]
			],
			'mime' => [
				ParamValidator::PARAM_ISMULTI => true,
			],
			'limit' => [
				ParamValidator::PARAM_DEFAULT => 10,
				ParamValidator::PARAM_TYPE => 'limit',
				IntegerDef::PARAM_MIN => 1,
				IntegerDef::PARAM_MAX => ApiBase::LIMIT_BIG1,
				IntegerDef::PARAM_MAX2 => ApiBase::LIMIT_BIG2
			],
		];

		if ( $this->getConfig()->get( MainConfigNames::MiserMode ) ) {
			$ret['mime'][ApiBase::PARAM_HELP_MSG] = 'api-help-param-disabled-in-miser-mode';
		}

		return $ret;
	}

	private $propertyFilter = [ 'archivename', 'thumbmime', 'uploadwarning' ];

	protected function getExamplesMessages() {
		return [
			'action=query&list=allimages&aifrom=B'
				=> 'apihelp-query+allimages-example-b',
			'action=query&list=allimages&aiprop=user|timestamp|url&' .
				'aisort=timestamp&aidir=older'
				=> 'apihelp-query+allimages-example-recent',
			'action=query&list=allimages&aimime=image/png|image/gif'
				=> 'apihelp-query+allimages-example-mimetypes',
			'action=query&generator=allimages&gailimit=4&' .
				'gaifrom=T&prop=imageinfo'
				=> 'apihelp-query+allimages-example-generator',
		];
	}

	public function getHelpUrls() {
		return 'https://www.mediawiki.org/wiki/Special:MyLanguage/API:Allimages';
	}
}
