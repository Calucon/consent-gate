<?php
/**
 * @package CaluconEmbedGate
 */

namespace CaluconEmbedGate\Tests\Unit;

use CaluconEmbedGate\Providers\Builtin\Descriptors;
use CaluconEmbedGate\Support\AppearanceCss;
use CaluconEmbedGate\Support\Options;
use CaluconEmbedGate\Tests\Support\PipelineFactory;
use PHPUnit\Framework\TestCase;

final class OptionsTest extends TestCase {

	public function test_garbage_input_yields_defaults(): void {
		self::assertSame( Options::defaults(), Options::sanitize( 'nonsense' ) );
		self::assertSame( Options::defaults(), Options::sanitize( null ) );
		self::assertSame( Options::defaults(), Options::sanitize( 42 ) );
	}

	public function test_checkbox_values_become_booleans(): void {
		$clean = Options::sanitize(
			array(
				'providers' => array(
					'youtube' => array( 'enabled' => '0', 'privacy_variant' => '1' ),
				),
				'detection' => array( 'scripts' => '0' ),
			)
		);

		self::assertFalse( $clean['providers']['youtube']['enabled'] );
		self::assertTrue( $clean['providers']['youtube']['privacy_variant'] );
		self::assertFalse( $clean['detection']['scripts'] );
		self::assertTrue( $clean['detection']['iframes'] );
	}

	public function test_note_and_action_are_stripped_of_markup(): void {
		$clean = Options::sanitize(
			array(
				'providers' => array(
					'youtube' => array( 'note' => '  <script>x</script>Custom note ' ),
				),
			)
		);

		self::assertSame( 'xCustom note', $clean['providers']['youtube']['note'] );
	}

	public function test_note_and_action_are_length_capped(): void {
		$clean = Options::sanitize(
			array(
				'providers' => array(
					'youtube' => array(
						'note'   => str_repeat( 'a', 600 ),
						'action' => str_repeat( 'b', 600 ),
					),
				),
			)
		);

		self::assertSame( 500, strlen( $clean['providers']['youtube']['note'] ) );
		self::assertSame( 500, strlen( $clean['providers']['youtube']['action'] ) );
	}

	public function test_host_lists_accept_newline_strings_and_pasted_urls(): void {
		$clean = Options::sanitize(
			array(
				'detection' => array(
					'own_hosts'  => "cdn.example.com\nhttps://media.example.com/path\n*.static.example.com\n\ninvalid host!",
					'never_gate' => array( 'Maps.Example.ORG.' ),
				),
			)
		);

		self::assertSame(
			array( 'cdn.example.com', 'media.example.com', '*.static.example.com' ),
			$clean['detection']['own_hosts']
		);
		self::assertSame( array( 'maps.example.org' ), $clean['detection']['never_gate'] );
	}

	public function test_unknown_provider_ids_are_dropped(): void {
		$clean = Options::sanitize(
			array( 'providers' => array( 'evil provider!' => array( 'enabled' => '0' ) ) )
		);

		self::assertSame( array(), $clean['providers'] );
	}

	public function test_disabled_provider_passes_through(): void {
		$overridden = Options::apply_provider_overrides(
			Descriptors::all(),
			array( 'providers' => array( 'youtube' => array( 'enabled' => false ) ) )
		);

		$input = '<iframe src="https://www.youtube.com/embed/y_pjE_p1HwE" title="T"></iframe>';
		$html  = PipelineFactory::gate( $input, array( 'example.test' ), array(), $overridden );

		self::assertSame( $input, $html );
	}

	public function test_privacy_variant_off_keeps_the_original_host(): void {
		$overridden = Options::apply_provider_overrides(
			Descriptors::all(),
			array( 'providers' => array( 'youtube' => array( 'privacy_variant' => false ) ) )
		);

		$html = PipelineFactory::gate(
			'<iframe src="https://www.youtube.com/embed/y_pjE_p1HwE" title="T"></iframe>',
			array( 'example.test' ),
			array(),
			$overridden
		);

		self::assertStringContainsString( 'www.youtube.com/embed/y_pjE_p1HwE', $html );
		self::assertStringNotContainsString( 'youtube-nocookie.com', $html );
		self::assertStringContainsString( 'data-cg-provider="youtube"', $html );
	}

