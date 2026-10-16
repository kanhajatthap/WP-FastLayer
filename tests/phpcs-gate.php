<?php
/**
 * PHPCS regression gate.
 *
 * Compares the per-sniff finding counts of a fresh PHPCS JSON report against a
 * committed baseline and fails when a sniff that is not on the allow-list grows
 * (or a brand-new non-allow-listed sniff appears). This lets the project keep
 * its intentional 4-space PHP indentation while still blocking *new* coding
 * standard / security / i18n regressions.
 *
 * Usage:
 *   php tests/phpcs-gate.php <phpcs-report.json> <baseline.json>
 *
 * Exit codes: 0 = no regressions, 1 = regressions (or bad input).
 *
 * @package FastLayer
 */

$report_path   = $argv[1] ?? 'phpcs-report.json';
$baseline_path = $argv[2] ?? __DIR__ . '/phpcs-baseline.json';

/**
 * Sniffs whose counts legitimately grow as code is added. These are pure
 * formatting / documentation sniffs that the project does not gate on (the PHP
 * house style is 4-space indentation). Growth here is expected and ignored.
 *
 * @var string[]
 */
$allow_grow = array(
	'Generic.WhiteSpace.DisallowSpaceIndent',
	'Generic.WhiteSpace.ScopeIndent',
	'Generic.Formatting.MultipleStatementAlignment',
	'WordPress.Arrays.MultipleStatementAlignment',
	'WordPress.Arrays.ArrayDeclarationSpacing',
	'WordPress.Arrays.ArrayIndentation',
	'WordPress.Arrays.ArrayKeySpacingRestrictions',
	'WordPress.WhiteSpace.OperatorSpacing',
	'Squiz.Commenting.FunctionComment',
	'Squiz.Commenting.VariableComment',
	'Squiz.Commenting.ClassComment',
	'Squiz.Commenting.FileComment',
	'Squiz.Commenting.DocCommentAlignment',
	'Squiz.Commenting.InlineComment',
	'Generic.Commenting.DocComment',
	'Generic.ControlStructures.InlineControlStructure',
	'Generic.CodeAnalysis.UnusedFunctionParameter',
	'Generic.Files.LineEndings',
	'PSR2.Namespaces',
	'PEAR.Functions.FunctionCallSignature',
	'Squiz.Functions.MultiLineFunctionDeclaration',
	'Squiz.PHP.EmbeddedPhp',
	'Squiz.Strings.DoubleQuoteUsage',
	'Squiz.WhiteSpace.SuperfluousWhitespace',
	'NormalizedArrays.Arrays.CommaAfterLast',
	'Universal.Operators.DisallowShortTernary',
	'Universal.Operators.DisallowStandalonePostIncrementDecrement',
	'Universal.NamingConventions.NoReservedKeywordParameterNames',
	'Modernize.FunctionCalls.Dirname',
);

/**
 * Whether a PHPCS message source belongs to a sniff the project does not gate
 * on. PHPCS reports sources as "Standard.Category.Sniff.MessageCode" while the
 * allow-list stores bare sniff names, so a message matches an entry when it
 * equals the entry or starts with "entry." -- matching the bare name alone
 * would never match a real finding.
 *
 * @param string   $source     PHPCS message source.
 * @param string[] $allow_grow Allow-listed sniff names.
 * @return bool
 */
function fastlayer_phpcs_source_is_allowed_to_grow( $source, $allow_grow ) {
	foreach ( $allow_grow as $sniff ) {
		if ( $source === $sniff || 0 === strpos( $source, $sniff . '.' ) ) {
			return true;
		}
	}
	return false;
}

$fail = static function ( $message ) {
	fwrite( STDERR, 'phpcs-gate: ' . $message . "\n" );
	exit( 1 );
};

if ( ! is_readable( $report_path ) ) {
	$fail( "cannot read report: {$report_path}" );
}
if ( ! is_readable( $baseline_path ) ) {
	$fail( "cannot read baseline: {$baseline_path}" );
}

$report   = json_decode( (string) file_get_contents( $report_path ), true );
$baseline = json_decode( (string) file_get_contents( $baseline_path ), true );

if ( ! is_array( $report ) || ! isset( $report['files'] ) ) {
	$fail( "report is not valid PHPCS JSON: {$report_path}" );
}
if ( ! is_array( $baseline ) || ! isset( $baseline['sniffs'] ) || ! is_array( $baseline['sniffs'] ) ) {
	$fail( "baseline is not valid: {$baseline_path}" );
}

// Aggregate the fresh report by sniff.
$counts = array();
foreach ( (array) $report['files'] as $file ) {
	foreach ( (array) ( $file['messages'] ?? array() ) as $message ) {
		$source = (string) ( $message['source'] ?? '' );
		if ( '' === $source ) {
			continue;
		}
		$counts[ $source ] = ( $counts[ $source ] ?? 0 ) + 1;
	}
}

$baseline_sniffs = $baseline['sniffs'];
$violations      = array();

foreach ( $counts as $source => $count ) {
	if ( fastlayer_phpcs_source_is_allowed_to_grow( $source, $allow_grow ) ) {
		continue;
	}
	$base = (int) ( $baseline_sniffs[ $source ] ?? 0 );
	if ( $count > $base ) {
		$violations[] = sprintf( '%s: %d (baseline %d, +%d)', $source, $count, $base, $count - $base );
	}
}

if ( $violations ) {
	fwrite( STDERR, "WPCS regressions vs baseline:\n" . implode( "\n", $violations ) . "\n" );
	exit( 1 );
}

echo sprintf(
	"No new WPCS violations vs baseline (%d sniffs checked).\n",
	count( $baseline_sniffs )
);
exit( 0 );
