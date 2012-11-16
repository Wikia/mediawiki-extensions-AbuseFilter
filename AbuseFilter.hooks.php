<?php

class AbuseFilterHooks {
	static $successful_action_vars = false;
	/** @var WikiPage|Article|bool */
	static $last_edit_page = false; // make sure edit filter & edit save hooks match
	// So far, all of the error message out-params for these hooks accept HTML.
	// Hooray!

	/**
	 * Entry points for MediaWiki hook 'EditFilterMerged'
	 *
	 * @param $editor EditPage instance (object)
	 * @param $text string Content of the edit box
	 * @param &$error string Error message to return
	 * @param $summary string Edit summary for page
	 * @return bool
	 */
	public static function onEditFilterMerged( $editor, $text, &$error, $summary ) {
		// Load vars
		$vars = new AbuseFilterVariableHolder;

		# Replace line endings so the filter won't get confused as $text
		# was not processed by Parser::preSaveTransform (bug 20310)
		$text = str_replace( "\r\n", "\n", $text );

		self::$successful_action_vars = false;
		self::$last_edit_page = false;

		// Check for null edits.
		$oldtext = '';

		$article = $editor->getArticle();
		if ( $article->exists() ) {
			// Make sure we load the latest text saved in database (bug 31656)
			$revision = $article->getRevision();
			if ( !$revision ) {
				return true;
			}
			$oldtext = AbuseFilter::revisionToString( $revision, Revision::RAW );
		}

		// Cache article object so we can share a parse operation
		$title = $editor->mTitle;
		$articleCacheKey = $title->getNamespace() . ':' . $title->getText();
		AFComputedVariable::$articleCache[$articleCacheKey] = $article;

		if ( strcmp( $oldtext, $text ) == 0 ) {
			// Don't trigger for null edits.
			return true;
		}

		global $wgUser;
		$vars->addHolder( AbuseFilter::generateUserVars( $wgUser ) );
		$vars->addHolder( AbuseFilter::generateTitleVars( $title , 'article' ) );

		$vars->setVar( 'action', 'edit' );
		$vars->setVar( 'summary', $summary );
		$vars->setVar( 'minor_edit', $editor->minoredit );

		$vars->setVar( 'old_wikitext', $oldtext );
		$vars->setVar( 'new_wikitext', $text );

		$vars->addHolder( AbuseFilter::getEditVars( $title, $article ) );

		$filter_result = AbuseFilter::filterAction( $vars, $title );

		if ( $filter_result !== true ) {
			global $wgOut;
			$wgOut->addHTML( $filter_result );
			$editor->showEditForm();
			return false;
		}

		self::$successful_action_vars = $vars;
		self::$last_edit_page = $editor->mArticle;

		return true;
	}

	public static function onArticleSaveComplete(
		&$article, &$user, $text, $summary, $minoredit, $watchthis, $sectionanchor,
		&$flags, $revision
	) {
		if ( ! self::$successful_action_vars || ! $revision ) {
			self::$successful_action_vars = false;
			return true;
		}

		$vars = self::$successful_action_vars;

		if ( ( $vars->getVar('article_prefixedtext')->toString() !==
			$article->getTitle()->getPrefixedText() ) ||
			( $vars->getVar('summary')->toString() !== $summary )
		) {
			return true;
		}

		if ( !self::identicalPageObjects( $article, self::$last_edit_page ) ) {
			return true; // this isn't the edit $successful_action_vars was set for
		}
		self::$last_edit_page = false;

		if ( $vars->getVar('local_log_ids') ) {
			// Now actually do our storage
			$log_ids = $vars->getVar('local_log_ids')->toNative();
			$dbw = wfGetDB( DB_MASTER );

			if ( count($log_ids) ) {
				$dbw->update( 'abuse_filter_log',
					array( 'afl_rev_id' => $revision->getId() ),
					array( 'afl_id' => $log_ids ),
					__METHOD__
				);
			}
		}

		if ( $vars->getVar('global_log_ids') ) {
			$log_ids = $vars->getVar('global_log_ids')->toNative();

			global $wgAbuseFilterCentralDB;
			$fdb = wfGetDB( DB_MASTER, array(), $wgAbuseFilterCentralDB );

			if ( count($log_ids) ) {
				$fdb->update( 'abuse_filter_log',
					array( 'afl_rev_id' => $revision->getId() ),
					array( 'afl_id' => $log_ids, 'afl_wiki' => wfWikiId() ),
					__METHOD__
				);
			}
		}

		return true;
	}

