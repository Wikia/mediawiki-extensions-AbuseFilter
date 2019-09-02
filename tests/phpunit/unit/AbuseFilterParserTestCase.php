<?php
/**
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
 *
 * @license GPL-2.0-or-later
 */

/**
 * Helper for parser-related tests
 */
abstract class AbuseFilterParserTestCase extends MediaWikiUnitTestCase {
	/**
	 * @return AbuseFilterParser[]
	 */
	protected function getParsers() {
		static $parsers = null;
		if ( !$parsers ) {
			// We're not interested in caching or logging; tests should call respectively setCache
			// and setLogger if they want to test any of those.
			$contLang = new LanguageEn();
			$cache = new EmptyBagOStuff();
			$logger = new \Psr\Log\NullLogger();

			$parser = new AbuseFilterParser( $contLang, $cache, $logger );
			$parser->toggleConditionLimit( false );
			$cachingParser = new AbuseFilterCachingParser( $contLang, $cache, $logger );
			$cachingParser->toggleConditionLimit( false );
			$parsers = [ $parser, $cachingParser ];
		} else {
			// Reset so that already executed tests don't influence new ones
			$parsers[0]->resetState();
			$parsers[0]->clearFuncCache();
			$parsers[1]->resetState();
			$parsers[1]->clearFuncCache();
		}
		return $parsers;
	}

	/**
	 * Base method for testing exceptions
	 *
	 * @param string $excep Identifier of the exception (e.g. 'unexpectedtoken')
	 * @param string $expr The expression to test
	 * @param string $caller The function where the exception is thrown, if available
	 *  The method may be different in Parser and CachingParser, but this parameter is
	 *  just used for debugging purposes.
	 */
	protected function exceptionTest( $excep, $expr, $caller ) {
		foreach ( $this->getParsers() as $parser ) {
			$pname = get_class( $parser );
			try {
				$parser->parse( $expr );
			} catch ( AFPUserVisibleException $e ) {
				$this->assertEquals(
					$excep,
					$e->mExceptionID,
					"Exception $excep not thrown in $caller. Parser: $pname."
				);
				continue;
			}

			$this->fail( "Exception $excep not thrown in $caller. Parser: $pname." );
		}
	}
}