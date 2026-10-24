<?php
/**
 * Community and mother-tongue groups (owner, 2026-10-24).
 *
 * Both questions are free text, so "agarwal", "aggarwal", "agrawal",
 * "marwadi" and "maheshwari" were different answers, and a filter on them
 * was useless. The member keeps typing whatever they like, and their words
 * are still shown on the profile as the sub-community. Each answer is also
 * sorted here into one or more groups, which is what filters and the "same
 * community / language" ranking points use.
 *
 * Rules run in order. A rule that matches records its group and removes the
 * words it matched, so a later, broader rule cannot claim them too ("vishwa
 * brahmin" is Vishwakarma, not Brahmin). An answer listing several
 * communities ("maheshwari and jain") gets every group it names. Words that
 * say nothing ("hindu", "general", "caste no bar") give no group, which
 * counts as unanswered: never filtered out.
 *
 * Groups are stored as one user-meta row each (csm_bucket_community,
 * csm_bucket_tongue), rebuilt whenever the field is saved and by
 * `wp csm buckets rebuild`. `wp csm buckets report` lists what fell through.
 */

namespace CAShaadi\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Buckets {

	/** The filter option for everyone whose answer fits no group, or who left it blank. */
	const OTHERS = 'Others';

	/** kind => xprofile field name. */
	const FIELDS = array(
		'community' => 'Community',
		'tongue'    => 'Language (Mother Tongue)',
	);

	/*
	 * label => regex (matched against the lower-cased answer). Order matters.
	 * An empty label consumes the words without giving a group.
	 */
	const COMMUNITY = array(
		array( 'Vishwakarma', '/jangid brahmin|brahmin jangid|vish?wa ?brahm\w*|viswa ?karma|vishwa ?karma|vishwakarma|viswakarma|jangid|jangra|dhiman|panchal|suthar|sutar|luhar|lohar|carpenter|achary/' ),
		array( 'Sonar / Soni', '/sonar|\bsoni\b|swarnkar|sunar|suniy?a+r|gold ?smith/' ),
		array( 'Padmashali / Weavers', '/padma ?s?h?all?i|padmasali|devanga|thogat\w* ?veer\w* ?k\w*|thogat\w*|togata|sengunth\w*/' ),
		array( '', '/brahma ?k?sh?a?t?riya|brahmakshtriya|vann?iya\w* ?kula ?k?sh?atriya|agnikula ?kshatriya/' ),
		array( 'Sikh', '/\bsikh|\bsikg\b|gursikh|ramga?r\w*|ahluwalia/' ),
		array( 'Jain', '/jain|shwet\w*|swet\w*|digamb\w*|sthanak\w*|terapanthi|deravasi|श्वेतांबर/' ),
		array( 'Sindhi', '/sindhi|bhai ?bha?nd\w*|bhagnari|moolchandani|ladwani|sahiti|larkana|sakhru/' ),
		array( 'Lohana', '/lohana|thakkar|halai/' ),
		array( 'Punjabi Khatri / Arora', '/khatri|\bkatri\b|arora|punjab\w*|punjbai|\bsood\b|malhotra|chopra|ahuja|khann+a|threja|adlakha|multani|kapoor|sethi/' ),
		array( 'Gujarati Vania / Vaishnav', '/vaishn?a?v|vaishy?a? vani|\bvani?y?a\b|kapol|\bmodh\b|shrimali|\bshah\b/' ),
		array( 'Arya Vysya / Komati', '/a?arya ?v\w*|aryavysya|v[yi]s+h?y\w*|vyshas?|\bysyas\b|komti|kalinga/' ),
		array( 'Agarwal / Marwari / Maheshwari / Baniya', '/ag+a?r+a?wal|agrawal|marwa[rd]i|marvadi|mah?e?s?h?w[ae]?r[iy]|mahrshwari|baniy?a|\bbanya\b|\bbanik\b|khandelwal|oswal|porwal|\bgarg\b|goyal|\bgoel\b|bansal|jindal|singhal|mittal|gupta|varshney|barn?a?wal|vijayvargiya|mahajan|rastogi|kesh?a?r?wani|keshri|agrahari|medatwal|\bnema\b|vaishy?a?\b|\bvaish\b|dosar|jaiswal|kal[aw]+ar|\bkalar\b|kasaudhan/' ),
		array( 'Bhumihar', '/bhumihar/' ),
		array( 'Brahmin', '/g[oa]u?w?da? saraswat|b[hr]*[ar]*h?a?m+[ai]n|bramhin|bhramin|brahim|brahmon|brgamin|brhamin|bhrahmin|brahamin|iyengar|\biyer\b|saraswat|\bgsb\b|kdgb|havyaka|deshastha|maithil|kan[yi]?a?kubj|saryupar\w*|pareek|dadhich|\bgaur\b|\bgaud\b|anavil|chitrapur|sanadhya|parashar|upadhyay|rajpurohit|\bsharma\b|zharma|\btyagi\b|goswami/' ),
		array( 'Rajput / Kshatriya', '/rajp[uo]+t|k?sh?[ae]?t\w*riya|kshtriya|kshatirya|shtriya|thakur|chauhan|rathore|darbar|khandayat/' ),
		array( 'Maratha', '/maratha|96 ?k|96 kuli|kunb?h?i|kunabi|maharashtrian|marathi|deshmukh|jadhav|\bpawar\b|\bpatil\b/' ),
		array( 'Patel / Patidar', '/patel|patidar|leu?va|leauva|kadva/' ),
		array( 'Kayastha', '/kay?a?s[th]+\w*|kaysth|kayasath|karuneegar/' ),
		array( 'Jat', '/\bja+tt?\b|faujdar|sinsinwar/' ),
		array( 'Yadav / Ahir', '/yadav\w*|\bahir\b|yaduwanshi|yeadavas|golla/' ),
		array( 'Reddy', '/redd(y|ys|iar)/' ),
		array( 'Kamma / Chowdary', '/kamm?a\b|kamna|chowdary/' ),
		array( 'Kapu / Naidu / Balija', '/kaa?pu|naidu|balija|telaga|munnur\w*/' ),
		array( 'Lingayat', '/lingay\w*|lingyath|veera?shaiva|veershiva|panchamsali|jangama/' ),
		array( 'Vokkaliga / Gowda', '/vokk?aliga\w*|gowdas?|gouder/' ),
		array( 'Nair', '/\bnair\b|menon|pisharody/' ),
		array( 'Ezhava / Thiyya', '/ezhava|ezhua|thiyya|billava/' ),
		array( 'Nadar', '/nadar/' ),
		array( 'Tamil Vellalar / Mudaliar / Gounder', '/vellal\w*|vellar|mudh?ali\w*|gounder|pillai|chett?iy?ar|nagarathar|udaiyar|agamudayar|thevar|mukkulathor|kallar/' ),
		array( 'Prajapati / Kumhar', '/prajapat\w*|kumh?a+r|kumbh\w*|kummari|kulal/' ),
		array( 'Teli / Sahu', '/\bteli\b|\bsahu\b|ghanchi/' ),
		array( 'Kurmi', '/kurmi/' ),
		array( 'Kushwaha / Maurya', '/kushw?b?aha|kushbaha|mau?rya|mourya/' ),
		array( 'Saini / Mali', '/saini|\bmali\b/' ),
		array( 'SC / ST', '/\bsc\b|schedule\w* caste|jatav|chamar|ravidas\w*|valmiki|\bmahar\b|ramdasia|bairwa|\bmala\b|meena|adi karnataka|khatik/' ),
		array( 'Muslim', '/musl?i?m|islam|sunni|\bshia\b|shaikh?|sheikh|\bs[ae]?yy?ed\b|ansari|\bkhan\b|pathan|\bpatan\b|memon|bohra|siddiqui|mappila|ahle|ehle|hanafi|deobandi|lebba[iy]|mansoorie|\bturk\b|\bshafi\b|rawther|dudekula/' ),
		array( 'Christian', '/christian|catholic|orthodox|pentecost|syrian|latin/' ),
		array( 'Buddhist', '/buddh\w*/' ),
	);

