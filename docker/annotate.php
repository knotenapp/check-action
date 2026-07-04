<?php

/**
 * Turns `knoten:check --json` output into GitHub Actions error annotations plus a
 * human summary. Reads the JSON violations array on STDIN; argv[1] is the repo
 * root, used to make file paths relative so GitHub can attach the annotation to
 * the right line.
 *
 * When the input isn't the JSON array (e.g. the "no rules — nothing to check"
 * notice), it is passed through untouched.
 */
$root = rtrim((string) ($argv[1] ?? ''), '/');
$raw = stream_get_contents(STDIN) ?: '';

$violations = json_decode($raw, true);

if (! is_array($violations)) {
    fwrite(STDOUT, $raw);
    exit(0);
}

$relative = static function (?string $file) use ($root): ?string {
    if ($file === null) {
        return null;
    }

    return $root !== '' && str_starts_with($file, $root)
        ? ltrim(substr($file, strlen($root)), '/')
        : $file;
};

$escape = static fn (string $value): string => str_replace(
    ["\r", "\n", '%'],
    ['%0D', '%0A', '%25'],
    $value,
);

$byRule = [];

foreach ($violations as $violation) {
    $rule = $violation['rule'] ?? 'Architecture violation';
    $byRule[$rule] = ($byRule[$rule] ?? 0) + 1;

    $source = $violation['source'] ?? [];
    $target = $violation['target'] ?? [];
    $edge = $violation['edge'] ?? [];
    $file = $relative($source['file'] ?? null);
    $line = $edge['sites'][0]['line'] ?? $source['line'] ?? 1;

    $message = $escape(sprintf(
        '%s: %s → %s (%s)',
        $rule,
        $source['label'] ?? '?',
        $target['label'] ?? '?',
        $edge['kind'] ?? 'depends-on',
    ));

    if ($file !== null) {
        printf("::error file=%s,line=%d::%s\n", $file, $line, $message);
    } else {
        printf("::error::%s\n", $message);
    }
}

$total = count($violations);

if ($total === 0) {
    fwrite(STDOUT, "Knoten: architecture OK — no violations.\n");
    exit(0);
}

fwrite(STDOUT, sprintf(
    "\nKnoten found %d architecture violation(s) across %d rule(s):\n",
    $total,
    count($byRule),
));

foreach ($byRule as $rule => $count) {
    fwrite(STDOUT, sprintf("  • %s: %d\n", $rule, $count));
}
