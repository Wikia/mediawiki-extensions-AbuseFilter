<?php
/**
 * Created on Mar 28, 2009
 *
 * AbuseFilter extension
 *
 * Copyright © 2008 Alex Z. mrzmanwiki AT gmail DOT com
 * Based mostly on code by Bryan Tong Minh and Roan Kattouw
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

namespace MediaWiki\Extension\AbuseFilter\Api;

use AbuseFilter;
use ApiBase;
use ApiQuery;
use ApiQueryBase;
use InvalidArgumentException;
use MediaWiki\Extension\AbuseFilter\AbuseFilterServices;
use MediaWiki\Extension\AbuseFilter\CentralDBNotAvailableException;
use MediaWiki\Extension\AbuseFilter\GlobalNameUtils;
use MediaWiki\MediaWikiServices;
use MWTimestamp;
use SpecialAbuseLog;
use Title;
use User;
use Wikimedia\IPUtils;

/**
 * Query module to list abuse log entries.
 *
 * @ingroup API
 * @ingroup Extensions
 */
class QueryAbuseLog extends ApiQueryBase {
	/**
	 * @param ApiQuery $query
	 * @param string $moduleName
	 */
	public function __construct( ApiQuery $query, $moduleName ) {
		parent::__construct( $query, $moduleName, 'afl' );
	}