	public function test_note_override_reaches_the_panel(): void {
		$overridden = Options::apply_provider_overrides(
			Descriptors::all(),
			array( 'providers' => array( 'youtube' => array( 'note' => 'House rules apply.' ) ) )
		);

		$html = PipelineFactory::gate(
			'<iframe src="https://www.youtube.com/embed/y_pjE_p1HwE" title="T"></iframe>',
			array( 'example.test' ),
			array(),
			$overridden
		);

		self::assertStringContainsString( '<p class="cg-embed__note">House rules apply.</p>', $html );
	}

	public function test_appearance_accepts_hex_colours_and_known_presets_only(): void {
		$clean = Options::sanitize(
			array(
				'appearance' => array(
					'preset'    => 'card',
					'bg'        => '#FFFFFF',
					'fg'        => 'red',
					'accent'    => '#12ab34',
					'accent_fg' => 'url(javascript:x)}body{',
				),
			)
		);

		self::assertSame( 'card', $clean['appearance']['preset'] );
		self::assertSame( '#ffffff', $clean['appearance']['bg'] );
		// Non-hex values could smuggle CSS out of the custom property.
		self::assertSame( '', $clean['appearance']['fg'] );
		self::assertSame( '#12ab34', $clean['appearance']['accent'] );
		self::assertSame( '', $clean['appearance']['accent_fg'] );

		$bad = Options::sanitize( array( 'appearance' => array( 'preset' => 'neon' ) ) );
		self::assertSame( 'default', $bad['appearance']['preset'] );
	}

	public function test_appearance_corners_accepts_known_values_only(): void {
		self::assertSame( '', Options::defaults()['appearance']['corners'] );

		$clean = Options::sanitize( array( 'appearance' => array( 'corners' => 'pill' ) ) );
		self::assertSame( 'pill', $clean['appearance']['corners'] );

		// Unknown values fall back to the default — never into emitted CSS.
		$bad = Options::sanitize( array( 'appearance' => array( 'corners' => '12px;}body{' ) ) );
		self::assertSame( '', $bad['appearance']['corners'] );
	}

	public function test_appearance_new_knobs_are_bounded_and_enum_checked(): void {
		$clean = Options::sanitize(
			array(
				'appearance' => array(
					'corners'      => 'custom',
					'radius'       => '999',
					'border_width' => '99',
					'border_color' => '#ABCDEF',
					'shadow'       => 'soft',
					'density'      => 'spacious',
				),
			)
		);

		self::assertSame( 'custom', $clean['appearance']['corners'] );
		self::assertSame( 48, $clean['appearance']['radius'], 'radius clamps to 48' );
		self::assertSame( '10', $clean['appearance']['border_width'], 'border width clamps to 10' );
		self::assertSame( '#abcdef', $clean['appearance']['border_color'], 'hex lowercased like the other colours' );
		self::assertSame( 'soft', $clean['appearance']['shadow'] );
		self::assertSame( 'spacious', $clean['appearance']['density'] );

		$bad = Options::sanitize(
			array(
				'appearance' => array(
					'radius'       => 'huge',
					'border_width' => 'expression(alert(1))',
					'border_color' => 'red',
					'shadow'       => 'dramatic',
					'density'      => 'cosy',
				),
			)
		);

		self::assertSame( 12, $bad['appearance']['radius'] );
		self::assertSame( '', $bad['appearance']['border_width'] );
		self::assertSame( '', $bad['appearance']['border_color'] );
		self::assertSame( '', $bad['appearance']['shadow'] );
		self::assertSame( '', $bad['appearance']['density'] );
	}

	public function test_appearance_round_two_knobs_sanitise(): void {
		$clean = Options::sanitize(
			array(
				'appearance' => array(
					'withdraw_style' => 'link',
					'button_size'    => 'large',
					'play_icon'      => '1',
					'note_size'      => 'small',
					'align'          => 'center',
					'dark'           => '1',
					'dark_bg'        => '#101418',
					'dark_accent_fg' => 'ARGB(1,2,3)',
				),
			)
		);

		self::assertSame( 'link', $clean['appearance']['withdraw_style'] );
		self::assertSame( 'large', $clean['appearance']['button_size'] );
		self::assertTrue( $clean['appearance']['play_icon'] );
		self::assertSame( 'small', $clean['appearance']['note_size'] );
		self::assertSame( 'center', $clean['appearance']['align'] );
		self::assertTrue( $clean['appearance']['dark'] );
		self::assertSame( '#101418', $clean['appearance']['dark_bg'] );
		self::assertSame( '', $clean['appearance']['dark_accent_fg'], 'non-hex dark colour rejected' );

		$bad = Options::sanitize(
			array(
				'appearance' => array(
					'withdraw_style' => 'blinking',
					'button_size'    => 'giant',
					'note_size'      => 'huge',
					'align'          => 'justify',
				),
			)
		);
		self::assertSame( '', $bad['appearance']['withdraw_style'] );
		self::assertSame( '', $bad['appearance']['button_size'] );
		self::assertSame( '', $bad['appearance']['note_size'] );
		self::assertSame( '', $bad['appearance']['align'] );
		self::assertFalse( $bad['appearance']['play_icon'] );
		self::assertFalse( $bad['appearance']['dark'] );
	}

