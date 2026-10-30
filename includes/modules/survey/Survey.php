<?php
/**
 * Member survey: 5 questions and a short "what should we improve" (owner,
 * 2026-10-30). No direct price question (owner: "questions like these
 * incentivize lying"); the price signal is what members have already paid for
 * matrimony services, a past fact with nothing to gain by bending it.
 *
 * Reward: 1 profile boost (a bought-boost credit, Spotlight::CREDITS), given
 * on submit. Once per member, for the first csm_survey_max answers (default
 * 300) so Discover is not flooded with boosts. csm_survey_on = 0 stops it.
 *
 * Invited by a one-time popup (AppPage's chain) for members past their first
 * two days. The screen is /survey/. Answers: user meta csm_survey1 and a
 * survey_answer event. `wp csm survey [--text]` reads the results.
 */

namespace CAShaadi\Modules\Survey;

use CAShaadi\Core\AppPage;
use CAShaadi\Core\Membership;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Survey {

	const DONE_META = 'csm_survey1';
	const SEEN_META = 'csm_survey1_seen';   // the invitation popup was shown
	const COUNT_OPT = 'csm_survey1_count';
	const MAX_OPT   = 'csm_survey_max';
	const ON_OPT    = 'csm_survey_on';

	/** key => [ question, [ value => label ], type ] */
	public static function questions() {
		return array(
			'paid'    => array(
				'Have you ever paid for a matrimony service (a site, app, bureau or agent)? How much in total?',
				array(
					'never'  => 'No, never',
					'u2k'    => 'Under ₹2,000',
					'2-5k'   => '₹2,000 – ₹5,000',
					'5-15k'  => '₹5,000 – ₹15,000',
					'15-50k' => '₹15,000 – ₹50,000',
					'50k+'   => 'More than ₹50,000',
				),
				'one',
			),
			'helps'   => array(
				'Which 2 would help you most in finding a match?',
				array(
					'more'     => 'More profiles every week',
					'phone'    => 'See phone numbers',
					'first'    => 'Your requests shown first',
					'viewers'  => 'See who viewed you',
					'verified' => 'Only verified CAs',
					'filters'  => 'More filters',
					'help'     => 'Personal help from our team',
				),
				'two',
			),
			'problem' => array(
				'What is the biggest problem for you on CA Shaadi right now?',
				array(
					'few'      => 'Too few profiles',
					'match'    => 'Profiles don\'t match what I want',
					'reply'    => 'People don\'t reply',
					'genuine'  => 'Not sure profiles are genuine',
					'hard'     => 'The app is hard to use',
					'other'    => 'Something else',
				),
				'one',
			),
			'who'     => array(
				'Who is involved in finding the match?',
				array(
					'me'      => 'Mostly me',
					'family'  => 'Mostly my parents / family',
					'both'    => 'Both together',
				),
				'one',
			),
			'nps'     => array(
				'How likely are you to recommend CA Shaadi to a friend?',
				array_combine( range( 0, 10 ), array_map( 'strval', range( 0, 10 ) ) ),
				'scale',
			),
		);
	}

	public static function register() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 1 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'csm survey', array( __CLASS__, 'cli' ), array(
				'synopsis' => array( array( 'type' => 'flag', 'name' => 'text', 'optional' => true ) ),
			) );
		}
	}

	/** Can this member still take it (and get the boost)? */
	public static function open_for( $uid ) {
		$uid = (int) $uid;
		if ( ! $uid || ! get_option( self::ON_OPT, 1 ) || user_can( $uid, 'manage_options' ) || get_user_meta( $uid, self::DONE_META, true ) ) {
			return false;
		}
		if ( (int) get_option( self::COUNT_OPT, 0 ) >= (int) get_option( self::MAX_OPT, 300 ) ) {
			return false;
		}
		if ( class_exists( '\\CAShaadi\\Modules\\Discover\\Boost' ) && \CAShaadi\Modules\Discover\Boost::is_new( $uid ) ) {
			return false;
		}
		return true;
	}

	/** The one-time invitation popup, for AppPage's chain; false when not due. */
	public static function payload( $uid ) {
		if ( ! self::open_for( $uid ) || get_user_meta( (int) $uid, self::SEEN_META, true ) ) {
			return false;
		}
		return array(
			'title' => 'Get a free profile boost',
			'text'  => "Answer 6 quick questions about CA Shaadi. It takes about a minute.\n\nYour answers help us improve, and you get 1 free profile boost.",
			'ok'    => 'Start',
			'url'   => home_url( '/survey/' ),
			'seen'  => rest_url( 'csm/v1/survey/seen' ),
		);
	}

	public static function routes() {
		register_rest_route( 'csm/v1', '/survey', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_submit' ),
			'permission_callback' => 'is_user_logged_in',
		) );
		register_rest_route( 'csm/v1', '/survey/seen', array(
			'methods'             => 'POST',
			'callback'            => function () {
				$uid = get_current_user_id();
				if ( ! get_user_meta( $uid, self::SEEN_META, true ) ) {
					update_user_meta( $uid, self::SEEN_META, time() );
				}
				return new \WP_REST_Response( array( 'ok' => true ), 200 );
			},
			'permission_callback' => 'is_user_logged_in',
		) );
	}

	public static function rest_submit( $request ) {
		$uid = get_current_user_id();
		if ( get_user_meta( $uid, self::DONE_META, true ) ) {
			return new \WP_REST_Response( array( 'ok' => false, 'message' => 'You have already answered. Thank you!' ), 200 );
		}
		if ( ! self::open_for( $uid ) ) {
			return new \WP_REST_Response( array( 'ok' => false, 'message' => 'This survey is closed. Thank you for your interest.' ), 200 );
		}
		$a   = array();
		$bad = array();
		foreach ( self::questions() as $key => $q ) {
			$opts = $q[1];
			if ( 'two' === $q[2] ) {
				$v = array_values( array_unique( array_filter( array_map( 'strval', (array) $request->get_param( $key ) ), function ( $x ) use ( $opts ) { return isset( $opts[ $x ] ); } ) ) );
				if ( count( $v ) !== 2 ) {
					$bad[ $key ] = 'Please pick 2.';
				}
			} else {
				$v = (string) $request->get_param( $key );
				if ( '' === $v || ! isset( $opts[ $v ] ) ) {
					$bad[ $key ] = 'Please choose one.';
				}
			}
			$a[ $key ] = $v;
		}
		$text = trim( sanitize_textarea_field( (string) $request->get_param( 'improve' ) ) );
		if ( mb_strlen( $text ) < 3 ) {
			$bad['improve'] = 'Please write a few words.';
		}
		$a['improve'] = mb_substr( $text, 0, 1000 );
		if ( $bad ) {
			return new \WP_REST_Response( array( 'ok' => false, 'errors' => $bad ), 200 );
		}
		$a['at'] = time();
		// Claim first, so a double tap cannot give two boosts.
		if ( ! add_user_meta( $uid, self::DONE_META, wp_json_encode( $a ), true ) ) {
			return new \WP_REST_Response( array( 'ok' => false, 'message' => 'You have already answered. Thank you!' ), 200 );
		}
		update_option( self::COUNT_OPT, (int) get_option( self::COUNT_OPT, 0 ) + 1, false );
		if ( class_exists( '\\CAShaadi\\Modules\\Premium\\Spotlight' ) ) {
			update_user_meta( $uid, \CAShaadi\Modules\Premium\Spotlight::CREDITS, \CAShaadi\Modules\Premium\Spotlight::credits( $uid ) + 1 );
		}
		if ( function_exists( 'cashaadi' ) ) {
			cashaadi()->log_event( 'survey_answer', $uid, 0, array_merge( $a, array(
				'gender' => (string) cashaadi()->get_gender( $uid ),
				'plan'   => Membership::is_gold( $uid ) ? 'gold' : ( Membership::is_premium( $uid ) ? 'premium' : 'free' ),
			) ) );
		}
		return new \WP_REST_Response( array( 'ok' => true, 'profile' => home_url( '/profile/' ) ), 200 );
	}

	/* -------------------------------------------------------------- screen */

	public static function maybe_render() {
		if ( ! AppPage::claim( 'survey' ) ) {
			return;
		}
		$uid  = get_current_user_id();
		$done = (bool) get_user_meta( $uid, self::DONE_META, true );
		$open = self::open_for( $uid );
		AppPage::assets();
		AppPage::open( 'Quick survey', 'profile' );
		echo '<div class="csm-sv">';
		if ( $done ) {
			echo '<h1>Thank you!</h1><p>You have already answered. Your free boost is on your profile.</p><a class="csm-sv-btn" href="' . esc_url( home_url( '/profile/' ) ) . '">Go to my profile</a>';
		} elseif ( ! $open && ! current_user_can( 'manage_options' ) ) {
			echo '<h1>This survey is closed</h1><p>Thank you for your interest.</p><a class="csm-sv-btn" href="' . esc_url( home_url( '/discover/' ) ) . '">Back to Discover</a>';
		} else {
			echo self::form(); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts
		}
		echo '</div>' . self::css();
		AppPage::close( 'profile' );
		exit;
	}

	private static function form() {
		$o  = '<h1>6 quick questions</h1><p class="csm-sv-sub">Answer all of them and get 1 free profile boost.</p>';
		$o .= '<form id="csm-sv-form" novalidate data-url="' . esc_url( rest_url( 'csm/v1/survey' ) ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'wp_rest' ) ) . '">';
		$n  = 0;
		foreach ( self::questions() as $key => $q ) {
			$n++;
			$o .= '<fieldset class="csm-sv-q" data-key="' . esc_attr( $key ) . '"><legend><span>' . $n . '.</span> ' . esc_html( $q[0] ) . ( 'two' === $q[2] ? ' <small>(pick 2)</small>' : '' ) . '</legend>';
			$o .= '<div class="csm-sv-opts' . ( 'scale' === $q[2] ? ' is-scale' : '' ) . '">';
			foreach ( $q[1] as $v => $label ) {
				$type = 'two' === $q[2] ? 'checkbox' : 'radio';
				$o   .= '<label class="csm-sv-opt"><input type="' . $type . '" name="' . esc_attr( $key ) . ( 'checkbox' === $type ? '[]' : '' ) . '" value="' . esc_attr( (string) $v ) . '"><span>' . esc_html( $label ) . '</span></label>';
			}
			if ( 'scale' === $q[2] ) {
				$o .= '<div class="csm-sv-ends"><span>Not likely</span><span>Very likely</span></div>';
			}
			$o .= '</div><p class="csm-sv-err"></p></fieldset>';
		}
		$o .= '<fieldset class="csm-sv-q" data-key="improve"><legend><span>' . ( $n + 1 ) . '.</span> One thing we should improve</legend>'
			. '<textarea name="improve" rows="3" maxlength="1000" placeholder="Write a few words"></textarea><p class="csm-sv-err"></p></fieldset>';
		$o .= '<p class="csm-sv-err csm-sv-top"></p><button type="submit" class="csm-sv-btn">Submit and get my boost</button></form>';
		$o .= '<script>(function(){var f=document.getElementById("csm-sv-form");if(!f){return;}'
			. '[].forEach.call(f.querySelectorAll("fieldset[data-key=helps] input"),function(c){c.addEventListener("change",function(){var on=f.querySelectorAll("fieldset[data-key=helps] input:checked");if(on.length>2){c.checked=false;}});});'
			. 'f.addEventListener("submit",function(e){e.preventDefault();var b=f.querySelector(".csm-sv-btn"),d={};'
			. '[].forEach.call(f.querySelectorAll(".csm-sv-err"),function(x){x.textContent="";});'
			. '[].forEach.call(f.querySelectorAll("fieldset[data-key]"),function(fs){var k=fs.getAttribute("data-key");'
			. 'if(k==="helps"){d[k]=[].map.call(fs.querySelectorAll("input:checked"),function(i){return i.value;});}'
			. 'else if(k==="improve"){d[k]=fs.querySelector("textarea").value;}else{var c=fs.querySelector("input:checked");d[k]=c?c.value:"";}});'
			. 'b.disabled=true;b.textContent="Sending…";'
			. 'fetch(f.getAttribute("data-url"),{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/json","X-WP-Nonce":f.getAttribute("data-nonce")},body:JSON.stringify(d)}).then(function(r){return r.json();}).then(function(r){'
			. 'if(r&&r.ok){f.parentNode.innerHTML="<h1>Thank you!</h1><p>Your free profile boost is ready. Start it from your profile whenever you like.</p><a class=\"csm-sv-btn\" href=\""+r.profile+"\">Go to my profile</a>";window.scrollTo(0,0);return;}'
			. 'b.disabled=false;b.textContent="Submit and get my boost";var first=null;'
			. 'Object.keys((r&&r.errors)||{}).forEach(function(k){var fs=f.querySelector("fieldset[data-key=\""+k+"\"]");if(fs){fs.querySelector(".csm-sv-err").textContent=r.errors[k];first=first||fs;}});'
			. 'if(r&&r.message){f.querySelector(".csm-sv-top").textContent=r.message;}'
			. 'if(first){first.scrollIntoView({block:"center",behavior:"smooth"});}'
			. '}).catch(function(){b.disabled=false;b.textContent="Submit and get my boost";f.querySelector(".csm-sv-top").textContent="No connection. Please try again.";});});})();</script>';
		return $o;
	}

	private static function css() {
		return '<style>.csm-sv{max-width:640px;margin:0 auto;padding:16px 16px 40px;color:#16212b}.csm-sv h1{font-size:24px;margin:8px 0 4px}'
			. '.csm-sv-sub{color:#5c6a76;margin:0 0 18px}.csm-sv-q{border:1px solid #e4eaf0;border-radius:14px;padding:14px;margin:0 0 14px;background:#fff}'
			. '.csm-sv-q legend{font-weight:700;font-size:16px;line-height:1.35;padding:0 4px}.csm-sv-q legend span{color:#7a1220}.csm-sv-q legend small{font-weight:400;color:#5c6a76}'
			. '.csm-sv-opts{display:flex;flex-direction:column;gap:8px;margin-top:8px}.csm-sv-opt{display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid #e4eaf0;border-radius:10px;cursor:pointer;font-size:15px}'
			. '.csm-sv-opt input{margin:0;flex:0 0 auto}.csm-sv-opt:has(input:checked){border-color:#0b2350;background:#f3f7fc}'
			. '.csm-sv-opts.is-scale{flex-direction:row;flex-wrap:wrap;gap:6px}.csm-sv-opts.is-scale .csm-sv-opt{flex:1 0 38px;justify-content:center;padding:10px 0;flex-direction:column;gap:2px}'
			. '.csm-sv-opts.is-scale .csm-sv-opt input{position:absolute;opacity:0;pointer-events:none}.csm-sv-ends{width:100%;display:flex;justify-content:space-between;color:#5c6a76;font-size:12px}'
			. '.csm-sv textarea{width:100%;box-sizing:border-box;border:1px solid #c9d2dc;border-radius:10px;padding:10px;font:inherit;margin-top:8px}'
			. '.csm-sv-err{color:#b3261e;font-size:13px;margin:6px 0 0}.csm-sv-err:empty{display:none}'
			. '.csm-sv-btn{display:block;width:100%;padding:14px;border:0;border-radius:999px;background:#0b2350;color:#fff!important;font-weight:700;font-size:16px;text-align:center;text-decoration:none;cursor:pointer}'
			. '.csm-sv-btn:disabled{opacity:.6}</style>';
	}

	/* ----------------------------------------------------------------- CLI */

	/**
	 * wp csm survey [--text]
	 *
	 * Each answer's share, overall and split by who is involved (me / family /
	 * both), gender and plan. --text also lists the "improve" answers.
	 */
	public static function cli( $args, $assoc ) {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT actor_id, metadata FROM {$wpdb->prefix}csm_event_log WHERE event_type = 'survey_answer' ORDER BY id" );
		$all  = array();
		foreach ( (array) $rows as $r ) {
			$m = json_decode( (string) $r->metadata, true );
			if ( is_array( $m ) ) {
				$all[ (int) $r->actor_id ] = $m; // one per member
			}
		}
		$n = count( $all );
		\WP_CLI::log( "{$n} answers (cap " . (int) get_option( self::MAX_OPT, 300 ) . ', survey ' . ( get_option( self::ON_OPT, 1 ) ? 'on' : 'off' ) . ').' );
		if ( ! $n ) {
			return;
		}
		$groups = array(
			'all'    => function () { return true; },
			'me'     => function ( $m ) { return 'me' === ( $m['who'] ?? '' ); },
			'family' => function ( $m ) { return 'family' === ( $m['who'] ?? '' ); },
			'both'   => function ( $m ) { return 'both' === ( $m['who'] ?? '' ); },
			'men'    => function ( $m ) { return 'Male' === trim( (string) ( $m['gender'] ?? '' ) ); },
			'women'  => function ( $m ) { return 'Female' === trim( (string) ( $m['gender'] ?? '' ) ); },
			'free'   => function ( $m ) { return 'free' === ( $m['plan'] ?? '' ); },
			'paid'   => function ( $m ) { return 'free' !== ( $m['plan'] ?? 'free' ); },
		);
		$size = array();
		foreach ( $groups as $g => $fn ) {
			$size[ $g ] = count( array_filter( $all, $fn ) );
		}
		foreach ( self::questions() as $key => $q ) {
			$table = array();
			foreach ( $q[1] as $v => $label ) {
				$row = array( 'answer' => $label );
				foreach ( $groups as $g => $fn ) {
					$c = 0;
					foreach ( $all as $m ) {
						if ( $fn( $m ) && in_array( (string) $v, array_map( 'strval', (array) ( $m[ $key ] ?? array() ) ), true ) ) {
							$c++;
						}
					}
					$row[ $g . ' (' . $size[ $g ] . ')' ] = $size[ $g ] ? round( 100 * $c / $size[ $g ] ) . '%' : '-';
				}
				$table[] = $row;
			}
			\WP_CLI::log( "\n" . $q[0] . ( 'two' === $q[2] ? ' (each person picked 2, so columns add to 200%)' : '' ) );
			\WP_CLI\Utils\format_items( 'table', $table, array_keys( $table[0] ) );
			if ( 'nps' === $key ) {
				$s = array_map( function ( $m ) { return (int) ( $m['nps'] ?? 0 ); }, $all );
				$pro = count( array_filter( $s, function ( $x ) { return $x >= 9; } ) );
				$det = count( array_filter( $s, function ( $x ) { return $x <= 6; } ) );
				\WP_CLI::log( sprintf( 'NPS: %d (promoters %d%%, detractors %d%%).', round( 100 * ( $pro - $det ) / $n ), round( 100 * $pro / $n ), round( 100 * $det / $n ) ) );
			}
		}
		if ( ! empty( $assoc['text'] ) ) {
			\WP_CLI::log( "\nOne thing we should improve:" );
			foreach ( $all as $m ) {
				\WP_CLI::log( '  [' . ( $m['who'] ?? '' ) . ', ' . trim( (string) ( $m['gender'] ?? '' ) ) . ', ' . ( $m['plan'] ?? '' ) . '] ' . str_replace( "\n", ' ', (string) ( $m['improve'] ?? '' ) ) );
			}
		}
	}
}
