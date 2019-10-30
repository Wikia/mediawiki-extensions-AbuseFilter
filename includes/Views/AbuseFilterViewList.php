<?php

use MediaWiki\MediaWikiServices;

/**
 * The default view used in Special:AbuseFilter
 */
class AbuseFilterViewList extends AbuseFilterView {
	/**
	 * Shows the page
	 */
	public function show() {
		$out = $this->getOutput();
		$request = $this->getRequest();
		$config = $this->getConfig();
		$user = $this->getUser();

		// Show filter performance statistics
		$this->showStatus();

		$out->addWikiMsg( 'abusefilter-intro' );

		// New filter button
		if ( AbuseFilter::canEdit( $user ) ) {
			$out->enableOOUI();
			$link = new OOUI\ButtonWidget( [
				'label' => $this->msg( 'abusefilter-new' )->text(),
				'href' => $this->getTitle( 'new' )->getFullURL(),
			] );
			$out->addHTML( $link );
		}

		$conds = [];
		$deleted = $request->getVal( 'deletedfilters' );
		$furtherOptions = $request->getArray( 'furtheroptions', [] );
		// Backward compatibility with old links
		if ( $request->getBool( 'hidedisabled' ) ) {
			$furtherOptions[] = 'hidedisabled';
		}
		if ( $request->getBool( 'hideprivate' ) ) {
			$furtherOptions[] = 'hideprivate';
		}
		$defaultscope = 'all';
		if ( $config->get( 'AbuseFilterCentralDB' ) !== null
				&& !$config->get( 'AbuseFilterIsCentral' ) ) {
			// Show on remote wikis as default only local filters
			$defaultscope = 'local';
		}
		$scope = $request->getVal( 'rulescope', $defaultscope );

		$searchEnabled = AbuseFilter::canViewPrivate( $user ) && !(
			$config->get( 'AbuseFilterCentralDB' ) !== null &&
			!$config->get( 'AbuseFilterIsCentral' ) &&
			$scope === 'global' );

		if ( $searchEnabled ) {
			$querypattern = $request->getVal( 'querypattern', '' );
			$searchmode = $request->getVal( 'searchoption', 'LIKE' );
		} else {
			$querypattern = '';
			$searchmode = '';
		}

		if ( $deleted === 'show' ) {
			// Nothing
		} elseif ( $deleted === 'only' ) {
			$conds['af_deleted'] = 1;
		} else {
			// hide, or anything else.
			$conds['af_deleted'] = 0;
			$deleted = 'hide';
		}
		if ( in_array( 'hidedisabled', $furtherOptions ) ) {
			$conds['af_deleted'] = 0;
			$conds['af_enabled'] = 1;
		}
		if ( in_array( 'hideprivate', $furtherOptions ) ) {
			$conds['af_hidden'] = 0;
		}

		if ( $scope === 'local' ) {
			$conds['af_global'] = 0;
		} elseif ( $scope === 'global' ) {
			$conds['af_global'] = 1;
		}

		if ( $querypattern !== '' ) {
			// Check the search pattern. Filtering the results is done in AbuseFilterPager
			$error = null;
			if ( !in_array( $searchmode, [ 'LIKE', 'RLIKE', 'IRLIKE' ] ) ) {
				$error = 'abusefilter-list-invalid-searchmode';
			} elseif ( $searchmode !== 'LIKE' && !StringUtils::isValidPCRERegex( "/$querypattern/" ) ) {
				$error = 'abusefilter-list-regexerror';
			}

			if ( $error !== null ) {
				$out->addHTML(
					Xml::tags(
						'p',
						null,
						Html::errorBox( $this->msg( $error )->escaped() )
					)
				);

				// Reset the conditions in case of error
				$conds = [ 'af_deleted' => 0 ];
				$querypattern = '';
			}
		}

		$this->showList(
			compact(
				'deleted',
				'furtherOptions',
				'querypattern',
				'searchmode',
				'scope',
				'searchEnabled'
			),
			$conds
		);
	}

