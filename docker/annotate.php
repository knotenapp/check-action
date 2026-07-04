<?php

/**
 * Turns `knoten:check --json` output into GitHub Actions error annotations, a
 * human summary, and — on pull requests — a single sticky PR comment. Reads the
 * JSON violations array on STDIN; argv[1] is the repo root, used to make file
 * paths relative so GitHub can attach annotations to the right line.
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
} else {
    fwrite(STDOUT, sprintf(
        "\nKnoten found %d architecture violation(s) across %d rule(s):\n",
        $total,
        count($byRule),
    ));

    foreach ($byRule as $rule => $count) {
        fwrite(STDOUT, sprintf("  • %s: %d\n", $rule, $count));
    }
}

// Mirror the verdict into a sticky PR comment when running on a pull request.
knoten_post_pr_comment($violations, $relative);

/**
 * Upsert a single Knoten comment on the current pull request. No-ops (silently)
 * unless commenting is enabled, a token is present, and the event is a PR — so a
 * `push` build or a missing token never errors, it just skips the comment.
 *
 * @param  list<array<string, mixed>>  $violations
 * @param  callable(?string): ?string  $relative
 */
function knoten_post_pr_comment(array $violations, callable $relative): void
{
    $flag = strtolower(trim((string) getenv('INPUT_COMMENT')));
    if (in_array($flag, ['false', '0', 'no', 'off'], true)) {
        return;
    }

    $token = (string) getenv('INPUT_GITHUB_TOKEN');
    if ($token === '') {
        return;
    }

    $event = strtolower((string) getenv('GITHUB_EVENT_NAME'));
    if (! in_array($event, ['pull_request', 'pull_request_target'], true)) {
        return;
    }

    $repo = (string) getenv('GITHUB_REPOSITORY');
    $api = rtrim((string) (getenv('GITHUB_API_URL') ?: 'https://api.github.com'), '/');
    $eventPath = (string) getenv('GITHUB_EVENT_PATH');

    $prNumber = null;
    if ($eventPath !== '' && is_file($eventPath)) {
        $payload = json_decode((string) file_get_contents($eventPath), true);
        $prNumber = $payload['pull_request']['number'] ?? $payload['number'] ?? null;
    }

    if ($repo === '' || $prNumber === null) {
        return;
    }

    $marker = '<!-- knoten-check -->';
    $body = $marker."\n".knoten_comment_body($violations, $relative);

    // Find our previous comment (if any) so the result stays a single, updating
    // comment instead of piling a new one onto every run.
    $existingId = null;
    $existing = knoten_github_api('GET', "{$api}/repos/{$repo}/issues/{$prNumber}/comments?per_page=100", $token);
    if (is_array($existing)) {
        foreach ($existing as $comment) {
            if (isset($comment['body']) && str_contains((string) $comment['body'], $marker)) {
                $existingId = $comment['id'];
                break;
            }
        }
    }

    if ($existingId !== null) {
        knoten_github_api('PATCH', "{$api}/repos/{$repo}/issues/comments/{$existingId}", $token, ['body' => $body]);
    } else {
        knoten_github_api('POST', "{$api}/repos/{$repo}/issues/{$prNumber}/comments", $token, ['body' => $body]);
    }
}

/**
 * Render the Markdown body of the PR comment: a green all-clear or a table of
 * every violation grouped by nothing (one row each) with a clickable location.
 *
 * @param  list<array<string, mixed>>  $violations
 * @param  callable(?string): ?string  $relative
 */
function knoten_comment_body(array $violations, callable $relative): string
{
    $sha = substr((string) getenv('GITHUB_SHA'), 0, 7);
    $footer = $sha !== '' ? "\n\n<sub>Knoten architecture check · commit <code>{$sha}</code></sub>" : '';

    if ($violations === []) {
        return "### ✅ Knoten architecture check\n\nNo architecture violations found.".$footer;
    }

    $rules = [];
    foreach ($violations as $violation) {
        $rules[$violation['rule'] ?? 'Architecture violation'] = true;
    }

    $total = count($violations);
    $ruleCount = count($rules);

    $out = "### 🚧 Knoten architecture check\n\n";
    $out .= "Found **{$total}** architecture violation(s) across **{$ruleCount}** rule(s).\n\n";
    $out .= "| Rule | From → To | Kind | Location |\n";
    $out .= "| --- | --- | --- | --- |\n";

    foreach ($violations as $violation) {
        $source = $violation['source'] ?? [];
        $target = $violation['target'] ?? [];
        $edge = $violation['edge'] ?? [];

        $file = $relative($source['file'] ?? null);
        $line = $edge['sites'][0]['line'] ?? $source['line'] ?? null;
        $location = $file !== null
            ? ($line !== null ? "`{$file}:{$line}`" : "`{$file}`")
            : '—';

        $out .= sprintf(
            "| %s | `%s` → `%s` | %s | %s |\n",
            knoten_md_cell((string) ($violation['rule'] ?? '?')),
            knoten_md_cell((string) ($source['label'] ?? '?')),
            knoten_md_cell((string) ($target['label'] ?? '?')),
            knoten_md_cell((string) ($edge['kind'] ?? 'depends-on')),
            $location,
        );
    }

    return $out.$footer;
}

/**
 * Escape the characters that would break a Markdown table cell.
 */
function knoten_md_cell(string $value): string
{
    return str_replace(['|', "\r", "\n"], ['\\|', ' ', ' '], $value);
}

/**
 * Minimal GitHub REST call. Uses ext-curl when available, else an HTTPS stream.
 * Returns the decoded JSON body, or null on a transport/HTTP error (logged to
 * STDERR) so a comment failure never breaks the gate's exit status.
 *
 * @param  array<string, mixed>|null  $json
 * @return mixed
 */
function knoten_github_api(string $method, string $url, string $token, ?array $json = null)
{
    $payload = $json !== null ? (string) json_encode($json) : null;
    $headers = [
        "Authorization: Bearer {$token}",
        'Accept: application/vnd.github+json',
        'User-Agent: knoten-check',
        'X-GitHub-Api-Version: 2022-11-28',
        'Content-Type: application/json',
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
    } else {
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $payload ?? '',
            'ignore_errors' => true,
        ]]);
        $response = @file_get_contents($url, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                $status = (int) $m[1];
            }
        }
    }

    if ($response === false || $status >= 400) {
        fwrite(STDERR, "Knoten: PR comment API {$method} failed (HTTP {$status}).\n");

        return null;
    }

    return json_decode((string) $response, true);
}
