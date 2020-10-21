<?php

use MediaWiki\Extension\AbuseFilter\AbuseFilterServices;
use MediaWiki\Extension\AbuseFilter\VariableGenerator\RunVariableGenerator;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\User\UserIdentity;
use Wikimedia\Rdbms\Database;
use Wikimedia\Rdbms\IMaintainableDatabase;

class AbuseFilterHooks {
	private const FETCH_ALL_TAGS_KEY = 'abusefilter-fetch-all-tags';

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
				'"abusefilter-privatedetails". Please update the names in your settings.'
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
		$startTime = microtime( true );

		$filterResult = self::filterEdit( $context, $user, $content, $summary, $slot );

		if ( !$filterResult->isOK() ) {
			// Produce a useful error message for API edits
			$filterResultApi = self::getApiStatus( $filterResult );
			$status->merge( $filterResultApi );
		}
		MediaWikiServices::getInstance()->getStatsdDataFactory()
			->timing( 'timing.editAbuseFilter', microtime( true ) - $startTime );
	}

	/**
	 * Implementation for EditFilterMergedContent hook.
	 *
	 * @param IContextSource $context the context of the edit
	 * @param User $user
	 * @param Content $content the new Content generated by the edit
	 * @param string $summary Edit summary for page
	 * @param string $slot slot role for the content
	 * @return Status
	 */
	public static function filterEdit(
		IContextSource $context,
		User $user,
		Content $content,
		$summary, $slot = SlotRecord::MAIN
	) : Status {
		self::$lastEditPage = null;

		// @todo is there any real point in passing this in?
		$text = AbuseFilter::contentToString( $content );

		$title = $context->getTitle();
		if ( $title === null ) {
			// T144265: This *should* never happen.
			$logger = LoggerFactory::getInstance( 'AbuseFilter' );
			$logger->warning( __METHOD__ . ' received a null title.' );
			return Status::newGood();
		}

		if ( $title->canExist() && $title->exists() ) {
			// Make sure we load the latest text saved in database (bug 31656)
			$page = $context->getWikiPage();
		} else {
			$page = null;
		}

		$vars = new AbuseFilterVariableHolder();
		$builder = new RunVariableGenerator( $vars, $user, $title );
		$vars = $builder->getEditVars( $content, $text, $summary, $slot, $page );
		if ( $vars === null ) {
			// We don't have to filter the edit
			return Status::newGood();
		}
		$runner = new AbuseFilterRunner( $user, $title, $vars, 'default' );
		$filterResult = $runner->run();
		if ( !$filterResult->isOK() ) {
			return $filterResult;
		}

		self::$lastEditPage = $page;

		return Status::newGood();
	}

	/**
	 * @param Status $status Error message details
	 * @return Status Status containing the same error messages with extra data for the API
	 */
	private static function getApiStatus( Status $status ) {
		$allActionsTaken = $status->getValue();
		$statusForApi = Status::newGood();

		foreach ( $status->getErrors() as $error ) {
			list( $filterDescription, $filter ) = $error['params'];
			$actionsTaken = $allActionsTaken[ $filter ];

			$code = ( $actionsTaken === [ 'warn' ] ) ? 'abusefilter-warning' : 'abusefilter-disallowed';
			$data = [
				'abusefilter' => [
					'id' => $filter,
					'description' => $filterDescription,
					'actions' => $actionsTaken,
				],
			];

			$message = ApiMessage::create( $error, $code, $data );
			$statusForApi->fatal( $message );
		}

		return $statusForApi;
	}

	/**
	 * @param WikiPage $wikiPage
	 * @param UserIdentity $userIdentity
	 * @param string $summary
	 * @param int $flags
	 * @param RevisionRecord $revisionRecord
	 */
	public static function onPageSaveComplete(
		WikiPage $wikiPage,
		UserIdentity $userIdentity,
		string $summary,
		int $flags,
		RevisionRecord $revisionRecord
	) {
		$curTitle = $wikiPage->getTitle()->getPrefixedText();
		if ( !isset( AbuseFilter::$logIds[ $curTitle ] ) ||
			$wikiPage !== self::$lastEditPage
		) {
			// This isn't the edit AbuseFilter::$logIds was set for
			AbuseFilter::$logIds = [];
			return;
		}

		// Ignore null edit.
		$parentRevId = $revisionRecord->getParentId();
		if ( $parentRevId !== null ) {
			$parentRev = MediaWikiServices::getInstance()
				->getRevisionLookup()
				->getRevisionById( $parentRevId );
			if ( $parentRev && $revisionRecord->hasSameContent( $parentRev ) ) {
				AbuseFilter::$logIds = [];
				return;
			}
		}

		self::$lastEditPage = null;

		$logs = AbuseFilter::$logIds[ $curTitle ];
		if ( $logs[ 'local' ] ) {
			// Now actually do our storage
			$dbw = wfGetDB( DB_MASTER );

			$dbw->update( 'abuse_filter_log',
				[ 'afl_rev_id' => $revisionRecord->getId() ],
				[ 'afl_id' => $logs['local'] ],
				__METHOD__
			);
		}

		if ( $logs[ 'global' ] ) {
			$fdb = AbuseFilter::getCentralDB( DB_MASTER );
			$fdb->update( 'abuse_filter_log',
				[ 'afl_rev_id' => $revisionRecord->getId() ],
				[ 'afl_id' => $logs['global'], 'afl_wiki' => WikiMap::getCurrentWikiDbDomain()->getId() ],
				__METHOD__
			);
		}
	}

	/**
	 * @param User $user
	 * @param array &$promote
	 */
	public static function onGetAutoPromoteGroups( User $user, &$promote ) {
		global $wgAbuseFilterActions;

		if ( ( $wgAbuseFilterActions['blockautopromote'] ?? false ) && $promote ) {
			$cache = ObjectCache::getInstance( 'hash' );
			$key = AbuseFilter::autoPromoteBlockKey( $cache, $user );
			$blocked = (bool)$cache->getWithSetCallback(
				$key,
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
		$vars = new AbuseFilterVariableHolder();
		$builder = new RunVariableGenerator( $vars, $user, $oldTitle );
		$vars = $builder->getMoveVars( $newTitle, $reason );
		$runner = new AbuseFilterRunner( $user, $oldTitle, $vars, 'default' );
		$result = $runner->run();
		$status->merge( $result );
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
		$vars = new AbuseFilterVariableHolder();
		$builder = new RunVariableGenerator( $vars, $user, $article->getTitle() );
		$vars = $builder->getDeleteVars( $reason );
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
		$tagger = AbuseFilterServices::getChangeTagger();
		$tags = $tagger->getTagsForRecentChange( $recentChange );
		if ( $tags ) {
			$recentChange->addTags( $tags );
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
	 * @param array &$tags
	 * @param bool $enabled
	 */
	private static function fetchAllTags( array &$tags, $enabled ) {
		$services = MediaWikiServices::getInstance();
		$cache = $services->getMainWANObjectCache();
		$fname = __METHOD__;

		$afTags = $cache->getWithSetCallback(
			// Key to store the cached value under
			$cache->makeKey( self::FETCH_ALL_TAGS_KEY, (int)$enabled ),
			// Time-to-live (in seconds)
			$cache::TTL_MINUTE,
			// Function that derives the new key value
			function ( $oldValue, &$ttl, array &$setOpts ) use ( $enabled, $fname ) {
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

				$tags = [];
				foreach ( $res as $row ) {
					$tags = array_merge(
						$row->afa_parameters ? explode( "\n", $row->afa_parameters ) : [],
						$tags
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
						$tags = array_merge(
							$row->afa_parameters ? explode( "\n", $row->afa_parameters ) : [],
							$tags
						);
					}
				}

				return array_unique( $tags );
			}
		);

		$afTags[] = 'abusefilter-condition-limit';
		$tags = array_merge( $tags, $afTags );
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
			} else {
				$updater->addExtensionUpdate( [ 'addTable', 'abuse_filter',
					"$dir/abusefilter.tables.sqlite.sql", true ] );
			}
			$updater->addExtensionTable( 'abuse_filter_history',
				"$dir/db_patches/patch-abuse_filter_history.sql" );

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

			$updater->addExtensionIndex(
				'abuse_filter_log', 'afl_wiki_timestamp',
				"$dir/db_patches/patch-global_logging_wiki-index.sql"
			);

			if ( $updater->getDB()->getType() === 'mysql' ) {
				$updater->addExtensionUpdate( [
					'modifyField', 'abuse_filter_log', 'afl_namespace',
					"$dir/db_patches/patch-afl-namespace_int.sql", true
				] );
			} else {
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

			if ( $updater->getDB()->getType() == 'mysql' ) {
				$updater->addExtensionUpdate( [
					'addIndex', 'abuse_filter_log', 'filter_timestamp_full',
					"$dir/db_patches/patch-split-afl_filter.sql", true
				] );
			} else {
				$updater->addExtensionUpdate( [
					'addIndex', 'abuse_filter_log', 'filter_timestamp_full',
					"$dir/db_patches/patch-split-afl_filter.sqlite.sql", true
				] );
			}
			if ( $updater->getDB()->getType() == 'mysql' ) {
				$updater->addExtensionUpdate( [ 'modifyField', 'abuse_filter_log', 'afl_patrolled_by',
					"$dir/db_patches/patch-afl_change_deleted_patrolled.sql", true ] );
			} else {
				$updater->addExtensionUpdate( [ 'modifyField', 'abuse_filter_log', 'afl_patrolled_by',
					"$dir/db_patches/patch-afl_change_deleted_patrolled.sqlite.sql", true ] );
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
			$updater->addExtensionUpdate( [
				'setDefault', 'abuse_filter_log', 'afl_filter', ''
			] );
			$updater->addExtensionUpdate( [
				'addPgField', 'abuse_filter_log', 'afl_global', 'SMALLINT NOT NULL DEFAULT 0' ] );
			$updater->addExtensionUpdate( [
				'addPgField', 'abuse_filter_log', 'afl_filter_id', 'INTEGER NOT NULL DEFAULT 0' ] );
			$updater->addExtensionUpdate( [
				'addPgIndex', 'abuse_filter_log', 'abuse_filter_log_filter_timestamp_full',
				'(afl_global, afl_filter_id, afl_timestamp)' ] );
			$updater->addExtensionUpdate( [ 'setDefault', 'abuse_filter_log', 'afl_deleted', 0 ] );
			$updater->addExtensionUpdate( [
				'changeNullableField', 'abuse_filter_log', 'afl_deleted', 'NOT NULL', true ] );
			$updater->addExtensionUpdate( [ 'setDefault', 'abuse_filter_log', 'afl_patrolled_by', 0 ] );
			$updater->addExtensionUpdate( [
				'changeNullableField', 'abuse_filter_log', 'afl_patrolled_by', 'NOT NULL', true ] );
		}

		$updater->addExtensionUpdate( [ [ __CLASS__, 'createAbuseFilterUser' ] ] );
		$updater->addPostDatabaseUpdateMaintenance( 'NormalizeThrottleParameters' );
		$updater->addPostDatabaseUpdateMaintenance( 'FixOldLogEntries' );
		$updater->addPostDatabaseUpdateMaintenance( 'UpdateVarDumps' );
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

		$vars = new AbuseFilterVariableHolder();
		$builder = new RunVariableGenerator( $vars, $user, $title );
		$vars = $builder->getUploadVars( $action, $upload, $summary, $text, $props );
		if ( $vars === null ) {
			return true;
		}
		$runner = new AbuseFilterRunner( $user, $title, $vars, 'default' );
		$filterResult = $runner->run();

		if ( !$filterResult->isOK() ) {
			// Produce a useful error message for API edits
			$filterResultApi = self::getApiStatus( $filterResult );
			// @todo Return all errors instead of only the first one
			$error = $filterResultApi->getErrors()[0]['message'];
		}

		return $filterResult->isOK();
	}

	/**
	 * For integration with the Renameuser extension.
	 *
	 * @param RenameuserSQL $renameUserSQL
	 */
	public static function onRenameUserSQL( RenameuserSQL $renameUserSQL ) {
		$renameUserSQL->tablesJob['abuse_filter'] = [
			RenameuserSQL::NAME_COL => 'af_user_text',
			RenameuserSQL::UID_COL => 'af_user',
			RenameuserSQL::TIME_COL => 'af_timestamp',
			'uniqueKey' => 'af_id'
		];
		$renameUserSQL->tablesJob['abuse_filter_history'] = [
			RenameuserSQL::NAME_COL => 'afh_user_text',
			RenameuserSQL::UID_COL => 'afh_user',
			RenameuserSQL::TIME_COL => 'afh_timestamp',
			'uniqueKey' => 'afh_id'
		];
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
	 * @param User $user
	 */
	public static function onParserOutputStashForEdit(
		WikiPage $page, Content $content, ParserOutput $output, string $summary, User $user
	) {
		// XXX: This makes the assumption that this method is only ever called for the main slot.
		// Which right now holds true, but any more fancy MCR stuff will likely break here...
		$slot = SlotRecord::MAIN;

		// Cache any resulting filter matches.
		// Do this outside the synchronous stash lock to avoid any chance of slowdown.
		DeferredUpdates::addCallableUpdate(
			function () use (
				$user,
				$page,
				$summary,
				$content,
				$slot
			) {
				$startTime = microtime( true );
				$vars = new AbuseFilterVariableHolder();
				$generator = new RunVariableGenerator( $vars, $user, $page->getTitle() );
				$vars = $generator->getStashEditVars( $content, $summary, $slot, $page );
				if ( !$vars ) {
					return;
				}
				$runner = new AbuseFilterRunner( $user, $page->getTitle(), $vars, 'default' );
				$runner->runForStash();
				$totalTime = microtime( true ) - $startTime;
				MediaWikiServices::getInstance()->getStatsdDataFactory()
					->timing( 'timing.stashAbuseFilter', $totalTime );
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
		if ( $db->tableExists( $externalPrefix . AbuseFilterConsequencesTest::$externalTables[0], __METHOD__ ) ) {
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
