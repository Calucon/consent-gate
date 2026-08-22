<?php
/**
 * @package CaluconEmbedGate
 */

namespace CaluconEmbedGate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * wpml-config.xml is read by WPML and Polylang; a typo there fails
 * silently on the site. Pin its shape against the code it describes.
 */
final class WpmlConfigTest extends TestCase {

	public function test_config_is_well_formed_and_names_the_real_option_and_attributes(): void {
		$path = dirname( __DIR__, 2 ) . '/wpml-config.xml';
		$xml  = simplexml_load_file( $path );
		self::assertNotFalse( $xml, 'wpml-config.xml must parse' );

		$option = (string) $xml->{'admin-texts'}->key['name'];
		self::assertSame( \CaluconEmbedGate\Support\Options::OPTION, $option );

		// Every gated block type the editor integration knows gets both
		// attribute keys — derived from editor.js so the two cannot drift.
		$editor = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/editor.js' );
		self::assertSame( 1, preg_match( '/GATED_BLOCKS = \[([^\]]+)\]/', $editor, $m ) );
		preg_match_all( "/'([a-z\/-]+)'/", $m[1], $ids );
		$expected = $ids[1];

		$declared = array();
		foreach ( $xml->{'gutenberg-blocks'}->{'gutenberg-block'} as $block ) {
			$keys = array();
			foreach ( $block->key as $key ) {
				$keys[] = (string) $key['name'];
			}
			self::assertSame( array( 'caluconEmbedGateAction', 'caluconEmbedGateNote' ), $keys, (string) $block['type'] );
			$declared[] = (string) $block['type'];
		}
		self::assertSame( $expected, $declared );
	}
}
