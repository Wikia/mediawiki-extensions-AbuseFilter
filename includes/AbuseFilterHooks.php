<?php

use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\MutableRevisionRecord;
use MediaWiki\Revision\SlotRecord;
use Wikimedia\Rdbms\Database;
use Wikimedia\Rdbms\IMaintainableDatabase;

class AbuseFilterHooks {
	const FETCH_ALL_TAGS_KEY = 'abusefilter-fetch-all-tags';

	/** @var WikiPage|null Make sure edit filter & edit save hooks match */
	private static $lastEditPage = null;

	/**
	 * Called right after configuration has been loaded.
	 */
	public static function onRegistration() {
		global $wgAuthManagerAutoConfig, $wgActionFilteredLogs, $wgAbuseFilterProfile,
			$wgAbuseFilterProfiling, $wgAbuseFilterPrivateLog, $wgAbuseFilterForceSummary,
			$wgGroupPermissions;

		// @todo Remove this in a future release (added in 1.33)
		if ( isset( $wgAbuseFilterProfile ) || isset( $wgAbuseFilterProfiling ) ) {
			wfWarn( '$wgAbuseFilterProfile and $wgAbuseFilterProfiling have been removed and ' .
				'profiling is now enabled by default.' );
		}

		if ( isset( $wgAbuseFilterPrivateLog ) ) {
			global $wgAbuseFilterLogPrivateDetailsAccess;
			$wgAbuseFilterLogPrivateDetailsAccess = $wgAbuseFilterPrivateLog;
			wfWarn( '$wgAbuseFilterPrivateLog has been renamed to $wgAbuseFilterLogPrivateDetailsAccess. ' .
				'Please make the change in your settings; the format is identical.'
			);
		}
		if ( isset( $wgAbuseFilterForceSummary ) ) {
			global $wgAbuseFilterPrivateDetailsForceReason;
			$wgAbuseFilterPrivateDetailsForceReason = $wgAbuseFilterForceSummary;
			wfWarn( '$wgAbuseFilterForceSummary has been renamed to ' .
				'$wgAbuseFilterPrivateDetailsForceReason. Please make the change in your settings; ' .
				'the format is identical.'
			);
		}

		$found = false;
		foreach ( $wgGroupPermissions as &$perms ) {
			if ( array_key_exists( 'abusefilter-private', $perms ) ) {
				$perms['abusefilter-privatedetails'] = $perms[ 'abusefilter-private' ];
				unset( $perms[ 'abusefilter-private' ] );
				$found = true;
			}
			if ( array_key_exists( 'abusefilter-private-log', $perms ) ) {
				$perms['abusefilter-privatedetails-log'] = $perms[ 'abusefilter-private-log' ];
				unset( $perms[ 'abusefilter-private-log' ] );
				$found = true;
			}
		}
		unset( $perms );

		if ( $found ) {
			wfWarn( 'The group permissions "abusefilter-private-log" and "abusefilter-private" have ' .
				'been renamed, respectively, to "abusefilter-privatedetails-log" and ' .
				'"abusefilter-privatedetails-log". Please update the names in your settings.'
			);
		}

		$wgAuthManagerAutoConfig['preauth'][AbuseFilterPreAuthenticationProvider::class] = [
			'class' => AbuseFilterPreAuthenticationProvider::class,
			// Run after normal preauth providers to keep the log cleaner
			'sort' => 5,
		];

		$wgActionFilteredLogs['suppress'] = array_merge(
			$wgActionFilteredLogs['suppress'],
			// Message: log-action-filter-suppress-abuselog
			[ 'abuselog' => [ 'hide-afl', 'unhide-afl' ] ]
		);
		$wgActionFilteredLogs['rights'] = array_merge(
			$wgActionFilteredLogs['rights'],
			// Messages: log-action-filter-rights-blockautopromote,
			// log-action-filter-rights-restoreautopromote
			[
				'blockautopromote' => [ 'blockautopromote' ],
				'restoreautopromote' => [ 'restoreautopromote' ]
			]
		);
	}

	/**
	 * Entry point for the EditFilterMergedContent hook.
	 *
	 * @param IContextSource $context the context of the edit
	 * @param Content $content the new Content generated by the edit
	 * @param Status $status Error message to return
	 * @param string $summary Edit summary for page
	 * @param User $user the user performing the edit
	 * @param bool $minoredit whether this is a minor edit according to the user.
	 * @param string $slot slot role for the content
	 */
	public static function onEditFilterMergedContent( IContextSource $context, Content $content,
		Status $status, $summary, User $user, $minoredit, $slot = SlotRecord::MAIN
	) {
		// @todo is there any real point in passing this in?
		$text = AbuseFilter::contentToString( $content );

		$filterStatus = self::filterEdit( $context, $content, $text, $status, $summary, $slot );

		if ( !$filterStatus->isOK() ) {
			// Produce a useful error message for API edits
			$status->apiHookResult = self::getApiResult( $filterStatus );
		}
	}

