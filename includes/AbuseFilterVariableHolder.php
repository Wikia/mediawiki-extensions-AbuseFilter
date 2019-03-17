<?php

class AbuseFilterVariableHolder {
	/** @var (AFPData|AFComputedVariable)[] */
	public $mVars = [];

	/** @var string[] Variables used to store meta-data, we'd better be safe. See T191715 */
	public static $varBlacklist = [ 'global_log_ids', 'local_log_ids' ];

	/** @var bool Whether this object is being used for an ongoing action being filtered */
	public $forFilter = false;

	/** @var int 2 is the default and means that new variables names (from T173889) should be used.
	 *    1 means that the old ones should be used, e.g. if this object is constructed from an
	 *    afl_var_dump which still bears old variables.
	 */
	public $mVarsVersion = 2;

	/**
	 * @param string $variable
	 * @param mixed $datum
	 */
	public function setVar( $variable, $datum ) {
		$variable = strtolower( $variable );
		if ( !( $datum instanceof AFPData || $datum instanceof AFComputedVariable ) ) {
			$datum = AFPData::newFromPHPVar( $datum );
		}

		$this->mVars[$variable] = $datum;
	}

	/**
	 * @param string $variable
	 * @param string $method
	 * @param array $parameters
	 */
	public function setLazyLoadVar( $variable, $method, $parameters ) {
		$placeholder = new AFComputedVariable( $method, $parameters );
		$this->setVar( $variable, $placeholder );
	}

	/**
	 * Get a variable from the current object
	 *
	 * @param string $variable
	 * @return AFPData
	 */
	public function getVar( $variable ) {
		$variable = strtolower( $variable );
		if ( $this->mVarsVersion === 1 && in_array( $variable, AbuseFilter::getDeprecatedVariables() ) ) {
			// Variables are stored with old names, but the parser has given us
			// a new name. Translate it back.
			$variable = array_search( $variable, AbuseFilter::getDeprecatedVariables() );
		}
		if ( isset( $this->mVars[$variable] ) ) {
			if ( $this->mVars[$variable] instanceof AFComputedVariable ) {
				/** @suppress PhanUndeclaredMethod False positive */
				$value = $this->mVars[$variable]->compute( $this );
				$this->setVar( $variable, $value );
				return $value;
			} elseif ( $this->mVars[$variable] instanceof AFPData ) {
				return $this->mVars[$variable];
			}
		} elseif ( array_key_exists( $variable, AbuseFilter::$disabledVars ) ) {
			wfWarn( "Disabled variable $variable requested. Please fix the filter as this will " .
				"be removed soon." );
		}
		return new AFPData();
	}

	/**
	 * @return AbuseFilterVariableHolder
	 */
	public static function merge() {
		$newHolder = new AbuseFilterVariableHolder;
		$newHolder->addHolders( ...func_get_args() );

		return $newHolder;
	}

	/**
	 * Merge any number of holders given as arguments into this holder.
	 *
	 * @throws MWException
	 */
	public function addHolders() {
		$holders = func_get_args();

		foreach ( $holders as $addHolder ) {
			if ( !is_object( $addHolder ) ) {
				throw new MWException( 'Invalid argument to AbuseFilterVariableHolder::addHolders' );
			}
			$this->mVars = array_merge( $this->mVars, $addHolder->mVars );
		}
	}

	/**
	 * Export all variables stored in this object as string
	 *
	 * @return string[]
	 */
	public function exportAllVars() {
		$exported = [];
		foreach ( array_keys( $this->mVars ) as $varName ) {
			if ( !in_array( $varName, self::$varBlacklist ) ) {
				$exported[$varName] = $this->getVar( $varName )->toString();
			}
		}

		return $exported;
	}

	/**
	 * Export all non-lazy variables stored in this object as string
	 *
	 * @return string[]
	 */
	public function exportNonLazyVars() {
		$exported = [];
		foreach ( $this->mVars as $varName => $data ) {
			if (
				!( $data instanceof AFComputedVariable )
				&& !in_array( $varName, self::$varBlacklist )
			) {
				$exported[$varName] = $this->getVar( $varName )->toString();
			}
		}

		return $exported;
	}

	/**
	 * Dump all variables stored in this object in their native types.
	 * If you want a not yet set variable to be included in the results you can
	 * either set $compute to an array with the name of the variable or set
	 * $compute to true to compute all not yet set variables.
	 *
	 * @param array|bool $compute Variables we should copute if not yet set
	 * @param bool $includeUserVars Include user set variables
	 * @return array
	 */
	public function dumpAllVars( $compute = [], $includeUserVars = false ) {
		$coreVariables = [];

		if ( !$includeUserVars ) {
			// Compile a list of all variables set by the extension to be able
			// to filter user set ones by name
			global $wgRestrictionTypes;

			$activeVariables = array_keys( AbuseFilter::getBuilderValues()['vars'] );
			$deprecatedVariables = array_keys( AbuseFilter::getDeprecatedVariables() );
			$coreVariables = array_merge( $activeVariables, $deprecatedVariables );

			// @todo _restrictions variables should be handled in builderValues as well.
			$prefixes = [ 'moved_from', 'moved_to', 'page' ];
			foreach ( $wgRestrictionTypes as $action ) {
				foreach ( $prefixes as $prefix ) {
					$coreVariables[] = "{$prefix}_restrictions_$action";
				}
			}

			$coreVariables = array_map( 'strtolower', $coreVariables );
		}

		$exported = [];
		foreach ( array_keys( $this->mVars ) as $varName ) {
			if (
				( $includeUserVars || in_array( strtolower( $varName ), $coreVariables ) ) &&
				// Only include variables set in the extension in case $includeUserVars is false
				!in_array( $varName, self::$varBlacklist ) &&
				( $compute === true ||
					( is_array( $compute ) && in_array( $varName, $compute ) ) ||
					$this->mVars[$varName] instanceof AFPData
				)
			) {
				$exported[$varName] = $this->getVar( $varName )->toNative();
			}
		}

		return $exported;
	}

	/**
	 * @param string $var
	 * @return bool
	 */
	public function varIsSet( $var ) {
		return array_key_exists( $var, $this->mVars );
	}

	/**
	 * Compute all vars which need DB access. Useful for vars which are going to be saved
	 * cross-wiki or used for offline analysis.
	 */
	public function computeDBVars() {
		static $dbTypes = [
			'links-from-wikitext-or-database',
			'load-recent-authors',
			'page-age',
			'get-page-restrictions',
			'simple-user-accessor',
			'user-age',
			'user-groups',
			'user-rights',
			'revision-text-by-id',
			'revision-text-by-timestamp'
		];

		foreach ( $this->mVars as $name => $value ) {
			if ( $value instanceof AFComputedVariable &&
				in_array( $value->mMethod, $dbTypes )
			) {
				$value = $value->compute( $this );
				$this->setVar( $name, $value );
			}
		}
	}
}