	public function test_appearance_round_three_enums(): void {
		$clean = Options::sanitize(
			array(
				'appearance' => array(
					'button_style' => 'outline',
					'button_width' => 'full',
					'hover'        => 'strong',
					'poster_panel' => 'bar',
				),
			)
		);
		self::assertSame( 'outline', $clean['appearance']['button_style'] );
		self::assertSame( 'full', $clean['appearance']['button_width'] );
		self::assertSame( 'strong', $clean['appearance']['hover'] );
		self::assertSame( 'bar', $clean['appearance']['poster_panel'] );

		$bad = Options::sanitize(
			array(
				'appearance' => array(
					'button_style' => 'neon',
					'button_width' => '200%',
					'hover'        => 'wiggle',
					'poster_panel' => 'top',
				),
			)
		);
		foreach ( array( 'button_style', 'button_width', 'hover', 'poster_panel' ) as $key ) {
			self::assertSame( '', $bad['appearance'][ $key ], $key );
		}

		$more = Options::sanitize( array( 'appearance' => array( 'poster_dim' => 'light', 'link' => '#0A5BD3' ) ) );
		self::assertSame( 'light', $more['appearance']['poster_dim'] );
		self::assertSame( '#0a5bd3', $more['appearance']['link'] );
		$worse = Options::sanitize( array( 'appearance' => array( 'poster_dim' => 'pitch-black', 'link' => 'blue' ) ) );
		self::assertSame( '', $worse['appearance']['poster_dim'] );
		self::assertSame( '', $worse['appearance']['link'] );
	}

	public function test_provider_privacy_url_override_is_https_only_and_applied(): void {
		$clean = Options::sanitize(
			array(
				'providers' => array(
					'vimeo'   => array( 'privacy_url' => ' https://vimeo.com/de/privacy ' ),
					'youtube' => array( 'privacy_url' => 'javascript:alert(1)' ),
					'spotify' => array( 'privacy_url' => 'http://www.spotify.com/de/legal/privacy-policy/' ),
					'reddit'  => array( 'privacy_url' => 'https://x y' ),
				),
			)
		);
		self::assertSame( 'https://vimeo.com/de/privacy', $clean['providers']['vimeo']['privacy_url'] );
		// A row with nothing valid in it is dropped altogether.
		self::assertTrue( empty( $clean['providers']['youtube']['privacy_url'] ), 'non-https scheme rejected' );
		self::assertTrue( empty( $clean['providers']['spotify']['privacy_url'] ), 'plain http rejected' );
		self::assertTrue( empty( $clean['providers']['reddit']['privacy_url'] ), 'malformed URL rejected' );

		$providers = Options::apply_provider_overrides(
			\CaluconEmbedGate\Providers\Builtin\Descriptors::all(),
			$clean
		);
		$by_id = array();
		foreach ( $providers as $descriptor ) {
			$by_id[ $descriptor['id'] ] = $descriptor;
		}
		self::assertSame( 'https://vimeo.com/de/privacy', $by_id['vimeo']['privacy_url'] );
		self::assertSame( 'https://policies.google.com/privacy', $by_id['youtube']['privacy_url'], 'rejected override leaves the built-in' );
	}

	public function test_colour_swatch_grammar_inherit_theme_reference_or_custom_hex(): void {
		$clean = Options::sanitize(
			array(
				'appearance' => array(
					'bg'               => 'preset:Base',     // theme reference, slug lowercased
					'fg'               => 'custom',
					'fg_custom'        => '#FFFFFF',         // custom hex from the picker
					'accent'           => 'custom',
					'accent_custom'    => 'red',             // invalid hex → inherit
					'accent_fg'        => 'preset:accent-2) ;x', // not a slug → inherit
					'link'             => '#0a5bd3',         // raw hex (stored tree) accepted
					'border_color'     => '',                // inherit
					'dark_bg'          => 'preset:contrast-2',
				),
			)
		);
		self::assertSame( 'preset:base', $clean['appearance']['bg'] );
		self::assertSame( '#ffffff', $clean['appearance']['fg'] );
		self::assertSame( '', $clean['appearance']['accent'] );
		self::assertSame( '', $clean['appearance']['accent_fg'] );
		self::assertSame( '#0a5bd3', $clean['appearance']['link'] );
		self::assertSame( '', $clean['appearance']['border_color'] );
		self::assertSame( 'preset:contrast-2', $clean['appearance']['dark_bg'] );
	}

