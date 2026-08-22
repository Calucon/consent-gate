<?php
/**
 * The built-in provider set (PLAN.md §4.2).
 *
 * Descriptors are data, not classes (§4.1). WordPress-free: the translate
 * callable is injected, identity outside WordPress. Provider names are
 * proper nouns and are never translated (§9.15).
 *
 * @package CaluconEmbedGate
 */

namespace CaluconEmbedGate\Providers\Builtin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ships enough that a typical site needs no configuration. Every descriptor
 * prefers a privacy-preserving load target where one exists: measured on the
 * source site, youtube-nocookie.com sets 0 cookies where youtube.com sets 5.
 */
final class Descriptors {

	/**
	 * @param callable|null $translate Maps English strings to the site language.
	 * @return array[] Provider descriptors, most specific first.
	 */
	public static function all( ?callable $translate = null ): array {
		$t = $translate ?? static function ( string $text ): string {
			return $text;
		};

		return array(
			array(
				'id'               => 'youtube',
				'kind'             => 'video',
				'label'            => 'YouTube',
				'match'            => array(
					'iframe_host' => array(
						'youtube.com',
						'www.youtube.com',
						'm.youtube.com',
						'youtube-nocookie.com',
						'www.youtube-nocookie.com',
						'youtu.be',
					),
					'iframe_path' => '#^/embed/(?P<id>[A-Za-z0-9_-]{6,20})#',
				),
				// Data minimisation: measured 0 cookies vs 5 on the default host.
				'load_host'        => 'www.youtube-nocookie.com',
				'load_path'        => '/embed/{id}',
				'fallback'         => 'https://www.youtube.com/watch?v={id}',
				'scrub_hint_hosts' => array( 'i.ytimg.com', 's.ytimg.com', 'img.youtube.com', 'yt3.ggpht.com' ),
				'privacy_url'      => 'https://policies.google.com/privacy',
				'controller'       => 'Google Ireland Limited, Dublin, Ireland',
				'note'             => $t( 'Loading this video contacts YouTube (Google), which receives your IP address and which page you are on, and sets cookies.' ),
				'action'           => $t( 'Load video from YouTube' ),
				'aspect'           => '16:9',
				'iframe_allow'     => 'accelerometer; encrypted-media; gyroscope; picture-in-picture; web-share',
				'strategy'         => 'iframe',
			),
			array(
				'id'               => 'vimeo',
				'kind'             => 'video',
				'label'            => 'Vimeo',
				'match'            => array(
					'iframe_host' => array( 'player.vimeo.com' ),
					'iframe_path' => '#^/video/(?P<id>[0-9]+)#',
				),
				// Keep the original URL (unlisted videos need their ?h= hash)
				// and merge dnt=1, which suppresses Vimeo's analytics.
				'load_query'       => array( 'dnt' => '1' ),
				'fallback'         => 'https://vimeo.com/{id}',
				'scrub_hint_hosts' => array( 'i.vimeocdn.com', 'f.vimeocdn.com' ),
				'privacy_url'      => 'https://vimeo.com/privacy',
				'controller'       => 'Vimeo.com, Inc., New York, USA',
				'note'             => $t( 'Loading this video contacts Vimeo, which receives your IP address and which page you are on, and may set cookies.' ),
				'action'           => $t( 'Load video from Vimeo' ),
				'aspect'           => '16:9',
				'strategy'         => 'iframe',
			),
			array(
				'id'               => 'google-maps',
				'kind'             => 'map',
				'label'            => 'Google Maps',
				'match'            => array(
					'iframe_host' => array( 'www.google.com', 'google.com', 'maps.google.com' ),
					// Three shapes in the field: /maps/embed (Share → Embed a
					// map), /maps/d/embed (My Maps), and the legacy
					// /maps?q=…&output=embed which is bare /maps as a path.
					'iframe_path' => '#^/maps(?:/|$)#',
				),
				// No privacy-preserving variant exists; gate only. The README
				// suggests OpenStreetMap as the replacement.
				// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- nothing is loaded from these hosts; they are listed so the plugin can REMOVE preconnect/dns-prefetch hints pointing at them (resource-hint scrubbing).
				'scrub_hint_hosts' => array( 'maps.gstatic.com', 'maps.googleapis.com' ), // REMOVED from resource hints — never requested by this plugin.
				'privacy_url'      => 'https://policies.google.com/privacy',
				'controller'       => 'Google Ireland Limited, Dublin, Ireland',
				'note'             => $t( 'Loading this map contacts Google Maps, which receives your IP address and which page you are on, and sets cookies.' ),
				'action'           => $t( 'Load map from Google Maps' ),
				'strategy'         => 'iframe',
			),
			array(
				'id'          => 'openstreetmap',
				'kind'        => 'map',
				'label'       => 'OpenStreetMap',
				'match'       => array(
					'iframe_host' => array( 'www.openstreetmap.org', 'openstreetmap.org' ),
					'iframe_path' => '#^/export/embed#',
				),
				'privacy_url' => 'https://osmfoundation.org/wiki/Privacy_Policy',
				'controller'  => 'OpenStreetMap Foundation, Cambridge, UK',
				'note'        => $t( 'Loading this map contacts OpenStreetMap, which receives your IP address and which page you are on.' ),
				'action'      => $t( 'Load map from OpenStreetMap' ),
				'strategy'    => 'iframe',
			),
			array(
				'id'               => 'spotify',
				'kind'             => 'audio',
				'label'            => 'Spotify',
				'match'            => array(
					'iframe_host' => array( 'open.spotify.com' ),
					'iframe_path' => '#^/embed/(?P<type>track|album|playlist|episode|show|artist)/(?P<id>[A-Za-z0-9]+)#',
				),
				'fallback'         => 'https://open.spotify.com/{type}/{id}',
				'scrub_hint_hosts' => array( 'i.scdn.co' ),
				'privacy_url'      => 'https://www.spotify.com/legal/privacy-policy/',
				'controller'       => 'Spotify AB, Stockholm, Sweden',
				'note'             => $t( 'Loading this player contacts Spotify, which receives your IP address and which page you are on, and sets cookies.' ),
				'action'           => $t( 'Load player from Spotify' ),
				'strategy'         => 'iframe',
			),
			array(
				'id'          => 'soundcloud',
				'kind'        => 'audio',
				'label'       => 'SoundCloud',
				'match'       => array(
					'iframe_host' => array( 'w.soundcloud.com' ),
					'iframe_path' => '#^/player#',
				),
				'privacy_url' => 'https://soundcloud.com/pages/privacy',
				'controller'  => 'SoundCloud Global Limited & Co. KG, Berlin, Germany',
				'note'        => $t( 'Loading this player contacts SoundCloud, which receives your IP address and which page you are on, and may set cookies.' ),
				'action'      => $t( 'Load player from SoundCloud' ),
				'strategy'    => 'iframe',
			),
			array(
				'id'          => 'apple-music',
				'kind'        => 'audio',
				'label'       => 'Apple Music',
				'match'       => array(
					'iframe_host' => array( 'embed.music.apple.com', 'embed.podcasts.apple.com' ),
				),
				'privacy_url' => 'https://www.apple.com/legal/privacy/',
				'controller'  => 'Apple Distribution International Ltd., Cork, Ireland',
				'note'        => $t( 'Loading this player contacts Apple, which receives your IP address and which page you are on.' ),
				'action'      => $t( 'Load player from Apple' ),
				'strategy'    => 'iframe',
			),
			array(
				'id'          => 'google-calendar',
				'kind'        => 'calendar',
				'label'       => 'Google Calendar',
				'match'       => array(
					'iframe_host' => array( 'calendar.google.com' ),
					'iframe_path' => '#^/calendar/embed#',
				),
				'privacy_url' => 'https://policies.google.com/privacy',
				'controller'  => 'Google Ireland Limited, Dublin, Ireland',
				'note'        => $t( 'Loading this calendar contacts Google, which receives your IP address and which page you are on, and sets cookies.' ),
				'action'      => $t( 'Load calendar from Google' ),
				'strategy'    => 'iframe',
			),
			array(
				'id'          => 'google-forms',
				'kind'        => 'form',
				'label'       => 'Google Forms',
				'match'       => array(
					'iframe_host' => array( 'docs.google.com' ),
					'iframe_path' => '#^/forms/#',
				),
				'privacy_url' => 'https://policies.google.com/privacy',
				'controller'  => 'Google Ireland Limited, Dublin, Ireland',
				'note'        => $t( 'Loading this form contacts Google, which receives your IP address and which page you are on, and sets cookies.' ),
				'action'      => $t( 'Load form from Google' ),
				'strategy'    => 'iframe',
			),
			array(
				'id'          => 'matterport',
				'kind'        => '3d',
				'label'       => 'Matterport',
				'match'       => array(
					'iframe_host' => array( 'my.matterport.com' ),
					'iframe_path' => '#^/show#',
				),
				'privacy_url' => 'https://matterport.com/legal/privacy-policy',
				'controller'  => 'Matterport, Inc., Sunnyvale, USA',
				'note'        => $t( 'Loading this tour contacts Matterport, which receives your IP address and which page you are on.' ),
				'action'      => $t( 'Load tour from Matterport' ),
				'strategy'    => 'iframe',
			),
			array(
				'id'          => 'sketchfab',
				'kind'        => '3d',
				'label'       => 'Sketchfab',
				'match'       => array(
					'iframe_host' => array( 'sketchfab.com' ),
					'iframe_path' => '#/embed#',
				),
				'privacy_url' => 'https://sketchfab.com/privacy',
				'controller'  => 'Sketchfab, Inc., New York, USA',
				'note'        => $t( 'Loading this model contacts Sketchfab, which receives your IP address and which page you are on.' ),
				'action'      => $t( 'Load model from Sketchfab' ),
				'strategy'    => 'iframe',
			),
			array(
				'id'          => 'typeform',
				'kind'        => 'form',
				'label'       => 'Typeform',
				'match'       => array(
					'iframe_host' => array( 'form.typeform.com' ),
					'script_host' => array( 'embed.typeform.com' ),
				),
				'privacy_url' => 'https://www.typeform.com/privacy-policy/',
				'controller'  => 'Typeform S.L., Barcelona, Spain',
				'note'        => $t( 'Loading this form contacts Typeform, which receives your IP address and which page you are on, and sets cookies.' ),
				'action'      => $t( 'Load form from Typeform' ),
				'strategy'    => 'iframe',
			),
			array(
				'id'          => 'calendly',
				'kind'        => 'calendar',
				'label'       => 'Calendly',
				'match'       => array(
					'iframe_host' => array( 'calendly.com' ),
					'script_host' => array( 'assets.calendly.com' ),
				),
				'privacy_url' => 'https://calendly.com/privacy',
				'controller'  => 'Calendly LLC, Atlanta, USA',
				'note'        => $t( 'Loading this scheduler contacts Calendly, which receives your IP address and which page you are on, and sets cookies.' ),
				'action'      => $t( 'Load scheduler from Calendly' ),
				'strategy'    => 'iframe',
			),
			array(
				'id'                 => 'strava',
				'kind'               => 'social',
				'label'              => 'Strava',
				'match'              => array(
					'script_host' => array( 'strava-embeds.com', 'www.strava-embeds.com' ),
				),
				'privacy_url'        => 'https://www.strava.com/legal/privacy',
				'controller'         => 'Strava, Inc., San Francisco, USA',
				'note'               => $t( 'Loading this activity contacts Strava, which receives your IP address and which page you are on, and sets cookies.' ),
				'action'             => $t( 'Load activity from Strava' ),
				'strategy'           => 'script',
				'companion_class'    => array( 'strava-embed-placeholder' ),
				// The companion div carries data-embed-type/data-embed-id;
				// the human page is derivable from them.
				'companion_fallback' => static function ( array $attributes ) {
					$type = isset( $attributes['data-embed-type'] ) && is_string( $attributes['data-embed-type'] )
						? $attributes['data-embed-type'] : '';
					$id   = isset( $attributes['data-embed-id'] ) && is_string( $attributes['data-embed-id'] )
						? $attributes['data-embed-id'] : '';
					$map  = array(
						'activity' => 'activities',
						'segment'  => 'segments',
						'route'    => 'routes',
						'club'     => 'clubs',
					);
					if ( '' === $id || ! isset( $map[ $type ] ) || ! preg_match( '/^[0-9]+$/', $id ) ) {
						return null;
					}
					return 'https://www.strava.com/' . $map[ $type ] . '/' . rawurlencode( $id );
				},
			),
			array(
				'id'               => 'twitter',
				'kind'             => 'social',
				'label'            => 'X (Twitter)',
				'match'            => array(
					'iframe_host' => array( 'platform.twitter.com', 'platform.x.com' ),
					'script_host' => array( 'platform.twitter.com', 'platform.x.com' ),
				),
				'privacy_url'      => 'https://x.com/en/privacy',
				'controller'       => 'Twitter International Unlimited Company, Dublin, Ireland',
				'note'             => $t( 'Loading this post contacts X (Twitter), which receives your IP address and which page you are on, and sets cookies.' ),
				'action'           => $t( 'Load post from X (Twitter)' ),
				'strategy'         => 'script',
				'companion_class'  => array( 'twitter-tweet', 'twitter-timeline' ),
				'scrub_hint_hosts' => array( 'syndication.twitter.com', 'pbs.twimg.com', 'abs.twimg.com' ),
			),
			array(
				'id'               => 'instagram',
				'kind'             => 'social',
				'label'            => 'Instagram',
				'match'            => array(
					'iframe_host' => array( 'www.instagram.com', 'instagram.com' ),
					'script_host' => array( 'www.instagram.com', 'instagram.com', 'platform.instagram.com' ),
				),
				'privacy_url'      => 'https://privacycenter.instagram.com/policy',
				'controller'       => 'Meta Platforms Ireland Limited, Dublin, Ireland',
				'note'             => $t( 'Loading this post contacts Instagram (Meta), which receives your IP address and which page you are on, and sets cookies.' ),
				'action'           => $t( 'Load post from Instagram' ),
				'strategy'         => 'script',
				'companion_class'  => array( 'instagram-media' ),
				'scrub_hint_hosts' => array( 'scontent.cdninstagram.com' ),
			),
			array(
				'id'              => 'tiktok',
				'kind'            => 'video',
				'label'           => 'TikTok',
				'match'           => array(
					'iframe_host' => array( 'www.tiktok.com' ),
					'script_host' => array( 'www.tiktok.com' ),
				),
				'privacy_url'     => 'https://www.tiktok.com/legal/privacy-policy',
				'controller'      => 'TikTok Technology Limited, Dublin, Ireland',
				'note'            => $t( 'Loading this video contacts TikTok, which receives your IP address and which page you are on, and sets cookies.' ),
				'action'          => $t( 'Load video from TikTok' ),
				'strategy'        => 'script',
				'companion_class' => array( 'tiktok-embed' ),
			),
			array(
				'id'                 => 'facebook',
				'kind'               => 'social',
				'label'              => 'Facebook',
				'match'              => array(
					'iframe_host' => array( 'www.facebook.com', 'web.facebook.com' ),
					'script_host' => array( 'connect.facebook.net' ),
				),
				'privacy_url'        => 'https://www.facebook.com/privacy/policy/',
				'controller'         => 'Meta Platforms Ireland Limited, Dublin, Ireland',
				'note'               => $t( 'Loading this content contacts Facebook (Meta), which receives your IP address and which page you are on, and sets cookies.' ),
				'action'             => $t( 'Load content from Facebook' ),
				'strategy'           => 'script',
				// The canonical shape is <div id="fb-root"></div><script>…
				// with the .fb-post companion AFTER the script; its data-href
				// is the human page.
				'companion_class'    => array( 'fb-post', 'fb-video', 'fb-page' ),
				'companion_fallback' => static function ( array $attributes ) {
					$href = isset( $attributes['data-href'] ) && is_string( $attributes['data-href'] )
						? trim( $attributes['data-href'] ) : '';
					return preg_match( '#^https://(www|web)\.facebook\.com/#', $href ) ? $href : null;
				},
				'scrub_hint_hosts'   => array( 'staticxx.facebook.com' ),
			),
			array(
				'id'              => 'reddit',
				'kind'            => 'social',
				'label'           => 'Reddit',
				'match'           => array(
					'iframe_host' => array( 'embed.reddit.com', 'www.redditmedia.com' ),
					'script_host' => array( 'embed.reddit.com', 'embed.redditmedia.com' ),
				),
				'privacy_url'     => 'https://www.reddit.com/policies/privacy-policy',
				'controller'      => 'Reddit, Inc., San Francisco, USA',
				'note'            => $t( 'Loading this post contacts Reddit, which receives your IP address and which page you are on, and sets cookies.' ),
				'action'          => $t( 'Load post from Reddit' ),
				'strategy'        => 'script',
				'companion_class' => array( 'reddit-embed-bq' ),
			),
			array(
				'id'          => 'giphy',
				'kind'        => 'image',
				'label'       => 'GIPHY',
				'match'       => array(
					'iframe_host' => array( 'giphy.com' ),
					'script_host' => array( 'giphy.com' ),
				),
				'privacy_url' => 'https://support.giphy.com/hc/en-us/articles/360032872931',
				'controller'  => 'Giphy, Inc., New York, USA',
				'note'        => $t( 'Loading this image contacts GIPHY, which receives your IP address and which page you are on.' ),
				'action'      => $t( 'Load image from GIPHY' ),
				'strategy'    => 'iframe',
			),
		);
	}
}