	/**
	 * Get variables for filtering an edit.
	 * @todo Consider splitting into a separate class
	 * @see self::filterEdit for more parameter docs
	 * @internal Until we'll find the right place for it
	 *
	 * @param Title $title
	 * @param User $user
	 * @param Content $content
	 * @param string $text
	 * @param string $summary
	 * @param string $slot
	 * @param WikiPage|null $page
	 * @return AbuseFilterVariableHolder|null
	 */
	public static function getEditVars(
		Title $title,
		User $user,
		Content $content,
		$text,
		$summary,
		$slot,
		WikiPage $page = null
	) {
		$oldContent = null;

		if ( $page !== null ) {
			$oldRevision = $page->getRevision();
			if ( !$oldRevision ) {
				return null;
			}

			$oldContent = $oldRevision->getContent( Revision::RAW );
			$oldAfText = AbuseFilter::revisionToString( $oldRevision, $user );

			// XXX: Recreate what the new revision will probably be so we can get the full AF
			// text for all slots
			$oldRevRecord = $oldRevision->getRevisionRecord();
			$newRevision = MutableRevisionRecord::newFromParentRevision( $oldRevRecord );
			$newRevision->setContent( $slot, $content );
			$text = AbuseFilter::revisionToString( $newRevision, $user );

			// Cache article object so we can share a parse operation
			$articleCacheKey = $title->getNamespace() . ':' . $title->getText();
			AFComputedVariable::$articleCache[$articleCacheKey] = $page;

			// Don't trigger for null edits.
			if ( $content && $oldContent ) {
				// Compare Content objects if available
				if ( $content->equals( $oldContent ) ) {
					return null;
				}
			} elseif ( strcmp( $oldAfText, $text ) === 0 ) {
				// Otherwise, compare strings
				return null;
			}
		} else {
			$oldAfText = '';
		}

		return self::newVariableHolderForEdit(
			$user, $title, $page, $summary, $content, $text, $oldAfText, $oldContent
		);
	}

	/**
	 * Implementation for EditFilterMergedContent hook.
	 *
	 * @param IContextSource $context the context of the edit
	 * @param Content $content the new Content generated by the edit
	 * @param string $text new page content (subject of filtering)
	 * @param Status $status Error message to return
	 * @param string $summary Edit summary for page
	 * @param string $slot slot role for the content
	 * @return Status
	 */
	public static function filterEdit( IContextSource $context, Content $content, $text,
		Status $status, $summary, $slot = SlotRecord::MAIN
	) {
		self::$lastEditPage = null;

		$title = $context->getTitle();
		if ( $title === null ) {
			// T144265: This *should* never happen.
			$logger = LoggerFactory::getInstance( 'AbuseFilter' );
			$logger->warning( __METHOD__ . ' received a null title.' );
			return Status::newGood();
		}

		$user = $context->getUser();
		if ( $title->canExist() && $title->exists() ) {
			// Make sure we load the latest text saved in database (bug 31656)
			$page = $context->getWikiPage();
		} else {
			$page = null;
		}

		$vars = self::getEditVars( $title, $user, $content, $text, $summary, $slot, $page );
		if ( $vars === null ) {
			// We don't have to filter the edit
			return Status::newGood();
		}
		$runner = new AbuseFilterRunner( $user, $title, $vars, 'default' );
		$filterResult = $runner->run();
		if ( !$filterResult->isOK() ) {
			$status->merge( $filterResult );

			return $filterResult;
		}

		self::$lastEditPage = $page;

		return Status::newGood();
	}

	/**
	 * @param User $user
	 * @param Title $title
	 * @param WikiPage|null $page
	 * @param string $summary
	 * @param Content $newcontent
	 * @param string $text
	 * @param string $oldtext
	 * @param Content|null $oldcontent
	 * @return AbuseFilterVariableHolder
	 * @throws MWException
	 */
	private static function newVariableHolderForEdit(
		User $user, Title $title, $page, $summary, Content $newcontent,
		$text, $oldtext, Content $oldcontent = null
	) {
		$vars = new AbuseFilterVariableHolder();
		$vars->addHolders(
			AbuseFilter::generateUserVars( $user ),
			AbuseFilter::generateTitleVars( $title, 'page' )
		);
		$vars->setVar( 'action', 'edit' );
		$vars->setVar( 'summary', $summary );
		if ( $oldcontent instanceof Content ) {
			$oldmodel = $oldcontent->getModel();
		} else {
			$oldmodel = '';
			$oldtext = '';
		}
		$vars->setVar( 'old_content_model', $oldmodel );
		$vars->setVar( 'new_content_model', $newcontent->getModel() );
		$vars->setVar( 'old_wikitext', $oldtext );
		$vars->setVar( 'new_wikitext', $text );
		$vars->addHolders( AbuseFilter::getEditVars( $title, $page ) );

		return $vars;
	}