	/**
	 * Check if two article objects are identical or have an identical WikiPage
	 * @param $page1 Article|WikiPage
	 * @param $page2 Article|WikiPage
	 * @return bool
	 */
	protected static function identicalPageObjects( $page1, $page2 ) {
		if ( 	( class_exists('MWInit') && MWInit::methodExists( 'Article', 'getPage' ) ) ||
			( !class_exists('MWInit') && method_exists('Article', 'getPage') )
		) {
			$wpage1 = ( $page1 instanceof Article ) ? $page1->getPage() : $page1;
			$wpage2 = ( $page2 instanceof Article ) ? $page2->getPage() : $page2;
			return ( $wpage1 === $wpage2 );
		} else { // b/c for before WikiPage
			return ( $page1 === $page2 ); // should be two Article objects
		}
	}

	/**
	 * @param $user
	 * @param $promote
	 * @return bool
	 */
	public static function onGetAutoPromoteGroups( $user, &$promote ) {
		global $wgMemc;

		$key = AbuseFilter::autoPromoteBlockKey( $user );

		if ( $wgMemc->get( $key ) ) {
			$promote = array();
		}

		return true;
	}

	/**
	 * @param $oldTitle Title
	 * @param $newTitle Title
	 * @param $user User
	 * @param $error
	 * @param $reason
	 * @return bool
	 */
	public static function onAbortMove( $oldTitle, $newTitle, $user, &$error, $reason ) {
		$vars = new AbuseFilterVariableHolder;

		global $wgUser;
		$vars->addHolder(
			AbuseFilterVariableHolder::merge(
				AbuseFilter::generateUserVars( $wgUser ),
				AbuseFilter::generateTitleVars( $oldTitle, 'MOVED_FROM' ),
				AbuseFilter::generateTitleVars( $newTitle, 'MOVED_TO' )
			)
		);
		$vars->setVar( 'SUMMARY', $reason );
		$vars->setVar( 'ACTION', 'move' );

		$filter_result = AbuseFilter::filterAction( $vars, $oldTitle );

		$error = $filter_result;

		return $filter_result == '' || $filter_result === true;
	}

	/**
	 * @param $article Article
	 * @param $user User
	 * @param $reason string
	 * @param $error
	 * @return bool
	 */
	public static function onArticleDelete( &$article, &$user, &$reason, &$error ) {
		$vars = new AbuseFilterVariableHolder;

		global $wgUser;
		$vars->addHolder( AbuseFilter::generateUserVars( $wgUser ) );
		$vars->addHolder( AbuseFilter::generateTitleVars( $article->getTitle(), 'ARTICLE' ) );
		$vars->setVar( 'SUMMARY', $reason );
		$vars->setVar( 'ACTION', 'delete' );

		$filter_result = AbuseFilter::filterAction( $vars, $article->getTitle() );

		$error = $filter_result;

		return $filter_result == '' || $filter_result === true;
	}

	/**
	 * @param $user User
	 * @param $message
	 * @param $autocreate bool Indicates whether the account is created automatically.
	 * @return bool
	 */
	private static function checkNewAccount( $user, &$message, $autocreate ) {
		if ( $user->getName() == wfMessage( 'abusefilter-blocker' )->inContentLanguage()->text() ) {
			$message = wfMessage( 'abusefilter-accountreserved' )->text();

			return false;
		}

		$vars = new AbuseFilterVariableHolder;

		// Add variables only for a registered user, so IP addresses of
		// new users won't be exposed
		global $wgUser;
		if ( $wgUser->getId() ) {
			$vars->addHolder( AbuseFilter::generateUserVars( $wgUser ) );
		}

		$vars->setVar( 'ACTION', $autocreate ? 'autocreateaccount' : 'createaccount' );
		$vars->setVar( 'ACCOUNTNAME', $user->getName() );

		$filter_result = AbuseFilter::filterAction(
			$vars, SpecialPage::getTitleFor( 'Userlogin' ) );

		$message = $filter_result;

		return $filter_result == '' || $filter_result === true;
	}

	/**
	 * @param $user User
	 * @param $message
	 * @return bool
	 */
	public static function onAbortNewAccount( $user, &$message ) {
		return self::checkNewAccount( $user, $message, false );
	}

	/**
	 * @param $user User
	 * @param $message
	 * @return bool
	 */
	public static function onAbortAutoAccount( $user, &$message ) {
		// FIXME: ERROR MESSAGE IS SHOWN IN A WEIRD WAY, BEACUSE $message
		// HERE MEANS NAME OF THE MESSAGE, NOT THE TEXT OF THE MESSAGE AS
		// IN AbortNewAccount HOOK WHICH WE CANNOT PROVIDE!
		return self::checkNewAccount( $user, $message, true );
	}