	public function test_privacy_link_toggle_defaults_off_and_becomes_boolean(): void {
		self::assertFalse( Options::sanitize( array() )['display']['privacy_link'] );
		self::assertFalse( Options::sanitize( array( 'display' => array( 'privacy_link' => '0' ) ) )['display']['privacy_link'] );
		self::assertTrue( Options::sanitize( array( 'display' => array( 'privacy_link' => '1' ) ) )['display']['privacy_link'] );
	}

	public function test_always_gate_list_is_sanitised_like_the_other_host_lists(): void {
		$clean = Options::sanitize(
			array(
				'detection' => array(
					'always_gate' => "widgets.example.com\nhttps://Tracking.Example.org/path",
				),
			)
		);

		self::assertSame( array( 'widgets.example.com', 'tracking.example.org' ), $clean['detection']['always_gate'] );
	}

	public function test_cmp_bridge_is_off_by_default(): void {
		$defaults = Options::defaults();

		self::assertFalse( $defaults['cmp']['bridge'] );
		self::assertFalse( $defaults['cmp']['tcf'] );
		self::assertSame( 'external-media', $defaults['cmp']['borlabs_group'] );
	}

	public function test_cmp_flags_become_booleans(): void {
		$clean = Options::sanitize(
			array(
				'cmp' => array(
					'bridge' => '1',
					'tcf'    => '0',
				),
			)
		);

		self::assertTrue( $clean['cmp']['bridge'] );
		self::assertFalse( $clean['cmp']['tcf'] );
	}

	public function test_cmp_borlabs_group_accepts_slugs_only(): void {
		$clean = Options::sanitize( array( 'cmp' => array( 'borlabs_group' => 'Marketing-Group_2' ) ) );
		self::assertSame( 'marketing-group_2', $clean['cmp']['borlabs_group'] );

		// Anything that could break out of the inline config JSON falls
		// back to the default rather than travelling to the page.
		$bad = Options::sanitize( array( 'cmp' => array( 'borlabs_group' => 'x"};alert(1);//' ) ) );
		self::assertSame( 'external-media', $bad['cmp']['borlabs_group'] );
	}

	public function test_custom_provider_rows_are_sanitised(): void {
		$clean = Options::sanitize(
			array(
				'custom_providers' => array(
					// Blank row (always present in the form): ignored.
					array( 'id' => '', 'label' => '', 'hosts' => '', 'script_hosts' => '', 'kind' => '' ),
					// Markup stripped, pasted URL reduced to its host, wildcard dropped, kind whitelisted.
					array( 'label' => '<b>Example</b> Partner', 'hosts' => "https://widgets.example-partner.com/embed/1\n*.example-partner.com\nnot a host", 'kind' => 'poster' ),
					// No hosts at all: ignored.
					array( 'label' => 'Hostless', 'hosts' => '', 'script_hosts' => '' ),
					// Remove flag: dropped.
					array( 'id' => 'custom-gone', 'label' => 'Gone', 'hosts' => 'gone.example', 'remove' => '1' ),
					// Existing id is kept even though the label changed.
					array( 'id' => 'custom-old-name', 'label' => 'New Name', 'hosts' => 'old.example', 'kind' => 'map' ),
					// Same label as an existing row: gets a distinct id; any known kind is accepted.
					array( 'label' => 'New Name', 'hosts' => 'other.example', 'kind' => '3d' ),
					// A forged id that is not in the allowed shape is replaced.
					array( 'id' => 'youtube', 'label' => 'Fake', 'hosts' => 'fake.example' ),
				),
				'providers'        => array(
					'custom-gone'     => array( 'note' => 'stale' ),
					'custom-old-name' => array( 'note' => 'kept' ),
					'youtube'         => array( 'enabled' => '0' ),
				),
			)
		);

		self::assertSame(
			array(
				array( 'id' => 'custom-example-partner', 'label' => 'Example Partner', 'hosts' => array( 'widgets.example-partner.com' ), 'script_hosts' => array(), 'kind' => '' ),
				array( 'id' => 'custom-old-name', 'label' => 'New Name', 'hosts' => array( 'old.example' ), 'script_hosts' => array(), 'kind' => 'map' ),
				array( 'id' => 'custom-new-name', 'label' => 'New Name', 'hosts' => array( 'other.example' ), 'script_hosts' => array(), 'kind' => '3d' ),
				array( 'id' => 'custom-fake', 'label' => 'Fake', 'hosts' => array( 'fake.example' ), 'script_hosts' => array(), 'kind' => '' ),
			),
			$clean['custom_providers']
		);
		// Override rows follow the provider: the removed one is pruned, the kept one stays, built-ins untouched.
		self::assertArrayNotHasKey( 'custom-gone', $clean['providers'] );
		self::assertSame( 'kept', $clean['providers']['custom-old-name']['note'] );
		self::assertFalse( $clean['providers']['youtube']['enabled'] );
	}