	/**
	 * @param Status $status Error message details
	 * @return array API result
	 */
	private static function getApiResult( Status $status ) {
		global $wgFullyInitialised;

		$params = $status->getErrorsArray()[0];
		$key = array_shift( $params );

		$warning = wfMessage( $key )->params( $params );
		if ( !$wgFullyInitialised ) {
			// This could happen for account autocreation checks
			$warning = $warning->inContentLanguage();
		}

		list( $filterDescription, $filter ) = $params;

		// The value is a nested structure keyed by filter id, which doesn't make sense when we only
		// return the result from one filter. Flatten it to a plain array of actions.
		$actionsTaken = array_values( array_unique(
			array_merge( ...array_values( $status->getValue() ) )
		) );
		$code = ( $actionsTaken === [ 'warn' ] ) ? 'abusefilter-warning' : 'abusefilter-disallowed';

		ApiResult::setIndexedTagName( $params, 'param' );
		return [
			'code' => $code,
			'message' => [
				'key' => $key,
				'params' => $params,
			],
			'abusefilter' => [
				'id' => $filter,
				'description' => $filterDescription,
				'actions' => $actionsTaken,
			],
			// For backwards-compatibility
			'info' => 'Hit AbuseFilter: ' . $filterDescription,
			'warning' => $warning->parse(),
		];
	}

	/**
	 * @param WikiPage $wikiPage
	 * @param User $user
	 * @param string $content Content
	 * @param string $summary
	 * @param bool $minoredit
	 * @param bool $watchthis
	 * @param string $sectionanchor
	 * @param int $flags
	 * @param Revision $revision
	 * @param Status $status
	 * @param int $baseRevId
	 */
	public static function onPageContentSaveComplete(
		WikiPage $wikiPage, User $user, $content, $summary, $minoredit, $watchthis, $sectionanchor,
		$flags, Revision $revision, Status $status, $baseRevId
	) {
		$curTitle = $wikiPage->getTitle()->getPrefixedText();
		if ( !isset( AbuseFilter::$logIds[ $curTitle ] ) || !$revision ||
			$wikiPage !== self::$lastEditPage
		) {
			// This isn't the edit AbuseFilter::$logIds was set for
			AbuseFilter::$logIds = [];
			return;
		}

		self::$lastEditPage = null;

		$logs = AbuseFilter::$logIds[ $curTitle ];
		if ( $logs[ 'local' ] ) {
			// Now actually do our storage
			$dbw = wfGetDB( DB_MASTER );

			$dbw->update( 'abuse_filter_log',
				[ 'afl_rev_id' => $revision->getId() ],
				[ 'afl_id' => $logs['local'] ],
				__METHOD__
			);
		}

		if ( $logs[ 'global' ] ) {
			$fdb = AbuseFilter::getCentralDB( DB_MASTER );
			$fdb->update( 'abuse_filter_log',
				[ 'afl_rev_id' => $revision->getId() ],
				[ 'afl_id' => $logs['global'], 'afl_wiki' => wfWikiID() ],
				__METHOD__
			);
		}
	}

	/**
	 * @param User $user
	 * @param array &$promote
	 */
	public static function onGetAutoPromoteGroups( User $user, &$promote ) {
		if ( $promote ) {
			$cache = ObjectCache::getInstance( 'hash' );
			$blocked = (bool)$cache->getWithSetCallback(
				$cache->makeKey( 'abusefilter', 'block-autopromote', $user->getId() ),
				$cache::TTL_PROC_LONG,
				function () use ( $user ) {
					return AbuseFilter::getAutoPromoteBlockStatus( $user );
				}
			);

			if ( $blocked ) {
				$promote = [];
			}
		}
	}

	/**
	 * Get variables used to filter a move.
	 * @todo Consider splitting into a separate class
	 * @internal Until we'll find the right place for it
	 *
	 * @param User $user
	 * @param Title $oldTitle
	 * @param Title $newTitle
	 * @param string $reason
	 * @return AbuseFilterVariableHolder
	 */
	public static function getMoveVars(
		User $user,
		Title $oldTitle,
		Title $newTitle,
		$reason
	) : AbuseFilterVariableHolder {
		$vars = new AbuseFilterVariableHolder;
		$vars->addHolders(
			AbuseFilter::generateUserVars( $user ),
			AbuseFilter::generateTitleVars( $oldTitle, 'MOVED_FROM' ),
			AbuseFilter::generateTitleVars( $newTitle, 'MOVED_TO' )
		);
		$vars->setVar( 'summary', $reason );
		$vars->setVar( 'action', 'move' );
		return $vars;
	}

	/**
	 * @param Title $oldTitle
	 * @param Title $newTitle
	 * @param User $user
	 * @param string $reason
	 * @param Status &$status
	 */
	public static function onTitleMove(
		Title $oldTitle,
		Title $newTitle,
		User $user,
		$reason,
		Status &$status
	) {
		$vars = self::getMoveVars( $user, $oldTitle, $newTitle, $reason );
		$runner = new AbuseFilterRunner( $user, $oldTitle, $vars, 'default' );
		$result = $runner->run();
		$status->merge( $result );
	}

	/**
	 * Get variables for filtering a deletion.
	 * @todo Consider splitting into a separate class
	 * @internal Until we'll find the right place for it
	 *
	 * @param User $user
	 * @param WikiPage $article
	 * @param string $reason
	 * @return AbuseFilterVariableHolder
	 */
	public static function getDeleteVars(
		User $user,
		WikiPage $article,
		$reason
	) : AbuseFilterVariableHolder {
		$vars = new AbuseFilterVariableHolder;

		$vars->addHolders(
			AbuseFilter::generateUserVars( $user ),
			AbuseFilter::generateTitleVars( $article->getTitle(), 'page' )
		);

		$vars->setVar( 'summary', $reason );
		$vars->setVar( 'action', 'delete' );
		return $vars;
	}