	/**
	 * @param $recentChange RecentChange
	 * @return bool
	 */
	public static function onRecentChangeSave( $recentChange ) {
		$title = Title::makeTitle(
			$recentChange->getAttribute( 'rc_namespace' ),
			$recentChange->getAttribute( 'rc_title' )
		);
		$action = $recentChange->mAttribs['rc_log_type'] ?
			$recentChange->mAttribs['rc_log_type'] : 'edit';
		$actionID = implode( '-', array(
				$title->getPrefixedText(), $recentChange->mAttribs['rc_user_text'], $action
			) );

		if ( !empty( AbuseFilter::$tagsToSet[$actionID] )
			&& count( $tags = AbuseFilter::$tagsToSet[$actionID] ) )
		{
			ChangeTags::addTags(
				$tags,
				$recentChange->mAttribs['rc_id'],
				$recentChange->mAttribs['rc_this_oldid'],
				$recentChange->mAttribs['rc_logid']
			);
		}

		return true;
	}

	/**
	 * @param $emptyTags array
	 * @return bool
	 */
	public static function onListDefinedTags( &$emptyTags ) {
		# This is a pretty awful hack.
		$dbr = wfGetDB( DB_SLAVE );

		$res = $dbr->select(
			array( 'abuse_filter_action', 'abuse_filter' ),
			'afa_parameters',
			array( 'afa_consequence' => 'tag', 'af_enabled' => true ),
			__METHOD__,
			array(),
			array( 'abuse_filter' => array( 'INNER JOIN', 'afa_filter=af_id' ) )
		);

		foreach ( $res as $row ) {
			$emptyTags = array_filter(
				array_merge( explode( "\n", $row->afa_parameters ), $emptyTags )
			);
		}

		return true;
	}

	/**
	 * @param $updater DatabaseUpdater
	 * @throws MWException
	 * @return bool
	 */
	public static function onLoadExtensionSchemaUpdates( $updater = null ) {
		$dir = dirname( __FILE__ );

		if ( $updater->getDB()->getType() == 'mysql' || $updater->getDB()->getType() == 'sqlite' ) {
			if ( $updater->getDB()->getType() == 'mysql' ) {
				$updater->addExtensionUpdate( array( 'addTable', 'abuse_filter', "$dir/abusefilter.tables.sql", true ) );
				$updater->addExtensionUpdate( array( 'addTable', 'abuse_filter_history', "$dir/db_patches/patch-abuse_filter_history.sql", true ) );
			} else {
				$updater->addExtensionUpdate( array( 'addTable', 'abuse_filter', "$dir/abusefilter.tables.sqlite.sql", true ) );
				$updater->addExtensionUpdate( array( 'addTable', 'abuse_filter_history', "$dir/db_patches/patch-abuse_filter_history.sqlite.sql", true ) );
			}
			$updater->addExtensionUpdate( array( 'addField', 'abuse_filter_history', 'afh_changed_fields', "$dir/db_patches/patch-afh_changed_fields.sql", true ) );
			$updater->addExtensionUpdate( array( 'addField', 'abuse_filter', 'af_deleted', "$dir/db_patches/patch-af_deleted.sql", true ) );
			$updater->addExtensionUpdate( array( 'addField', 'abuse_filter', 'af_actions', "$dir/db_patches/patch-af_actions.sql", true ) );
			$updater->addExtensionUpdate( array( 'addField', 'abuse_filter', 'af_global', "$dir/db_patches/patch-global_filters.sql", true ) );
			$updater->addExtensionUpdate( array( 'addField', 'abuse_filter_log', 'afl_rev_id', "$dir/db_patches/patch-afl_action_id.sql", true ) );
			if ( $updater->getDB()->getType() == 'mysql' ) {
				$updater->addExtensionUpdate( array( 'addIndex', 'abuse_filter_log', 'filter_timestamp', "$dir/db_patches/patch-fix-indexes.sql", true ) );
			} else {
				$updater->addExtensionUpdate( array( 'addIndex', 'abuse_filter_log', 'afl_filter_timestamp', "$dir/db_patches/patch-fix-indexes.sqlite.sql", true ) );
			}

			$updater->addExtensionUpdate( array('addField', 'abuse_filter', 'af_group', "$dir/db_patches/patch-af_group.sql", true ) );

			if ( $updater->getDB()->getType() == 'mysql' ) {
				$updater->addExtensionUpdate( array( 'addIndex', 'abuse_filter_log', 'wiki_timestamp', "$dir/db_patches/patch-global_logging_wiki-index.sql", true ) );
			} else {
				$updater->addExtensionUpdate( array( 'addIndex', 'abuse_filter_log', 'afl_wiki_timestamp', "$dir/db_patches/patch-global_logging_wiki-index.sqlite.sql", true ) );
			}

		} elseif ( $updater->getDB()->getType() == 'postgres' ) {
			$updater->addExtensionUpdate( array( 'addTable', 'abuse_filter', "$dir/abusefilter.tables.pg.sql", true ) );
			$updater->addExtensionUpdate( array( 'addTable', 'abuse_filter_history', "$dir/db_patches/patch-abuse_filter_history.pg.sql", true ) );
			$updater->addExtensionUpdate( array( 'addPgField', 'abuse_filter', 'af_actions', "TEXT NOT NULL DEFAULT ''" ) );
			$updater->addExtensionUpdate( array( 'addPgField', 'abuse_filter', 'af_deleted', 'SMALLINT NOT NULL DEFAULT 0' ) );
			$updater->addExtensionUpdate( array( 'addPgField', 'abuse_filter', 'af_global', 'SMALLINT NOT NULL DEFAULT 0' ) );
			$updater->addExtensionUpdate( array( 'addPgField', 'abuse_filter_log', 'afl_wiki', 'TEXT' ) );
			$updater->addExtensionUpdate( array( 'addPgField', 'abuse_filter_log', 'afl_deleted', 'SMALLINT' ) );
			$updater->addExtensionUpdate( array( 'changeField', 'abuse_filter_log', 'afl_filter', 'TEXT', '' ) );
			$updater->addExtensionUpdate( array( 'addPgExtIndex', 'abuse_filter_log', 'abuse_filter_log_ip', "(afl_ip)" ) );
			$updater->addExtensionUpdate( array( 'addPgExtIndex', 'abuse_filter_log', 'abuse_filter_log_wiki', "(afl_wiki)" ) );
		}

		$updater->addExtensionUpdate( array( array( __CLASS__, 'createAbuseFilterUser' ) ) );

		return true;
	}