	/** Languages. English is ignored unless it is the only one given. */
	const TONGUE = array(
		array( 'Marwari / Rajasthani', '/marwa[rd]+i|marva[rd]i|mrwadi|marwardi|rajast?h?ani|rajsthani|mewari/' ),
		array( 'Hindi', '/hindi|hindhi|himdi|hibdi|\bhidi\b|\bhind\b|hinfi|\bhini\b|hundi|hindia|hindk|हिन्दी|hi di|\bhindu\b/' ),
		array( 'Telugu', '/tel[ue]?g[ue]?|teligu|tekugu|twlugu/' ),
		array( 'Gujarati', '/gu[jz]\w*|gujarat/' ),
		array( 'Marathi', '/marathi/' ),
		array( 'Tamil', '/tamils?/' ),
		array( 'Malayalam', '/ma+la?y?a?lam|kerala/' ),
		array( 'Punjabi', '/pa?u?njabi|punjab\b/' ),
		array( 'Kannada', '/kannada/' ),
		array( 'Sindhi', '/sindhi|bhagnari/' ),
		array( 'Urdu', '/urdu|uedu/' ),
		array( 'Odia', '/odi?y?a\b|oriya/' ),
		array( 'Bengali', '/beng?ali|bangla|bangali/' ),
		array( 'Maithili', '/mai?thi?li|maithli|methali|maithali|mithali/' ),
		array( 'Konkani', '/konkani/' ),
		array( 'Tulu', '/tulu/' ),
		array( 'Haryanvi', '/haryan\w*|haryani/' ),
		array( 'Bhojpuri', '/bh[ou]jpuri/' ),
		array( 'Kutchi', '/kutch\w*/' ),
		array( 'Kashmiri', '/kashmiri/' ),
		array( 'Nepali', '/nepali/' ),
		array( 'Pahadi (Garhwali / Kumaoni / Dogri)', '/garhwali|gadwali|kuma\w*ni|kumoni|pahadi|padahi|dogri|himachali/' ),
		array( 'Saurashtra', '/s[ao]u?rashtra/' ),
		array( 'English', '/english|englisn/' ),
	);