	/**
	 * @inheritDoc
	 */
	public function execute() {
		$afPermManager = AbuseFilterServices::getPermissionManager();
		$lookup = AbuseFilterServices::getFilterLookup();
		$aflFilterMigrationStage = $this->getConfig()->get( 'AbuseFilterAflFilterMigrationStage' );

		// Same check as in SpecialAbuseLog
		$this->checkUserRightsAny( 'abusefilter-log' );

		$user = $this->getUser();
		$params = $this->extractRequestParams();

		$prop = array_flip( $params['prop'] );
		$fld_ids = isset( $prop['ids'] );
		$fld_filter = isset( $prop['filter'] );
		$fld_user = isset( $prop['user'] );
		$fld_title = isset( $prop['title'] );
		$fld_action = isset( $prop['action'] );
		$fld_details = isset( $prop['details'] );
		$fld_result = isset( $prop['result'] );
		$fld_timestamp = isset( $prop['timestamp'] );
		$fld_hidden = isset( $prop['hidden'] );
		$fld_revid = isset( $prop['revid'] );
		$isCentral = $this->getConfig()->get( 'AbuseFilterIsCentral' );
		$fld_wiki = $isCentral && isset( $prop['wiki'] );

		if ( $fld_details ) {
			$this->checkUserRightsAny( 'abusefilter-log-detail' );
		}

		// Map of [ [ id, global ], ... ]
		$searchFilters = [];
		// Match permissions for viewing events on private filters to SpecialAbuseLog (bug 42814)
		// @todo Avoid code duplication with SpecialAbuseLog::showList, make it so that, if hidden
		// filters are specified, we only filter them out instead of failing.
		if ( $params['filter'] ) {
			if ( !is_array( $params['filter'] ) ) {
				$params['filter'] = [ $params['filter'] ];
			}

			$foundInvalid = false;
			foreach ( $params['filter'] as $filter ) {
				try {
					$searchFilters[] = GlobalNameUtils::splitGlobalName( $filter );
				} catch ( InvalidArgumentException $e ) {
					$foundInvalid = true;
					continue;
				}
			}
			// @phan-suppress-next-line PhanImpossibleCondition
			if ( $foundInvalid ) {
				// @todo Tell what the invalid IDs are
				$this->addWarning( 'abusefilter-log-invalid-filter' );
			}
			if ( !$afPermManager->canViewPrivateFiltersLogs( $user ) ) {
				foreach ( $searchFilters as [ $filterID, $global ] ) {
					try {
						$isHidden = $lookup->getFilter( $filterID, $global )->isHidden();
					} catch ( CentralDBNotAvailableException $_ ) {
						$isHidden = false;
					}
					if ( $isHidden ) {
						$this->dieWithError(
							[ 'apierror-permissiondenied', $this->msg( 'action-abusefilter-log-private' ) ]
						);
					}
				}
			}
		}

		$result = $this->getResult();

		$this->addTables( 'abuse_filter_log' );
		$this->addFields( 'afl_timestamp' );
		$this->addFields( 'afl_rev_id' );
		$this->addFields( 'afl_deleted' );
		$this->addFieldsIf( 'afl_filter',
			( $aflFilterMigrationStage & SCHEMA_COMPAT_READ_OLD ) !== 0 );
		$this->addFieldsIf( 'afl_filter_id',
			( $aflFilterMigrationStage & SCHEMA_COMPAT_READ_NEW ) !== 0 );
		$this->addFieldsIf( 'afl_global',
			( $aflFilterMigrationStage & SCHEMA_COMPAT_READ_NEW ) !== 0 );
		$this->addFieldsIf( 'afl_id', $fld_ids );
		$this->addFieldsIf( 'afl_user_text', $fld_user );
		$this->addFieldsIf( [ 'afl_namespace', 'afl_title' ], $fld_title );
		$this->addFieldsIf( 'afl_action', $fld_action );
		$this->addFieldsIf( 'afl_var_dump', $fld_details );
		$this->addFieldsIf( 'afl_actions', $fld_result );
		$this->addFieldsIf( 'afl_wiki', $fld_wiki );

		if ( $fld_filter ) {
			$this->addTables( 'abuse_filter' );
			$this->addFields( 'af_public_comments' );

			if ( $aflFilterMigrationStage & SCHEMA_COMPAT_READ_NEW ) {
				$join = [ 'af_id=afl_filter_id', 'afl_global' => 0 ];
			} else {
				// SCHEMA_COMPAT_READ_OLD
				$join = 'af_id=afl_filter';
			}

			$this->addJoinConds( [ 'abuse_filter' => [ 'LEFT JOIN', $join ] ] );
		}

		$this->addOption( 'LIMIT', $params['limit'] + 1 );

		$this->addWhereIf( [ 'afl_id' => $params['logid'] ], isset( $params['logid'] ) );

		$this->addWhereRange( 'afl_timestamp', $params['dir'], $params['start'], $params['end'] );

		if ( isset( $params['user'] ) ) {
			$u = User::newFromName( $params['user'] );
			if ( $u ) {
				// Username normalisation
				$params['user'] = $u->getName();
				$userId = $u->getId();
			} elseif ( IPUtils::isIPAddress( $params['user'] ) ) {
				// It's an IP, sanitize it
				$params['user'] = IPUtils::sanitizeIP( $params['user'] );
				$userId = 0;
			}

			if ( isset( $userId ) ) {
				// Only add the WHERE for user in case it's either a valid user
				// (but not necessary an existing one) or an IP.
				$this->addWhere(
					[
						'afl_user' => $userId,
						'afl_user_text' => $params['user']
					]
				);
			}
		}

		$this->addWhereIf( [ 'afl_deleted' => 0 ], !$afPermManager->canSeeHiddenLogEntries( $user ) );

		if ( $searchFilters ) {
			$conds = [];
			// @todo Avoid code duplication with SpecialAbuseLog::showList
			if ( $aflFilterMigrationStage & SCHEMA_COMPAT_READ_NEW ) {
				$filterConds = [ 'local' => [], 'global' => [] ];
				foreach ( $searchFilters as $filter ) {
					$isGlobal = $filter[1];
					$key = $isGlobal ? 'global' : 'local';
					$filterConds[$key][] = $filter[0];
				}
				$conds = [];
				// @phan-suppress-next-line PhanImpossibleCondition False positive
				if ( $filterConds['local'] ) {
					$conds[] = $this->getDB()->makeList(
						[ 'afl_global' => 0, 'afl_filter_id' => $filterConds['local'] ],
						LIST_AND
					);
				}
				// @phan-suppress-next-line PhanImpossibleCondition False positive
				if ( $filterConds['global'] ) {
					$conds[] = $this->getDB()->makeList(
						[ 'afl_global' => 1, 'afl_filter_id' => $filterConds['global'] ],
						LIST_AND
					);
				}
				$conds = $this->getDB()->makeList( $conds, LIST_OR );
			} else {
				// SCHEMA_COMPAT_READ_OLD
				$names = [];
				foreach ( $searchFilters as $filter ) {
					$names[] = GlobalNameUtils::buildGlobalName( ...$filter );
				}
				$conds = [ 'afl_filter' => $names ];
			}
			$this->addWhere( $conds );
		}

		if ( isset( $params['wiki'] ) ) {
			// 'wiki' won't be set if $wgAbuseFilterIsCentral = false
			$this->addWhereIf( [ 'afl_wiki' => $params['wiki'] ], $isCentral );
		}

		$title = $params['title'];
		if ( $title !== null ) {
			$titleObj = Title::newFromText( $title );
			if ( $titleObj === null ) {
				$this->dieWithError( [ 'apierror-invalidtitle', wfEscapeWikiText( $title ) ] );
			}
			$this->addWhereFld( 'afl_namespace', $titleObj->getNamespace() );
			$this->addWhereFld( 'afl_title', $titleObj->getDBkey() );
		}
		$res = $this->select( __METHOD__ );

		$count = 0;
		foreach ( $res as $row ) {
			if ( ++$count > $params['limit'] ) {
				// We've had enough
				$ts = new MWTimestamp( $row->afl_timestamp );
				$this->setContinueEnumParameter( 'start', $ts->getTimestamp( TS_ISO_8601 ) );
				break;
			}
			$hidden = SpecialAbuseLog::isHidden( $row );
			if ( $hidden === true && !$afPermManager->canSeeHiddenLogEntries( $user ) ) {
				continue;
			}
			if ( $hidden === 'implicit' ) {
				$revRec = MediaWikiServices::getInstance()
					->getRevisionLookup()
					->getRevisionById( (int)$row->afl_rev_id );
				if ( !AbuseFilter::userCanViewRev( $revRec, $user ) ) {
					continue;
				}
			}

			if ( $aflFilterMigrationStage & SCHEMA_COMPAT_READ_NEW ) {
				$filterID = $row->afl_filter_id;
				$global = $row->afl_global;
				$fullName = GlobalNameUtils::buildGlobalName( $filterID, $global );
			} else {
				// SCHEMA_COMPAT_READ_OLD
				list( $filterID, $global ) = GlobalNameUtils::splitGlobalName( $row->afl_filter );
				$fullName = $row->afl_filter;
			}
			$isHidden = $lookup->getFilter( $filterID, $global )->isHidden();
			$canSeeDetails = $afPermManager->canSeeLogDetailsForFilter( $user, $isHidden );

			$entry = [];
			if ( $fld_ids ) {
				$entry['id'] = intval( $row->afl_id );
				$entry['filter_id'] = $canSeeDetails ? $fullName : '';
			}
			if ( $fld_filter ) {
				if ( $global ) {
					$entry['filter'] = $lookup->getFilter( $filterID, true )->getName();
				} else {
					$entry['filter'] = $row->af_public_comments;
				}
			}
			if ( $fld_user ) {
				$entry['user'] = $row->afl_user_text;
			}
			if ( $fld_wiki ) {
				$entry['wiki'] = $row->afl_wiki;
			}
			if ( $fld_title ) {
				$title = Title::makeTitle( $row->afl_namespace, $row->afl_title );
				ApiQueryBase::addTitleInfo( $entry, $title );
			}
			if ( $fld_action ) {
				$entry['action'] = $row->afl_action;
			}
			if ( $fld_result ) {
				$entry['result'] = $row->afl_actions;
			}
			if ( $fld_revid && $row->afl_rev_id !== null ) {
				$entry['revid'] = $canSeeDetails ? $row->afl_rev_id : '';
			}
			if ( $fld_timestamp ) {
				$ts = new MWTimestamp( $row->afl_timestamp );
				$entry['timestamp'] = $ts->getTimestamp( TS_ISO_8601 );
			}
			if ( $fld_details ) {
				$entry['details'] = [];
				if ( $canSeeDetails ) {
					$vars = AbuseFilterServices::getVariablesBlobStore()->loadVarDump( $row->afl_var_dump );
					$entry['details'] = $vars->exportAllVars();
				}
			}

			if ( $fld_hidden && $hidden ) {
				$entry['hidden'] = $hidden;
			}

			if ( $entry ) {
				$fit = $result->addValue( [ 'query', $this->getModuleName() ], null, $entry );
				if ( !$fit ) {
					$ts = new MWTimestamp( $row->afl_timestamp );
					$this->setContinueEnumParameter( 'start', $ts->getTimestamp( TS_ISO_8601 ) );
					break;
				}
			}
		}
		$result->addIndexedTagName( [ 'query', $this->getModuleName() ], 'item' );
	}