	/**
	 * @param WikiPage $article
	 * @param User $user
	 * @param string $reason
	 * @param string &$error
	 * @param Status $status
	 * @return bool
	 */
	public static function onArticleDelete( WikiPage $article, User $user, $reason, &$error,
		Status $status ) {
		$vars = self::getDeleteVars( $user, $article, $reason );
		$runner = new AbuseFilterRunner( $user, $article->getTitle(), $vars, 'default' );
		$filterResult = $runner->run();

		$status->merge( $filterResult );
		$error = $filterResult->isOK() ? '' : $filterResult->getHTML();

		return $filterResult->isOK();
	}

	/**
	 * @param RecentChange $recentChange
	 */
	public static function onRecentChangeSave( RecentChange $recentChange ) {
		$title = Title::makeTitle(
			$recentChange->getAttribute( 'rc_namespace' ),
			$recentChange->getAttribute( 'rc_title' )
		);

		$logType = $recentChange->getAttribute( 'rc_log_type' ) ?: 'edit';
		if ( $logType === 'newusers' ) {
			$action = $recentChange->getAttribute( 'rc_log_action' ) === 'autocreate' ?
				'autocreateaccount' :
				'createaccount';
		} else {
			$action = $logType;
		}
		$actionID = AbuseFilter::getTaggingActionId(
			$action,
			$title,
			$recentChange->getAttribute( 'rc_user_text' )
		);

		if ( isset( AbuseFilter::$tagsToSet[$actionID] ) ) {
			$recentChange->addTags( AbuseFilter::$tagsToSet[$actionID] );
			unset( AbuseFilter::$tagsToSet[$actionID] );
		}
	}

	/**
	 * Purge all cache related to tags, both within AbuseFilter and in core
	 */
	public static function purgeTagCache() {
		ChangeTags::purgeTagCacheAll();

		$services = MediaWikiServices::getInstance();
		$cache = $services->getMainWANObjectCache();

		$cache->delete(
			$cache->makeKey( self::FETCH_ALL_TAGS_KEY, 0 )
		);

		$cache->delete(
			$cache->makeKey( self::FETCH_ALL_TAGS_KEY, 1 )
		);
	}

	/**
	 * @param array $tags
	 * @param bool $enabled
	 */
	private static function fetchAllTags( array &$tags, $enabled ) {
		$services = MediaWikiServices::getInstance();
		$cache = $services->getMainWANObjectCache();
		$fname = __METHOD__;

		$tags = $cache->getWithSetCallback(
			// Key to store the cached value under
			$cache->makeKey( self::FETCH_ALL_TAGS_KEY, (int)$enabled ),
			// Time-to-live (in seconds)
			$cache::TTL_MINUTE,
			// Function that derives the new key value
			function ( $oldValue, &$ttl, array &$setOpts ) use ( $enabled, $tags, $fname ) {
				global $wgAbuseFilterCentralDB, $wgAbuseFilterIsCentral;

				$dbr = wfGetDB( DB_REPLICA );
				// Account for any snapshot/replica DB lag
				$setOpts += Database::getCacheSetOptions( $dbr );

				// This is a pretty awful hack.

				$where = [ 'afa_consequence' => 'tag', 'af_deleted' => false ];
				if ( $enabled ) {
					$where['af_enabled'] = true;
				}
				$res = $dbr->select(
					[ 'abuse_filter_action', 'abuse_filter' ],
					'afa_parameters',
					$where,
					$fname,
					[],
					[ 'abuse_filter' => [ 'INNER JOIN', 'afa_filter=af_id' ] ]
				);

				foreach ( $res as $row ) {
					$tags = array_filter(
						array_merge( explode( "\n", $row->afa_parameters ), $tags )
					);
				}

				if ( $wgAbuseFilterCentralDB && !$wgAbuseFilterIsCentral ) {
					$dbr = AbuseFilter::getCentralDB( DB_REPLICA );
					$res = $dbr->select(
						[ 'abuse_filter_action', 'abuse_filter' ],
						'afa_parameters',
						[ 'af_global' => 1 ] + $where,
						$fname,
						[],
						[ 'abuse_filter' => [ 'INNER JOIN', 'afa_filter=af_id' ] ]
					);

					foreach ( $res as $row ) {
						$tags = array_filter(
							array_merge( explode( "\n", $row->afa_parameters ), $tags )
						);
					}
				}

				return $tags;
			}
		);

		$tags[] = 'abusefilter-condition-limit';
	}

	/**
	 * @param string[] &$tags
	 */
	public static function onListDefinedTags( array &$tags ) {
		self::fetchAllTags( $tags, false );
	}

	/**
	 * @param string[] &$tags
	 */
	public static function onChangeTagsListActive( array &$tags ) {
		self::fetchAllTags( $tags, true );
	}