	/**
	 * Updater callback to create the AbuseFilter user after the user tables have been updated.
	 * @param $updater DatabaseUpdater
	 */
	public static function createAbuseFilterUser( $updater ) {
		$user = User::newFromName( wfMessage( 'abusefilter-blocker' )->inContentLanguage()->text() );

		if ( $user && !$updater->updateRowExists( 'create abusefilter-blocker-user' ) ) {
			if ( !$user->getId() ) {
				$user->addToDatabase();
				$user->saveSettings();
				# Increment site_stats.ss_users
				$ssu = new SiteStatsUpdate( 0, 0, 0, 0, 1 );
				$ssu->doUpdate();
			} else {
				// Sorry dude, we need this account.
				$user->setPassword( null );
				$user->setEmail( null );
				$user->saveSettings();
			}
			$updater->insertUpdateRow( 'create abusefilter-blocker-user' );
			# Promote user so it doesn't look too crazy.
			$user->addGroup( 'sysop' );
		}
	}

	/**
	 * @param $id
	 * @param $nt Title
	 * @param $tools
	 * @return bool
	 */
	public static function onContributionsToolLinks( $id, $nt, &$tools ) {
		global $wgUser;
		if ( $wgUser->isAllowed( 'abusefilter-log' ) ) {
				$tools[] = Linker::link(
					SpecialPage::getTitleFor( 'AbuseLog' ),
					wfMessage( 'abusefilter-log-linkoncontribs' )->text(),
					array( 'title' => wfMessage( 'abusefilter-log-linkoncontribs-text' )->parse() ),
					array( 'wpSearchUser' => $nt->getText() )
				);
		}
		return true;
	}

	/**
	 * @param $saveName
	 * @param $tempName
	 * @param $error
	 * @return bool
	 */
	public static function onUploadVerification( $saveName, $tempName, &$error ) {
		$vars = new AbuseFilterVariableHolder;

		global $wgUser;
		$title = Title::makeTitle( NS_FILE, $saveName );
		$vars->addHolder(
			AbuseFilterVariableHolder::merge(
				AbuseFilter::generateUserVars( $wgUser ),
				AbuseFilter::generateTitleVars( $title, 'FILE' )
			)
		);

		$vars->setVar( 'ACTION', 'upload' );
		$vars->setVar( 'file_sha1', sha1_file( $tempName ) ); // TODO share with save

		$filter_result = AbuseFilter::filterAction( $vars, $title );

		if ( is_string( $filter_result ) ) {
			$error = $filter_result;
		}

		return $filter_result == '' || $filter_result === true;
	}

	/**
	 * Adds global variables to the Javascript as needed
	 *
	 * @param array $vars
	 * @return bool
	 */
	public static function onMakeGlobalVariablesScript( array &$vars ) {
		if ( AbuseFilter::$editboxName !== null ) {
			$vars['abuseFilterBoxName'] = AbuseFilter::$editboxName;
		}

		if ( AbuseFilterViewExamine::$examineType !== null ) {
			$vars['abuseFilterExamine'] = array(
				'type' => AbuseFilterViewExamine::$examineType,
				'id' => AbuseFilterViewExamine::$examineId,
			);
		}
		return true;
	}
}
