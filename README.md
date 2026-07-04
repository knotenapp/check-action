# Knoten architecture check — GitHub Action

Fail a build when a Laravel architecture rule declared in `knoten.php` is
violated. Wraps [Knoten](https://github.com/Williamug/knoten)'s `knoten:check`
gate in a prebuilt Docker image, so no PHP/Composer setup is needed in the
consuming workflow. Violations are reported as inline annotations on the PR diff
and fail the job.

## Usage

```yaml
name: architecture

on:
  pull_request:
  push:
    branches: [main, develop]

permissions:
  contents: read

jobs:
  knoten-check:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: knotenapp/check-action@v1
        # with:
        #   path: .            # project to check, relative to the repo root
        #   config: knoten.php # rules file; default: auto-discover knoten.php
```

## Inputs

| Input    | Default        | Description                                                        |
| -------- | -------------- | ------------------------------------------------------------------ |
| `path`   | `.`            | Path to the project to check, relative to the repo root.           |
| `config` | *auto-discover* | Rules file relative to the repo. Defaults to `knoten.php` / `.knoten.php` in the project. |

## Rules file

Declare forbidden dependencies in a `knoten.php` at the project root:

```php
<?php

return [
    'rules' => [
        [
            'name' => 'Controllers must not query tables directly',
            'from' => ['kind' => 'controller'],
            'to'   => ['kind' => 'table'],
        ],
    ],
];
```

## How the image is built

Knoten isn't a Composer package, so the [`Dockerfile`](./Dockerfile) clones the
app from its repo at a pinned ref and overlays the action entrypoint. The
`publish` workflow builds and pushes the image to
`ghcr.io/knotenapp/check-action` on each `v*` tag; the app build it bundles is
set by the `KNOTEN_REF` repository variable.