	/**
	 * @return array
	 * @see ApiQueryBase::getAllowedParams()
	 */
	public function getAllowedParams() {
		$params = [
			'logid' => [
				ApiBase::PARAM_TYPE => 'integer'
			],
			'start' => [
				ApiBase::PARAM_TYPE => 'timestamp'
			],
			'end' => [
				ApiBase::PARAM_TYPE => 'timestamp'
			],
			'dir' => [
				ApiBase::PARAM_TYPE => [
					'newer',
					'older'
				],
				ApiBase::PARAM_DFLT => 'older',
				ApiBase::PARAM_HELP_MSG => 'api-help-param-direction',
			],
			'user' => null,
			'title' => null,
			'filter' => [
				ApiBase::PARAM_TYPE => 'string',
				ApiBase::PARAM_ISMULTI => true,
				ApiBase::PARAM_HELP_MSG => [
					'apihelp-query+abuselog-param-filter',
					GlobalNameUtils::GLOBAL_FILTER_PREFIX
				]
			],
			'limit' => [
				ApiBase::PARAM_DFLT => 10,
				ApiBase::PARAM_TYPE => 'limit',
				ApiBase::PARAM_MIN => 1,
				ApiBase::PARAM_MAX => ApiBase::LIMIT_BIG1,
				ApiBase::PARAM_MAX2 => ApiBase::LIMIT_BIG2
			],
			'prop' => [
				ApiBase::PARAM_DFLT => 'ids|user|title|action|result|timestamp|hidden|revid',
				ApiBase::PARAM_TYPE => [
					'ids',
					'filter',
					'user',
					'title',
					'action',
					'details',
					'result',
					'timestamp',
					'hidden',
					'revid',
				],
				ApiBase::PARAM_ISMULTI => true
			]
		];
		if ( $this->getConfig()->get( 'AbuseFilterIsCentral' ) ) {
			$params['wiki'] = [
				ApiBase::PARAM_TYPE => 'string',
			];
			$params['prop'][ApiBase::PARAM_DFLT] .= '|wiki';
			$params['prop'][ApiBase::PARAM_TYPE][] = 'wiki';
			$params['filter'][ApiBase::PARAM_HELP_MSG] = 'apihelp-query+abuselog-param-filter-central';
		}
		return $params;
	}

	/**
	 * @return array
	 * @see ApiBase::getExamplesMessages()
	 */
	protected function getExamplesMessages() {
		return [
			'action=query&list=abuselog'
				=> 'apihelp-query+abuselog-example-1',
			'action=query&list=abuselog&afltitle=API'
				=> 'apihelp-query+abuselog-example-2',
		];
	}
}
