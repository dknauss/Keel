#!/usr/bin/env bash
# Analyze fixtures without executing their WordPress calls. Keep the standalone
# runtime suite dependency-free so the PHP compatibility matrix needs no vendor/.
set -euo pipefail
cd "$( dirname "${BASH_SOURCE[0]}" )/.."

vendor/bin/phpstan analyse -c phpstan.neon.dist --no-progress --memory-limit=512M tests/phpstan/valid.php
result="$(mktemp)"
diagnostics="$(mktemp)"
trap 'rm -f "$result" "$diagnostics"' EXIT
status=0
vendor/bin/phpstan analyse -c phpstan.neon.dist --no-progress --memory-limit=512M --error-format=json tests/phpstan/invalid.php > "$result" 2> "$diagnostics" || status=$?
if [ "$status" -ne 1 ]; then
    cat "$result" "$diagnostics"
    echo "Expected contract violations (exit 1), got exit $status." >&2
    exit 1
fi
php -r '
$data = json_decode(file_get_contents($argv[1]), true);
$expected = array(
    "keel_contract_reject_missing_key" => "offsetAccess.notFound",
    "keel_contract_reject_owner" => "return.type",
    "keel_contract_reject_report_key" => "offsetAccess.notFound",
    "keel_contract_reject_install_plan" => "argument.type",
    "keel_contract_reject_result_key" => "offsetAccess.notFound",
    "keel_contract_reject_return_target" => "offsetAccess.notFound",
    "keel_contract_reject_health_status" => "return.type",
    "keel_contract_reject_plugin_paths" => "return.type",
    "keel_contract_reject_registration_key" => "offsetAccess.notFound",
    "keel_contract_reject_divergence_marker" => "return.type",
    "keel_contract_reject_notice_screens" => "return.type",
    "keel_contract_reject_version_status" => "return.type",
    "keel_contract_reject_lookup_record" => "return.type",
    "keel_contract_reject_failure_text" => "return.type",
    "keel_contract_reject_action_fallthrough" => "deadCode.unreachable",
    "keel_contract_reject_password_false" => array("return.type", "return.unusedType", "return.unusedType"),
    "keel_contract_reject_prepared_user" => array("argument.type", "argument.templateType"),
    "keel_contract_reject_range_values" => "argument.type",
    "keel_contract_reject_choice_type" => "argument.type",
    "keel_contract_reject_range_labels" => "argument.type",
    "keel_contract_reject_minor_core_input" => "argument.type",
    "keel_contract_reject_minor_core_output" => "return.type",
    "keel_contract_reject_major_core_input" => "argument.type",
    "keel_contract_reject_major_core_output" => "return.type",
    "keel_contract_reject_dev_core_input" => "argument.type",
    "keel_contract_reject_dev_core_output" => "return.type",
    "keel_contract_reject_translation_input" => "argument.type",
    "keel_contract_reject_translation_output" => "return.type",
);
foreach ($expected as $case => $identifiers) {
    $expected[$case] = (array) $identifiers;
    sort($expected[$case]);
}
ksort($expected);
$expected_error_count = array_sum(array_map("count", $expected));
$lines = file("tests/phpstan/invalid.php");
$function = "";
$owners = array();
foreach ($lines as $number => $line) {
    if (preg_match("/function (keel_contract_reject_[a-z_]+)/", $line, $match)) {
        $function = $match[1];
    }
    $owners[$number + 1] = $function;
}
$found = array();
foreach (($data["files"] ?? array()) as $file) {
    foreach ($file["messages"] as $message) {
        $found[$owners[$message["line"]] ?? ""][] = $message["identifier"];
    }
}
foreach ($found as $case => $identifiers) {
    sort($found[$case]);
}
ksort($found);
if (!is_array($data) || !empty($data["errors"]) || ($data["totals"]["file_errors"] ?? 0) !== $expected_error_count || $expected !== $found) {
    fwrite(STDERR, "Contract fixture did not reject every expected case.\n");
    fwrite(STDERR, file_get_contents($argv[1]));
    exit(1);
}
echo "Static contracts: ", count($expected), " invalid cases rejected (", $expected_error_count, " diagnostics).\n";
' "$result"