	public static function register() {
		add_action( 'xprofile_data_after_save', array( __CLASS__, 'on_save' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'csm buckets', __NAMESPACE__ . '\\BucketsCli' );
		}
	}

	public static function field_id( $kind ) {
		static $ids = array();
		if ( ! isset( $ids[ $kind ] ) ) {
			$ids[ $kind ] = function_exists( 'xprofile_get_field_id_from_name' ) && isset( self::FIELDS[ $kind ] )
				? (int) xprofile_get_field_id_from_name( self::FIELDS[ $kind ] )
				: 0;
		}
		return $ids[ $kind ];
	}

	public static function meta_key( $kind ) {
		return 'csm_bucket_' . $kind;
	}

	/** Every group label of a kind, in rule order (for filter lists). */
	public static function labels( $kind ) {
		$out = array();
		foreach ( 'tongue' === $kind ? self::TONGUE : self::COMMUNITY as $r ) {
			if ( '' !== $r[0] && ! in_array( $r[0], $out, true ) && 'English' !== $r[0] ) {
				$out[] = $r[0];
			}
		}
		if ( 'tongue' === $kind ) {
			$out[] = 'English';
		}
		$out[] = self::OTHERS;
		return $out;
	}

	/** The groups a free-text answer belongs to. */
	public static function of( $kind, $text ) {
		$t = strtolower( html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES ) );
		$t = ' ' . trim( preg_replace( '/[\s,\/\-\.\(\)&;]+/u', ' ', $t ) ) . ' ';
		if ( '' === trim( $t ) ) {
			return array();
		}
		$out = array();
		foreach ( 'tongue' === $kind ? self::TONGUE : self::COMMUNITY as $r ) {
			if ( preg_match( $r[1] . 'u', $t ) ) {
				if ( '' !== $r[0] && ! in_array( $r[0], $out, true ) ) {
					$out[] = $r[0];
				}
				$t = preg_replace( $r[1] . 'u', ' ', $t );
			}
		}
		/*
		 * English counts only when nothing else was named. Hindi is dropped
		 * when a regional language is also given ("gujarati, hindi" is a
		 * Gujarati speaker who also speaks Hindi), except for the Hindi-belt
		 * languages, which stay alongside it.
		 */
		if ( 'tongue' === $kind && count( $out ) > 1 ) {
			$out = array_values( array_diff( $out, array( 'English' ) ) );
			$belt = array( 'Hindi', 'Bhojpuri', 'Haryanvi', 'Maithili', 'Pahadi (Garhwali / Kumaoni / Dogri)' );
			if ( in_array( 'Hindi', $out, true ) && array_diff( $out, $belt ) ) {
				$out = array_values( array_diff( $out, array( 'Hindi' ) ) );
			}
		}
		return $out;
	}

	/** A member's stored groups. */
	public static function get( $uid, $kind ) {
		return array_values( array_filter( array_map( 'strval', (array) get_user_meta( (int) $uid, self::meta_key( $kind ), false ) ) ) );
	}

	/** Recompute and store one member's groups from their current answer. */
	public static function rebuild_user( $uid, $kind ) {
		$fid = self::field_id( $kind );
		if ( ! $fid ) {
			return array();
		}
		$val = function_exists( 'xprofile_get_field_data' ) ? xprofile_get_field_data( $fid, (int) $uid ) : '';
		$val = is_array( $val ) ? implode( ', ', $val ) : (string) $val;
		$groups = self::of( $kind, $val );
		delete_user_meta( (int) $uid, self::meta_key( $kind ) );
		foreach ( $groups as $g ) {
			add_user_meta( (int) $uid, self::meta_key( $kind ), $g );
		}
		return $groups;
	}

	public static function on_save( $data ) {
		$fid = is_object( $data ) && isset( $data->field_id ) ? (int) $data->field_id : 0;
		$uid = is_object( $data ) && isset( $data->user_id ) ? (int) $data->user_id : 0;
		if ( ! $fid || ! $uid ) {
			return;
		}
		foreach ( array_keys( self::FIELDS ) as $kind ) {
			if ( self::field_id( $kind ) === $fid ) {
				self::rebuild_user( $uid, $kind );
			}
		}
	}
}
