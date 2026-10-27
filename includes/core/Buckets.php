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
		'city'      => 'City',
		'native'    => 'Native Place',
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

	/*
	 * City and native place -> state (owner, 2026-10-26). Big cities and
	 * common spellings; `wp csm buckets report` lists what falls through.
	 * Shared by 'city' and 'native'.
	 */
	const STATE = array(
		array( 'Maharashtra', '/maharash?tra|mumbai|bombay|thane|th?ana\b|panvel|kalyan|dombivli|vasai|virar|mira ?road|bhayand[ae]r|borivali|andheri|pune|poona|pimpri|chinchwad|nagpur|nashik|nasik|aurangabad|sambhaji ?nagar|solapur|kolhapur|sangli|satara|amravati|akola|jalgaon|latur|nanded|ahmednagar|ahilya ?nagar|dhule|chandrapur|ratnagiri|wardha|yavatmal|bhiwandi|ulhasnagar|ambernath|badlapur|lonavala|baramati|ichalkaranji|beed|parbhani|jalna|gondia|bhusawal|malegaon|jaysingpur|chalisgaon|ambarnath|waluj|balapur/' ),
		array( 'Gujarat', '/gujarat|ahm[ae]d[ae]?bad|amdavad|surat|vadodara|baroda|rajkot|bhavnagar|jamnagar|gandhinagar|junagadh|anand\b|nadiad|bharuch|ankleshwar|vapi|valsad|navsari|morbi|mehsana|palanpur|bhuj|kutch|kachchh|gandhidham|porbandar|godhra|patan\b|veraval|amreli|surendranagar|botad|dahod|ahe?md?a?bad|bardoli|bilimora|deesa|halol/' ),
		array( 'Rajasthan', '/rajasthan|jaipur|jodhpur|udaipur|kota\b|ajmer|bikaner|alwar|bhilwara|sikar|sri ?ganganagar|ganganagar|pali\b|chittorgarh|chittaurgarh|kishangarh|beawar|jhunjhunu|churu|nagaur|barmer|jaisalmer|tonk|bundi|banswara|dungarpur|hanumangarh|sirohi|abu road|bharatpur|dausa|sawai madhopur|rajsamand|nathdwara|balotra|bhiwadi|neemrana|makrana|ladnun|sujangarh|ratangarh|fatehpur shekhawati|shekhawati|behror|jhalawar|chirawa|didwana|kuchaman/' ),
		array( 'Delhi NCR', '/delhi|new delhi|\bncr\b|gurgaon|gurugram|noida|ghaziabad|faridabad|greater noida|indirapuram|vaishali|dwarka|rohini|janakpuri|pitampura|lajpat|gurgoan|gu?ru?g\w{0,2}r?a?m\b|grugram|gururgam|girigram|gaziabad|gautam ?budd?h/' ),
		array( 'Uttar Pradesh', '/uttar ?pradesh|\bu ?p\b|lucknow|kanpur|agra|varanasi|banaras|benaras|kashi|allahabad|prayag\w*|meerut|bareilly|aligarh|moradabad|saharanpur|gorakhpur|jhansi|mathura|vrindavan|firozabad|muzaffarnagar|shahjahanpur|rampur|hapur|bulandshahr|etawah|mirzapur|ayodhya|faizabad|sultanpur|azamgarh|jaunpur|ballia|deoria|basti|gonda|bahraich|sitapur|hardoi|unnao|rae ?bareli|pratapgarh|fatehpur|banda|lalitpur|mainpuri|etah|kasganj|budaun|pilibhit|lakhimpur|bijnor|amroha|shamli|baghpat|modinagar|orai|ghazipur|chandauli|mau\b|kushinagar|maharajganj|siddharthnagar|bhadohi|sonbhadra|khurja|kannauj|farrukhabad|barabanki|hathras|deoband|dhampur|ambedkar ?nagar/' ),
		array( 'Madhya Pradesh', '/madhya ?pradesh|\bm ?p\b|indore|bhopal|jabalpur|gwalior|ujjain|sagar|ratlam|dewas|satna|rewa|katni|singrauli|burhanpur|khandwa|khargone|chhindwara|neemuch|mandsaur|shivpuri|guna|vidisha|hoshangabad|narmadapuram|itarsi|betul|seoni|damoh|chhatarpur|tikamgarh|morena|bhind|datia|shahdol|sehore|dhar\b|mhow|pithampur|harda|balaghat|mandla|shajapur|rajgarh|ashoknagar|jaora|amanganj/' ),
		array( 'West Bengal', '/west ?bengal|kolkata|calcutta|howrah|durgapur|asansol|siliguri|kharagpur|haldia|bardhaman|burdwan|hooghly|hugli|barasat|salt ?lake|bidhannagar|darjeeling|malda|jalpaiguri|serampore|chandannagar|krishnanagar|bankura|purulia|midnapore|medinipur|cooch ?behar|alipurduar|bhatpara/' ),
		array( 'Telangana', '/telangana|hyderabad|secunderabad|warangal|karimnagar|nizamabad|khammam|ramagundam|mahbubnagar|nalgonda|adilabad|suryapet|siddipet|miryalaguda|cyberabad|jagtial|hanamkonda|chevella/' ),
		array( 'Andhra Pradesh', '/andhra|vizag|visakha\w*|vijayawada|guntur|nellore|kurnool|tirupati|rajahmundry|rajamahendravaram|kakinada|kadapa|anantapur|eluru|ongole|chittoor|machilipatnam|srikakulam|vizianagaram|bhimavaram|tenali|proddatur|nandyal|hindupur|amaravati|tadepalligudem|narasaraopet|palakollu|gudivada|adoni|anaparthy|kanigiri|addanki|amalapuram|gudiwada|guntakal|dharmavaram|\bap\b/' ),
		array( 'Karnataka', '/karnataka|bangalore|bengaluru|banglore|bengalore|mysore|mysuru|mangalore|mangaluru|hubli|hubballi|dharwad|belgaum|belagavi|gulbarga|kalaburagi|davangere|davanagere|bellary|ballari|shimoga|shivamogga|tumkur|tumakuru|udupi|bijapur|vijayapura|hassan|raichur|bidar|hospet|chitradurga|mandya|chikmagalur|karwar|bagalkot|gadag|kolar|manipal|sirsi|bhatkal|jamkhandi|kodagu|coorg|puttur|ban?g\w*luru|beng\w*luru|bengluru|belgavi|chikkaballapur|chikk?a?ma?n?ga?l\w*/' ),
		array( 'Tamil Nadu', '/tamil ?nadu|chennai|madras|coimbatore|kovai|madurai|trichy|tiruchi\w*|salem|tirupp?ur|erode|vellore|tirunelveli|thoothukudi|tuticorin|thanjavur|dindigul|karur|nagercoil|kanchipuram|hosur|kumbakonam|namakkal|sivakasi|pollachi|cuddalore|karaikudi|ooty|nilgiris|kanyakumari|udumalpet|ambur|bhavani|dharapuram/' ),
		array( 'Kerala', '/kerala|kochi|cochin|ernakulam|thiruvananthapuram|trivandrum|kozhikode|calicut|thrissur|trichur|kollam|quilon|kannur|cannanore|alappuzha|alleppey|palakkad|palghat|kottayam|malappuram|kasaragod|pathanamthitta|idukki|wayanad|aluva|thalassery|tirur|kasarg[oa]d|adoor|alanallur|areekode|chalakudy|chelakkara/' ),
		array( 'Punjab', '/punjab|ludhiana|amritsar|jalandhar|jullundur|patiala|bathinda|bhatinda|mohali|hoshiarpur|pathankot|moga|abohar|firozpur|ferozepur|phagwara|kapurthala|barnala|sangrur|muktsar|fazilka|rajpura|khanna|zirakpur|batala|malerkotla|mansa|sirhind|faridkot|bareta/' ),
		array( 'Chandigarh', '/chandigarh|tricity|panchkula/' ),
		array( 'Haryana', '/haryana|rohtak|hisar|hissar|panipat|karnal|sonipat|sonepat|ambala|yamuna ?nagar|kurukshetra|bhiwani|sirsa|jind|kaithal|rewari|palwal|bahadurgarh|jhajjar|fatehabad|mahendragarh|narnaul|hansi|tohana|charkhi dadri|cheeka/' ),
		array( 'Bihar', '/bihar|patna|gaya\b|bhagalpur|muzaffarpur|darbhanga|purnia|purnea|begusarai|arrah|\bara\b|katihar|munger|chapra|saharsa|sasaram|hajipur|siwan|motihari|bettiah|samastipur|madhubani|sitamarhi|kishanganj|buxar|jehanabad|nalanda|bihar ?sharif|forbesganj|dehri|araria/' ),
		array( 'Jharkhand', '/jharkhand|ranchi|jamshedpur|tatanagar|dhanbad|bokaro|hazaribagh|hazaribag|deoghar|giridih|ramgarh|dumka|phusro|chaibasa|daltonganj|palamu|sahibganj|godda|koderma|jamtara|pakur|gumla|lohardaga|chas\b|chatra\b/' ),
		array( 'Odisha', '/odisha|orissa|bhubaneswar|bhubaneshwar|cuttack|rourkela|sambalpur|berhampur|brahmapur|balasore|baleswar|puri\b|jharsuguda|bargarh|angul|jajpur|kendrapara|bhadrak|baripada|keonjhar|sundargarh|koraput|rayagada|jeypore|talcher|dhenkanal|gunupur|chatrapur/' ),
		array( 'Chhattisgarh', '/chh?attisgarh|raipur|bhilai|durg\b|bilaspur|korba|rajnandgaon|raigarh|jagdalpur|ambikapur|dhamtari|mahasamund|janjgir|kawardha|bhatapara|dhantari/' ),
		array( 'Uttarakhand', '/uttarakhand|uttaranchal|dehradun|dehra ?dun|haridwar|rishikesh|roorkee|haldwani|nainital|kashipur|rudrapur|almora|pithoragarh|mussoorie|kotdwar|kichha|gadarpur/' ),
		array( 'Himachal Pradesh', '/himachal|shimla|simla|mandi\b|solan|dharamshala|dharamsala|kangra|kullu|manali|hamirpur|una\b|bilaspur hp|chamba|nahan|baddi|palampur/' ),
		array( 'Jammu & Kashmir / Ladakh', '/jammu|kashmir|srinagar|ladakh|leh\b|anantnag|baramulla|kathua|udhampur/' ),
		array( 'Assam / North East', '/assam|guwahati|gauhati|dibrugarh|silchar|jorhat|tezpur|tinsukia|nagaon|bongaigaon|meghalaya|shillong|tripura|agartala|manipur|imphal|nagaland|kohima|dimapur|mizoram|aizawl|arunachal|itanagar|sikkim|gangtok|north ?east|duliajan/' ),
		array( 'Goa', '/\bgoa\b|panaji|panjim|margao|madgaon|vasco|mapusa|ponda/' ),
		array( 'Puducherry', '/puducherry|pondicherry|pondy|karaikal/' ),
		array( 'Abroad', '/dubai|abu ?dhabi|sharjah|\buae\b|qatar|doha|muscat|oman|kuwait|bahrain|riyadh|jeddah|saudi|singapore|malaysia|kuala|hong ?kong|london|\buk\b|united kingdom|england|scotland|ireland|dublin|\busa?\b|united states|america|new york|california|texas|new jersey|chicago|boston|seattle|san francisco|canada|toronto|vancouver|calgary|australia|sydney|melbourne|brisbane|perth|new ?zealand|auckland|germany|frankfurt|berlin|munich|netherlands|amsterdam|france|paris|switzerland|zurich|belgium|sweden|japan|tokyo|nepal|kathmandu|africa|nairobi|kenya|lagos/' ),
	);

	/*
	 * The member's own choice of group (v1.86.2): array( text, group ). It
	 * holds only while their answer is unchanged; a new answer is grouped
	 * again, and the screen offers the choice again.
	 */
	const SET = 'csm_bucket_%s_set';

	public static function register() {
		add_action( 'xprofile_data_after_save', array( __CLASS__, 'on_save' ) );
		add_action( 'csm_buckets_nightly', array( __CLASS__, 'nightly' ) );
		if ( ! wp_next_scheduled( 'csm_buckets_nightly' ) ) {
			wp_schedule_event( strtotime( 'tomorrow 03:10' ), 'daily', 'csm_buckets_nightly' );
		}
		add_action( 'rest_api_init', array( __CLASS__, 'rest_routes' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'csm buckets', __NAMESPACE__ . '\\BucketsCli' );
		}
	}

	public static function field_id( $kind ) {
		static $ids = array();
		if ( 'city' === $kind ) {
			return (int) Config::FIELD_CITY; // the 'City' label does not find the live field
		}
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

	/** The rules for a kind. */
	public static function rules( $kind ) {
		if ( 'tongue' === $kind ) {
			return self::TONGUE;
		}
		return in_array( $kind, array( 'city', 'native' ), true ) ? self::STATE : self::COMMUNITY;
	}

	/** A known kind from a request, else 'community'. */
	public static function kind( $kind ) {
		return isset( self::FIELDS[ $kind ] ) ? (string) $kind : 'community';
	}

	/** Every group label of a kind, in rule order (for filter lists). */
	public static function labels( $kind ) {
		$out = array();
		foreach ( self::rules( $kind ) as $r ) {
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
		foreach ( self::rules( $kind ) as $r ) {
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
	public static function rebuild_user( $uid, $kind, $val = null ) {
		$fid = self::field_id( $kind );
		if ( ! $fid ) {
			return array();
		}
		/*
		 * The value just saved, when the save hands it over (v1.89.86): read
		 * back here it could still be the cached old answer, and a member who
		 * joined with "Hindi, punjabi" was left with no group at all.
		 */
		if ( null === $val ) {
			$val = function_exists( 'xprofile_get_field_data' ) ? xprofile_get_field_data( $fid, (int) $uid ) : '';
		} else {
			$val = maybe_unserialize( $val );
		}
		$val = is_array( $val ) ? implode( ', ', $val ) : (string) $val;
		$groups = self::of( $kind, $val );
		$set    = get_user_meta( (int) $uid, sprintf( self::SET, $kind ), true );
		if ( is_array( $set ) && isset( $set['text'], $set['group'] ) && self::norm( $set['text'] ) === self::norm( $val ) ) {
			$groups = self::OTHERS === $set['group'] ? array() : array( (string) $set['group'] );
		}
		delete_user_meta( (int) $uid, self::meta_key( $kind ) );
		foreach ( $groups as $g ) {
			add_user_meta( (int) $uid, self::meta_key( $kind ), $g );
		}
		return $groups;
	}

	public static function norm( $v ) {
		return strtolower( trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $v ) ) ) );
	}

	/* ------------------------------------------------------------- REST */

	public static function rest_routes() {
		register_rest_route( 'csm/v1', '/buckets/suggest', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_suggest' ),
			'permission_callback' => 'is_user_logged_in',
		) );
		register_rest_route( 'csm/v1', '/buckets/choose', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_choose' ),
			'permission_callback' => 'is_user_logged_in',
		) );
	}

	/** The group(s) an answer would get, and the full list to choose from. */
	public static function rest_suggest( $request ) {
		$kind = self::kind( $request->get_param( 'kind' ) );
		$text = (string) $request->get_param( 'text' );
		$set  = get_user_meta( get_current_user_id(), sprintf( self::SET, $kind ), true );
		return new \WP_REST_Response( array(
			'groups'  => self::of( $kind, $text ),
			// The member's earlier choice, if it was made for this same answer.
			'chosen'  => is_array( $set ) && isset( $set['text'], $set['group'] ) && self::norm( $set['text'] ) === self::norm( $text ) ? (string) $set['group'] : '',
			'options' => self::labels( $kind ),
		), 200 );
	}

	/** The member picks the group for their (current) answer. */
	public static function rest_choose( $request ) {
		$uid   = get_current_user_id();
		$kind  = self::kind( $request->get_param( 'kind' ) );
		$group = (string) $request->get_param( 'group' );
		$text  = (string) $request->get_param( 'text' );
		if ( ! in_array( $group, self::labels( $kind ), true ) ) {
			return new \WP_REST_Response( array( 'ok' => false ), 200 );
		}
		update_user_meta( $uid, sprintf( self::SET, $kind ), array( 'text' => $text, 'group' => $group ) );
		self::rebuild_user( $uid, $kind ); // applies now if the answer is already saved
		return new \WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/** What the onboarding and profile screens need for the picker. */
	public static function js_config() {
		return array(
			'suggest' => rest_url( 'csm/v1/buckets/suggest' ),
			'choose'  => rest_url( 'csm/v1/buckets/choose' ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'tongues' => array_values( array_diff( self::labels( 'tongue' ), array( self::OTHERS ) ) ),
		);
	}

	/** 'community' / 'tongue' when this field id is one of them, else ''. */
	public static function kind_of_field( $fid ) {
		foreach ( array_keys( self::FIELDS ) as $kind ) {
			if ( (int) $fid && self::field_id( $kind ) === (int) $fid ) {
				return $kind;
			}
		}
		return '';
	}

	public static function on_save( $data ) {
		$fid = is_object( $data ) && isset( $data->field_id ) ? (int) $data->field_id : 0;
		$uid = is_object( $data ) && isset( $data->user_id ) ? (int) $data->user_id : 0;
		if ( ! $fid || ! $uid ) {
			return;
		}
		foreach ( array_keys( self::FIELDS ) as $kind ) {
			if ( self::field_id( $kind ) === $fid ) {
				self::rebuild_user( $uid, $kind, isset( $data->value ) ? $data->value : null );
			}
		}
	}

	/** Regroup everyone from their current answers. Returns how many answers. */
	public static function rebuild_all() {
		global $wpdb;
		$n = 0;
		foreach ( array_keys( self::FIELDS ) as $kind ) {
			$fid = self::field_id( $kind );
			if ( ! $fid ) {
				continue;
			}
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, value FROM {$wpdb->prefix}bp_xprofile_data WHERE field_id = %d AND value <> ''", $fid ) );
			$want = array();
			foreach ( $rows as $r ) {
				$want[ (int) $r->user_id ] = $r->value;
			}
			// Members with a value: regrouped (their own choice still applies).
			foreach ( $want as $uid => $val ) {
				self::rebuild_user( $uid, $kind, $val );
				$n++;
			}
			// Members whose answer is gone: no groups.
			$stale = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s", self::meta_key( $kind ) ) );
			foreach ( $stale as $uid ) {
				if ( ! isset( $want[ (int) $uid ] ) ) {
					delete_user_meta( (int) $uid, self::meta_key( $kind ) );
				}
			}
		}
		return $n;
	}

	/** Nightly safety net: anything a save missed is regrouped (v1.89.86). */
	public static function nightly() {
		self::rebuild_all();
	}
}
