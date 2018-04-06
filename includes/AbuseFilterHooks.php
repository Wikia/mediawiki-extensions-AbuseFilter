<?php

use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\Database;

class AbuseFilterHooks {
	const FETCH_ALL_TAGS_KEY = 'abusefilter-fetch-all-tags';

	public static $successful_action_vars = false;
	/** @var WikiPage|Article|bool */
	public static $last_edit_page = false; // make sure edit filter & edit save hooks match
	// So far, all of the error message out-params for these hooks accept HTML.
	// Hooray!

	/**
	 * Called right after configuration has been loaded.
	 */
	public static function onRegistration() {
		global $wgAbuseFilterAvailableActions, $wgAbuseFilterRestrictedActions,
			$wgAuthManagerAutoConfig;

		if ( isset( $wgAbuseFilterAvailableActions ) || isset( $wgAbuseFilterRestrictedActions ) ) {
			wfWarn( '$wgAbuseFilterAvailableActions and $wgAbuseFilterRestrictedActions have been '
				. 'removed. Please use $wgAbuseFilterActions and $wgAbuseFilterRestrictions '
				. 'instead. The format is the same except the action names are the keys of the '
				. 'array and the values are booleans.' );
		}

		$wgAuthManagerAutoConfig['preauth'][AbuseFilterPreAuthenticationProvider::class] = [
			'class' => AbuseFilterPreAuthenticationProvider::class,
			'sort' => 5, // run after normal preauth providers to keep the log cleaner
		];
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
	 * @return bool Always true
	 */
	public static function onEditFilterMergedContent( IContextSource $context, Content $content,
		Status $status, $summary, User $user, $minoredit
	) {
		$text = AbuseFilter::contentToString( $content );

		$filterStatus = self::filterEdit( $context, $content, $text, $status, $summary, $minoredit );

		if ( !$filterStatus->isOK() ) {
			// Produce a useful error message for API edits
			$status->apiHookResult = self::getApiResult( $filterStatus );
		}

		return true;
	}

	/**
	 * Implementation for EditFilterMergedContent hook.
	 *
	 * @param IContextSource $context the context of the edit
	 * @param Content $content the new Content generated by the edit
	 * @param string $text new page content (subject of filtering)
	 * @param Status $status Error message to return
	 * @param string $summary Edit summary for page
	 * @param bool $minoredit whether this is a minor edit according to the user.
	 * @return Status
	 */
	public static function filterEdit( IContextSource $context, $content, $text,
		Status $status, $summary, $minoredit
	) {
		$title = $context->getTitle();

		self::$successful_action_vars = false;
		self::$last_edit_page = false;

		$user = $context->getUser();

		$oldcontent = null;

		if ( ( $title instanceof Title ) && $title->canExist() && $title->exists() ) {
			// Make sure we load the latest text saved in database (bug 31656)
			$page = $context->getWikiPage();
			$revision = $page->getRevision();
			if ( !$revision ) {
				return Status::newGood();
			}

			$oldcontent = $revision->getContent( Revision::RAW );
			$oldtext = AbuseFilter::contentToString( $oldcontent );

			// Cache article object so we can share a parse operation
			$articleCacheKey = $title->getNamespace() . ':' . $title->getText();
			AFComputedVariable::$articleCache[$articleCacheKey] = $page;

			// Don't trigger for null edits.
			if ( $content && $oldcontent ) {
				// Compare Content objects if available
				if ( $content->equals( $oldcontent ) ) {
					return Status::newGood();
				}
			} elseif ( strcmp( $oldtext, $text ) == 0 ) {
				// Otherwise, compare strings
				return Status::newGood();
			}
		} else {
			$page = null;
		}

		// Load vars for filters to check
		$vars = self::newVariableHolderForEdit(
			$user, $title, $page, $summary, $content, $oldcontent, $text
		);

		$filter_result = AbuseFilter::filterAction( $vars, $title );
		if ( !$filter_result->isOK() ) {
			$status->merge( $filter_result );

			return $filter_result;
		}

		self::$successful_action_vars = $vars;
		self::$last_edit_page = $page;

		return Status::newGood();
	}

	/**
	 * @param User $user
	 * @param Title $title
	 * @param WikiPage|null $page
	 * @param string $summary
	 * @param Content $newcontent
	 * @param Content|null $oldcontent
	 * @param string $text
	 * @return AbuseFilterVariableHolder
	 * @throws MWException
	 */
	private static function newVariableHolderForEdit(
		User $user, Title $title, $page, $summary, Content $newcontent,
		$oldcontent = null, $text
	) {
		$vars = new AbuseFilterVariableHolder();
		$vars->addHolders(
			AbuseFilter::generateUserVars( $user ),
			AbuseFilter::generateTitleVars( $title, 'ARTICLE' )
		);
		$vars->setVar( 'action', 'edit' );
		$vars->setVar( 'summary', $summary );
		if ( $oldcontent instanceof Content ) {
			$oldmodel = $oldcontent->getModel();
			$oldtext = AbuseFilter::contentToString( $oldcontent );
		} else {
			$oldmodel = '';
			$oldtext = '';
		}
		$vars->setVar( 'old_content_model', $oldmodel );
		$vars->setVar( 'new_content_model', $newcontent->getModel() );
		$vars->setVar( 'old_wikitext', $oldtext );
		$vars->setVar( 'new_wikitext', $text );
		// TODO: set old_content and new_content vars, use them
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

		$filterDescription = $params[0];
		$filter = $params[1];

		// The value is a nested structure keyed by filter id, which doesn't make sense when we only
		// return the result from one filter. Flatten it to a plain array of actions.
		$actionsTaken = array_values( array_unique(
			call_user_func_array( 'array_merge', array_values( $status->getValue() ) )
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
	 * @param WikiPage &$wikiPage
	 * @param User &$user
	 * @param string $content Content
	 * @param string $summary
	 * @param bool $minoredit
	 * @param bool $watchthis
	 * @param string $sectionanchor
	 * @param int &$flags
	 * @param Revision $revision
	 * @param Status &$status
	 * @param int $baseRevId
	 *
	 * @return bool
	 */
	public static function onPageContentSaveComplete(
		WikiPage &$wikiPage, &$user, $content, $summary, $minoredit, $watchthis, $sectionanchor,
		&$flags, $revision, &$status, $baseRevId
	) {
		if ( !self::$successful_action_vars || !$revision ) {
			self::$successful_action_vars = false;

			return true;
		}

		/** @var AbuseFilterVariableHolder $vars */
		$vars = self::$successful_action_vars;

		if ( $vars->getVar( 'article_prefixedtext' )->toString() !==
			$wikiPage->getTitle()->getPrefixedText()
		) {
			return true;
		}

		if ( !self::identicalPageObjects( $wikiPage, self::$last_edit_page ) ) {
			return true; // this isn't the edit $successful_action_vars was set for
		}
		self::$last_edit_page = false;

		if ( $vars->getVar( 'local_log_ids' ) ) {
			// Now actually do our storage
			$log_ids = $vars->getVar( 'local_log_ids' )->toNative();
			$dbw = wfGetDB( DB_MASTER );

			if ( $log_ids !== null && count( $log_ids ) ) {
				$dbw->update( 'abuse_filter_log',
					[ 'afl_rev_id' => $revision->getId() ],
					[ 'afl_id' => $log_ids ],
					__METHOD__
				);
			}
		}

		if ( $vars->getVar( 'global_log_ids' ) ) {
			$log_ids = $vars->getVar( 'global_log_ids' )->toNative();

			if ( $log_ids !== null && count( $log_ids ) ) {
				global $wgAbuseFilterCentralDB;
				$fdb = wfGetDB( DB_MASTER, [], $wgAbuseFilterCentralDB );

				$fdb->update( 'abuse_filter_log',
					[ 'afl_rev_id' => $revision->getId() ],
					[ 'afl_id' => $log_ids, 'afl_wiki' => wfWikiID() ],
					__METHOD__
				);
			}
		}

		return true;
	}

	/**
	 * Check if two article objects are identical or have an identical WikiPage
	 * @param Article|WikiPage $page1
	 * @param Article|WikiPage $page2
	 * @return bool
	 */
	protected static function identicalPageObjects( $page1, $page2 ) {
		$wpage1 = ( $page1 instanceof Article ) ? $page1->getPage() : $page1;
		$wpage2 = ( $page2 instanceof Article ) ? $page2->getPage() : $page2;

		return $wpage1 === $wpage2;
	}

	/**
	 * @param User $user
	 * @param array &$promote
	 * @return bool
	 */
	public static function onGetAutoPromoteGroups( $user, &$promote ) {
		if ( $promote ) {
			$key = AbuseFilter::autoPromoteBlockKey( $user );
			$blocked = (bool)ObjectCache::getInstance( 'hash' )->getWithSetCallback(
				$key,
				30,
				function () use ( $key ) {
					return (int)ObjectCache::getMainStashInstance()->get( $key );
				}
			);

			if ( $blocked ) {
				$promote = [];
			}
		}

		return true;
	}

	/**
	 * @param Title $oldTitle
	 * @param Title $newTitle
	 * @param User $user
	 * @param string $reason
	 * @param Status $status
	 * @return bool
	 */
	public static function onMovePageCheckPermissions( Title $oldTitle, Title $newTitle,
		User $user, $reason, Status $status
	) {
		$vars = new AbuseFilterVariableHolder;
		$vars->addHolders(
			AbuseFilter::generateUserVars( $user ),
			AbuseFilter::generateTitleVars( $oldTitle, 'MOVED_FROM' ),
			AbuseFilter::generateTitleVars( $newTitle, 'MOVED_TO' )
		);
		$vars->setVar( 'SUMMARY', $reason );
		$vars->setVar( 'ACTION', 'move' );

		$result = AbuseFilter::filterAction( $vars, $oldTitle );
		$status->merge( $result );

		return $result->isOK();
	}

	/**
	 * @param WikiPage &$article
	 * @param User &$user
	 * @param string &$reason
	 * @param string &$error
	 * @param Status &$status
	 * @return bool
	 */
	public static function onArticleDelete( &$article, &$user, &$reason, &$error, &$status ) {
		$vars = new AbuseFilterVariableHolder;

		$vars->addHolders(
			AbuseFilter::generateUserVars( $user ),
			AbuseFilter::generateTitleVars( $article->getTitle(), 'ARTICLE' )
		);

		$vars->setVar( 'SUMMARY', $reason );
		$vars->setVar( 'ACTION', 'delete' );

		$filter_result = AbuseFilter::filterAction( $vars, $article->getTitle() );

		$status->merge( $filter_result );
		$error = $filter_result->isOK() ? '' : $filter_result->getHTML();

		return $filter_result->isOK();
	}

	/**
	 * @param RecentChange $recentChange
	 * @return bool
	 */
	public static function onRecentChangeSave( $recentChange ) {
		$title = Title::makeTitle(
			$recentChange->getAttribute( 'rc_namespace' ),
			$recentChange->getAttribute( 'rc_title' )
		);
		$action = $recentChange->mAttribs['rc_log_type'] ?
			$recentChange->mAttribs['rc_log_type'] : 'edit';
		$actionID = implode( '-', [
			$title->getPrefixedText(), $recentChange->getAttribute( 'rc_user_text' ), $action
		] );

		if ( isset( AbuseFilter::$tagsToSet[$actionID] ) ) {
			$recentChange->addTags( AbuseFilter::$tagsToSet[$actionID] );
		}

		return true;
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
	 * @return bool
	 */
	private static function fetchAllTags( array &$tags, $enabled ) {
		$services = MediaWikiServices::getInstance();
		$cache = $services->getMainWANObjectCache();

		$tags = $cache->getWithSetCallback(
			// Key to store the cached value under
			$cache->makeKey( self::FETCH_ALL_TAGS_KEY, (int)$enabled ),

			// Time-to-live (in seconds)
			$cache::TTL_MINUTE,

			// Function that derives the new key value
			function ( $oldValue, &$ttl, array &$setOpts ) use ( $enabled, $tags ) {
				global $wgAbuseFilterCentralDB, $wgAbuseFilterIsCentral;

				$dbr = wfGetDB( DB_REPLICA );
				// Account for any snapshot/replica DB lag
				$setOpts += Database::getCacheSetOptions( $dbr );

				# This is a pretty awful hack.

				$where = [ 'afa_consequence' => 'tag', 'af_deleted' => false ];
				if ( $enabled ) {
					$where['af_enabled'] = true;
				}
				$res = $dbr->select(
					[ 'abuse_filter_action', 'abuse_filter' ],
					'afa_parameters',
					$where,
					__METHOD__,
					[],
					[ 'abuse_filter' => [ 'INNER JOIN', 'afa_filter=af_id' ] ]
				);

				foreach ( $res as $row ) {
					$tags = array_filter(
						array_merge( explode( "\n", $row->afa_parameters ), $tags )
					);
				}

				if ( $wgAbuseFilterCentralDB && !$wgAbuseFilterIsCentral ) {
					$dbr = wfGetDB( DB_REPLICA, [], $wgAbuseFilterCentralDB );
					$where['af_global'] = 1;
					$res = $dbr->select(
						[ 'abuse_filter_action', 'abuse_filter' ],
						'afa_parameters',
						$where,
						__METHOD__,
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

		return true;
	}

	/**
	 * @param string[] &$tags
	 * @return bool
	 */
	public static function onListDefinedTags( array &$tags ) {
		return self::fetchAllTags( $tags, false );
	}

	/**
	 * @param string[] &$tags
	 * @return bool
	 */
	public static function onChangeTagsListActive( array &$tags ) {
		return self::fetchAllTags( $tags, true );
	}

	/**
	 * @param DatabaseUpdater $updater
	 * @throws MWException
	 * @return bool
	 */
	public static function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater ) {
		$dir = dirname( __DIR__ );

		if ( $updater->getDB()->getType() == 'mysql' || $updater->getDB()->getType() == 'sqlite' ) {
			if ( $updater->getDB()->getType() == 'mysql' ) {
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
			if ( $updater->getDB()->getType() == 'mysql' ) {
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

			if ( $updater->getDB()->getType() == 'mysql' ) {
				$updater->addExtensionUpdate( [
					'addIndex', 'abuse_filter_log', 'wiki_timestamp',
					"$dir/db_patches/patch-global_logging_wiki-index.sql", true
				] );
			} else {
				$updater->addExtensionUpdate( [
					'addIndex', 'abuse_filter_log', 'afl_wiki_timestamp',
					"$dir/db_patches/patch-global_logging_wiki-index.sqlite.sql", true
				] );
			}

			if ( $updater->getDB()->getType() == 'mysql' ) {
				$updater->addExtensionUpdate( [
					'modifyField', 'abuse_filter_log', 'afl_namespace',
					"$dir/db_patches/patch-afl-namespace_int.sql", true
				] );
			} else {
				/*
				$updater->addExtensionUpdate( array(
					'modifyField',
					'abuse_filter_log',
					'afl_namespace',
					"$dir/db_patches/patch-afl-namespace_int.sqlite.sql",
					true
				) );
				 */
				/* @todo Modify a column in sqlite, which do not support such
				 * things create backup, drop, create with new schema, copy,
				 * drop backup or simply see
				 * https://www.mediawiki.org/wiki/Manual:SQLite#About_SQLite :
				 * Several extensions are known to have database update or
				 * installation issues with SQLite: AbuseFilter, ...
				 */
			}
		} elseif ( $updater->getDB()->getType() == 'postgres' ) {
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
				'addPgField', 'abuse_filter_log', 'afl_wiki', 'TEXT' ] );
			$updater->addExtensionUpdate( [
				'addPgField', 'abuse_filter_log', 'afl_deleted', 'SMALLINT' ] );
			$updater->addExtensionUpdate( [
				'changeField', 'abuse_filter_log', 'afl_filter', 'TEXT', '' ] );
			$updater->addExtensionUpdate( [
				'addPgExtIndex', 'abuse_filter_log', 'abuse_filter_log_ip', "(afl_ip)" ] );
			$updater->addExtensionUpdate( [
				'addPgExtIndex', 'abuse_filter_log', 'abuse_filter_log_wiki', "(afl_wiki)" ] );
			$updater->addExtensionUpdate( [
				'changeField', 'abuse_filter_log', 'afl_namespace', "INTEGER", '' ] );
			$updater->addExtensionUpdate( [
				'addPgField', 'abuse_filter', 'af_group', "TEXT NOT NULL DEFAULT 'default'" ] );
			$updater->addExtensionUpdate( [
				'addPgExtIndex', 'abuse_filter', 'abuse_filter_group_enabled_id',
				"(af_group, af_enabled, af_id)"
			] );
			$updater->addExtensionUpdate( [
				'addPgField', 'abuse_filter_history', 'afh_group', "TEXT" ] );
		}

		$updater->addExtensionUpdate( [ [ __CLASS__, 'createAbuseFilterUser' ] ] );

		return true;
	}

	/**
	 * Updater callback to create the AbuseFilter user after the user tables have been updated.
	 * @param DatabaseUpdater $updater
	 */
	public static function createAbuseFilterUser( $updater ) {
		$username = wfMessage( 'abusefilter-blocker' )->inContentLanguage()->text();
		$user = User::newFromName( $username );

		if ( $user && !$updater->updateRowExists( 'create abusefilter-blocker-user' ) ) {
			$user = User::newSystemUser( $username, [ 'steal' => true ] );
			$updater->insertUpdateRow( 'create abusefilter-blocker-user' );
			# Promote user so it doesn't look too crazy.
			$user->addGroup( 'sysop' );
		}
	}

	/**
	 * @param int $id
	 * @param Title $nt
	 * @param array &$tools
	 * @param SpecialPage $sp for context
	 */
	public static function onContributionsToolLinks( $id, $nt, array &$tools, SpecialPage $sp ) {
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
	 * Filter an upload.
	 *
	 * @param UploadBase $upload
	 * @param User $user
	 * @param array $props
	 * @param string $comment
	 * @param string $pageText
	 * @param array|ApiMessage &$error
	 * @return bool
	 */
	public static function onUploadVerifyUpload( UploadBase $upload, User $user,
		array $props, $comment, $pageText, &$error
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
	 * @param array $props File properties, as returned by FSFile::getPropsFromPath()
	 * @param string|null $summary Upload log comment (also used as edit summary)
	 * @param string|null $text File description page text (only used for new uploads)
	 * @param array|ApiMessage &$error
	 * @return bool
	 */
	public static function filterUpload( $action, UploadBase $upload, User $user,
		array $props, $summary, $text, &$error
	) {
		$title = $upload->getTitle();

		$vars = new AbuseFilterVariableHolder;
		$vars->addHolders(
			AbuseFilter::generateUserVars( $user ),
			AbuseFilter::generateTitleVars( $title, 'ARTICLE' )
		);
		$vars->setVar( 'ACTION', $action );

		// We use the hexadecimal version of the file sha1.
		// Use UploadBase::getTempFileSha1Base36 so that we don't have to calculate the sha1 sum again
		$sha1 = Wikimedia\base_convert( $upload->getTempFileSha1Base36(), 36, 16, 40 );

		$vars->setVar( 'file_sha1', $sha1 );
		$vars->setVar( 'file_size', $upload->getFileSize() );

		$vars->setVar( 'file_mime', $props['mime'] );
		$vars->setVar(
			'file_mediatype',
			MediaWiki\MediaWikiServices::getInstance()->getMimeAnalyzer()
				->getMediaType( null, $props['mime'] )
		);
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
					return true;
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
			$vars->setVar( 'minor_edit', false );
			$vars->setVar( 'old_wikitext', $oldtext );
			$vars->setVar( 'new_wikitext', $text );
			// TODO: set old_content and new_content vars, use them
			$vars->addHolders( AbuseFilter::getEditVars( $title, $page ) );
		}

		$filter_result = AbuseFilter::filterAction( $vars, $title );

		if ( !$filter_result->isOK() ) {
			$messageAndParams = $filter_result->getErrorsArray()[0];
			$apiResult = self::getApiResult( $filter_result );
			$error = ApiMessage::create(
				$messageAndParams,
				$apiResult['code'],
				$apiResult
			);
		}

		return $filter_result->isOK();
	}

	/**
	 * Adds global variables to the Javascript as needed
	 *
	 * @param array &$vars
	 * @return bool
	 */
	public static function onMakeGlobalVariablesScript( array &$vars ) {
		if ( isset( AbuseFilter::$editboxName ) && AbuseFilter::$editboxName !== null ) {
			$vars['abuseFilterBoxName'] = AbuseFilter::$editboxName;
		}

		if ( AbuseFilterViewExamine::$examineType !== null ) {
			$vars['abuseFilterExamine'] = [
				'type' => AbuseFilterViewExamine::$examineType,
				'id' => AbuseFilterViewExamine::$examineId,
			];
		}

		return true;
	}

	/**
	 * Tables that Extension:UserMerge needs to update
	 *
	 * @param array &$updateFields
	 * @return bool
	 */
	public static function onUserMergeAccountFields( array &$updateFields ) {
		$updateFields[] = [ 'abuse_filter', 'af_user', 'af_user_text' ];
		$updateFields[] = [ 'abuse_filter_log', 'afl_user', 'afl_user_text' ];
		$updateFields[] = [ 'abuse_filter_history', 'afh_user', 'afh_user_text' ];

		return true;
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
		WikiPage $page, Content $content, ParserOutput $output, $summary = '', $user = null
	) {
		$revision = $page->getRevision();
		if ( !$revision ) {
			return;
		}

		$text = AbuseFilter::contentToString( $content );
		$oldcontent = $revision->getContent( Revision::RAW );
		$user = $user ?: RequestContext::getMain()->getUser();

		// Cache any resulting filter matches.
		// Do this outside the synchronous stash lock to avoid any chance of slowdown.
		DeferredUpdates::addCallableUpdate(
			function () use ( $user, $page, $summary, $content, $oldcontent, $text ) {
				$vars = self::newVariableHolderForEdit(
					$user, $page->getTitle(), $page, $summary, $content, $oldcontent, $text
				);
				AbuseFilter::filterAction( $vars, $page->getTitle(), 'default', $user, 'stash' );
			},
			DeferredUpdates::PRESEND
		);
	}
}