	public function test_custom_providers_default_to_none_and_survive_a_resave(): void {
		self::assertSame( array(), Options::sanitize( array() )['custom_providers'] );

		$first  = Options::sanitize( array( 'custom_providers' => array( array( 'label' => 'Example', 'hosts' => 'a.example' ) ) ) );
		$second = Options::sanitize( array( 'custom_providers' => $first['custom_providers'] ) );

		self::assertSame( $first['custom_providers'], $second['custom_providers'] );
	}

	/**
	 * A 0.9.4 option tree as stored on a live site, with the customisations
	 * an owner could make then. After the 0.10.0 upgrade: every new key at
	 * its default, the old customisations kept, and the emitted appearance
	 * CSS identical to what those old values alone produce — the panel's
	 * look does not change on update.
	 */
	public function test_a_0_9_4_option_tree_upgrades_without_changing_the_look(): void {
		$stored = array(
			'providers'  => array( 'youtube' => array( 'enabled' => true, 'privacy_variant' => false, 'note' => 'House rules.' ) ),
			'detection'  => array( 'iframes' => true, 'scripts' => true, 'images' => false, 'own_hosts' => array( 'cdn.example' ), 'never_gate' => array(), 'always_gate' => array(), 'www_equivalence' => true, 'output_buffer' => false ),
			'appearance' => array( 'preset' => 'card', 'bg' => '#123456', 'fg' => '#ffffff', 'accent' => '', 'accent_fg' => '', 'corners' => 'rounded' ),
			'consent'    => array( 'memory' => 'session', 'scope' => 'provider', 'duration_days' => 180 ),
			'cmp'        => array( 'bridge' => false, 'borlabs_group' => 'external-media', 'tcf' => false ),
		);

		$clean = Options::sanitize( $stored );

		// Old values survive.
		self::assertSame( 'card', $clean['appearance']['preset'] );
		self::assertSame( '#123456', $clean['appearance']['bg'] );
		self::assertSame( 'rounded', $clean['appearance']['corners'] );
		self::assertFalse( $clean['providers']['youtube']['privacy_variant'] );
		self::assertSame( 'House rules.', $clean['providers']['youtube']['note'] );
		self::assertSame( array( 'cdn.example' ), $clean['detection']['own_hosts'] );
		self::assertSame( 'session', $clean['consent']['memory'] );
		// New keys at their defaults.
		$defaults = Options::defaults();
		self::assertSame( array(), $clean['custom_providers'] );
		self::assertFalse( $clean['display']['privacy_link'], 'the privacy link stays off for upgraded sites' );
		foreach ( $defaults['appearance'] as $key => $default ) {
			if ( ! isset( $stored['appearance'][ $key ] ) ) {
				self::assertSame( $default, $clean['appearance'][ $key ], "appearance.$key" );
			}
		}
		// Same CSS as the pre-0.10 values alone: the new defaults emit nothing.
		$old_only = $stored['appearance'] + $defaults['appearance'];
		self::assertSame( AppearanceCss::build( $old_only ), AppearanceCss::build( $clean['appearance'] ) );
		self::assertSame( '', AppearanceCss::build( $defaults['appearance'] ), 'an untouched 0.10.0 emits no CSS' );
		// And a pristine 0.9.4 default tree round-trips to the 0.10.0 defaults.
		$pristine = $stored;
		$pristine['appearance'] = array( 'preset' => 'default', 'bg' => '', 'fg' => '', 'accent' => '', 'accent_fg' => '', 'corners' => '' );
		self::assertSame( $defaults['appearance'], Options::sanitize( $pristine )['appearance'] );
	}
}