	/**
	 * @param DatabaseUpdater $updater
	 * @throws MWException
	 */
	public static function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater ) {
		$dir = dirname( __DIR__ );

		if ( $updater->getDB()->getType() === 'mysql' || $updater->getDB()->getType() === 'sqlite' ) {
			if ( $updater->getDB()->getType() === 'mysql' ) {
				$updater->addExtensionUpdate( [ 'addTable', 'abuse_filter',
					"$dir/abusefilter.tables.sql", true ] );
				$updater->addExtensionUpdate( [ 'addTable', 'abuse_filter_history',
					"$dir/db_patches/patch-abuse_filter_history.sql", true ] );
			} else {
				$updater->addExtensionUpdate( [ 'addTable', 'abuse_filter',
					"$dir/abusefilter.tables.sqlite.sql", true ] );
				$updater->addExtensionUpdate( [ 'addTable', 'abuse_filter_history',
					"$dir/db_patches/patch-abuse_filter_history.sqlite.sql", true ] );
			}
			$updater->addExtensionUpdate( [
				'addField', 'abuse_filter_history', 'afh_changed_fields',
				"$dir/db_patches/patch-afh_changed_fields.sql", true
			] );
			$updater->addExtensionUpdate( [ 'addField', 'abuse_filter', 'af_deleted',
				"$dir/db_patches/patch-af_deleted.sql", true ] );
			$updater->addExtensionUpdate( [ 'addField', 'abuse_filter', 'af_actions',
				"$dir/db_patches/patch-af_actions.sql", true ] );
			$updater->addExtensionUpdate( [ 'addField', 'abuse_filter', 'af_global',
				"$dir/db_patches/patch-global_filters.sql", true ] );
			$updater->addExtensionUpdate( [ 'addField', 'abuse_filter_log', 'afl_rev_id',
				"$dir/db_patches/patch-afl_action_id.sql", true ] );
			if ( $updater->getDB()->getType() === 'mysql' ) {
				$updater->addExtensionUpdate( [ 'addIndex', 'abuse_filter_log',
					'filter_timestamp', "$dir/db_patches/patch-fix-indexes.sql", true ] );
			} else {
				$updater->addExtensionUpdate( [
					'addIndex', 'abuse_filter_log', 'afl_filter_timestamp',
					"$dir/db_patches/patch-fix-indexes.sqlite.sql", true
				] );
			}

			$updater->addExtensionUpdate( [ 'addField', 'abuse_filter',
				'af_group', "$dir/db_patches/patch-af_group.sql", true ] );

			if ( $updater->getDB()->getType() === 'mysql' ) {
				$updater->addExtensionUpdate( [
					'addIndex', 'abuse_filter_log', 'wiki_timestamp',
					"$dir/db_patches/patch-global_logging_wiki-index.sql", true
				] );
				$updater->addExtensionUpdate( [
					'modifyField', 'abuse_filter_log', 'afl_namespace',
					"$dir/db_patches/patch-afl-namespace_int.sql", true
				] );
			} else {
				$updater->addExtensionUpdate( [
					'addIndex', 'abuse_filter_log', 'afl_wiki_timestamp',
					"$dir/db_patches/patch-global_logging_wiki-index.sqlite.sql", true
				] );
				$updater->addExtensionUpdate( [
					'modifyField', 'abuse_filter_log', 'afl_namespace',
					"$dir/db_patches/patch-afl-namespace_int.sqlite.sql", true
				] );
			}
			if ( $updater->getDB()->getType() === 'mysql' ) {
				$updater->addExtensionUpdate( [ 'dropField', 'abuse_filter_log',
					'afl_log_id', "$dir/db_patches/patch-drop_afl_log_id.sql", true ] );
			} else {
				$updater->addExtensionUpdate( [ 'dropField', 'abuse_filter_log',
					'afl_log_id', "$dir/db_patches/patch-drop_afl_log_id.sqlite.sql", true ] );
			}
		} elseif ( $updater->getDB()->getType() === 'postgres' ) {
			$updater->addExtensionUpdate( [
				'addTable', 'abuse_filter', "$dir/abusefilter.tables.pg.sql", true ] );
			$updater->addExtensionUpdate( [
				'addTable', 'abuse_filter_history',
				"$dir/db_patches/patch-abuse_filter_history.pg.sql", true
			] );
			$updater->addExtensionUpdate( [
				'addPgField', 'abuse_filter', 'af_actions', "TEXT NOT NULL DEFAULT ''" ] );
			$updater->addExtensionUpdate( [
				'addPgField', 'abuse_filter', 'af_deleted', 'SMALLINT NOT NULL DEFAULT 0' ] );
			$updater->addExtensionUpdate( [
				'addPgField', 'abuse_filter', 'af_global', 'SMALLINT NOT NULL DEFAULT 0' ] );
			$updater->addExtensionUpdate( [
				'addPgField', 'abuse_filter', 'af_group', "TEXT NOT NULL DEFAULT 'default'" ] );
			$updater->addExtensionUpdate( [
				'addPgExtIndex', 'abuse_filter', 'abuse_filter_group_enabled_id',
				"(af_group, af_enabled, af_id)"
			] );
			$updater->addExtensionUpdate( [
				'addPgField', 'abuse_filter_history', 'afh_group', "TEXT" ] );
			$updater->addExtensionUpdate( [
				'addPgField', 'abuse_filter_log', 'afl_wiki', 'TEXT' ] );
			$updater->addExtensionUpdate( [
				'addPgField', 'abuse_filter_log', 'afl_deleted', 'SMALLINT' ] );
			$updater->addExtensionUpdate( [
				'setDefault', 'abuse_filter_log', 'afl_deleted', '0' ] );
			$updater->addExtensionUpdate( [
				'changeNullableField', 'abuse_filter_log', 'afl_deleted', 'NOT NULL', true ] );
			$updater->addExtensionUpdate( [
				'addPgField', 'abuse_filter_log', 'afl_patrolled_by', 'INTEGER' ] );
			$updater->addExtensionUpdate( [
				'addPgField', 'abuse_filter_log', 'afl_rev_id', 'INTEGER' ] );
			$updater->addExtensionUpdate( [
				'changeField', 'abuse_filter_log', 'afl_filter', 'TEXT', '' ] );
			$updater->addExtensionUpdate( [
				'changeField', 'abuse_filter_log', 'afl_namespace', "INTEGER", '' ] );
			$updater->addExtensionUpdate( [
				'dropPgIndex', 'abuse_filter_log', 'abuse_filter_log_filter' ] );
			$updater->addExtensionUpdate( [
				'dropPgIndex', 'abuse_filter_log', 'abuse_filter_log_ip' ] );
			$updater->addExtensionUpdate( [
				'dropPgIndex', 'abuse_filter_log', 'abuse_filter_log_title' ] );
			$updater->addExtensionUpdate( [
				'dropPgIndex', 'abuse_filter_log', 'abuse_filter_log_user' ] );
			$updater->addExtensionUpdate( [
				'dropPgIndex', 'abuse_filter_log', 'abuse_filter_log_user_text' ] );
			$updater->addExtensionUpdate( [
				'dropPgIndex', 'abuse_filter_log', 'abuse_filter_log_wiki' ] );
			$updater->addExtensionUpdate( [
				'addPgExtIndex', 'abuse_filter_log', 'abuse_filter_log_filter_timestamp',
				'(afl_filter,afl_timestamp)'
			] );
			$updater->addExtensionUpdate( [
				'addPgExtIndex', 'abuse_filter_log', 'abuse_filter_log_user_timestamp',
				'(afl_user,afl_user_text,afl_timestamp)'
			] );
			$updater->addExtensionUpdate( [
				'addPgExtIndex', 'abuse_filter_log', 'abuse_filter_log_page_timestamp',
				'(afl_namespace,afl_title,afl_timestamp)'
			] );
			$updater->addExtensionUpdate( [
				'addPgExtIndex', 'abuse_filter_log', 'abuse_filter_log_ip_timestamp',
				'(afl_ip, afl_timestamp)'
			] );
			$updater->addExtensionUpdate( [
				'addPgExtIndex', 'abuse_filter_log', 'abuse_filter_log_rev_id',
				'(afl_rev_id)'
			] );
			$updater->addExtensionUpdate( [
				'addPgExtIndex', 'abuse_filter_log', 'abuse_filter_log_wiki_timestamp',
				'(afl_wiki,afl_timestamp)'
			] );
			$updater->addExtensionUpdate( [
				'dropPgField', 'abuse_filter_log', 'afl_log_id' ] );
		}

		$updater->addExtensionUpdate( [ [ __CLASS__, 'createAbuseFilterUser' ] ] );
		$updater->addPostDatabaseUpdateMaintenance( 'NormalizeThrottleParameters' );
	}

	/**
	 * Updater callback to create the AbuseFilter user after the user tables have been updated.
	 * @param DatabaseUpdater $updater
	 */
	public static function createAbuseFilterUser( DatabaseUpdater $updater ) {
		$username = wfMessage( 'abusefilter-blocker' )->inContentLanguage()->text();
		$user = User::newFromName( $username );

		if ( $user && !$updater->updateRowExists( 'create abusefilter-blocker-user' ) ) {
			$user = User::newSystemUser( $username, [ 'steal' => true ] );
			$updater->insertUpdateRow( 'create abusefilter-blocker-user' );
			// Promote user so it doesn't look too crazy.
			$user->addGroup( 'sysop' );
		}
	}

	/**
	 * @param int $id
	 * @param Title $nt
	 * @param array &$tools
	 * @param SpecialPage $sp for context
	 */
	public static function onContributionsToolLinks( $id, Title $nt, array &$tools, SpecialPage $sp ) {
		$username = $nt->getText();
		if ( $sp->getUser()->isAllowed( 'abusefilter-log' ) && !IP::isValidRange( $username ) ) {
			$linkRenderer = $sp->getLinkRenderer();
			$tools['abuselog'] = $linkRenderer->makeLink(
				SpecialPage::getTitleFor( 'AbuseLog' ),
				$sp->msg( 'abusefilter-log-linkoncontribs' )->text(),
				[ 'title' => $sp->msg( 'abusefilter-log-linkoncontribs-text',
					$username )->text() ],
				[ 'wpSearchUser' => $username ]
			);
		}
	}

	/**
	 * @param IContextSource $context
	 * @param LinkRenderer $linkRenderer
	 * @param string[] &$links
	 */
	public static function onHistoryPageToolLinks(
		IContextSource $context,
		LinkRenderer $linkRenderer,
		array &$links
	) {
		$user = $context->getUser();
		if ( $user->isAllowed( 'abusefilter-log' ) ) {
			$links[] = $linkRenderer->makeLink(
				SpecialPage::getTitleFor( 'AbuseLog' ),
				$context->msg( 'abusefilter-log-linkonhistory' )->text(),
				[ 'title' => $context->msg( 'abusefilter-log-linkonhistory-text' )->text() ],
				[ 'wpSearchTitle' => $context->getTitle()->getPrefixedText() ]
			);
		}
	}

	/**
	 * Filter an upload.
	 *
	 * @param UploadBase $upload
	 * @param User $user
	 * @param array|null $props
	 * @param string $comment
	 * @param string $pageText
	 * @param array|ApiMessage &$error
	 * @return bool
	 */
	public static function onUploadVerifyUpload( UploadBase $upload, User $user,
		$props, $comment, $pageText, &$error
	) {
		return self::filterUpload( 'upload', $upload, $user, $props, $comment, $pageText, $error );
	}

	/**
	 * Filter an upload to stash. If a filter doesn't need to check the page contents or
	 * upload comment, it can use `action='stashupload'` to provide better experience to e.g.
	 * UploadWizard (rejecting files immediately, rather than after the user adds the details).
	 *
	 * @param UploadBase $upload
	 * @param User $user
	 * @param array $props
	 * @param array|ApiMessage &$error
	 * @return bool
	 */
	public static function onUploadStashFile( UploadBase $upload, User $user,
		array $props, &$error
	) {
		return self::filterUpload( 'stashupload', $upload, $user, $props, null, null, $error );
	}

	/**
	 * Get variables for filtering an upload.
	 * @todo Consider splitting into a separate class
	 * @internal Until we'll find the right place for it
	 *
	 * @param string $action
	 * @param User $user
	 * @param Title $title
	 * @param UploadBase $upload
	 * @param string $summary
	 * @param string $text
	 * @param array $props
	 * @return AbuseFilterVariableHolder|null
	 */
	public static function getUploadVars(
		$action,
		User $user,
		Title $title,
		UploadBase $upload,
		$summary,
		$text,
		$props
	) {
		$mimeAnalyzer = MediaWikiServices::getInstance()->getMimeAnalyzer();
		if ( !$props ) {
			$props = ( new MWFileProps( $mimeAnalyzer ) )->getPropsFromPath(
				$upload->getTempPath(),
				true
			);
		}

		$vars = new AbuseFilterVariableHolder;
		$vars->addHolders(
			AbuseFilter::generateUserVars( $user ),
			AbuseFilter::generateTitleVars( $title, 'page' )
		);
		$vars->setVar( 'action', $action );

		// We use the hexadecimal version of the file sha1.
		// Use UploadBase::getTempFileSha1Base36 so that we don't have to calculate the sha1 sum again
		$sha1 = Wikimedia\base_convert( $upload->getTempFileSha1Base36(), 36, 16, 40 );

		// This is the same as AbuseFilter::getUploadVarsFromRCRow, but from a different source
		$vars->setVar( 'file_sha1', $sha1 );
		$vars->setVar( 'file_size', $upload->getFileSize() );

		$vars->setVar( 'file_mime', $props['mime'] );
		$vars->setVar( 'file_mediatype', $mimeAnalyzer->getMediaType( null, $props['mime'] ) );
		$vars->setVar( 'file_width', $props['width'] );
		$vars->setVar( 'file_height', $props['height'] );
		$vars->setVar( 'file_bits_per_channel', $props['bits'] );

		// We only have the upload comment and page text when using the UploadVerifyUpload hook
		if ( $summary !== null && $text !== null ) {
			// This block is adapted from self::filterEdit()
			if ( $title->exists() ) {
				$page = WikiPage::factory( $title );
				$revision = $page->getRevision();
				if ( !$revision ) {
					return null;
				}

				$oldcontent = $revision->getContent( Revision::RAW );
				$oldtext = AbuseFilter::contentToString( $oldcontent );

				// Cache article object so we can share a parse operation
				$articleCacheKey = $title->getNamespace() . ':' . $title->getText();
				AFComputedVariable::$articleCache[$articleCacheKey] = $page;

				// Page text is ignored for uploads when the page already exists
				$text = $oldtext;
			} else {
				$page = null;
				$oldtext = '';
			}

			// Load vars for filters to check
			$vars->setVar( 'summary', $summary );
			$vars->setVar( 'old_wikitext', $oldtext );
			$vars->setVar( 'new_wikitext', $text );
			// TODO: set old_content and new_content vars, use them
			$vars->addHolders( AbuseFilter::getEditVars( $title, $page ) );
		}
		return $vars;
	}

	/**
	 * Implementation for UploadStashFile and UploadVerifyUpload hooks.
	 *
	 * @param string $action 'upload' or 'stashupload'
	 * @param UploadBase $upload
	 * @param User $user User performing the action
	 * @param array|null $props File properties, as returned by MWFileProps::getPropsFromPath().
	 * @param string|null $summary Upload log comment (also used as edit summary)
	 * @param string|null $text File description page text (only used for new uploads)
	 * @param array|ApiMessage &$error
	 * @return bool
	 */
	public static function filterUpload( $action, UploadBase $upload, User $user,
		$props, $summary, $text, &$error
	) {
		$title = $upload->getTitle();
		if ( $title === null ) {
			// T144265: This could happen for 'stashupload' if the specified title is invalid.
			// Let UploadBase warn the user about that, and we'll filter later.
			$logger = LoggerFactory::getInstance( 'AbuseFilter' );
			$logger->warning( __METHOD__ . " received a null title. Action: $action." );
			return true;
		}

		$vars = self::getUploadVars( $action, $user, $title, $upload, $summary, $text, $props );
		if ( $vars === null ) {
			return true;
		}
		$runner = new AbuseFilterRunner( $user, $title, $vars, 'default' );
		$filterResult = $runner->run();

		if ( !$filterResult->isOK() ) {
			$messageAndParams = $filterResult->getErrorsArray()[0];
			$apiResult = self::getApiResult( $filterResult );
			$error = ApiMessage::create(
				$messageAndParams,
				$apiResult['code'],
				$apiResult
			);
		}

		return $filterResult->isOK();
	}

	/**
	 * Tables that Extension:UserMerge needs to update
	 *
	 * @param array &$updateFields
	 */
	public static function onUserMergeAccountFields( array &$updateFields ) {
		$updateFields[] = [ 'abuse_filter', 'af_user', 'af_user_text' ];
		$updateFields[] = [ 'abuse_filter_log', 'afl_user', 'afl_user_text' ];
		$updateFields[] = [ 'abuse_filter_history', 'afh_user', 'afh_user_text' ];
	}

	/**
	 * Warms the cache for getLastPageAuthors() - T116557
	 *
	 * @param WikiPage $page
	 * @param Content $content
	 * @param ParserOutput $output
	 * @param string $summary
	 * @param User|null $user
	 */
	public static function onParserOutputStashForEdit(
		WikiPage $page, Content $content, ParserOutput $output, $summary = '', $user = null
	) {
		$oldRevision = $page->getRevision();
		if ( !$oldRevision ) {
			return;
		}

		$oldContent = $oldRevision->getContent( Revision::RAW );
		$user = $user ?: RequestContext::getMain()->getUser();
		$oldAfText = AbuseFilter::revisionToString( $oldRevision, $user );

		// XXX: This makes the assumption that this method is only ever called for the main slot.
		// Which right now holds true, but any more fancy MCR stuff will likely break here...
		$slot = SlotRecord::MAIN;

		// XXX: Recreate what the new revision will probably be so we can get the full AF
		// text for all slots
		$oldRevRecord = $oldRevision->getRevisionRecord();
		$newRevision = MutableRevisionRecord::newFromParentRevision( $oldRevRecord );
		$newRevision->setContent( $slot, $content );
		$text = AbuseFilter::revisionToString( $newRevision, $user );

		// Cache any resulting filter matches.
		// Do this outside the synchronous stash lock to avoid any chance of slowdown.
		DeferredUpdates::addCallableUpdate(
			function () use ( $user, $page, $summary, $content, $text, $oldContent, $oldAfText ) {
				$vars = self::newVariableHolderForEdit(
					$user, $page->getTitle(), $page, $summary, $content, $text, $oldAfText, $oldContent
				);
				$runner = new AbuseFilterRunner( $user, $page->getTitle(), $vars, 'default' );
				$runner->runForStash();
			},
			DeferredUpdates::PRESEND
		);
	}

	/**
	 * Setup tables to emulate global filters, used in AbuseFilterConsequencesTest.
	 *
	 * @param IMaintainableDatabase $db
	 * @param string $prefix The prefix used in unit tests
	 * @suppress PhanUndeclaredClassConstant AbuseFilterConsequencesTest is in AutoloadClasses
	 * @suppress PhanUndeclaredClassStaticProperty AbuseFilterConsequencesTest is in AutoloadClasses
	 */
	public static function onUnitTestsAfterDatabaseSetup( IMaintainableDatabase $db, $prefix ) {
		$externalPrefix = AbuseFilterConsequencesTest::DB_EXTERNAL_PREFIX;
		if ( $db->tableExists( $externalPrefix . AbuseFilterConsequencesTest::$externalTables[0] ) ) {
			// Check a random table to avoid unnecessary table creations. See T155147.
			return;
		}

		foreach ( AbuseFilterConsequencesTest::$externalTables as $table ) {
			// Don't create them as temporary, as we'll access the DB via another connection
			$db->duplicateTableStructure(
				"$prefix$table",
				"$prefix$externalPrefix$table",
				false,
				__METHOD__
			);
		}
	}

	/**
	 * Drop tables used for global filters in AbuseFilterConsequencesTest.
	 *   Note: this has the same problem as T201290.
	 *
	 * @suppress PhanUndeclaredClassConstant AbuseFilterConsequencesTest is in AutoloadClasses
	 * @suppress PhanUndeclaredClassStaticProperty AbuseFilterConsequencesTest is in AutoloadClasses
	 */
	public static function onUnitTestsBeforeDatabaseTeardown() {
		$db = wfGetDB( DB_MASTER );
		foreach ( AbuseFilterConsequencesTest::$externalTables as $table ) {
			$db->dropTable( AbuseFilterConsequencesTest::DB_EXTERNAL_PREFIX . $table );
		}
	}
}