	/**
	 * @param array $optarray
	 * @param array $conds
	 */
	private function showList( array $optarray, array $conds = [ 'af_deleted' => 0 ] ) {
		$config = $this->getConfig();
		$this->getOutput()->addHTML(
			Xml::tags( 'h2', null, $this->msg( 'abusefilter-list' )->parse() )
		);

		$deleted = $optarray['deleted'];
		$furtherOptions = $optarray['furtherOptions'];
		$scope = $optarray['scope'];
		$searchEnabled = $optarray['searchEnabled'];
		$querypattern = $optarray['querypattern'];
		$searchmode = $optarray['searchmode'];

		if (
			$config->get( 'AbuseFilterCentralDB' ) !== null
			&& !$config->get( 'AbuseFilterIsCentral' )
			&& $scope === 'global'
		) {
			$pager = new GlobalAbuseFilterPager(
				$this,
				$conds,
				$this->linkRenderer
			);
		} else {
			$pager = new AbuseFilterPager(
				$this,
				$conds,
				$this->linkRenderer,
				$querypattern,
				$searchmode
			);
		}

		// Options form
		$formDescriptor = [];
		$formDescriptor['deletedfilters'] = [
			'name' => 'deletedfilters',
			'type' => 'radio',
			'flatlist' => true,
			'label-message' => 'abusefilter-list-options-deleted',
			'options-messages' => [
				'abusefilter-list-options-deleted-show' => 'show',
				'abusefilter-list-options-deleted-hide' => 'hide',
				'abusefilter-list-options-deleted-only' => 'only',
			],
			'default' => $deleted,
		];

		if ( $config->get( 'AbuseFilterCentralDB' ) !== null ) {
			$optionsMsg = [
				'abusefilter-list-options-scope-local' => 'local',
				'abusefilter-list-options-scope-global' => 'global',
			];
			if ( $config->get( 'AbuseFilterIsCentral' ) ) {
				// For central wiki: add third scope option
				$optionsMsg['abusefilter-list-options-scope-all'] = 'all';
			}
			$formDescriptor['rulescope'] = [
				'name' => 'rulescope',
				'type' => 'radio',
				'flatlist' => true,
				'label-message' => 'abusefilter-list-options-scope',
				'options-messages' => $optionsMsg,
				'default' => $scope,
			];
		}

		$formDescriptor['furtheroptions'] = [
			'name' => 'furtheroptions',
			'type' => 'multiselect',
			'label-message' => 'abusefilter-list-options-further-options',
			'flatlist' => true,
			'options' => [
				$this->msg( 'abusefilter-list-options-hideprivate' )->parse() => 'hideprivate',
				$this->msg( 'abusefilter-list-options-hidedisabled' )->parse() => 'hidedisabled',
			],
			'default' => $furtherOptions
		];

		// ToDo: Since this is only for saving space, we should convert it to use a 'hide-if'
		if ( $searchEnabled ) {
			$formDescriptor['querypattern'] = [
				'name' => 'querypattern',
				'type' => 'text',
				'label-message' => 'abusefilter-list-options-searchfield',
				'placeholder' => $this->msg( 'abusefilter-list-options-searchpattern' )->text(),
				'default' => $querypattern
			];

			$formDescriptor['searchoption'] = [
				'name' => 'searchoption',
				'type' => 'radio',
				'flatlist' => true,
				'label-message' => 'abusefilter-list-options-searchoptions',
				'options-messages' => [
					'abusefilter-list-options-search-like' => 'LIKE',
					'abusefilter-list-options-search-rlike' => 'RLIKE',
					'abusefilter-list-options-search-irlike' => 'IRLIKE',
				],
				'default' => $searchmode
			];
		}

		$formDescriptor['limit'] = [
			'name' => 'limit',
			'type' => 'select',
			'label-message' => 'abusefilter-list-limit',
			'options' => $pager->getLimitSelectList(),
			'default' => $pager->getLimit(),
		];

		HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() )
			->addHiddenField( 'title', $this->getTitle()->getPrefixedDBkey() )
			->setAction( $this->getTitle()->getFullURL() )
			->setWrapperLegendMsg( 'abusefilter-list-options' )
			->setSubmitTextMsg( 'abusefilter-list-options-submit' )
			->setMethod( 'get' )
			->prepareForm()
			->displayForm( false );

		$this->getOutput()->addHTML(
			$pager->getNavigationBar() .
			$pager->getBody() .
			$pager->getNavigationBar()
		);
	}

	/**
	 * Generates a summary of filter activity using the internal statistics.
	 */
	public function showStatus() {
		$stash = MediaWikiServices::getInstance()->getMainObjectStash();

		$totalCount = 0;
		$matchCount = 0;
		$overflowCount = 0;
		foreach ( $this->getConfig()->get( 'AbuseFilterValidGroups' ) as $group ) {
			$profile = $stash->get( AbuseFilter::filterProfileGroupKey( $group ) );
			if ( $profile !== false ) {
				$totalCount += $profile[ 'total' ];
				$overflowCount += $profile[ 'overflow' ];
				$matchCount += $profile[ 'matches' ];
			}
		}

		if ( $totalCount > 0 ) {
			$overflowPercent = round( 100 * $overflowCount / $totalCount, 2 );
			$matchPercent = round( 100 * $matchCount / $totalCount, 2 );

			$status = $this->msg( 'abusefilter-status' )
				->numParams(
					$totalCount,
					$overflowCount,
					$overflowPercent,
					$this->getConfig()->get( 'AbuseFilterConditionLimit' ),
					$matchCount,
					$matchPercent
				)->parse();

			$status = Xml::tags( 'div', [ 'class' => 'mw-abusefilter-status' ], $status );
			$this->getOutput()->addHTML( $status );
		}
	}
}
